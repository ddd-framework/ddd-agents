<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationRequestFromToolResult;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationChildProfile;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequests;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAwaitedRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaToolCallAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateToolArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\MessageSubAgentArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\MessageSubAgentTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\WaitForSubAgentsTool;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentRepeatedToolCallCheck;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRefusal;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Exceptions\AIModelCallFailedException;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationTransactionsService;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Delegation SPAWN: ONE sub-agent per `delegate` row — the model's own call
 * ({@see spawnSubAgentOfModelCall()}, with the model-only guards), the synthetic rows the platform writes for the
 * sub-agents a tool result asks for ({@see spawnSubAgentsRequestedByToolResult()}, awaited conversations included) and
 * for its own background work ({@see spawnSubAgentsOfPlatformRequests()}); each spawn writes the run's deadline row, the
 * seeded child, the record and the purpose's run record, then admits the child against the tree's capacity (async) or
 * runs it inline (sync). Also the admission of QUEUED children into freed slots, `message_sub_agent` and
 * the continuation runs. An inline (sync) child runs through the loop ({@see AgentChildTurnRunnerInterface}); rows are written
 * under the run the loop passes in ({@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::persistMessageUnderRun()}).
 * What happens when a child finishes is {@see AIConversationDelegationResultsService}'s.
 */
class AIConversationDelegationSpawnService extends Service
{
    use AITraceLogTrait;

    /** Records the delegation batch as part of the purpose's run, through the purpose's run hook ({@see AIConversationPurpose::recordDelegationBatchOfRun()}). */
    protected function recordPurposeRunBatchMembership(AIConversation $conversation, ?AIConversationDelegationBatch $delegationBatch): void
    {
        if ($delegationBatch === null) {
            return;
        }
        $conversation->findPurpose()?->recordDelegationBatchOfRun($conversation, $delegationBatch);
    }

    /**
     * The sub-agents a COMPLETED tool's result asks for (the requesting tools): ONE synthetic
     * `delegate` row per task item ({@see AgentDelegationRequestFromToolResult}), contiguous after the producing call,
     * each with its own record (`requestedBy: TOOL_RESULT`, the producing call as requester, the item's label, the
     * request's next step and flag), its own deadline row and its own child — then one more row per awaited conversation
     * that can still be joined (async only). A child conversation's tool result asks for nothing (one level). A
     * request that notifies without resuming is platform work: dropped on the sync path, as before. Returns whether the
     * turn ends here to wait for the run ends that resume it (async: at least one such sub-agent started) — the loop then
     * releases and every run end's notification resumes the parent; sync: the children ran inline, their notifications
     * follow the step.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     * @throws MessageIndexLockUnavailableException
     */
    public function spawnSubAgentsRequestedByToolResult(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $parentConversation,
        ToolCall $completedToolCall,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $asyncDelegations
    ): bool {
        $delegationRequest = AgentDelegationRequestFromToolResult::fromStructuredContent($completedToolCall->getDecodedResult()->structuredContent, (int)($parentConversation->id ?? 0));
        if (!$delegationRequest->declaresTasks || $parentConversation->isDelegation()) {
            return false; // one level of delegation — a child's tool result never starts grandchildren
        }
        $runsAsync = $asyncDelegations && isset($parentConversation->id);
        if ($delegationRequest->notifiesParentWithoutResuming && !$runsAsync) {
            return false; // platform work never runs inline on the sync path (it would block the very turn it serves)
        }
        $parksForTheRunEnds = false;
        $startedCount = 0;
        /** @var AIConversationMessage[] $rowsWaitingForAdmission */
        $rowsWaitingForAdmission = [];
        foreach ($delegationRequest->buildSubAgentRequests($completedToolCall)->getElements() as $agentSubAgentRequest) {
            // async: the children are queued, not admitted — a fast child must not end before the request's later rows exist
            $rowsWaitingForAdmission[] = $this->spawnSubAgentOnPlatformDelegateRow($agentRun, $agentChildTurnRunner, $parentConversation, $agentSubAgentRequest, $transientMessageIds, $modelNameOverride, $consoleStream, $runsAsync, !$runsAsync);
            $startedCount++;
            $parksForTheRunEnds = $parksForTheRunEnds || ($runsAsync && !$agentSubAgentRequest->notifiesParentWithoutResuming);
        }
        if ($runsAsync && $startedCount > 0 && $delegationRequest->awaitedConversationIds !== []) {
            // a purposed run of another conversation the parent waits for: a sub-agent of the same request
            $joinableAwaitedConversationIds = $this->filterJoinableAwaitedConversationIds($parentConversation, $delegationRequest->awaitedConversationIds);
            foreach ($delegationRequest->buildAwaitedRunRequests($completedToolCall, $joinableAwaitedConversationIds)->getElements() as $awaitedRunRequest) {
                $this->spawnAwaitedRunOnPlatformDelegateRow($agentRun, $parentConversation, $awaitedRunRequest, $transientMessageIds, $consoleStream);
            }
        }
        if ($runsAsync) {
            $this->admitAndAnswerPlatformDelegateRows($agentRun, $parentConversation, $rowsWaitingForAdmission, $consoleStream);
        }
        $this->aiTrace('turn.subAgents.requestedByToolResult', [
            'conversationId' => $parentConversation->id ?? null,
            'sourceTool' => $completedToolCall->name ?? null,
            'subAgentCount' => $startedCount,
            'async' => $runsAsync,
            'parks' => $parksForTheRunEnds,
        ]);
        return $parksForTheRunEnds;
    }

    /**
     * The platform's own work as sub-agents: the background operations a tool result
     * decided and the purpose step's warm-ups — ONE synthetic `delegate` row per request, each run notifying the parent
     * without resuming it (the request carries the flag; its deadline row carries the BACKGROUND condition so no reader of
     * "the parent waits for its children" counts it). Async only — the requesters gate it. Returns the rows, in request
     * order, so the loop can wait (bounded) for a run the purpose's next step needs.
     *
     * @return AIConversationMessage[] the synthetic `delegate` rows
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     * @throws MessageIndexLockUnavailableException
     */
    public function spawnSubAgentsOfPlatformRequests(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $parentConversation,
        AgentSubAgentRequests $agentSubAgentRequests,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream
    ): array {
        $delegateCallRows = [];
        if (!isset($parentConversation->id) || $parentConversation->isDelegation()) {
            return $delegateCallRows;
        }
        foreach ($agentSubAgentRequests->getElements() as $agentSubAgentRequest) {
            $delegateCallRows[] = $this->spawnSubAgentOnPlatformDelegateRow($agentRun, $agentChildTurnRunner, $parentConversation, $agentSubAgentRequest, $transientMessageIds, null, $consoleStream, true, false);
        }
        $this->admitAndAnswerPlatformDelegateRows($agentRun, $parentConversation, $delegateCallRows, $consoleStream);
        if ($delegateCallRows !== []) {
            $this->aiTrace('turn.subAgents.platformWork', ['conversationId' => $parentConversation->id, 'subAgentCount' => count($delegateCallRows)]);
        }
        return $delegateCallRows;
    }

    /**
     * ONE requested sub-agent on its own synthetic `delegate` row: the row appended to the parent (the
     * existing `call_fanout_` id family, so the recorded-run id families stay stable), the call in the model's shape, the
     * next sub-agent number, the spawn ({@see spawnSubAgent()} — deadline row, child, record, inline run or the child
     * queued), the fixed answer as the call's result and the call COMPLETED, persisted. With `$admitsAtOnce` false (async)
     * the child waits QUEUED and the row is answered after the request's every row exists
     * ({@see admitAndAnswerPlatformDelegateRows()}). Returns the row.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     * @throws MessageIndexLockUnavailableException
     */
    protected function spawnSubAgentOnPlatformDelegateRow(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $parentConversation,
        AgentSubAgentRequest $agentSubAgentRequest,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $async,
        bool $admitsAtOnce = true
    ): AIConversationMessage {
        $delegateCallMessage = $this->appendPlatformDelegateRow($parentConversation, $agentSubAgentRequest, $transientMessageIds);
        $subAgentNumber = $this->messagesService()->findNextSubAgentNumber($parentConversation);
        if (!$admitsAtOnce) {
            // the row is persisted with a valid answer before the admission: a process that stops in between leaves
            // no call without a result on the wire
            $delegateCallMessage->toolCall->result = DelegationSubAgentRecord::queued($subAgentNumber, $agentSubAgentRequest, 0, new DateTime())->buildAnswerTextBeforeAdmission();
        }
        $spawnAnswer = $this->spawnSubAgent(
            $agentRun,
            $agentChildTurnRunner,
            $parentConversation,
            $agentSubAgentRequest,
            $subAgentNumber,
            $delegateCallMessage,
            $transientMessageIds,
            $modelNameOverride,
            $consoleStream,
            $async,
            $admitsAtOnce
        );
        if ($admitsAtOnce) {
            $this->answerPlatformDelegateRow($agentRun, $delegateCallMessage, $spawnAnswer, $consoleStream);
        }
        return $delegateCallMessage;
    }

