<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallFollowUpsOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequests;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallDecodedResult;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The follow-up work a COMPLETED tool call declares in its result: the three loop
 * conventions read the result's `structuredContent` (decoded once, by the ToolCall itself) — `turnMinimumSteps` raises
 * this turn's step budget, `backgroundOperations[]` starts platform sub-agents whose ends do not resume the parent,
 * `delegationTasks[]` starts sub-agents — one synthetic `delegate` row each. The TOOL decides, the loop
 * holds zero per-tool knowledge. The loop calls this
 * after the post-write re-sweep and the plan note of the call; it also starts the purpose's warm-ups after a step
 * through {@see startBackgroundSubAgents()}.
 */
class AgentCompletedToolCallFollowUpsService extends Service
{
    /**
     * Run the follow-up work `$toolCall` (COMPLETED) declared in its result, in this order: the turn's step floor, the
     * platform's background sub-agents, the sub-agents the result requests. Returns true when the async turn ends after
     * this step to wait for the run ends that resume it (each one's notification resumes the parent); sync, the
     * children ran inline and their notifications follow the step.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     * @throws MessageIndexLockUnavailableException
     */
    public function runFollowUpsOfCompletedToolCall(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $conversation,
        ToolCall $toolCall,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $asyncDelegations
    ): bool {
        $toolCallDecodedResult = $toolCall->getDecodedResult();
        $this->recordTurnMinimumStepsFromToolResult($conversation, $toolCallDecodedResult);
        $this->startBackgroundSubAgents($agentRun, $agentChildTurnRunner, $conversation, $this->purposeBackgroundWorkService()->buildSubAgentRequestsForCompletedToolCall(
            $conversation,
            $toolCall,
            $asyncDelegations
        ), $transientMessageIds, $consoleStream);
        return $this->delegationSpawnService()->spawnSubAgentsRequestedByToolResult(
            $agentRun,
            $agentChildTurnRunner,
            $conversation,
            $toolCall,
            $transientMessageIds,
            $modelNameOverride,
            $consoleStream,
            $asyncDelegations
        );
    }

    /**
     * TOOL-DECLARED TURN STEP FLOOR — the third loop-control convention next to `backgroundOperations` and
     * `delegationTasks`: a COMPLETED tool whose result's `structuredContent` carries a positive integer
     * `turnMinimumSteps` declares how many steps its flow needs at least in THIS turn (a tool that starts a flow of
     * several steps: plan, one step per part, build, check, report). The loop records the largest floor declared
     * this turn on the working state, stamped with the turn, and persists that slice so a chunked worker turn sees it
     * after its re-read; {@see \DDD\Domain\AI\Entities\Conversations\Agent\AIConversationTurnBudget::$totalMaxSteps}
     * re-derives the budget at every step boundary, so the very turn that declared the floor grows. The TOOL decides,
     * the loop holds zero per-tool knowledge.
     */
    public function recordTurnMinimumStepsFromToolResult(AIConversation $conversation, ToolCallDecodedResult $toolCallDecodedResult): void
    {
        $declaredTurnMinimumSteps = $toolCallDecodedResult->structuredContent['turnMinimumSteps'] ?? null;
        if (!is_int($declaredTurnMinimumSteps) || $declaredTurnMinimumSteps <= 0) {
            return;
        }
        $currentTurnInitiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex() ?? -1;
        $agentWorkingState = $conversation->getAgentWorkingState() ?? new AgentWorkingState();
        $floorAlreadyDeclaredThisTurn = $agentWorkingState->turnMinimumStepsTurnInitiatorMessageIndex === $currentTurnInitiatorMessageIndex
            ? ($agentWorkingState->turnMinimumSteps ?? 0)
            : 0;
        $agentWorkingState->turnMinimumSteps = max($floorAlreadyDeclaredThisTurn, $declaredTurnMinimumSteps);
        $agentWorkingState->turnMinimumStepsTurnInitiatorMessageIndex = $currentTurnInitiatorMessageIndex;
        $conversation->agentWorkingState = $agentWorkingState;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationsService->persistAgentWorkingStateSliceEvenWithoutAWorkingState($conversation, 'turnMinimumSteps', 'turnMinimumStepsTurnInitiatorMessageIndex');
    }

