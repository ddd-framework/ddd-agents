<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationChildTerminal;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentSubAgentRunEnd;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults;
use DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationTransactionsService;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Delegation RESULTS: a child's terminal record (its result slice on the parent's
 * `delegate` call, the batch's exactly-once parent-resume claim) and the parent resume after it, the signals that tell
 * the parent its children or its background work finished, the repair of `delegate` calls a lost completion left
 * unresolved, and the adoption of a batch membership another conversation stamped mid-turn. Admitting queued children
 * into the slot a finished child freed is the spawn's ({@see AIConversationDelegationSpawnService}); the loop runs it
 * between {@see recordDelegationChildTerminal()} and {@see resumeParentAfterChildTerminal()}, today's order.
 */
class AIConversationDelegationResultsService extends Service
{
    use AITraceLogTrait;

    /**
     * The end of a sub-agent's run — ONE transaction (READ COMMITTED): the sub-agent's `delegate` row
     * locked FIRST (the lock every end of this run takes), the child's token-guarded terminal UPDATE with the fence of
     * the accepted input (an input newer than the worker's last look makes the child YIELD and continue), the run's
     * terminal write on the record (status, finish, the child's verdict and whole-subtree cost; the reason `childFailed`
     * on a FAILED run) and the deadline row's end. Lost terminal UPDATE: a yield (released IDLE, resumed), a child that
     * is already terminal (the timeout ended the run first — its end and notification are written; this verdict stays
     * in the child's transcript) or a claim lost to a live worker. A child of a row stored before sub-agent records existed ends through
     * the legacy finish ({@see recordDelegationChildTerminal()}). What follows outside the transaction — the freed
     * slot's promotion and the notification — is the loop's ({@see notifyParentAfterRunEnd()}).
     *
     * @throws Throwable
     */
    public function recordSubAgentRunEnd(
        AgentRun $agentRun,
        AIConversation $childConversation,
        string $runToken,
        string $terminalRunState,
        AIConversationDelegationBatchesService $batchesService
    ): AgentSubAgentRunEnd {
        $delegateCallMessageId = $childConversation->delegatedFromMessageId ?? null;
        $childId = $childConversation->id ?? null;
        $storedDelegateCallRow = $delegateCallMessageId !== null && $childId !== null
            ? $this->messagesService()->findStoredDelegateCallRow((int)$delegateCallMessageId)
            : null;
        if ($storedDelegateCallRow === null || !isset($storedDelegateCallRow->delegationResults) || $storedDelegateCallRow->delegationResults->isLegacyMap()) {
            return AgentSubAgentRunEnd::ofLegacyChild($this->recordDelegationChildTerminal($agentRun, $childConversation, $runToken, $terminalRunState, $batchesService));
        }
        $lastSeenMessageId = $this->findLastSeenMessageId($childConversation);
        $rootConversationId = $childConversation->rootConversationId ?? $storedDelegateCallRow->aiConversationId;
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $conversationsTable = DBAIConversationModel::getTableName();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $this->transactionsService()->beginReadCommittedTransaction($connection);
        try {
            $lockedDelegateCallRow = $this->messagesService()->lockStoredDelegateCallRow((int)$delegateCallMessageId);
            $runNumber = $lockedDelegateCallRow?->delegationResults?->subAgent?->currentRunNumber ?? 1;
            // the accepted-input fence: an inbound row newer than the worker's last look — and on the end of
            // an ANSWERED turn, an orchestrator's message no model call has read, wherever it sits: the worker's last look
            // is its reload after the final model call, so a message written WAITING during that call sits below the final
            // text; the child yields and its next turn reads it. A FAILED end, or a turn cut by a cap, is never fenced on
            // it (its next turn could not read it either — a yield loop): the message is named in the run's end instead.
            $fencesOnWaitingOrchestratorMessage = self::fencesRunEndOnWaitingOrchestratorMessage($agentRun, $terminalRunState);
            $fenceConditions = [];
            if ($lastSeenMessageId !== null) {
                $fenceConditions[] = '((`m`.`role` = :userRole OR `m`.`messageType` = :signalType) AND `m`.`id` > :lastSeenMessageId)';
            }
            if ($fencesOnWaitingOrchestratorMessage) {
                $fenceConditions[] = '(`m`.`messageType` = :signalType AND `m`.`status` = :waiting AND `m`.`signalSource` = :orchestratorMessage AND `m`.`truncatedAt` IS NULL)';
            }
            $fence = $fenceConditions !== []
                ? " AND NOT EXISTS (SELECT 1 FROM `$messagesTable` `m` WHERE `m`.`aiConversationId` = :childId AND (" . implode(' OR ', $fenceConditions) . '))'
                : '';
            $terminalParameters = [
                'terminal' => $terminalRunState,
                'childId' => $childId,
                'token' => $runToken,
                'running' => AIConversationRunState::RUNNING,
            ];
            if ($fenceConditions !== []) {
                $terminalParameters['signalType'] = AIConversationMessage::MESSAGE_SIGNAL;
            }
            if ($lastSeenMessageId !== null) {
                $terminalParameters['userRole'] = AIConversationMessage::ROLE_USER;
                $terminalParameters['lastSeenMessageId'] = $lastSeenMessageId;
            }
            if ($fencesOnWaitingOrchestratorMessage) {
                $terminalParameters['waiting'] = AIConversationMessage::STATUS_PENDING;
                $terminalParameters['orchestratorMessage'] = AIConversationMessage::SIGNAL_SOURCE_ORCHESTRATOR_MESSAGE;
            }
            $terminalRows = (int)$connection->executeStatement(
                "UPDATE `$conversationsTable` SET `runState` = :terminal, `runToken` = NULL"
                . ' WHERE `id` = :childId AND `runToken` = :token AND `runState` = :running' . $fence,
                $terminalParameters
            );
            if ($terminalRows !== 1) {
                $connection->rollBack();
                return $this->decideLostRunEnd($agentRun, $childConversation, $runToken, true, $rootConversationId, (int)$delegateCallMessageId, $runNumber);
            }
            $resultSlice = $childConversation->buildDelegationResultSlice();
            $lockedRecord = $lockedDelegateCallRow?->delegationResults?->subAgent;
            // a continuation's child carries the earlier runs' work too: the run records only its own spend, from the stored
            // rows (a compacted child's loaded window misses the earlier runs)
            $childTotalCostInUsd = $this->messagesService()->findTotalCostInUsdOfConversation($childConversation);
            $runCostInUsd = $lockedRecord !== null ? $lockedRecord->buildCostOfCurrentRun($childTotalCostInUsd) : $childTotalCostInUsd;
            // an end that was not fenced on it names the orchestrator's message the child never read: a
            // FAILED end in its detail, a COMPLETED end of a capped turn after its result
            $messageWasNotRead = !$fencesOnWaitingOrchestratorMessage && $this->messagesService()->hasOrchestratorMessageWaitingForAModelCall($childConversation);
            $verdict = $resultSlice->getVerdictUnlessBlank();
            if ($messageWasNotRead && $terminalRunState === AIConversationRunState::COMPLETED) {
                $verdict = ($verdict ?? 'No result') . self::UNREAD_MESSAGE_NOTE_AFTER_RESULT;
            }
            $endedRun = DelegationSubAgentRun::ended(
                $runNumber,
                $terminalRunState === AIConversationRunState::COMPLETED ? DelegationSubAgentRun::STATUS_COMPLETED : DelegationSubAgentRun::STATUS_FAILED,
                new DateTime(),
                $verdict,
                $terminalRunState === AIConversationRunState::COMPLETED ? null : DelegationSubAgentRun::FAILURE_REASON_CHILD_FAILED,
                $messageWasNotRead && $terminalRunState !== AIConversationRunState::COMPLETED ? self::UNREAD_MESSAGE_NOTE : null,
                $runCostInUsd
            );
            $runEndWritten = $this->messagesService()->writeSubAgentRunEnded((int)$delegateCallMessageId, $endedRun);
            $deadlineId = $childConversation->delegationBatchId ?? null;
            if ($deadlineId !== null) {
                $batchesService->markSubAgentRunDeadlineEnded((int)$deadlineId, $endedRun->finishedAt ?? new DateTime());
            }
            $connection->commit();
        } catch (Throwable $runEndError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            } else {
                $connection->close();
            }
            throw $runEndError;
        }
        $this->runClaimsService()->forgetRunTokenClaimedInThisProcess($childConversation);
        $this->aiTrace('delegation.subAgent.runEnded', [
            'childConversationId' => $childId,
            'delegateCallMessageId' => $delegateCallMessageId,
            'runNumber' => $runNumber,
            'terminalRunState' => $terminalRunState,
            'runEndWritten' => $runEndWritten,
        ]);
        return AgentSubAgentRunEnd::decided($runEndWritten, $rootConversationId !== null ? (int)$rootConversationId : null, (int)$delegateCallMessageId, $runNumber);
    }

    /** The failure detail of a run end whose child never read its orchestrator's message. */
    public const string UNREAD_MESSAGE_NOTE = 'your message to it was not read';

    /** The same, after the result of a COMPLETED run end (a turn cut by a cap). */
    public const string UNREAD_MESSAGE_NOTE_AFTER_RESULT = '; your message to it was not read';

    /**
     * Whether a run end waits for an orchestrator's message no model call has read: only a COMPLETED end
     * of a turn that ended with an answer (a run end outside a model loop counts as answered) — its child yields and the
     * next turn's first model call reads the message. A FAILED end (a pre-drive failure over the retry limit, the budget)
     * and a turn cut by a cap are never held back: their next turn could not read it either.
     */
    protected static function fencesRunEndOnWaitingOrchestratorMessage(AgentRun $agentRun, string $terminalRunState): bool
    {
        return $terminalRunState === AIConversationRunState::COMPLETED && $agentRun->lastLoopStopReason?->isAnswered() !== false;
    }

    /**
     * The second half of a run end, after the loop promoted the freed slot: the run's one
     * notification into the parent when THIS process wrote the end; for a legacy child the legacy parent resume.
     *
     * @throws Throwable
     */
    public function notifyParentAfterRunEnd(AgentSubAgentRunEnd $agentSubAgentRunEnd, AIConversationDelegationBatchesService $batchesService): void
    {
        if ($agentSubAgentRunEnd->legacyChildTerminal !== null) {
            $this->resumeParentAfterChildTerminal($agentSubAgentRunEnd->legacyChildTerminal, $batchesService);
            return;
        }
        if (!$agentSubAgentRunEnd->runEndWritten || $agentSubAgentRunEnd->delegateCallMessageId === null) {
            return;
        }
        $this->delegationNotificationsService()->notifyParentOfSubAgentRunEnd($agentSubAgentRunEnd->delegateCallMessageId, $agentSubAgentRunEnd->runNumber);
    }

    /**
     * A run end whose terminal UPDATE matched no row: the fence refused (the token is still ours and RUNNING — an input
     * landed after the last look: the child yields, released IDLE and resumed), the child is already terminal (its run
     * end was written by the timeout or a redelivery — the slot is free), or a live worker owns it.
     */
    protected function decideLostRunEnd(
        AgentRun $agentRun,
        AIConversation $childConversation,
        string $runToken,
        bool $fenced,
        ?int $rootConversationId,
        int $delegateCallMessageId,
        int $runNumber
    ): AgentSubAgentRunEnd {
        $childRow = EntityManagerFactory::getInstance()->getConnection()->fetchAssociative(
            'SELECT `runToken`, `runState` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` = :childId',
            ['childId' => $childConversation->id]
        );
        if ($childRow !== false && $fenced && $childRow['runToken'] === $runToken && $childRow['runState'] === AIConversationRunState::RUNNING) {
            $this->aiTrace('turn.yielded.child.acceptedInput', ['conversationId' => $childConversation->id ?? null]);
            $this->runClaimsService()->releaseRun($childConversation, $runToken, AIConversationRunState::IDLE, 0, new DateTime());
            $this->realtimeSignalService()->publishChangeSignal($childConversation, AIConversationRealtimeSignalService::STATUS_RUNNING, $agentRun->pushConfigMemo);
            $this->resumeConversationAsync($childConversation);
            return AgentSubAgentRunEnd::yieldedForAcceptedInput();
        }
        $this->runClaimsService()->forgetRunTokenClaimedInThisProcess($childConversation);
        $childIsTerminal = $childRow !== false && AIConversationRunState::of((string)$childRow['runState'])->isTerminal();
        return AgentSubAgentRunEnd::decided(false, $childIsTerminal ? $rootConversationId : null, $delegateCallMessageId, $runNumber);
    }

    /** The newest message id this child's worker derived from — the fence of its terminal write. */
    protected function findLastSeenMessageId(AIConversation $childConversation): ?int
    {
        $lastSeenMessageId = null;
        if (isset($childConversation->messages)) {
            foreach ($childConversation->messages->getElements() as $seenMessage) {
                if (isset($seenMessage->id) && (int)$seenMessage->id > (int)$lastSeenMessageId) {
                    $lastSeenMessageId = (int)$seenMessage->id;
                }
            }
        }
        return $lastSeenMessageId;
    }

    /** The run-end notifier — resolved inline (DI rule); a test double replaces it. */
    protected function delegationNotificationsService(): AIConversationDelegationNotificationsService
    {
        /** @var AIConversationDelegationNotificationsService $aIConversationDelegationNotificationsService */
        $aIConversationDelegationNotificationsService = DDDService::instance()->getService(AIConversationDelegationNotificationsService::class);
        return $aIConversationDelegationNotificationsService;
    }

    /**
     * Child-finish tail: a delegation CHILD that reached a terminal verdict finishes into its parent's batch.
     * Writes the verdict onto the spawning `delegate`-call (on the PARENT, reached via this child's
     * `delegatedFromMessage`) — the channel the parent's results-reading turn + `check_delegations` read — then runs the exactly-once
     * batch settle ({@see AIConversationDelegationBatchesService::claimParentResumeOnChildTerminal}), which transitions THIS
     * child terminal (`$terminalRunState` COMPLETED/FAILED, clearing its run-token) and, if this completion first
     * satisfies the resume condition, claims the single parent resume. On a won wake: cancel the losers on a QUORUM/ANY
     * settle, then dispatch the parent resume (commit-before-fire — the settle already committed). A finishing child
     * always frees an in-flight slot → promote queued siblings regardless of who won.
     */
    public function recordDelegationChildTerminal(
        AgentRun $agentRun,
        AIConversation $childConversation,
        string $runToken,
        string $terminalRunState,
        AIConversationDelegationBatchesService $batchesService
    ): AgentDelegationChildTerminal {
        // each child writes ONLY its OWN {task, verdict} slice onto the shared delegate message's
        // delegationResults map below — concurrent siblings MERGE (JSON_MERGE_PATCH), so none clobbers another, and
        // {@see AIConversationMessage::getDelegationResult()} composes the call's result from that persisted map. Here we
        // resolve the anchor (for the parent id below) + record THIS child terminal; the delegate-call status is marked
        // COMPLETED ONCE when the group finishes (single writer, the settledAt gate (column name kept)).
        // isset() guard: a bare read of the un-set #[LazyLoad] anchor throws.
        $delegateCall = isset($childConversation->delegatedFromMessage) ? $childConversation->delegatedFromMessage : null;
        // The delegate call's id — from the column, never the lazy relation (unhydrated on an async resume); used
        // below to mark the call COMPLETED on the fresh settle.
        $delegateMessageId = $childConversation->delegatedFromMessageId ?? $delegateCall?->id ?? null;

        $batchId = $childConversation->delegationBatchId ?? null;
        if ($batchId === null) {
            // Defensive — an async delegation child should always carry a batch. Record the child terminal + resume the parent.
            $this->publishDelegationResultSlice($childConversation);
            $this->runClaimsService()->releaseRun($childConversation, $runToken, AIConversationRunState::IDLE, 0);
            // One level of delegation: the parent IS the root, carried as a column on the child — the lazy
            // `delegatedFromMessage` relation is never loaded on an async resume (the isset guard above).
            $parentConversationId = $childConversation->rootConversationId ?? $delegateCall?->aiConversationId;
            if ($parentConversationId !== null) {
                (new AIConversationResumeMessage((int)$parentConversationId))->dispatch();
            }
            return new AgentDelegationChildTerminal();
        }

        // The settle transitions THIS child terminal (its own token-guarded step) + claims the parent resume exactly once.
        // The terminal write is fenced on the newest message this worker derived from: a USER
        // continuation accepted after that look refuses the fence and the child YIELDS instead — released IDLE, resumed —
        // so the continuation is driven and never stranded behind a terminal row.
        $lastSeenMessageId = null;
        if (isset($childConversation->messages)) {
            foreach ($childConversation->messages->getElements() as $seenMessage) {
                if (isset($seenMessage->id) && (int)$seenMessage->id > (int)$lastSeenMessageId) {
                    $lastSeenMessageId = (int)$seenMessage->id;
                }
            }
        }
        $resumeClaim = $batchesService->claimParentResumeOnChildTerminal($childConversation, $runToken, $terminalRunState, $lastSeenMessageId);
        if (!$resumeClaim->yieldedForAcceptedInput) {
            // the child is terminal now (or another process made it so) — this process drives it no longer
            $this->runClaimsService()->forgetRunTokenClaimedInThisProcess($childConversation);
        }
        if ($resumeClaim->yieldedForAcceptedInput) {
            // Durable hand-over: the release stamps the OWED turn (the owed-run recovery cron re-fires a lost dispatch),
            // then the resume is dispatched. No verdict was published — the child continues.
            $this->aiTrace('turn.yielded.child.acceptedInput', ['conversationId' => $childConversation->id ?? null]);
            $this->runClaimsService()->releaseRun($childConversation, $runToken, AIConversationRunState::IDLE, 0, new DateTime());
            $this->realtimeSignalService()->publishChangeSignal($childConversation, AIConversationRealtimeSignalService::STATUS_RUNNING, $agentRun->pushConfigMemo);
            $this->resumeConversationAsync($childConversation);
            $childTerminal = new AgentDelegationChildTerminal();
            $childTerminal->yieldedForAcceptedInput = true;
            return $childTerminal;
        }
        // the child writes its OWN result slice onto the shared delegate message — when its terminal write won,
        // or when the row was ALREADY terminal (a timeout / cancellation cleared the token and wrote no slice — nobody
        // else will). A claim lost to a LIVE worker publishes nothing: that worker owns the verdict.
        if ($resumeClaim->childTerminalWon) {
            $this->publishDelegationResultSlice($childConversation);
        } elseif ($resumeClaim->childWasAlreadyTerminal) {
            // A worker that lost its token to a timeout / cancellation fills the gap ONLY: an existing slice belongs
            // to the worker that finished the child — never overwritten by a stale one.
            $this->publishDelegationResultSlice($childConversation, onlyIfAbsent: true);
        }
        // A finishing child freed an in-flight slot: the loop promotes the queue regardless of who won the parent resume.
        $childTerminal = new AgentDelegationChildTerminal();
        $rootConversationId = $childConversation->rootConversationId ?? $delegateCall?->aiConversationId;
        $childTerminal->rootConversationIdWithFreedSlot = $rootConversationId !== null ? (int)$rootConversationId : null;
        $childTerminal->wonParentResume = $resumeClaim->wonParentResume;
        $childTerminal->wasFreshSettle = $resumeClaim->wasFreshSettle;
        $childTerminal->delegationBatchId = (int)$batchId;
        $childTerminal->delegateMessageId = $delegateMessageId !== null ? (int)$delegateMessageId : null;
        $childTerminal->delegateCallMessage = $delegateCall;
        return $childTerminal;
    }

    /**
     * The second half of a child's finish, after the loop promoted the freed slot: on a won parent resume, cancel the
     * losers of a fresh QUORUM/ANY settle, mark the delegate call COMPLETED once, and dispatch the parent's resume —
     * never for a BACKGROUND batch (its note rides the parent's next genuine turn) and never for a parent that is over.
     */
    public function resumeParentAfterChildTerminal(AgentDelegationChildTerminal $childTerminal, AIConversationDelegationBatchesService $batchesService): void
    {
        $batchId = $childTerminal->delegationBatchId;
        $wasFreshSettle = $childTerminal->wasFreshSettle;
        $delegateMessageId = $childTerminal->delegateMessageId;
        $delegateCall = $childTerminal->delegateCallMessage;
        if (!$childTerminal->wonParentResume || $batchId === null) {
            return;
        }
        $batch = $batchesService->findBatchRowById($batchId);
        if ($batch === null) {
            return;
        }
        // Reaching a QUORUM / ANY condition no longer cancels the other children of a legacy batch — they
        // end through the same legacy finish (their answers still land on the row).
        // All children finished → mark the delegate call COMPLETED ONCE (sole winner; settledAt gate), addressed by
        // its id (delegatedFromMessageId — the column, never the lazy `delegatedFromMessage` relation, unhydrated on
        // an async resume). A call left RUNNING pins the compaction cover and shows the agent as busy in the admin chat.
        if ($wasFreshSettle && $delegateMessageId !== null) {
            // ONE compare-and-set on the row's `toolCall.status` (PENDING/RUNNING → COMPLETED) instead of a
            // load-mark-persist of the whole JSON column: the parent still holds this message in memory as RUNNING
            // and may re-persist it during its own turn — the row is the truth, and the parent's persist re-reads
            // it ({@see AIConversationMessagesService::persistMessageUnderRun()}). COMPLETED means the orchestration finished, whatever the children's own
            // outcomes were — those live in their run states and result slices.
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $aIConversationMessagesService->resolveDelegateCallStatusIfUnresolved((int)$delegateMessageId, AIConversationMessage::STATUS_COMPLETED);
            if ($delegateCall !== null && isset($delegateCall->toolCall) && !$delegateCall->toolCall->isResolved()) {
                $delegateCall->toolCall->markCompleted();
            }
        }
        // BACKGROUND batch: the settle is complete and the delegate call is marked,
        // but the parent is NEVER auto-driven — the completion note rides the next genuine turn.
        if ($batch->isBackgroundCondition()) {
            return;
        }
        if ($batch->parentConversationId !== null) {
            // Never re-drive a parent that is over (a re-wake for a late verdict of a COMPLETED / CANCELLED / FAILED
            // parent): its results stay readable in the fold channel, the loop must not restart it.
            $parentRunState = $this->runClaimsService()->findRunStateById((int)$batch->parentConversationId);
            if ($parentRunState === null || !AIConversationRunState::of($parentRunState)->isTerminal()) {
                (new AIConversationResumeMessage((int)$batch->parentConversationId))->dispatch();
            }
        }
    }

    /**
     * the child's OWN {task, verdict} slice onto the shared delegate message — concurrent siblings MERGE
     * (JSON_MERGE_PATCH on the delegationResults column), so none clobbers another. NO load: the target id is the
     * child's anchor (delegatedFromMessageId); a bare message with ONLY id + delegationResults set, written via the
     * strict partial update, touches no other column. Idempotent on redelivery (same key).
     */
    public function publishDelegationResultSlice(AIConversation $childConversation, bool $onlyIfAbsent = false): void
    {
        $delegateMessageId = $childConversation->delegatedFromMessageId ?? null;
        if ($delegateMessageId === null || !isset($childConversation->id)) {
            return;
        }
        if ($onlyIfAbsent) {
            // ONE conditional statement — two late writers of the same child can never both see "absent"
            // and the later overwrite the earlier one's slice
            $this->messagesService()->mergeDelegationResultSliceUnlessPresent($childConversation);
            return;
        }
        $delegationResults = new DelegationResults();
        $delegationResults->results = [(string)$childConversation->id => $childConversation->buildDelegationResultSlice()];
        $resultSlice = AIConversationMessage::newInstance();
        $resultSlice->id = $delegateMessageId;
        $resultSlice->delegationResults = $delegationResults;
        $resultSlice->updatePartialIgnoringRights('delegationResults');
    }

    /**
     * Report a SETTLED delegation fork-join to the orchestrator EXPLICITLY: append a DELEGATIONS_SETTLED
     * signal to the parent so the fold turn reads "your sub-agents finished, answer now" instead of the model having to
     * notice the now-populated `delegate` tool-result on its own (the weak-model failure where it polls
     * check_delegations until the repeat guard aborts). Called in the parent's OWN claimed drive right before
     * {@see AIConversationAgentService::generateAgentReply()}, so — unlike {@see AIConversationTurnSignalsService::injectSignal()} — it does NOT resume (the drive is already underway).
     * Idempotent across a retried fold ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages::isNewestMessageDelegationsSettledSignal()}). Best-effort: a
     * missing/unreadable batch just skips the cue (the model still has the verdicts in context).
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function injectChildrenFinishedSignal(
        AIConversation $conversation,
        int $batchId,
        AIConversationDelegationBatchesService $batchesService
    ): void {
        if (($conversation->messages ?? null)?->isNewestMessageDelegationsSettledSignal() === true) {
            return;
        }
        // NOTE: the delegate verdicts (each child's slice on the delegate-call's delegationResults VO) are
        // now loaded FRESH at results-reading time by the framework fix in DBEntitySet::find — useEntityRegistrCache=false threads
        // Query::HINT_REFRESH so the set reload re-hydrates managed entities instead of returning the long-running
        // worker's stale turn-1 copy. So getDelegationResult() renders the real `delegate` tool-result and the model can
        // answer directly; no in-service re-attach is needed.
        try {
            // find() is annotated non-null (it throws on a genuine miss); the batchId came from a query that already
            // found this settled batch, so this resolves — the catch only covers a read-rights/transient throw, where
            // we skip the cue rather than fail the fold (the verdicts are still in the delegate tool-result in context).
            $batch = $batchesService->find($batchId);
        } catch (Throwable) {
            return;
        }
        $terminalCounts = $batchesService->countChildrenByTerminalState($batchId);
        // The fan-out tool's continuation, carried on the legacy synthesized delegate call's arguments (the one-row
        // fan-out of the legacy batch) — null for a model-issued delegate.
        // Read off the conversation's OWN loaded messages by id — `spawnedByMessage` is a lazy-load property, and a
        // `??`/nullsafe read on it never triggers the load (a fold said "answer now" although the fan-out
        // call carried the continuation).
        $foldNextStep = null;
        $spawnedByMessageId = isset($batch->spawnedByMessageId) ? (int)$batch->spawnedByMessageId : 0;
        $foldSource = 'none';
        if ($spawnedByMessageId > 0) {
            $spawnedByMessage = null;
            foreach ($conversation->messages->getElements() as $candidateMessage) {
                if ((int)($candidateMessage->id ?? 0) === $spawnedByMessageId) {
                    $spawnedByMessage = $candidateMessage;
                    $foldSource = 'loaded';
                    break;
                }
            }
            if ($spawnedByMessage === null) {
                // The resume path loads a BOUNDED window — the fan-out call may sit before it. Read the row itself.
                try {
                    $spawnedByMessage = AIConversationMessage::getService()->find($spawnedByMessageId);
                    $foldSource = 'db';
                } catch (Throwable) {
                    $spawnedByMessage = null;
                }
            }
            $spawnedByArguments = $spawnedByMessage?->toolCall?->arguments ?? [];
            $foldNextStep = is_string($spawnedByArguments['foldNextStep'] ?? null) ? $spawnedByArguments['foldNextStep'] : null;
        }
        if ($foldNextStep === null) {
            // The fan-out batch is created BEFORE its delegate call is persisted (transient id on the batch row), so the
            // id path can miss. The batch's own call is the NEWEST `delegate` call in the conversation at fold time —
            // scan backwards for one that carries the continuation.
            $loadedMessages = $conversation->messages->getElements();
            for ($index = count($loadedMessages) - 1; $index >= 0; $index--) {
                $candidateMessage = $loadedMessages[$index];
                if (($candidateMessage->messageType ?? null) !== AIConversationMessage::MESSAGE_TOOL_CALL
                    || ($candidateMessage->toolCall?->name ?? null) !== DelegateTool::NAME) {
                    continue;
                }
                $candidateArguments = $candidateMessage->toolCall->arguments ?? [];
                if (is_string($candidateArguments['foldNextStep'] ?? null) && trim($candidateArguments['foldNextStep']) !== '') {
                    $foldNextStep = $candidateArguments['foldNextStep'];
                    $foldSource = 'newest-delegate-scan';
                }
                break; // the newest delegate call decides — an older fan-out's continuation must not leak forward
            }
        }
        $this->aiTrace('turn.fold.nextStep', [
            'conversationId' => $conversation->id ?? null,
            'batchId' => $batchId,
            'spawnedByMessageId' => $spawnedByMessageId,
            'source' => $foldSource,
            'hasFoldNextStep' => $foldNextStep !== null,
        ]);
        $signalMessage = AIConversationMessage::newInstance();
        $conversation->appendMessage(
            $signalMessage,
            AIConversationMessage::MESSAGE_SIGNAL,
            $batch->buildChildrenFinishedSignalText($terminalCounts, $foldNextStep),
            AIConversationMessage::ROLE_SYSTEM,
            AIConversationMessage::AUTHOR_AGENT,
            signalSource: AIConversationMessage::SIGNAL_SOURCE_DELEGATIONS_SETTLED
        );
        // update(0) — the signal message IS the target; written collision-free under the index lock.
        $this->messagesService()->persistNewRowUnderRunClaim($conversation, $signalMessage);
    }

    /**
     * Idempotent repair at the head of a genuine parent turn: every `delegate` call of this conversation whose row
     * status is still PENDING/RUNNING although its children's batch has SETTLED is resolved COMPLETED — in the row
     * (compare-and-set) and, when the message is in the loaded window, in memory. Set-based over the whole
     * conversation (a stale call below the compaction cover is repaired too). Covers the rows a lost completion
     * left behind (a parent re-persist that raced the last child's flip; a crash between the settle commit and the
     * flip): the admin chat derives "waiting for N delegations" from exactly this status and would otherwise show
     * the agent as busy for the rest of the conversation, and an unresolved call pins the compaction cover.
     */
    public function repairDelegateCallsOfSettledBatches(
        AIConversation $conversation,
        AIConversationDelegationBatchesService $batchesService
    ): void {
        if (!isset($conversation->id)) {
            return;
        }
        $staleDelegateMessageIds = $batchesService->findUnresolvedDelegateCallMessageIdsWithSettledBatch((int)$conversation->id);
        if ($staleDelegateMessageIds === []) {
            return;
        }
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        foreach ($staleDelegateMessageIds as $staleDelegateMessageId) {
            $flipped = $aIConversationMessagesService->resolveDelegateCallStatusIfUnresolved($staleDelegateMessageId, AIConversationMessage::STATUS_COMPLETED);
            $this->aiTrace('turn.delegateCall.repaired', ['conversationId' => $conversation->id, 'messageId' => $staleDelegateMessageId, 'flipped' => $flipped]);
        }
        if (!isset($conversation->messages)) {
            return;
        }
        foreach ($conversation->messages->getElements() as $message) {
            $toolCall = $message->toolCall ?? null;
            if ($toolCall !== null && isset($message->id) && in_array((int)$message->id, $staleDelegateMessageIds, true) && !$toolCall->isResolved()) {
                $toolCall->markCompleted();
            }
        }
    }

    /**
     * Inject ONE compact SIGNAL for all settled-but-unnoted BACKGROUND batches and mark them read. Runs at
     * the head of a GENUINE parent turn — background work never drives a turn of its
     * own; its results already sit on the delegate-call messages in context, this note only makes them salient
     * ("don't re-run what the background already fetched"). Idempotent: marking read happens with the injection.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function injectBackgroundWorkFinishedNote(
        AIConversation $conversation,
        AIConversationDelegationBatchesService $batchesService
    ): void {
        if (!isset($conversation->id)) {
            return;
        }
        $settledBackgroundBatchIds = $batchesService->findSettledUnfoldedBackgroundBatchIdsForParent((int)$conversation->id);
        if ($settledBackgroundBatchIds === []) {
            return;
        }
        $noteMessage = AIConversationMessage::newInstance();
        $conversation->appendMessage(
            $noteMessage,
            AIConversationMessage::MESSAGE_SIGNAL,
            'Background work finished (' . count($settledBackgroundBatchIds) . ' job group'
            . (count($settledBackgroundBatchIds) > 1 ? 's' : '') . '). The results are now available in the tool results '
            . 'above (do NOT re-run those reads). Values the owner already approved do not change because of them — an '
            . 'approved draft is persisted with the draft\'s wording.',
            AIConversationMessage::ROLE_SYSTEM,
            AIConversationMessage::AUTHOR_AGENT,
            signalSource: AIConversationMessage::SIGNAL_SOURCE_BACKGROUND_WORK_FINISHED
        );
        $this->messagesService()->persistNewRowUnderRunClaim($conversation, $noteMessage);
        foreach ($settledBackgroundBatchIds as $settledBackgroundBatchId) {
            $batchesService->markChildResultsReadByParent($settledBackgroundBatchId);
        }
    }

    /**
     * Re-reads the delegation anchor of a PURPOSED run at the end of its turn: a fan-out tool of another conversation may
     * have made it a batch member mid-turn ({@see AIConversationDelegationSpawnService::joinAwaitedConversationsToBatch()}) — three columns written to the row
     * while this worker drove the in-memory object it loaded before. Adopting them here routes the turn's end through
     * the child settle path, which settles the parent's batch at once. Purposed runs only (an awaited conversation is
     * one by design); one two-column read, never a full reload. Best-effort: a failed read changes nothing.
     */
    public function adoptDelegationAnchorJoinedMidTurn(AIConversation $conversation): void
    {
        if (!isset($conversation->id) || $conversation->isDelegation() || ($conversation->purposeName ?? null) === null) {
            return;
        }
        try {
            $delegationAnchor = AIConversation::getService()->findDelegationAnchorById((int)$conversation->id);
        } catch (Throwable) {
            return;
        }
        if ($delegationAnchor === null || $delegationAnchor->delegatedFromMessageId === null) {
            return;
        }
        $conversation->delegatedFromMessageId = $delegationAnchor->delegatedFromMessageId;
        $conversation->delegationBatchId = $delegationAnchor->delegationBatchId;
        $conversation->rootConversationId = $delegationAnchor->rootConversationId;
        $this->aiTrace('turn.delegation.anchorAdoptedMidTurn', [
            'conversationId' => $conversation->id,
            'delegatedFromMessageId' => $conversation->delegatedFromMessageId,
            'batchId' => $conversation->delegationBatchId,
        ]);
    }

    /** The transactions that read the latest commits — resolved inline (DI rule); a test double replaces it. */
    protected function transactionsService(): AIConversationTransactionsService
    {
        /** @var AIConversationTransactionsService $aIConversationTransactionsService */
        $aIConversationTransactionsService = DDDService::instance()->getService(AIConversationTransactionsService::class);
        return $aIConversationTransactionsService;
    }

    /** The messages service — resolved inline (DI rule); a test double replaces it. */
    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /** The run claims (release, the run tokens this process holds, the run state) — resolved inline (DI rule); a test double replaces it. */
    protected function runClaimsService(): AIConversationRunClaimsService
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService;
    }

    /** The realtime change-signal service — resolved inline (DI rule); a test double replaces it. */
    protected function realtimeSignalService(): AIConversationRealtimeSignalService
    {
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        return $aIConversationRealtimeSignalService;
    }

    /**
     * Enqueue the conversation's resume job — the loop's async resume ({@see AIConversationAgentService::resumeConversationIfReady()}
     * with `async: true`); a test double replaces it.
     */
    protected function resumeConversationAsync(AIConversation $conversation): void
    {
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
        $aIConversationAgentService->resumeConversationIfReady($conversation, async: true);
    }
}