    /**
     * The turn end of a turn the platform ends to wait for the run ends of the sub-agents a tool asked for:
     * ONE COMPLETED `wait_for_sub_agents` row written by the platform — the defined text-less turn end
     * ({@see AIConversationMessage::isTurnEndWithoutTextCall()}), on the wire as what happened: the turn waits for the
     * named sub-agents. The notifications that arrive after it open their own turn ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationTurnMessages::isOpenedBySubAgentNotificationsOnly()}),
     * which offers `wait_for_sub_agents`, the short wait and the notification turn's stall rules; the owed-work walks read
     * the row as answered, so the lost-resume pass never re-fires the parked turn. Names the unfinished sub-agents whose
     * end resumes the parent.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function appendPlatformWaitForSubAgentsRow(AgentRun $agentRun, AIConversation $parentConversation, AgentTransientMessageIds $transientMessageIds, ?AgentConsoleStream $consoleStream): void
    {
        $resumingUnfinishedRecords = new DelegationSubAgentRecords();
        $storedRecords = isset($parentConversation->id) ? $this->messagesService()->findSubAgentRecordsOfConversation($parentConversation) : new DelegationSubAgentRecords();
        foreach ($storedRecords->getUnfinished()->getElements() as $unfinishedRecord) {
            if (!$unfinishedRecord->notifiesParentWithoutResuming) {
                $resumingUnfinishedRecords->add($unfinishedRecord);
            }
        }
        $waitCallMessage = $this->messagesService()->appendNewMessage(
            $parentConversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_TOOL_CALL,
            '',
            authorType: AIConversationMessage::AUTHOR_ASSISTANT
        );
        $waitToolCall = new ToolCall();
        $waitToolCall->toolCallId = 'call_wait_' . RecordedRunClockAndIdSources::mintHexToken(8);
        $waitToolCall->name = WaitForSubAgentsTool::NAME;
        $waitToolCall->arguments = [];
        $waitToolCall->stampEffect(MCPToolEffectCatalog::getEffectForToolName(WaitForSubAgentsTool::NAME));
        $waitToolCall->result = WaitForSubAgentsTool::buildWaitingText($resumingUnfinishedRecords);
        $waitToolCall->markCompleted();
        $waitCallMessage->toolCall = $waitToolCall;
        $this->messagesService()->persistMessageUnderRun($agentRun, $waitCallMessage, $consoleStream);
        $this->aiTrace('turn.subAgents.platformWaitRow', ['conversationId' => $parentConversation->id ?? null, 'waitingFor' => $resumingUnfinishedRecords->count()]);
    }

    /**
     * The admission of a request's queued children once its every row exists: the tree's queue is
     * admitted against its capacity, then each row gets its fixed answer — started when its child was admitted, queued
     * behind the cap otherwise — and is persisted COMPLETED. A row without a record (a refusal) keeps its answer.
     *
     * @param AIConversationMessage[] $delegateCallRows
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    protected function admitAndAnswerPlatformDelegateRows(AgentRun $agentRun, AIConversation $parentConversation, array $delegateCallRows, ?AgentConsoleStream $consoleStream): void
    {
        if ($delegateCallRows === []) {
            return;
        }
        $admittedChildIds = $this->promoteQueuedDelegations($parentConversation->getTreeRootConversationId());
        foreach ($delegateCallRows as $delegateCallRow) {
            $delegationSubAgentRecord = isset($delegateCallRow->delegationResults) && !$delegateCallRow->delegationResults->isLegacyMap()
                ? $delegateCallRow->delegationResults->subAgent
                : null;
            if (!$delegationSubAgentRecord instanceof DelegationSubAgentRecord || !isset($delegateCallRow->toolCall)) {
                continue;
            }
            $isAdmitted = in_array((int)$delegationSubAgentRecord->childConversationId, $admittedChildIds, true);
            $this->answerPlatformDelegateRow(
                $agentRun,
                $delegateCallRow,
                $delegationSubAgentRecord->buildSpawnAnswerText($isAdmitted ? null : AIConversationRunClaimsService::DELEGATION_CONCURRENCY_CAP),
                $consoleStream
            );
        }
    }

    /**
     * An awaited conversation as a sub-agent of the request: its synthetic `delegate` row, a deadline
     * row, the record (run 1) and the guarded stamp of the three anchor columns onto the conversation — only while it
     * has not ended and belongs to no other parent. Its end then goes through the adoption at the end of its turn
     * ({@see AIConversationDelegationResultsService::adoptDelegationAnchorJoinedMidTurn()}) and the run-end path like any
     * child's. A conversation that ended between the tool's look and the stamp ends the run at once (COMPLETED: it had
     * already ended before it could be awaited); a conversation that already belongs to another request, and a stamp
     * that throws, end it FAILED with the reason — the parent's turn goes on either way, and the parent is told.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     * @throws Throwable
     */
    protected function spawnAwaitedRunOnPlatformDelegateRow(
        AgentRun $agentRun,
        AIConversation $parentConversation,
        AgentSubAgentRequest $awaitedRunRequest,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream
    ): void {
        $awaitedRunTask = $awaitedRunRequest->task;
        if (!$awaitedRunTask instanceof AgentDelegationAwaitedRunTask) {
            return;
        }
        $delegateCallMessage = $this->appendPlatformDelegateRow($parentConversation, $awaitedRunRequest, $transientMessageIds);
        $this->messagesService()->persistMessageUnderRun($agentRun, $delegateCallMessage, $consoleStream);
        $subAgentRunDeadline = $this->delegationBatchesService()->createSubAgentRunDeadline($parentConversation, $delegateCallMessage, self::SUB_AGENT_RUN_TIMEOUT_SECONDS);
        $now = new DateTime();
        $delegationSubAgentRecord = DelegationSubAgentRecord::queued($this->messagesService()->findNextSubAgentNumber($parentConversation), $awaitedRunRequest, $awaitedRunTask->awaitedConversationId, $now);
        $this->attachSubAgentRecord($parentConversation, $delegateCallMessage, $delegationSubAgentRecord);
        $stampError = null;
        try {
            $joined = $this->runClaimsService()->stampAwaitedConversationAsSubAgentChild(
                $awaitedRunTask->awaitedConversationId,
                (int)$delegateCallMessage->id,
                (int)$subAgentRunDeadline->id,
                $parentConversation->getTreeRootConversationId()
            );
        } catch (Throwable $stampThrowable) {
            // fail-soft per awaited conversation: the parent's turn goes on, the run ends FAILED with the reason
            $joined = false;
            $stampError = $stampThrowable->getMessage();
        }
        if ($joined) {
            $this->messagesService()->writeSubAgentRunStarted((int)$delegateCallMessage->id, 1, $now);
            $spawnAnswer = $delegationSubAgentRecord->buildSpawnAnswerText(null);
        } elseif ($stampError === null && !$this->hasAwaitedConversationEnded($awaitedRunTask->awaitedConversationId)) {
            // refused while it runs: it already belongs to another request (or it is this tree's root)
            $this->endAwaitedRunThatCouldNotBeJoined($delegationSubAgentRecord, (int)$delegateCallMessage->id, (int)$subAgentRunDeadline->id, $awaitedRunTask, 'it already belongs to another request');
            $spawnAnswer = 'Sub-agent ' . $delegationSubAgentRecord->buildName() . ' could not wait for conversation #'
                . $awaitedRunTask->awaitedConversationId . ': it already belongs to another request. It runs on without this request.';
        } elseif ($stampError === null) {
            $this->endAwaitedRunThatEndedBeforeItWasJoined($delegationSubAgentRecord, (int)$delegateCallMessage->id, (int)$subAgentRunDeadline->id, $awaitedRunTask);
            $spawnAnswer = 'Sub-agent ' . $delegationSubAgentRecord->buildName() . ': conversation #'
                . $awaitedRunTask->awaitedConversationId . ' had already ended before it could be awaited.';
        } else {
            $this->endAwaitedRunThatCouldNotBeJoined($delegationSubAgentRecord, (int)$delegateCallMessage->id, (int)$subAgentRunDeadline->id, $awaitedRunTask, $stampError);
            $spawnAnswer = 'Sub-agent ' . $delegationSubAgentRecord->buildName() . ' could not wait for conversation #'
                . $awaitedRunTask->awaitedConversationId . ' (the platform could not join it). It runs on without this request.';
        }
        $this->aiTrace('turn.subAgent.awaitedRun', [
            'parentConversationId' => $parentConversation->id ?? null,
            'awaitedConversationId' => $awaitedRunTask->awaitedConversationId,
            'subAgentNumber' => $delegationSubAgentRecord->number,
            'joined' => $joined,
        ]);
        $this->answerPlatformDelegateRow($agentRun, $delegateCallMessage, $spawnAnswer, $consoleStream);
    }

