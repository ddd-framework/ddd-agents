<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRunEndQuietWait;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationRunEndNotification;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The parent's notification of a sub-agent run end: EXACTLY ONE signal row per ended run, written into
 * the parent the sub-agent belongs to — `SUB_AGENT_RUN_ENDED` (the parent is resumed to read it) or
 * `SUB_AGENT_RUN_ENDED_WITHOUT_RESUME` (it waits for the parent's next genuine turn). The run's `reportedToParentAt`
 * compare-and-set is the exactly-once gate, so the run end's own path, the per-run timeout, the reaper's recovery and the
 * parent's turn head may all call it. The row is written WAITING ({@see AIConversationMessagesService::persistSignalWaitingForRunningTurn()}):
 * a running parent takes it at its next model call, an idle one is resumed. A sync drive (CLI, eval) writes the
 * notifications of the runs it ran inline itself, at the end of the step ({@see appendNotificationsOfRunsEndedInThisStep()}).
 * Written by a process that does not drive the parent — an INBOUND writer.
 */
class AIConversationDelegationNotificationsService extends Service
{
    use AITraceLogTrait;

    /**
     * Write the one notification of run `$runNumber` of the sub-agent on `$delegateCallMessageId` into its parent:
     * under the parent's message index lock (taken FIRST) and in one transaction — the
     * `reportedToParentAt` compare-and-set, then the signal row rendered from the records. After the commit a
     * notification that resumes the parent stamps the parent's inbound activity, ticks the realtime status and — when
     * the parent is not terminal and holds no live claim — dispatches ONE resume (a running parent takes the row at its
     * next model call; its release re-checks waiting rows). Returns whether THIS call wrote the notification.
     *
     * @throws Throwable
     */
    public function notifyParentOfSubAgentRunEnd(int $delegateCallMessageId, int $runNumber): bool
    {
        $delegateCallRow = $this->messagesService()->findStoredDelegateCallRow($delegateCallMessageId);
        if ($delegateCallRow === null || $delegateCallRow->aiConversationId === null
            || !isset($delegateCallRow->delegationResults) || $delegateCallRow->delegationResults->isLegacyMap()) {
            return false;
        }
        $parentConversation = $this->buildParentConversationForRowWrites((int)$delegateCallRow->aiConversationId);
        $messagesService = $this->messagesService();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $notification = null;
        $messageIndexLock = $messagesService->lockMessageIndex($parentConversation);
        try {
            $connection->beginTransaction();
            try {
                $notification = $this->writeNotificationRowIfUnreported($parentConversation, $delegateCallMessageId, $runNumber);
                $connection->commit();
            } catch (Throwable $notificationError) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                throw $notificationError;
            }
        } finally {
            $messagesService->unlockMessageIndex($messageIndexLock);
        }
        if ($notification === null) {
            return false;
        }
        $this->aiTrace('delegation.subAgent.notified', [
            'parentConversationId' => $parentConversation->id,
            'delegateCallMessageId' => $delegateCallMessageId,
            'subAgentNumber' => $notification->subAgentNumber,
            'runNumber' => $runNumber,
            'resumesParent' => $notification->resumesParent,
        ]);
        if ($notification->resumesParent) {
            $this->resumeParentForNotification($parentConversation);
        }
        return true;
    }

    /**
     * The recovery entry: the notifications of the sub-agent whose deadline row is `$deadlineId` — of
     * EVERY run of its record that ended with a recorded end and was not reported yet, oldest first (run k's
     * lost notification is delivered although run k+1 opened since). Returns whether at least one was written now (false:
     * all reported, or no sub-agent record behind the row).
     *
     * @throws Throwable
     */
    public function notifyParentOfSubAgentRunEndOfDeadline(int $deadlineId): bool
    {
        $delegateCallRow = $this->delegationBatchesService()->findSubAgentDelegateCallRowOfDeadline($deadlineId);
        if ($delegateCallRow === null) {
            return false;
        }
        $anyWritten = false;
        foreach ($delegateCallRow->delegationResults->subAgent->runs->getEndedRunsNotReportedToParent()->getRunsInOrder() as $unreportedRun) {
            if ($unreportedRun->isRecordedRunEnd() && $this->notifyParentOfSubAgentRunEnd((int)$delegateCallRow->id, $unreportedRun->runNumber)) {
                $anyWritten = true;
            }
        }
        return $anyWritten;
    }

    /**
     * The parent's turn head: the notifications of its runs that ended more than the recovery delay ago
     * and were never reported (a crash between the run-end commit and the notifier) are written now — the running turn
     * takes them at its first model call. Best-effort per run. Returns how many were written.
     */
    public function deliverLostNotificationsOfParent(AIConversation $parentConversation): int
    {
        if (!isset($parentConversation->id)) {
            return 0;
        }
        $writtenCount = 0;
        try {
            $deadlineIds = $this->delegationBatchesService()->findEndedUnreportedSubAgentRunDeadlineIds($parentConversation);
        } catch (Throwable $recoveryReadError) {
            $this->aiTrace('delegation.subAgent.recoveryReadFailed', ['parentConversationId' => $parentConversation->id, 'error' => $recoveryReadError->getMessage()]);
            return 0;
        }
        foreach ($deadlineIds as $deadlineId) {
            try {
                $writtenCount += $this->notifyParentOfSubAgentRunEndOfDeadline($deadlineId) ? 1 : 0;
            } catch (Throwable $recoveryError) {
                $this->aiTrace('delegation.subAgent.recoveryFailed', ['parentConversationId' => $parentConversation->id, 'deadlineId' => $deadlineId, 'error' => $recoveryError->getMessage()]);
            }
        }
        return $writtenCount;
    }

    /**
     * The sync drive's notifications: after a step whose `delegate` calls ran their sub-agents inline,
     * every loaded sub-agent row whose current run ended and was not reported yet gets its notification row appended to
     * the parent — after the step's call rows, so the step's calls and their results stay one wire group. A persisted
     * parent stamps `reportedToParentAt` AFTER the row is stored (a sync run has no deadline row, so no recovery could
     * re-send a notification whose stamp committed without its row); a transient one only in memory. No other writer
     * reports a sync run: the async notifier and the recovery read deadline rows only. A refused sub-agent (NOT_RUN) is no run end: its call's own answer says
     * it. The rows are the turn's own (no resume, nothing waits): the next model call of this turn reads them.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function appendNotificationsOfRunsEndedInThisStep(
        AgentRun $agentRun,
        AIConversation $parentConversation,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        if (!isset($parentConversation->messages)) {
            return;
        }
        $loadedRecords = $this->buildRecordsOfLoadedDelegateCallRows($parentConversation->messages);
        foreach ($parentConversation->messages->buildToolCallHistory()->getDelegateCallRows() as $delegateCallRow) {
            $delegationSubAgentRecord = isset($delegateCallRow->delegationResults) && !$delegateCallRow->delegationResults->isLegacyMap()
                ? $delegateCallRow->delegationResults->subAgent
                : null;
            $endedRun = $delegationSubAgentRecord?->getCurrentRun();
            if ($delegationSubAgentRecord === null || $endedRun === null || !$endedRun->isRecordedRunEnd() || $endedRun->reportedToParentAt !== null) {
                continue;
            }
            $reportedAt = new DateTime();
            $notification = DelegationRunEndNotification::buildFor(
                $delegationSubAgentRecord,
                $endedRun->runNumber,
                $loadedRecords->getUnfinished()->getAllExceptNumber($delegationSubAgentRecord->number),
                $loadedRecords,
                $reportedAt
            );
            $notificationMessage = $this->messagesService()->appendNewMessage(
                $parentConversation,
                $transientMessageIds,
                AIConversationMessage::MESSAGE_SIGNAL,
                $notification->text,
                role: AIConversationMessage::ROLE_SYSTEM,
                authorType: AIConversationMessage::AUTHOR_AGENT,
                signalSource: $notification->resumesParent
                    ? AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED
                    : AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME
            );
            $this->messagesService()->persistMessageUnderRun($agentRun, $notificationMessage, $consoleStream, $emitRealtimeSignal);
            // stamped only once the row is stored: a sync run has no deadline row the recovery could re-send from, so a
            // failed persist must leave the run unreported (the next step's scan writes it) rather than lose it
            $endedRun->reportedToParentAt = $reportedAt;
            if (isset($parentConversation->id) && isset($delegateCallRow->id)) {
                $this->messagesService()->stampSubAgentRunReportedToParent((int)$delegateCallRow->id, $endedRun->runNumber, $reportedAt);
            }
        }
    }

    /**
     * The short wait at the head of a turn that sub-agent notifications started (2 s
     * quiet / 8 s cap): the caller holds the parent's run claim and has made no model call yet. While another sub-agent
     * of the parent has an unfinished run, poll every {@see AgentSubAgentRunEndQuietWait::POLL_MICROSECONDS} — the
     * waiting run-end notifications (one count read), the unfinished runs (one read of the child rows), the claim's
     * heartbeat — until no new run end arrived for 2 s, 8 s passed, no run is unfinished, or the claim was lost. A run end
     * in that window finds the live claim, so its notifier writes the row WAITING and dispatches nothing; the first model
     * call takes every row. Nothing is waited for when no other sub-agent runs (the first poll ends it). Returns the wait
     * (why it ended, how many run ends arrived — the caller reloads the rows when any did). The caller decides that the
     * turn is one sub-agent notifications alone opened (never an owner's turn).
     *
     * @throws Throwable
     */
    public function waitForCloseRunEndsBeforeTheFirstModelCall(AIConversation $parentConversation, string $runToken): AgentSubAgentRunEndQuietWait
    {
        $runClaimsService = $this->runClaimsService();
        $agentSubAgentRunEndQuietWait = AgentSubAgentRunEndQuietWait::startAt($this->readMonotonicMilliseconds(), $this->countWaitingRunEndNotifications($parentConversation));
        while ($agentSubAgentRunEndQuietWait->continuesAfterPoll(
            $this->readMonotonicMilliseconds(),
            $this->countWaitingRunEndNotifications($parentConversation),
            $runClaimsService->hasUnfinishedSubAgentRunThatResumesParent($parentConversation)
        )) {
            if (!$runClaimsService->heartbeat($parentConversation, $runToken)) {
                $agentSubAgentRunEndQuietWait->endBecauseTheClaimWasLost();
                break;
            }
            $this->pauseBetweenPolls();
        }
        $this->aiTrace('delegation.subAgent.quietWait', [
            'parentConversationId' => $parentConversation->id ?? null,
            'endReason' => $agentSubAgentRunEndQuietWait->endReason,
            'waitedMs' => $agentSubAgentRunEndQuietWait->getWaitedMilliseconds(),
            'runEndsArrived' => $agentSubAgentRunEndQuietWait->runEndsArrivedDuringWait,
        ]);
        return $agentSubAgentRunEndQuietWait;
    }

    /** The parent's run-end notifications that wait for a model call (the source that resumes the parent). */
    protected function countWaitingRunEndNotifications(AIConversation $parentConversation): int
    {
        return $this->messagesService()->countSignalsWaitingForRunningTurnOfSource($parentConversation, AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED);
    }

    /** The clock of the short wait — monotonic milliseconds; a test double moves it. */
    protected function readMonotonicMilliseconds(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }

    /** The pause between two polls of the short wait; a test double writes a run end here instead of sleeping. */
    protected function pauseBetweenPolls(): void
    {
        usleep(AgentSubAgentRunEndQuietWait::POLL_MICROSECONDS);
    }

    /**
     * The compare-and-set and the row, run by {@see notifyParentOfSubAgentRunEnd()} inside its transaction under the
     * parent's index lock: null when the run had already been reported (or has not ended) — nothing written.
     *
     * @throws Throwable
     */
    protected function writeNotificationRowIfUnreported(AIConversation $parentConversation, int $delegateCallMessageId, int $runNumber): ?DelegationRunEndNotification
    {
        $reportedAt = new DateTime();
        if (!$this->messagesService()->stampSubAgentRunReportedToParent($delegateCallMessageId, $runNumber, $reportedAt)) {
            return null;
        }
        $delegateCallRow = $this->messagesService()->findStoredDelegateCallRow($delegateCallMessageId);
        $endedRecord = $delegateCallRow?->delegationResults?->subAgent ?? null;
        if (!$endedRecord instanceof DelegationSubAgentRecord) {
            return null;
        }
        $recordsOfParent = $this->messagesService()->findSubAgentRecordsOfConversation($parentConversation);
        $notification = DelegationRunEndNotification::buildFor(
            $endedRecord,
            $runNumber,
            $recordsOfParent->getUnfinished()->getAllExceptNumber($endedRecord->number),
            $recordsOfParent,
            $reportedAt
        );
        $notificationMessage = AIConversationMessage::newInstance();
        $parentConversation->appendMessage(
            $notificationMessage,
            AIConversationMessage::MESSAGE_SIGNAL,
            $notification->text,
            AIConversationMessage::ROLE_SYSTEM,
            AIConversationMessage::AUTHOR_AGENT,
            signalSource: $notification->resumesParent
                ? AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED
                : AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME
        );
        // never merged into another waiting row: two run ends are two notifications (the hand-over combines them)
        $this->messagesService()->persistSignalWaitingForRunningTurn($parentConversation, $notificationMessage, false);
        return $notification;
    }

    /**
     * After the commit of a notification that resumes the parent: the inbound activity stamp, the realtime RUNNING
     * tick, and ONE resume when no live claim drives it — all three only while the parent is not
     * terminal: a closed parent (a cancelled A2A task) is never ticked RUNNING nor resumed, so the run
     * state is read first.
     */
    protected function resumeParentForNotification(AIConversation $parentConversation): void
    {
        $runClaimsService = $this->runClaimsService();
        $parentRunState = $runClaimsService->findRunStateById((int)$parentConversation->id);
        if ($parentRunState === null || AIConversationRunState::of($parentRunState)->isTerminal()) {
            return;
        }
        try {
            $this->conversationsService()->recordInboundMessageActivity($parentConversation);
            $this->realtimeSignalService()->publishChangeSignal($this->buildParentConversationForRealtimeSignal((int)$parentConversation->id), AIConversationRealtimeSignalService::STATUS_RUNNING);
        } catch (Throwable $activityError) {
            $this->aiTrace('delegation.subAgent.activityStampFailed', ['parentConversationId' => $parentConversation->id ?? null, 'error' => $activityError->getMessage()]);
        }
        if ($runClaimsService->hasLiveRunClaim($parentConversation)) {
            // the running loop takes the waiting row at its next model call; every release path re-checks waiting rows
            return;
        }
        $this->dispatchParentResume((int)$parentConversation->id);
    }

    /** Dispatch the parent's resume — under the account of the process (the child's worker runs under the tree's). */
    protected function dispatchParentResume(int $parentConversationId): void
    {
        (new AIConversationResumeMessage($parentConversationId))->dispatch();
    }

    /**
     * A bare parent for the row writers: its id and an empty loaded set — the signal row is then always re-anchored
     * above the persisted newest index (the in-memory view is never current), and no message window is lazy-loaded.
     */
    protected function buildParentConversationForRowWrites(int $parentConversationId): AIConversation
    {
        $parentConversation = AIConversation::newInstance();
        $parentConversation->id = $parentConversationId;
        $parentConversation->messages = AIConversationMessages::newInstance();
        return $parentConversation;
    }

    /**
     * The parent as the realtime tick reads it: its stored row (the tree's root id keys the signal document) with no
     * loaded message set — the tick then reads the newest stored message id, the notification row just committed. The
     * bare parent of the row writers would publish no message id (its set is empty); a parent that cannot be read
     * falls back to its id alone.
     */
    protected function buildParentConversationForRealtimeSignal(int $parentConversationId): AIConversation
    {
        try {
            $storedParent = $this->conversationsService()->find($parentConversationId, false);
            if ($storedParent instanceof AIConversation) {
                return $storedParent;
            }
        } catch (Throwable) {
            // the id alone still ticks the right document for a root parent
        }
        $signalParent = AIConversation::newInstance();
        $signalParent->id = $parentConversationId;
        return $signalParent;
    }

    /** The sub-agent records of the loaded `delegate` rows, in conversation order (legacy rows read as legacy records). */
    protected function buildRecordsOfLoadedDelegateCallRows(AIConversationMessages $loadedMessages): DelegationSubAgentRecords
    {
        $loadedRecords = new DelegationSubAgentRecords();
        foreach ($loadedMessages->buildToolCallHistory()->getDelegateCallRows() as $delegateCallRow) {
            $loadedDelegationResults = isset($delegateCallRow->delegationResults) ? $delegateCallRow->delegationResults : new DelegationResults();
            foreach ($loadedDelegationResults->buildSubAgentRecords($delegateCallRow)->getElements() as $loadedRecord) {
                $loadedRecords->add($loadedRecord);
            }
        }
        return $loadedRecords;
    }

    /** The messages service — resolved inline (DI rule); a test double replaces it. */
    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /** The conversations service — resolved inline (DI rule). */
    protected function conversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        return $aIConversationsService;
    }

    /** The run claims (run state, live claim) — resolved inline (DI rule); a test double replaces it. */
    protected function runClaimsService(): AIConversationRunClaimsService
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService;
    }

    /** The batches service (deadline rows) — resolved inline (DI rule). */
    protected function delegationBatchesService(): AIConversationDelegationBatchesService
    {
        /** @var AIConversationDelegationBatchesService $aIConversationDelegationBatchesService */
        $aIConversationDelegationBatchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);
        return $aIConversationDelegationBatchesService;
    }

    /** The realtime change-signal service — resolved inline (DI rule). */
    protected function realtimeSignalService(): AIConversationRealtimeSignalService
    {
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        return $aIConversationRealtimeSignalService;
    }
}