    /**
     * Spawn and start the platform's background work the purpose's background-work service returned as requests:
     * ONE synthetic `delegate` row per request, each run notifying the parent without resuming
     * it; a purpose-declared grace is recorded for the wait before the next model call. Children over the concurrency
     * cap are queued and promoted as siblings finish, like model-spawned sub-agents.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     * @throws MessageIndexLockUnavailableException
     */
    public function startBackgroundSubAgents(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $conversation,
        AgentSubAgentRequests $agentSubAgentRequests,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream
    ): void {
        if ($agentSubAgentRequests->count() === 0) {
            return;
        }
        $delegateCallRows = $this->delegationSpawnService()->spawnSubAgentsOfPlatformRequests(
            $agentRun,
            $agentChildTurnRunner,
            $conversation,
            $agentSubAgentRequests,
            $transientMessageIds,
            $consoleStream
        );
        foreach (array_values($agentSubAgentRequests->getElements()) as $requestPosition => $agentSubAgentRequest) {
            $delegateCallRow = $delegateCallRows[$requestPosition] ?? null;
            if ($delegateCallRow instanceof AIConversationMessage) {
                $this->purposeBackgroundWorkService()->recordSubAgentRunAwaitedBeforeNextStep($agentRun, $conversation, $delegateCallRow, $agentSubAgentRequest->awaitGraceSeconds);
            }
        }
    }

    /** The delegation spawn — resolved inline (DI rule). */
    protected function delegationSpawnService(): AIConversationDelegationSpawnService
    {
        /** @var AIConversationDelegationSpawnService $aIConversationDelegationSpawnService */
        $aIConversationDelegationSpawnService = DDDService::instance()->getService(AIConversationDelegationSpawnService::class);
        return $aIConversationDelegationSpawnService;
    }

    /** The purposed conversation's background work — resolved inline (DI rule). */
    protected function purposeBackgroundWorkService(): AIConversationPurposeBackgroundWorkService
    {
        /** @var AIConversationPurposeBackgroundWorkService $aIConversationPurposeBackgroundWorkService */
        $aIConversationPurposeBackgroundWorkService = DDDService::instance()->getService(AIConversationPurposeBackgroundWorkService::class);
        return $aIConversationPurposeBackgroundWorkService;
    }