    /** Whether the awaited conversation's run state is terminal (one scalar read); an unreadable row counts as ended. */
    protected function hasAwaitedConversationEnded(int $awaitedConversationId): bool
    {
        try {
            $awaitedRunState = $this->runClaimsService()->findRunStateById($awaitedConversationId);
        } catch (Throwable) {
            return true;
        }
        return $awaitedRunState === null || AIConversationRunState::of($awaitedRunState)->isTerminal();
    }

    /**
     * An awaited conversation that had already ended when the platform stamped it: its run ends COMPLETED at once, saying
     * so (its own result is in that conversation, or in the record that conversation wrote), its deadline row
     * ends, and the parent is told by the one notification.
     *
     * @throws Throwable
     */
    protected function endAwaitedRunThatEndedBeforeItWasJoined(
        DelegationSubAgentRecord $delegationSubAgentRecord,
        int $delegateCallMessageId,
        int $deadlineId,
        AgentDelegationAwaitedRunTask $awaitedRunTask
    ): void {
        $this->endAwaitedRunAtOnce($delegationSubAgentRecord, $delegateCallMessageId, $deadlineId, DelegationSubAgentRun::ended(
            $delegationSubAgentRecord->currentRunNumber,
            DelegationSubAgentRun::STATUS_COMPLETED,
            new DateTime(),
            'Conversation #' . $awaitedRunTask->awaitedConversationId . ' had already ended before it could be awaited; its result '
                . 'is in that conversation (an observation: the chronicle entry it wrote).',
            null,
            null,
            null
        ));
    }

    /**
     * An awaited conversation the platform could not stamp (it belongs to another request, or a database error): its run
     * ends FAILED with the reason, its deadline row ends, and the parent is told — the conversation runs on without this
     * request.
     *
     * @throws Throwable
     */
    protected function endAwaitedRunThatCouldNotBeJoined(
        DelegationSubAgentRecord $delegationSubAgentRecord,
        int $delegateCallMessageId,
        int $deadlineId,
        AgentDelegationAwaitedRunTask $awaitedRunTask,
        string $refusalReason
    ): void {
        $this->endAwaitedRunAtOnce($delegationSubAgentRecord, $delegateCallMessageId, $deadlineId, DelegationSubAgentRun::ended(
            $delegationSubAgentRecord->currentRunNumber,
            DelegationSubAgentRun::STATUS_FAILED,
            new DateTime(),
            null,
            DelegationSubAgentRun::FAILURE_REASON_CHILD_FAILED,
            'conversation #' . $awaitedRunTask->awaitedConversationId . ' could not be joined: ' . $refusalReason,
            null
        ));
    }

    /**
     * The run of an awaited conversation ends at its spawn: the run end written, the deadline row ended, the notification.
     *
     * @throws Throwable
     */
    protected function endAwaitedRunAtOnce(DelegationSubAgentRecord $delegationSubAgentRecord, int $delegateCallMessageId, int $deadlineId, DelegationSubAgentRun $endedRun): void
    {
        $runEndWritten = $this->messagesService()->writeSubAgentRunEnded($delegateCallMessageId, $endedRun);
        $this->delegationBatchesService()->markSubAgentRunDeadlineEnded($deadlineId, $endedRun->finishedAt ?? new DateTime());
        if ($runEndWritten) {
            $this->delegationNotificationsService()->notifyParentOfSubAgentRunEnd($delegateCallMessageId, $delegationSubAgentRecord->currentRunNumber);
        }
    }

    /** A synthetic `delegate` row appended to the parent for one requested sub-agent — PENDING, not yet persisted. */
    protected function appendPlatformDelegateRow(AIConversation $parentConversation, AgentSubAgentRequest $agentSubAgentRequest, AgentTransientMessageIds $transientMessageIds): AIConversationMessage
    {
        $delegateCallMessage = $this->messagesService()->appendNewMessage(
            $parentConversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_TOOL_CALL,
            '',
            authorType: AIConversationMessage::AUTHOR_ASSISTANT
        );
        $delegateCallMessage->toolCall = ToolCall::buildPlatformDelegateCall(
            'call_fanout_' . RecordedRunClockAndIdSources::mintHexToken(8),
            $agentSubAgentRequest->buildDelegateCallArguments()
        );
        return $delegateCallMessage;
    }

    /**
     * The synthetic row's fixed answer and final status: COMPLETED — the platform's request was started (or its
     * run ended at once and the notification says so); persisted (the W-guard keeps the record off the re-persist).
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    protected function answerPlatformDelegateRow(AgentRun $agentRun, AIConversationMessage $delegateCallMessage, string $spawnAnswer, ?AgentConsoleStream $consoleStream): void
    {
        $delegateCallMessage->toolCall->result = $spawnAnswer;
        $delegateCallMessage->toolCall->markCompleted();
        $this->messagesService()->persistMessageUnderRun($agentRun, $delegateCallMessage, $consoleStream);
    }

    /** The run-end notifier — resolved inline (DI rule); a test double replaces it. */
    protected function delegationNotificationsService(): AIConversationDelegationNotificationsService
    {
        /** @var AIConversationDelegationNotificationsService $aIConversationDelegationNotificationsService */
        $aIConversationDelegationNotificationsService = DDDService::instance()->getService(AIConversationDelegationNotificationsService::class);
        return $aIConversationDelegationNotificationsService;
    }

    /**
     * The model's `delegate` call: ONE call starts ONE sub-agent. The call's arguments are read
     * as one task ({@see DelegateToolArguments}); the model-only guards refuse with a NOT_RUN record and the call FAILED —
     * no task shape or both, an agent task without `skill_names` under a skill rail, a tool run of a tool the model is not
     * offered, a tool run that repeats a completed write or a read of this turn, and (sync) a call after a sub-agent of
     * the same step failed. A sub-agent conversation's own call gets the one-level text and no record. Otherwise
     * {@see spawnSubAgent()}. Returns the call's answer, fixed now; the call's status is final now — COMPLETED
     * when the sub-agent started or was queued, FAILED when refused.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     * @throws MessageIndexLockUnavailableException
     */
    public function spawnSubAgentOfModelCall(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $parentConversation,
        ToolCall $delegateToolCall,
        AIConversationMessage $delegateCallMessage,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $async
    ): string {
        if ($parentConversation->isDelegation()) {
            // One level of delegation: a sub-agent creates no sub-agents of its own — no record, the one-level text
            $delegateToolCall->markFailed();
            return self::ONE_LEVEL_REFUSAL;
        }
        $delegateToolArguments = DelegateToolArguments::fromToolCallArguments($delegateToolCall->arguments);
        $agentSubAgentRequest = AgentSubAgentRequest::forModelCall($delegateToolArguments);
        $subAgentNumber = $this->messagesService()->findNextSubAgentNumber($parentConversation);
        $now = new DateTime();
        if ($agentSubAgentRequest === null) {
            return $this->refuseSubAgent($parentConversation, $delegateToolCall, $delegateCallMessage, DelegationSubAgentRecord::notRunWithoutTask($subAgentNumber, $delegateToolArguments, $now));
        }
        $delegationSubAgentRefusal = $this->findRefusalOfModelRequestedTask($agentRun, $parentConversation, $agentSubAgentRequest, $delegateToolCall, $async);
        if ($delegationSubAgentRefusal !== null) {
            return $this->refuseSubAgent(
                $parentConversation,
                $delegateToolCall,
                $delegateCallMessage,
                DelegationSubAgentRecord::notRun($subAgentNumber, $agentSubAgentRequest, $delegationSubAgentRefusal->failureReason, $delegationSubAgentRefusal->corrective, $now)
            );
        }
        $spawnAnswer = $this->spawnSubAgent($agentRun, $agentChildTurnRunner, $parentConversation, $agentSubAgentRequest, $subAgentNumber, $delegateCallMessage, $transientMessageIds, $modelNameOverride, $consoleStream, $async);
        $delegateToolCall->markCompleted();
        return $spawnAnswer;
    }

    /** The text a sub-agent conversation's own `delegate` call gets (one level of delegation). */
    public const string ONE_LEVEL_REFUSAL = 'delegate REJECTED — this conversation is itself a delegated sub-agent and cannot delegate further. '
        . 'Do the work here with your loaded tools; report what is out of your reach in your final answer.';

    /**
     * ONE sub-agent: the deadline row of its first run (async, persisted parent: 300 s, a tool run its
     * ceiling + 60 s), the child seeded through the shared builder with the deadline row as its `delegationBatchId`, the
     * purpose's run hooks, the record (run 1 QUEUED) written onto the `delegate` row — the only time the parent writes
     * the column — then async the child is queued and admitted against the tree's capacity, sync it runs inline and its
     * run ends at once: the child COMPLETED / FAILED (token-less) and the run's end written in the same step
     * (a throwing child ends FAILED with `budgetReached` / `providerError` / `childFailed` and the exception text). The
     * notification of a sync run end is appended after the step ({@see AIConversationDelegationNotificationsService::appendNotificationsOfRunsEndedInThisStep()}).
     * Returns the call's fixed answer. With `$admitsAtOnce` false the async child waits QUEUED for the caller's admission
     * (a request of several sub-agents admits once its every row exists).
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     * @throws MessageIndexLockUnavailableException
     */
    public function spawnSubAgent(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $parentConversation,
        AgentSubAgentRequest $agentSubAgentRequest,
        int $subAgentNumber,
        AIConversationMessage $delegateCallMessage,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $async,
        bool $admitsAtOnce = true
    ): string {
        $parentIsPersisted = isset($parentConversation->id);
        $runsAsync = $async && $parentIsPersisted;
        if (!isset($delegateCallMessage->id)) {
            $this->messagesService()->persistMessageUnderRun($agentRun, $delegateCallMessage, $consoleStream);
            if (!isset($delegateCallMessage->id)) {
                // a transient conversation: the shared tree counter gives the child a non-null, tree-unique anchor
                $delegateCallMessage->id = $transientMessageIds->allocateId();
            }
        }
        $task = $agentSubAgentRequest->task;
        $subAgentRunDeadline = $runsAsync
            ? $this->delegationBatchesService()->createSubAgentRunDeadline(
                $parentConversation,
                $delegateCallMessage,
                $task instanceof AgentDelegationToolRunTask ? AIConversationDelegationToolRunService::TOOL_RUN_TIMEOUT_SECONDS + 60 : self::SUB_AGENT_RUN_TIMEOUT_SECONDS,
                $agentSubAgentRequest->notifiesParentWithoutResuming
            )
            : null;
        $childConversation = $this->delegationChildSeedService()->createAndSeedDelegationChild(
            $agentRun,
            $parentConversation,
            $delegateCallMessage,
            $this->buildChildProfile($parentConversation, $agentSubAgentRequest),
            $subAgentRunDeadline,
            $transientMessageIds,
            isset($parentConversation->contextRetentionSettings) ? ($parentConversation->contextRetentionSettings->loadToolsOnSkillLoad ?? true) : true,
            $parentConversation->buildOwnerTurns()->countOwnerTurns(),
            $consoleStream
        );
        // the parent's purpose links the child (and the run's deadline row) into its run record (a step of a long
        // run that hands its parts to sub-agents)
        $parentConversation->findPurpose()?->recordDelegationChildOfRun($parentConversation, $childConversation, $task->buildChildSeedText());
        $this->recordPurposeRunBatchMembership($parentConversation, $subAgentRunDeadline);

        $delegationSubAgentRecord = DelegationSubAgentRecord::queued($subAgentNumber, $agentSubAgentRequest, 0, new DateTime());
        $delegationSubAgentRecord->childConversationId = isset($childConversation->id) ? (int)$childConversation->id : null;
        $this->attachSubAgentRecord($parentConversation, $delegateCallMessage, $delegationSubAgentRecord);
        $this->aiTrace('turn.subAgent.spawned', [
            'parentConversationId' => $parentConversation->id ?? null,
            'childId' => $childConversation->id ?? null,
            'subAgentNumber' => $subAgentNumber,
            'async' => $runsAsync,
            'toolRun' => $task instanceof AgentDelegationToolRunTask,
        ]);
        if (!$runsAsync) {
            $this->runSubAgentInline($agentRun, $agentChildTurnRunner, $childConversation, $delegateCallMessage, $transientMessageIds, $modelNameOverride, $consoleStream);
            return $delegateCallMessage->delegationResults->subAgent->buildInlineRunAnswerText();
        }
        $admittedChildIds = [];
        if (isset($childConversation->id) && $this->runClaimsService()->transitionRunState($childConversation, AIConversationRunState::IDLE, AIConversationRunState::QUEUED) && $admitsAtOnce) {
            $admittedChildIds = $this->promoteQueuedDelegations($parentConversation->getTreeRootConversationId());
        }
        $isAdmitted = in_array((int)($childConversation->id ?? 0), $admittedChildIds, true);
        return $delegationSubAgentRecord->buildSpawnAnswerText($isAdmitted ? null : AIConversationRunClaimsService::DELEGATION_CONCURRENCY_CAP);
    }

    /** The deadline of an agent sub-agent's run. */
    public const int SUB_AGENT_RUN_TIMEOUT_SECONDS = 300;

    /**
     * The sync run of a sub-agent: its child loop (an agent task) or its one tool call (a
     * tool run) runs in this process; then, in the same step, the child ends COMPLETED / FAILED without a token and
     * the run's end is written — on the row (a persisted parent) and on the in-memory record. A child that throws ends
     * FAILED with the closed reason of its exception; the claim-lost and index-lock exits are rethrown.
     *
     * @throws AgentRunClaimLostException
     * @throws MessageIndexLockUnavailableException
     */
    protected function runSubAgentInline(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $childConversation,
        AIConversationMessage $delegateCallMessage,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream
    ): void {
        $delegationSubAgentRecord = $delegateCallMessage->delegationResults->subAgent;
        $runNumber = $delegationSubAgentRecord->currentRunNumber;
        $runStartedAt = new DateTime();
        $currentRun = $delegationSubAgentRecord->getCurrentRun();
        if ($currentRun !== null) {
            $currentRun->status = DelegationSubAgentRun::STATUS_RUNNING;
            $currentRun->startedAt = $runStartedAt;
        }
        $rowIsPersisted = isset($childConversation->id);
        if ($rowIsPersisted) {
            $this->messagesService()->writeSubAgentRunStarted((int)$delegateCallMessage->id, $runNumber, $runStartedAt);
        }
        $terminalRunState = AIConversationRunState::COMPLETED;
        $failureReason = null;
        $failureDetail = null;
        try {
            if ($childConversation->isToolRunDelegationChild()) {
                $terminalRunState = $this->delegationToolRunService()->runToolRunChildTurn(AgentRun::forInlineChild($agentRun), $childConversation);
            } else {
                // the child's own model: a requesting tool may have pinned one; otherwise it inherited the parent's
                $agentChildTurnRunner->runInlineChildTurn($childConversation, $transientMessageIds, $childConversation->aiModelNameOverride ?? $modelNameOverride, $consoleStream, $agentRun);
            }
        } catch (AgentRunClaimLostException|MessageIndexLockUnavailableException $loopExit) {
            throw $loopExit;
        } catch (AiBudgetExceededException $budgetExceeded) {
            [$terminalRunState, $failureReason, $failureDetail] = [AIConversationRunState::FAILED, DelegationSubAgentRun::FAILURE_REASON_BUDGET_REACHED, $budgetExceeded->getMessage()];
        } catch (AIModelCallFailedException $modelCallFailed) {
            [$terminalRunState, $failureReason, $failureDetail] = [AIConversationRunState::FAILED, DelegationSubAgentRun::FAILURE_REASON_PROVIDER_ERROR, $modelCallFailed->getMessage()];
        } catch (Throwable $childError) {
            [$terminalRunState, $failureReason, $failureDetail] = [AIConversationRunState::FAILED, DelegationSubAgentRun::FAILURE_REASON_CHILD_FAILED, $childError->getMessage()];
        }
        if ($terminalRunState === AIConversationRunState::FAILED && $failureReason === null) {
            $failureReason = DelegationSubAgentRun::FAILURE_REASON_CHILD_FAILED;
        }
        // The child ends now — token-less (a sync child is never claimed), from the IDLE it was created in
        if (!$rowIsPersisted || !$this->runClaimsService()->transitionRunState($childConversation, AIConversationRunState::IDLE, $terminalRunState)) {
            $childConversation->runState = $terminalRunState;
        }
        $resultSlice = $childConversation->buildDelegationResultSlice();
        $finishedAt = new DateTime();
        $verdict = $resultSlice->getVerdictUnlessBlank();
        $endedStatus = $terminalRunState === AIConversationRunState::COMPLETED ? DelegationSubAgentRun::STATUS_COMPLETED : DelegationSubAgentRun::STATUS_FAILED;
        // a continuation's child carries the earlier runs' work too: the run records only its own spend, from the stored
        // rows when the child is stored (a compacted child's loaded window misses the earlier runs)
        $childTotalCostInUsd = $rowIsPersisted ? $this->messagesService()->findTotalCostInUsdOfConversation($childConversation) : (float)$resultSlice->costInUSD;
        $runCostInUsd = $delegationSubAgentRecord->buildCostOfCurrentRun($childTotalCostInUsd);
        if ($rowIsPersisted) {
            $this->messagesService()->writeSubAgentRunEnded(
                (int)$delegateCallMessage->id,
                DelegationSubAgentRun::ended($runNumber, $endedStatus, $finishedAt, $verdict, $failureReason, $failureDetail, $runCostInUsd)
            );
        }
        $delegateCallMessage->delegationResults->subAgent = $delegationSubAgentRecord->withRunEnded($endedStatus, $verdict, $failureReason, $failureDetail, $runCostInUsd, $finishedAt);
        $this->aiTrace('turn.subAgent.inlineRunEnded', [
            'childId' => $childConversation->id ?? null,
            'subAgentNumber' => $delegationSubAgentRecord->number,
            'status' => $endedStatus,
            'failureReason' => $failureReason,
        ]);
    }