    /**
     * The work after one dispatched tool call of a step (moved from the agent loop): the
     * task-plan column persist of a plan-tool call at loop level with its snapshot on the causing row, the
     * text-less turn end (`wait_for_sub_agents`), the owner report send at loop level, and
     * after a completed work call the post-write re-sweep, the variant re-resolution, the plan note and the follow-up work
     * the call's result declares. A claim-lost throw propagates to the loop's single claim-lost exit.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function runAfterStepWorkOfToolCall(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $conversation,
        ToolCall $toolCall,
        AIConversationMessage $callMessage,
        int $toolEmittedRowCountBeforeDispatch,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal,
        bool $asyncDelegations
    ): AgentToolCallFollowUpsOutcome {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        /** @var AIConversationTaskPlanRecordService $aIConversationTaskPlanRecordService */
        $aIConversationTaskPlanRecordService = DDDService::instance()->getService(AIConversationTaskPlanRecordService::class);
        $agentToolCallFollowUpsOutcome = new AgentToolCallFollowUpsOutcome();
        $afterStepRequest = AgentMetaTools::findAfterStepRequest($toolCall);
        if ($afterStepRequest?->persistsTaskPlan()) {
            // every plan-tool call, not only COMPLETED ones ({@see UpdateTaskPlanTool::afterStep()})
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $currentTaskPlan = $conversation->getTaskPlan();
            $aIConversationsService->persistTaskPlanSlice(
                $conversation,
                $currentTaskPlan,
                $agentRun->findRunTokenClaimedInThisProcess($conversation)
            );
            $aIConversationPurposeRunsService->applyPurposeConsequencesAfterPlanPersist($agentRun, $conversation, $currentTaskPlan, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
            // taskPlanSnapshot (traceability): stamp the post-mutation plan tree onto
            // the CAUSING message, in memory, AFTER the successful column write and BEFORE the message's own
            // INSERT — the snapshot rides that single INSERT (a claim-lost throw above means no message and no
            // snapshot; the crash window between the two statements can only leave the column AHEAD, never a
            // snapshot the column doesn't have). Byte-identical repeats are skipped: the plan tools are
            // repeat-guard-exempt, so a tic-looping model must not multiply multi-KB snapshots. COMPLETED
            // calls only — snapshot-stamping every rejection would multiply snapshots on a rejection loop.
            if ($currentTaskPlan !== null && $toolCall->status === AIConversationMessage::STATUS_COMPLETED) {
                if (!$currentTaskPlan->hasSameStoredStateAs($conversation->messages->findNewestTaskPlanSnapshotArray())) {
                    // DEEP copy — the live plan keeps mutating in place on later ticks; the snapshot must freeze
                    // this moment.
                    $callMessage->taskPlanSnapshot = $currentTaskPlan->buildDeepCopy();
                }
            }
        } elseif ($afterStepRequest?->endsTurnWithoutText()) {
            // the turn ends after this step without a text — no re-sweep, no follow-up work
            $agentToolCallFollowUpsOutcome->endsTurnWithoutText = true;
        } elseif ($afterStepRequest?->sendsOwnerReport()) {
            // the SEND runs at LOOP level (claim → visible report message → mail dispatch):
            // never inside dispatchToolCall, whose catch(Throwable) would demote a claim-lost throw to a
            // FAILED tool result while the delivered-claim is already consumed.
            $aIConversationOwnerReportDeliveryService->executeOwnerReportSendAtLoopLevel($agentRun, $conversation, $toolCall, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
        } elseif ($toolCall->status === AIConversationMessage::STATUS_COMPLETED) {
            // The POST-WRITE RE-SWEEP: after a completed WORK tool, hand the
            // still-open machine-checkable plan leaves back to the PURPOSE — the tool may just have created
            // the state a leaf waits for (a write → the verdict of the criterion that reads it), and the system
            // resolving it deterministically beats trusting the model to remember. Cheap by construction:
            // only purposed conversations, only leaves still open + criterion-bearing, entity reads
            // identity-mapped after first load. A sweep resolution counts as this turn's plan progress
            // (both stamps), so the checked stop treats it exactly like an agent tick.
            $taskPlanBeforeReSweep = $conversation->getTaskPlan();
            $openItemKeysBeforeReSweep = $taskPlanBeforeReSweep?->items?->getOpenItemNumberPaths() ?? [];
            $reSweepStampAt = hrtime(true);
            // The sweep is expensive over a long run: it loads the space fresh and reads
            // one facet per open criterion leaf — only a call that can have CHANGED the record earns it: a write
            // by the naming convention, or a read that wrote a widget into the transcript (the row index moved
            // past the call's own row). A plain read, a refused repeat and a gate refusal change nothing.
            if ($toolCall->canHaveChangedTheRecordWhenCompleted($conversation->messages->countToolEmittedRows() > $toolEmittedRowCountBeforeDispatch)) {
                $aIConversationTaskPlanRecordService->reResolveOpenSeededLeavesAfterWork($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal, $toolCall->name ?? null);
            }
            // A completed call that can change a dimension (a write of the record,
            // an owner statement) re-resolves exactly those — the next model call renders the new sections.
            $aIConversationPurposeRunsService->resolveAndPersistVariants($conversation, $toolCall->name ?? null);
            $agentToolCallFollowUpsOutcome->reSweepMs = (int)round((hrtime(true) - $reSweepStampAt) / 1e6);
            $reSweepStampAt = hrtime(true);
            $aIConversationTaskPlanRecordService->closeLeavesProvenByCompletedToolCallAndAppendPlanNote($agentRun, $conversation, $toolCall, $callMessage, $openItemKeysBeforeReSweep, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
            $agentToolCallFollowUpsOutcome->planNoteMs = (int)round((hrtime(true) - $reSweepStampAt) / 1e6);
            // TOOL-DECIDED FOLLOW-UP WORK ({@see AgentCompletedToolCallFollowUpsService}): the step floor, the
            // background children and the awaited delegation children the call's result declares
            if ($this->runFollowUpsOfCompletedToolCall(
                $agentRun,
                $agentChildTurnRunner,
                $conversation,
                $toolCall,
                $transientMessageIds,
                $modelNameOverride,
                $consoleStream,
                $asyncDelegations
            )) {
                $agentToolCallFollowUpsOutcome->awaitingDelegates = true;
            }
        }
        return $agentToolCallFollowUpsOutcome;
    }
}