    /**
     * Why the model's task is refused, or null (the model-only guards): an agent task without
     * `skill_names` under a skill rail; a tool run of a tool the model is not offered, or one repeating a completed
     * write or a read of this turn; on the sync path a call after a sub-agent of the same step failed.
     *
     * @param AgentRun $agentRun
     * @param AIConversation $parentConversation
     * @param AgentSubAgentRequest $agentSubAgentRequest
     * @param ToolCall $delegateToolCall
     * @param bool $async
     * @return DelegationSubAgentRefusal|null
     */
    protected function findRefusalOfModelRequestedTask(
        AgentRun $agentRun,
        AIConversation $parentConversation,
        AgentSubAgentRequest $agentSubAgentRequest,
        ToolCall $delegateToolCall,
        bool $async
    ): ?DelegationSubAgentRefusal {
        $task = $agentSubAgentRequest->task;
        if ($task instanceof AgentDelegationAgentTask && !$task->hasSkillNames() && $this->skillRailPurposeOf($parentConversation) !== null) {
            $offerableSkillNames = $parentConversation->findVisionCapableSkillNames(
                $parentConversation->findPermittedSkillNames($this->skillRailPurposeOf($parentConversation)?->getLoadableSkillNames() ?? [])
            );
            return DelegationSubAgentRefusal::forFailureReasonAndCorrective(
                DelegationSubAgentRun::FAILURE_REASON_REFUSED,
                'delegate REJECTED — the task names NO skill_names. A sub-agent starts with NONE of your loaded '
                    . 'skills: without skill_names it has no work tools and can only fail. Either pass the skills this task '
                    . 'needs in skill_names (pick from: ' . implode(', ', $offerableSkillNames) . '), or do the work here '
                    . 'yourself: load_skills the act skill and call its tools inline. Nothing was started.'
            );
        }
        if ($task instanceof AgentDelegationToolRunTask) {
            if (!$parentConversation->buildOfferedTools(true, null, $agentRun)->offersToolForASubAgentToolRun($task->toolName)) {
                return DelegationSubAgentRefusal::forFailureReasonAndCorrective(
                    DelegationSubAgentRun::FAILURE_REASON_REFUSED,
                    'delegate REJECTED — `' . $task->toolName . '` is not a tool offered to you here. A tool run '
                        . 'calls a tool of a skill you have loaded (load_skills first). Nothing was started.'
                );
            }
            $agentRepeatedToolCallCheck = AgentRepeatedToolCallCheck::forToolCall($task->buildRepeatCheckToolCall(), $parentConversation);
            $identicalWriteIndex = $agentRepeatedToolCallCheck->findEarlierIdenticalWriteIndex();
            if ($identicalWriteIndex !== null) {
                return DelegationSubAgentRefusal::forFailureReasonAndCorrective(
                    DelegationSubAgentRun::FAILURE_REASON_REFUSED,
                    'NOT started: you already ran `' . $task->toolName . '` with exactly these arguments (message '
                        . $identicalWriteIndex . ') — repeating a write with identical arguments does no new work.'
                );
            }
            if ($agentRepeatedToolCallCheck->findReusablePriorRead() !== null) {
                return DelegationSubAgentRefusal::forFailureReasonAndCorrective(
                    DelegationSubAgentRun::FAILURE_REASON_REFUSED,
                    'NOT started: `' . $task->toolName . '` already ran with exactly these arguments in this turn '
                        . '— its result is above; use it.'
                );
            }
        }
        if (!$async || !isset($parentConversation->id)) {
            $failedSubAgentOfThisStep = $this->findFailedSubAgentOfStep($parentConversation, $delegateToolCall);
            if ($failedSubAgentOfThisStep !== null) {
                return DelegationSubAgentRefusal::forFailureReasonAndCorrective(
                    DelegationSubAgentRun::FAILURE_REASON_EARLIER_SUB_AGENT_FAILED,
                    'Not started: sub-agent ' . $failedSubAgentOfThisStep->buildName() . ' of this step failed before it '
                        . '(' . ($failedSubAgentOfThisStep->getCurrentRun()?->buildFailureText() ?? 'failed') . '). Nothing was started.'
                );
            }
        }
        return null;
    }

    /**
     * The sub-agent of an EARLIER `delegate` call of the same model step (the calls the model emitted together) whose
     * inline run FAILED; null when none failed. Pure over the parent's loaded rows.
     */
    protected function findFailedSubAgentOfStep(AIConversation $parentConversation, ToolCall $delegateToolCall): ?DelegationSubAgentRecord
    {
        $emittedBatchId = $delegateToolCall->emittedBatchId ?? null;
        if ($emittedBatchId === null || !isset($parentConversation->messages)) {
            return null;
        }
        foreach ($parentConversation->messages->buildToolCallHistory()->getDelegateCallRows() as $delegateCallRow) {
            $rowToolCall = isset($delegateCallRow->toolCall) ? $delegateCallRow->toolCall : null;
            if ($rowToolCall === null || $rowToolCall === $delegateToolCall || ($rowToolCall->emittedBatchId ?? null) !== $emittedBatchId) {
                continue;
            }
            $rowRecord = isset($delegateCallRow->delegationResults) && !$delegateCallRow->delegationResults->isLegacyMap()
                ? $delegateCallRow->delegationResults->subAgent
                : null;
            if ($rowRecord?->getCurrentRun()?->status === DelegationSubAgentRun::STATUS_FAILED) {
                return $rowRecord;
            }
        }
        return null;
    }

    /**
     * A refused sub-agent (never run → NOT_RUN): its NOT_RUN record on the row, the call FAILED, the corrective
     * as the call's answer — no child, no deadline row.
     */
    protected function refuseSubAgent(AIConversation $parentConversation, ToolCall $delegateToolCall, AIConversationMessage $delegateCallMessage, DelegationSubAgentRecord $notRunRecord): string
    {
        $this->attachSubAgentRecord($parentConversation, $delegateCallMessage, $notRunRecord);
        $delegateToolCall->markFailed();
        $this->aiTrace('turn.subAgent.refused', [
            'parentConversationId' => $parentConversation->id ?? null,
            'subAgentNumber' => $notRunRecord->number,
            'failureReason' => $notRunRecord->getCurrentRun()?->failureReason,
        ]);
        return $notRunRecord->buildSpawnAnswerText(null);
    }

    /**
     * The record onto the `delegate` row: in memory (the W-guard keeps every later persist of the parent's copy off the
     * column) and, for a persisted parent, with the spawn's one write ({@see AIConversationMessagesService::writeSubAgentRecordOfSpawn()})
     * inside the claim-fenced write of this process's run claim: a worker that lost the parent's
     * claim writes no record. A claimless drive (CLI, eval, sync admin) holds no token and skips only the claim check.
     *
     * @throws \DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException the parent's run claim was lost — nothing is written
     */
    protected function attachSubAgentRecord(AIConversation $parentConversation, AIConversationMessage $delegateCallMessage, DelegationSubAgentRecord $delegationSubAgentRecord): void
    {
        $delegationResults = new DelegationResults();
        $delegationResults->subAgent = $delegationSubAgentRecord;
        $delegateCallMessage->delegationResults = $delegationResults;
        if (isset($parentConversation->id) && isset($delegateCallMessage->id)) {
            $writeScope = $this->messagesService()->openClaimFencedWrite(
                $parentConversation,
                $this->runClaimsService()->getRunTokenClaimedInThisProcess($parentConversation)
            );
            try {
                $this->messagesService()->writeSubAgentRecordOfSpawn((int)$delegateCallMessage->id, $delegationSubAgentRecord);
            } catch (\Throwable $writeError) {
                $this->messagesService()->rollBackClaimFencedWrite($writeScope, $writeError);
            }
            $this->messagesService()->commitClaimFencedWrite($writeScope);
        }
    }

    /**
     * How the child of one sub-agent is created and seeded: an agent task seeds its text and skills with the requester's
     * step budget and model; a tool run seeds today's tool-run seed under the tool-run purpose, its skill = the one the
     * requester named, else the first skill the parent's entitlement permits that provides the tool.
     */
    protected function buildChildProfile(AIConversation $parentConversation, AgentSubAgentRequest $agentSubAgentRequest): AgentDelegationChildProfile
    {
        $task = $agentSubAgentRequest->task;
        if ($task instanceof AgentDelegationToolRunTask) {
            $seedSkillNames = $task->getRequestedSeedSkillNames();
            if ($seedSkillNames === []) {
                $seedSkillNames = array_slice($parentConversation->findPermittedSkillNames(AISkill::getSkillNamesProvidingToolName($task->toolName)), 0, 1);
            }
            return AgentDelegationChildProfile::forBackgroundOperation($task->buildChildSeedText(), $seedSkillNames, (string)$task->getChildPurposeName());
        }
        return AgentDelegationChildProfile::forDelegateTask(
            $task->buildChildSeedText(),
            $task->getRequestedSeedSkillNames(),
            $agentSubAgentRequest->childMaxStepsPerTurn,
            $agentSubAgentRequest->childModelName
        );
    }

    protected function delegationToolRunService(): AIConversationDelegationToolRunService
    {
        /** @var AIConversationDelegationToolRunService $aIConversationDelegationToolRunService */
        $aIConversationDelegationToolRunService = DDDService::instance()->getService(AIConversationDelegationToolRunService::class);
        return $aIConversationDelegationToolRunService;
    }

    /** The run claims (run-state transitions of the children) — resolved inline (DI rule); a test double replaces it. */
    protected function runClaimsService(): AIConversationRunClaimsService
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService;
    }

    /**
     * Only conversations that can still be joined are COUNTED into a batch: one that ended between the tool's look and
     * this moment (a fast observation) would otherwise be expected but never settle — the batch then waited for the
     * reaper's timeout. Gone or terminal → dropped here, before the batch is sized.
     *
     * @param int[] $awaitedConversationIds
     * @return int[]
     */
    protected function filterJoinableAwaitedConversationIds(AIConversation $conversation, array $awaitedConversationIds): array
    {
        $joinable = [];
        foreach ($awaitedConversationIds as $awaitedConversationId) {
            try {
                // one scalar read of the run state — a raw read, no rights, no hydration
                /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
                $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
                $awaitedRunState = $aIConversationRunClaimsService->findRunStateById((int)$awaitedConversationId);
                if ($awaitedRunState !== null && !AIConversationRunState::of($awaitedRunState)->isTerminal()) {
                    $joinable[] = $awaitedConversationId;
                    continue;
                }
            } catch (Throwable) {
                // unreadable → not joinable
            }
            $this->aiTrace('turn.fanout.awaited.skipped', ['conversationId' => $conversation->id ?? null, 'awaitedConversationId' => $awaitedConversationId]);
        }
        return $joinable;
    }

    /**
     * The conversation's purpose IF it declares a skill rail (`allowedLoadSkillNames`), else null — the guard
     * scope of the skill-less-delegation rejection and its corrective's skill list.
     */
    protected function skillRailPurposeOf(AIConversation $conversation): ?AIConversationPurpose
    {
        $railPurpose = $conversation->findPurpose();
        return ($railPurpose instanceof AIConversationPurpose && $railPurpose->allowedLoadSkillNames !== [])
            ? $railPurpose
            : null;
    }

    /**
     * The delegation capacity of a tree: admit QUEUED children into the free slots
     * ({@see AIConversationRunClaimsService::admitQueuedDelegationChildren()} — the one admission path, under the tree's lock,
     * the admission stamp as the reservation) and dispatch each admitted child's resume. Called on a fresh spawn, after
     * a sibling settled (a slot freed) and after a continuation re-queued a finished child. Returns the admitted ids.
     *
     * @return int[]
     */
    public function promoteQueuedDelegations(int $rootConversationId): array
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        $admittedChildIds = $aIConversationRunClaimsService->admitQueuedDelegationChildren($rootConversationId);
        foreach ($admittedChildIds as $admittedChildId) {
            // The delegate call is addressed by the child's column, one scalar read; the
            // compare-and-set never re-opens a call that already resolved (a late promotion racing the settle).
            $delegateMessageId = $aIConversationRunClaimsService->findDelegatedFromMessageIdById($admittedChildId);
            if ($delegateMessageId !== null) {
                // the admitted sub-agent's current run moves QUEUED → RUNNING on its record; a legacy row
                // (no record) moves its call PENDING → RUNNING instead (the legacy adapter)
                $this->writeSubAgentRunStartedOrLegacyCallRunning($delegateMessageId);
            }
            (new AIConversationResumeMessage($admittedChildId))->dispatch();
        }
        return $admittedChildIds;
    }

    /**
     * The admission's write on the admitted child's `delegate` row: the sub-agent record's current run starts
     * (guarded on QUEUED); a row stored before sub-agent records existed gets the legacy call flip PENDING → RUNNING.
     */
    protected function writeSubAgentRunStartedOrLegacyCallRunning(int $delegateMessageId): void
    {
        $storedDelegateCallRow = $this->messagesService()->findStoredDelegateCallRow($delegateMessageId);
        if ($storedDelegateCallRow !== null && isset($storedDelegateCallRow->delegationResults) && !$storedDelegateCallRow->delegationResults->isLegacyMap()) {
            $this->messagesService()->writeSubAgentRunStarted($delegateMessageId, $storedDelegateCallRow->delegationResults->subAgent->currentRunNumber, new DateTime());
            return;
        }
        $this->messagesService()->markDelegateCallRunningIfPending($delegateMessageId);
    }

    /**
     * The model's `message_sub_agent` call, its arguments already checked by the dispatch: the sub-agent
     * by its number on the parent's `delegate` rows; refused for an unknown number, a legacy record, a sub-agent that never
     * started and a tool run ({@see DelegationSubAgentRecord::findRefusalOfMessage()}). Otherwise the message is written
     * into the child FIRST — an `ORCHESTRATOR_MESSAGE` signal row — and then the record decides under its row lock (the
     * lock every end of the run takes): a run that has not ended reads the row at its next step (its end is fenced on
     * rows newer than its last look, so it yields and continues); a run that has ended continues with the row as a new
     * run ({@see openContinuationRunOfRecord()}). So a run never ends past an unread message and two messages never open
     * two continuations of one run. The sync path (no worker, a claimless drive) runs the continuation inline in this
     * step like a spawn; its notification follows the step.
     *
     * @throws AgentRunClaimLostException
     * @throws MessageIndexLockUnavailableException
     * @throws Throwable
     */
    public function messageSubAgent(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $parentConversation,
        MessageSubAgentArguments $messageSubAgentArguments,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $async
    ): AgentMetaToolCallAnswer {
        $subAgentNumber = (int)$messageSubAgentArguments->subAgentNumber;
        $runsAsync = $async && isset($parentConversation->id);
        $delegateCallRow = $runsAsync
            ? $this->findStoredDelegateCallRowOfSubAgentNumber($parentConversation, $subAgentNumber)
            : $this->findLoadedDelegateCallRowOfSubAgentNumber($parentConversation, $subAgentNumber);
        $delegationSubAgentRecord = $delegateCallRow !== null && isset($delegateCallRow->delegationResults) && !$delegateCallRow->delegationResults->isLegacyMap()
            ? $delegateCallRow->delegationResults->subAgent
            : null;
        if (!$delegationSubAgentRecord instanceof DelegationSubAgentRecord) {
            return MessageSubAgentTool::answerUnknownSubAgent($subAgentNumber);
        }
        $refusalText = $delegationSubAgentRecord->findRefusalOfMessage();
        if ($refusalText !== null) {
            return MessageSubAgentTool::answerRefusedByRecord($refusalText);
        }
        if ($delegationSubAgentRecord->childConversationId === null || !isset($parentConversation->id) || !isset($delegateCallRow->id)) {
            return MessageSubAgentTool::answerUnreachable($subAgentNumber);
        }
        $this->aiTrace('turn.subAgent.messaged', [
            'parentConversationId' => $parentConversation->id,
            'subAgentNumber' => $subAgentNumber,
            'childId' => $delegationSubAgentRecord->childConversationId,
            'async' => $runsAsync,
        ]);
        if (!$runsAsync && !$delegationSubAgentRecord->hasUnfinishedRun()) {
            return $this->continueSubAgentInline(
                $agentRun,
                $agentChildTurnRunner,
                $parentConversation,
                $delegateCallRow,
                $delegationSubAgentRecord,
                $messageSubAgentArguments->message,
                $transientMessageIds,
                $modelNameOverride,
                $consoleStream
            );
        }
        $childConversation = $this->buildSubAgentChildForRowWrites($delegationSubAgentRecord, (int)$delegateCallRow->id, (int)$parentConversation->id);
        $this->messagesService()->appendOrchestratorMessageToSubAgent((int)$childConversation->id, $messageSubAgentArguments->message);
        return $this->deliverOrContinueAfterOrchestratorMessage($childConversation, (int)$delegateCallRow->id, $subAgentNumber, $messageSubAgentArguments->message);
    }

    /**
     * After the orchestrator's message row was written into the child: the record read under its row lock decides. An
     * unfinished run reads the row (a child that holds no live claim and waits IDLE is resumed); an ended run continues
     * with it as run k+1 (a new deadline row, the child re-armed and admitted against the tree's capacity). A
     * continuation another message opened first means the row reaches that run.
     *
     * @throws Throwable
     */
    protected function deliverOrContinueAfterOrchestratorMessage(AIConversation $childConversation, int $delegateCallMessageId, int $subAgentNumber, string $orchestratorMessageText): AgentMetaToolCallAnswer
    {
        $lockedRecord = $this->findSubAgentRecordUnderRowLock($delegateCallMessageId);
        if ($lockedRecord !== null && $lockedRecord->hasUnfinishedRun()) {
            $this->resumeSubAgentChildWaitingWithoutClaim($childConversation);
            return MessageSubAgentTool::answerDelivered($lockedRecord);
        }
        $continuedRecord = $this->openContinuationRunOfRecord($childConversation, $delegateCallMessageId, $orchestratorMessageText, DelegationSubAgentRun::CONTINUED_BY_PARENT, true);
        if ($continuedRecord !== null) {
            $admittedChildIds = $this->promoteQueuedDelegations($childConversation->getTreeRootConversationId());
            return MessageSubAgentTool::answerContinued($subAgentNumber, $continuedRecord->currentRunNumber, in_array((int)$childConversation->id, $admittedChildIds, true));
        }
        // another message or an operator opened the next run first: this row is read by that run
        $storedRecord = $this->messagesService()->findStoredDelegateCallRow($delegateCallMessageId)?->delegationResults?->subAgent;
        if ($storedRecord instanceof DelegationSubAgentRecord && $storedRecord->hasUnfinishedRun()) {
            return MessageSubAgentTool::answerDelivered($storedRecord);
        }
        return MessageSubAgentTool::answerKeptForTheNextRun($subAgentNumber);
    }

    /**
     * The continuation of a finished sub-agent from an operator or the owner (admin Continue / Edit, the
     * async CLI, a customer drive of the child): the caller wrote its opening message into the child (the owner-role row
     * of the drive); here the record opens run k+1 with `$continuedBy` and the message, a deadline row for it, and the
     * child is re-armed and admitted against the tree's capacity — never past the cap. A child whose run
     * has not ended is not re-armed: the message reaches the running child like any inbound row. A child of a row stored
     * before sub-agent records existed (no record) is re-armed the legacy way. Returns whether the child was re-armed. A root never is.
     *
     * @throws Throwable
     */
    public function openSubAgentContinuationRun(AIConversation $childConversation, string $openingMessage, string $continuedBy): bool
    {
        if (!isset($childConversation->id) || !$childConversation->isDelegation()) {
            return false;
        }
        $delegateCallMessageId = (int)$childConversation->delegatedFromMessageId;
        $storedDelegateCallRow = $this->messagesService()->findStoredDelegateCallRow($delegateCallMessageId);
        if ($storedDelegateCallRow === null || !isset($storedDelegateCallRow->delegationResults) || $storedDelegateCallRow->delegationResults->isLegacyMap()) {
            // the legacy adapter: no record to open a run on
            if (!$this->runClaimsService()->rearmTerminalDelegationChild($childConversation)) {
                return false;
            }
            $this->promoteQueuedDelegations($childConversation->getTreeRootConversationId());
            return true;
        }
        $continuedRecord = $this->openContinuationRunOfRecord($childConversation, $delegateCallMessageId, $openingMessage, $continuedBy, true);
        if ($continuedRecord === null) {
            return false;
        }
        $this->aiTrace('turn.subAgent.continued', [
            'childId' => $childConversation->id,
            'subAgentNumber' => $continuedRecord->number,
            'runNumber' => $continuedRecord->currentRunNumber,
            'continuedBy' => $continuedBy,
        ]);
        $this->promoteQueuedDelegations($childConversation->getTreeRootConversationId());
        return true;
    }

    /**
     * Run k+1 of the sub-agent on `$delegateCallMessageId` — ONE transaction (READ COMMITTED): the
     * `delegate` row locked first (the lock order of the run end and the timeout), the current run checked to have ended
     * with a recorded end, the new run's deadline row (`$withDeadline`: the async run; the sync path has none), the child
     * re-armed QUEUED with its `delegationBatchId` on the new deadline row (guarded on a terminal child), the record's
     * compare-and-set onto run k+1. Any step that finds nothing to do rolls everything back. Returns the record with the
     * new run, or null when no run was opened.
     *
     * @throws Throwable
     */
    protected function openContinuationRunOfRecord(
        AIConversation $childConversation,
        int $delegateCallMessageId,
        string $openingMessage,
        string $continuedBy,
        bool $withDeadline
    ): ?DelegationSubAgentRecord {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $this->transactionsService()->beginReadCommittedTransactionOrNestInOpenTransaction($connection);
        try {
            $lockedDelegateCallRow = $this->messagesService()->lockStoredDelegateCallRow($delegateCallMessageId);
            $lockedRecord = $lockedDelegateCallRow !== null && isset($lockedDelegateCallRow->delegationResults) && !$lockedDelegateCallRow->delegationResults->isLegacyMap()
                ? $lockedDelegateCallRow->delegationResults->subAgent
                : null;
            if (!$lockedRecord instanceof DelegationSubAgentRecord || $lockedRecord->getCurrentRun()?->isRecordedRunEnd() !== true) {
                $connection->rollBack();
                return null;
            }
            $continuationRun = DelegationSubAgentRun::continuation($lockedRecord->currentRunNumber + 1, $continuedBy, $openingMessage, new DateTime());
            $continuationRunDeadline = null;
            if ($withDeadline) {
                $parentConversation = AIConversation::newInstance();
                $parentConversation->id = (int)$lockedDelegateCallRow->aiConversationId;
                $delegateCallMessage = AIConversationMessage::newInstance();
                $delegateCallMessage->id = $delegateCallMessageId;
                $continuationRunDeadline = $this->delegationBatchesService()->createSubAgentRunDeadline(
                    $parentConversation,
                    $delegateCallMessage,
                    $lockedRecord->isToolRun() ? AIConversationDelegationToolRunService::TOOL_RUN_TIMEOUT_SECONDS + 60 : self::SUB_AGENT_RUN_TIMEOUT_SECONDS,
                    $lockedRecord->notifiesParentWithoutResuming
                );
            }
            if (!$this->runClaimsService()->rearmTerminalDelegationChild($childConversation, $continuationRunDeadline)
                || !$this->messagesService()->openSubAgentContinuationRunRecord($delegateCallMessageId, $continuationRun)
            ) {
                $connection->rollBack();
                return null;
            }
            $connection->commit();
            return $lockedRecord->withContinuationRun($continuationRun);
        } catch (Throwable $continuationError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            } else {
                $connection->close();
            }
            throw $continuationError;
        }
    }

    /**
     * The sync continuation (a continued run): run k+1 opened without a deadline row, THEN the
     * orchestrator's message into the child (a run that could not be opened writes no message, so the
     * "send again" answer never leads to a second copy), the child moved from its re-armed QUEUED to IDLE and run inline
     * through the loop in this step; the run's end is written on the row and on the parent's loaded copy, whose notification the step's end
     * appends ({@see AIConversationDelegationNotificationsService::appendNotificationsOfRunsEndedInThisStep()}).
     *
     * @throws AgentRunClaimLostException
     * @throws MessageIndexLockUnavailableException
     * @throws Throwable
     */
    protected function continueSubAgentInline(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $parentConversation,
        AIConversationMessage $loadedDelegateCallRow,
        DelegationSubAgentRecord $delegationSubAgentRecord,
        string $orchestratorMessageText,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream
    ): AgentMetaToolCallAnswer {
        $subAgentNumber = (int)$delegationSubAgentRecord->number;
        $childForRowWrites = $this->buildSubAgentChildForRowWrites($delegationSubAgentRecord, (int)$loadedDelegateCallRow->id, (int)$parentConversation->id);
        $continuedRecord = $this->openContinuationRunOfRecord($childForRowWrites, (int)$loadedDelegateCallRow->id, $orchestratorMessageText, DelegationSubAgentRun::CONTINUED_BY_PARENT, false);
        if ($continuedRecord === null) {
            return MessageSubAgentTool::answerNotTakenUp($subAgentNumber);
        }
        $this->messagesService()->appendOrchestratorMessageToSubAgent((int)$delegationSubAgentRecord->childConversationId, $orchestratorMessageText);
        // loaded after the message row: the child's loop reads it as the opening of run k+1
        $childConversation = $this->findSubAgentChildForInlineRun((int)$delegationSubAgentRecord->childConversationId);
        if ($childConversation === null) {
            return MessageSubAgentTool::answerUnreachable($subAgentNumber);
        }
        if (!$this->runClaimsService()->transitionRunState($childConversation, AIConversationRunState::QUEUED, AIConversationRunState::IDLE)) {
            $childConversation->runState = AIConversationRunState::IDLE;
        }
        $loadedDelegateCallRow->delegationResults->subAgent = $continuedRecord;
        $this->runSubAgentInline($agentRun, $agentChildTurnRunner, $childConversation, $loadedDelegateCallRow, $transientMessageIds, $modelNameOverride, $consoleStream);
        // the inline loop holds no run claim, so no hand-over marked the message read: it was in the loaded tail of the run
        // that just ended — read; never left waiting (a later run's end would be fenced on it)
        $this->messagesService()->findAndMarkReadSignalsWaitingForRunningTurn($childConversation);
        return MessageSubAgentTool::answerContinuedInline($loadedDelegateCallRow->delegationResults->subAgent);
    }

    /**
     * The sub-agent record of `$delegateCallMessageId` read under the row's lock and released at once: the moment that
     * orders this read against the run end and the timeout (both lock the row first). Null for a gone or legacy row.
     *
     * @throws Throwable
     */
    protected function findSubAgentRecordUnderRowLock(int $delegateCallMessageId): ?DelegationSubAgentRecord
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $this->transactionsService()->beginReadCommittedTransactionOrNestInOpenTransaction($connection);
        try {
            $lockedDelegateCallRow = $this->messagesService()->lockStoredDelegateCallRow($delegateCallMessageId);
            $connection->commit();
        } catch (Throwable $lockError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            } else {
                $connection->close();
            }
            throw $lockError;
        }
        return $lockedDelegateCallRow !== null && isset($lockedDelegateCallRow->delegationResults) && !$lockedDelegateCallRow->delegationResults->isLegacyMap()
            ? $lockedDelegateCallRow->delegationResults->subAgent
            : null;
    }

    /**
     * A child the message reached that waits IDLE without a live claim (admitted and not yet claimed, or released between
     * two of its turns) gets one resume; a running child takes the row at its next model call, a QUEUED one when it is
     * admitted. A second resume of an admitted child is a no-op at its claim.
     */
    protected function resumeSubAgentChildWaitingWithoutClaim(AIConversation $childConversation): void
    {
        $runClaimsService = $this->runClaimsService();
        if ($runClaimsService->findRunStateById((int)$childConversation->id) !== AIConversationRunState::IDLE
            || $runClaimsService->hasLiveRunClaim($childConversation)
        ) {
            return;
        }
        (new AIConversationResumeMessage((int)$childConversation->id))->dispatch();
    }

    /** The `delegate` row of the parent that carries sub-agent `$subAgentNumber`, as stored now; null when none does. */
    protected function findStoredDelegateCallRowOfSubAgentNumber(AIConversation $parentConversation, int $subAgentNumber): ?AIConversationMessage
    {
        $delegateCallMessageId = $this->messagesService()->findDelegateCallMessageIdOfSubAgentNumber($parentConversation, $subAgentNumber);
        return $delegateCallMessageId === null ? null : $this->messagesService()->findStoredDelegateCallRow($delegateCallMessageId);
    }

    /** The parent's loaded `delegate` row that carries sub-agent `$subAgentNumber` (the sync path's own copy); null when none does. */
    protected function findLoadedDelegateCallRowOfSubAgentNumber(AIConversation $parentConversation, int $subAgentNumber): ?AIConversationMessage
    {
        if (!isset($parentConversation->messages)) {
            return null;
        }
        foreach ($parentConversation->messages->buildToolCallHistory()->getDelegateCallRows() as $delegateCallRow) {
            $loadedRecord = isset($delegateCallRow->delegationResults) && !$delegateCallRow->delegationResults->isLegacyMap()
                ? $delegateCallRow->delegationResults->subAgent
                : null;
            if ($loadedRecord?->number === $subAgentNumber) {
                return $delegateCallRow;
            }
        }
        return null;
    }

    /**
     * A bare child for the row writes of a message or a continuation: its id, the `delegate` row it was spawned by and
     * its tree's root (the parent — one level). No message window is loaded.
     */
    protected function buildSubAgentChildForRowWrites(DelegationSubAgentRecord $delegationSubAgentRecord, int $delegateCallMessageId, int $parentConversationId): AIConversation
    {
        $childConversation = AIConversation::newInstance();
        $childConversation->id = (int)$delegationSubAgentRecord->childConversationId;
        $childConversation->delegatedFromMessageId = $delegateCallMessageId;
        $childConversation->rootConversationId = $parentConversationId;
        return $childConversation;
    }

    /**
     * The child of a sub-agent loaded as the loop runs it inline (the worker's load of a claimed turn): the stored row,
     * its space (a child at account level has none), its compaction cover and the message tail past the cover. Null
     * when the row is gone.
     */
    protected function findSubAgentChildForInlineRun(int $childConversationId): ?AIConversation
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $childConversation = $aIConversationsService->find($childConversationId, false);
        if (!$childConversation instanceof AIConversation) {
            return null;
        }
        if (isset($childConversation->agentSpaceId)) {
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            $agentSpacesService->attachAgentSpaceRelations($childConversation, false);
        }
        $childConversation->compactionNodes = $childConversation->findCompactionCoverNodes() ?? AIConversationCompactionNodes::newInstance();
        $childConversation->messages = $this->messagesService()->findContextTailMessages($childConversation);
        return $childConversation;
    }

    protected function delegationBatchesService(): AIConversationDelegationBatchesService
    {
        /** @var AIConversationDelegationBatchesService $aIConversationDelegationBatchesService */
        $aIConversationDelegationBatchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);
        return $aIConversationDelegationBatchesService;
    }

    protected function delegationChildSeedService(): AIConversationDelegationChildSeedService
    {
        /** @var AIConversationDelegationChildSeedService $aIConversationDelegationChildSeedService */
        $aIConversationDelegationChildSeedService = DDDService::instance()->getService(AIConversationDelegationChildSeedService::class);
        return $aIConversationDelegationChildSeedService;
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
}
