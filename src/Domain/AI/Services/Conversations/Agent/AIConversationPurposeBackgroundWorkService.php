<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequests;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Purposes\BackgroundMechanicalOperationPurpose;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * A purposed conversation's BACKGROUND work (layer L3): the requests for the platform's sub-agents a
 * completed tool or the conversation's purpose asks for (one {@see AgentSubAgentRequest} per item, each
 * notifying the parent without resuming it — the loop hands them to the delegation spawn, one synthetic `delegate` row
 * each; this service never creates a child) and the bounded wait for a run the turn needs before its next
 * model call. The children's single model-free turn is delegation work ({@see AIConversationDelegationToolRunService}); a run's end reaches the parent as its one notification. The per-run state (the
 * warm-up memo, the awaited runs) lives on the {@see AgentRun} the loop passes in, per
 * conversation id — an inline child's run is its own, so it never touches its parent's.
 */
class AIConversationPurposeBackgroundWorkService extends Service
{
    use AITraceLogTrait;

    /** @var int How often ONE warm tool may be spawned per conversation and turn across changing cache identities. */
    public const int MAX_BACKGROUND_WARMS_PER_TOOL_PER_TURN = 2;

    /** The poll interval of the bounded wait. */
    protected const int BACKGROUND_AWAIT_POLL_MICROSECONDS = 250_000;

    /**
     * The fire-and-forget platform work a just-completed tool REQUESTED via the generic `backgroundOperations` field of
     * its result (the non-awaited sibling of the `delegationTasks` convention) — one request per item
     * (the one request type of the three requesters); the loop spawns one synthetic `delegate` row each ({@see AIConversationDelegationSpawnService::spawnSubAgentsOfPlatformRequests()}).
     * The model's `delegate` tool untouched, the loop holding ZERO per-tool knowledge: WHICH background work follows a
     * tool is that tool's own decision (e.g. a tool that stores an address requests a
     * crawl of it only when the address GENUINELY changed — so an unchanged
     * re-send never re-crawls). Only on the async (worker) path of a persisted conversation: on the sync/CLI path the
     * resume dispatch runs INLINE (`sync://` transport) and would re-block the very turn this machinery exists to
     * unblock. Empty when nothing is requested or a gate holds. A conversation without a space gets the work its tool
     * asked for like any other: the child inherits what its parent works for — a space, or the account alone.
     */
    public function buildSubAgentRequestsForCompletedToolCall(AIConversation $conversation, ToolCall $completedToolCall, bool $asyncDelegations): AgentSubAgentRequests
    {
        $agentSubAgentRequests = new AgentSubAgentRequests();
        if (!$asyncDelegations || !isset($conversation->id)) {
            return $agentSubAgentRequests;
        }
        if ($conversation->isDelegation()) {
            return $agentSubAgentRequests; // one level of delegation — a child never spawns background children either
        }
        // The GENERIC backgroundOperations result convention (fire-and-forget sibling of the delegationTasks
        // convention): the TOOL decides its own background work and returns it in the result — the loop only
        // validates and spawns. No tool-name branches here (which work follows
        // a tool is that tool's knowledge, not this service's);
        // dedup is per-tool semantics (a tool requests its crawl only on a GENUINE change of the
        // address), so no conversation-wide scan is needed at all.
        $requestedOperations = $completedToolCall->getDecodedResult()->structuredContent['backgroundOperations'] ?? null;
        if (!is_array($requestedOperations) || $requestedOperations === []) {
            return $agentSubAgentRequests;
        }
        /** @var AgentBackgroundOperationsService $agentBackgroundOperationsService */
        $agentBackgroundOperationsService = DDDService::instance()->getService(AgentBackgroundOperationsService::class);
        $knownOperationNames = array_merge(
            $agentBackgroundOperationsService->getSupportedOperationNames(),
            [BackgroundMechanicalOperationPurpose::OPERATION_EXECUTE_TOOL]
        );
        foreach ($requestedOperations as $requestedOperation) {
            $operationName = is_array($requestedOperation) ? trim((string)($requestedOperation['operation'] ?? '')) : '';
            // Validate against the operation vocabulary + the tool-name shape BEFORE spawning: an unknown/typo'd
            // operation or a malformed tool name would otherwise start a full child conversation that can only
            // fail (and surface that failure into the parent's context).
            if (!in_array($operationName, $knownOperationNames, true)) {
                continue;
            }
            $requestedToolName = isset($requestedOperation['toolName']) ? (string)$requestedOperation['toolName'] : null;
            if ($requestedToolName !== null && preg_match('/^[A-Za-z0-9_]+$/', $requestedToolName) !== 1) {
                continue;
            }
            $agentSubAgentRequest = AgentSubAgentRequest::forToolResultBackgroundOperation(
                AgentDelegationToolRunTask::forLegacyBackgroundOperation(
                    $operationName,
                    $requestedToolName,
                    is_array($requestedOperation['toolArguments'] ?? null) ? $requestedOperation['toolArguments'] : [],
                    array_values(array_map('strval', is_array($requestedOperation['skillNames'] ?? null) ? $requestedOperation['skillNames'] : []))
                ),
                $completedToolCall
            );
            $agentSubAgentRequests->add($agentSubAgentRequest);
        }
        return $agentSubAgentRequests;
    }

    /**
     * PURPOSE-DECLARED BACKGROUND WARM-UPS (the web page markdown), the step-level sibling
     * of {@see buildSubAgentRequestsForCompletedToolCall()}: once EVERY call of a step completed, the conversation's
     * purpose is asked ({@see AIConversationPurpose::backgroundWarmOperationsAfterStep()}) which caches to warm
     * for the conversation's space (none: at account level), whose record the purpose loads FRESH after the writes; the
     * answer is deduped per conversation + operation + tool name
     * + url within the run ({@see AgentRun::$backgroundWarmOperationsDispatched}), skipped while the conversation's
     * stored sub-agent of the same call is unfinished or completed for the same input
     * ({@see DelegationSubAgentRecords::findRecordMakingToolRunRedundant()}), and returned as one request per warm,
     * its grace on the request. The SAME gates as the per-call path, no more: async only (the
     * sync/CLI path would run the child inline and block the turn), never in a delegation — and, like the per-call
     * path, ON eval runs too: an eval suite must measure the warm. Fail-soft: a
     * hiccup of the purpose or of the space's load never takes the turn down.
     *
     * @param ToolCall[] $stepToolCalls
     */
    public function buildPurposeWarmSubAgentRequestsAfterStep(
        AgentRun $agentRun,
        AIConversation $conversation,
        array $stepToolCalls,
        bool $asyncDelegations
    ): AgentSubAgentRequests {
        $agentSubAgentRequests = new AgentSubAgentRequests();
        if (!$asyncDelegations || !isset($conversation->id)
            || ($conversation->purposeName ?? null) === null || $conversation->isDelegation()
        ) {
            return $agentSubAgentRequests;
        }
        $completedToolNames = [];
        foreach ($stepToolCalls as $stepToolCall) {
            $toolName = (string)($stepToolCall->name ?? '');
            if ($toolName !== '' && $stepToolCall->status === AIConversationMessage::STATUS_COMPLETED) {
                $completedToolNames[$toolName] = true;
            }
        }
        if ($completedToolNames === []) {
            return $agentSubAgentRequests;
        }
        try {
            $aIConversationPurpose = $this->resolveConversationPurposeEntityOnce($agentRun, $conversation);
            if (!$aIConversationPurpose instanceof AIConversationPurpose) {
                return $agentSubAgentRequests;
            }
            // the purpose is asked with the conversation's space (loaded here when it is not yet), null at account
            // level; a purpose that reads the record of the space loads it past the entity registry — the step's own
            // writes count
            $requestedOperations = $aIConversationPurpose->backgroundWarmOperationsAfterStep(array_keys($completedToolNames), $conversation->agentSpace ?? null, $conversation);
        } catch (Throwable $warmResolutionError) {
            $this->aiTrace('backgroundWarm.resolveFailed', ['conversationId' => $conversation->id, 'message' => $warmResolutionError->getMessage()]);
            return $agentSubAgentRequests;
        }
        $conversationId = (int)$conversation->id;
        $subAgentRecordsOfConversation = null;
        foreach ($requestedOperations as $requestedOperation) {
            if (!$requestedOperation->isRunnable()) {
                continue;
            }
            // the url is part of the key: a second address stored in the same turn warms too; the
            // purpose's optional `cacheIdentity` likewise — a later write step of the same turn whose facets changed
            // the warmed entry's identity must warm again, an unchanged record must not
            $memoKeyPrefix = $requestedOperation->buildDispatchMemoKeyPrefix();
            $memoKey = $requestedOperation->buildDispatchMemoKey();
            if (isset($agentRun->backgroundWarmOperationsDispatched[$conversationId][$memoKey])) {
                continue;
            }
            // bounded: a turn whose write steps keep changing the identity re-warms a few times, never per step
            $warmsOfThisToolThisTurn = count(array_filter(
                array_keys($agentRun->backgroundWarmOperationsDispatched[$conversationId] ?? []),
                static fn (string $dispatchedMemoKey): bool => str_starts_with($dispatchedMemoKey, $memoKeyPrefix)
            ));
            if ($warmsOfThisToolThisTurn >= self::MAX_BACKGROUND_WARMS_PER_TOOL_PER_TURN) {
                continue;
            }
            $warmToolRunTask = AgentDelegationToolRunTask::forLegacyBackgroundOperation(
                $requestedOperation->operation,
                $requestedOperation->toolName,
                $requestedOperation->toolArguments,
                $requestedOperation->skillNames
            );
            $warmToolRunTask->warmedCacheIdentity = $requestedOperation->cacheIdentity;
            // The conversation's records decide across runs and turns (the memo above only knows this run): a second
            // warm of the same call never starts while one is QUEUED or RUNNING — its stale identity is computed
            // either way, and the model's call over the newer record computes its own — nor once one COMPLETED for the
            // same input. Read once per step, only when the purpose asked for a warm.
            $subAgentRecordsOfConversation ??= $this->findSubAgentRecordsOfConversationWithoutThrowing($conversation);
            $existingSubAgentRecord = $subAgentRecordsOfConversation->findRecordMakingToolRunRedundant($warmToolRunTask);
            if ($existingSubAgentRecord !== null) {
                $this->aiTrace('backgroundWarm.skippedForExistingSubAgent', [
                    'conversationId' => $conversationId,
                    'toolName' => $requestedOperation->toolName,
                    'subAgentNumber' => $existingSubAgentRecord->number,
                    'subAgentRunStatus' => $existingSubAgentRecord->getCurrentRun()?->status,
                ]);
                continue;
            }
            $agentRun->backgroundWarmOperationsDispatched[$conversationId][$memoKey] = true;
            $agentSubAgentRequest = AgentSubAgentRequest::forPurposeStep($warmToolRunTask);
            $agentSubAgentRequest->awaitGraceSeconds = $requestedOperation->awaitGraceSeconds;
            $agentSubAgentRequests->add($agentSubAgentRequest);
        }
        return $agentSubAgentRequests;
    }

    /**
     * A purpose-declared grace (a read the next step builds on): the loop waits (bounded) for the sub-agent run on the
     * `delegate` row `$delegateCallMessageId` before the next model call of the SAME turn — its notification then sits on
     * the wire for the step that needs it ({@see awaitSubAgentRunsOwedBeforeThisStep()}). Nothing is recorded without a
     * grace.
     */
    public function recordSubAgentRunAwaitedBeforeNextStep(
        AgentRun $agentRun,
        AIConversation $conversation,
        AIConversationMessage $delegateCallMessage,
        int $awaitGraceSeconds
    ): void {
        if ($awaitGraceSeconds <= 0 || !isset($delegateCallMessage->id) || !isset($conversation->id)) {
            return;
        }
        $agentRun->subAgentRunsAwaitedBeforeNextStep[(int)$conversation->id][(int)$delegateCallMessage->id] = hrtime(true) + $awaitGraceSeconds * 1_000_000_000;
    }

    /**
     * {@see awaitSubAgentRunsOwedBeforeThisStep()} for the agent loop (moved from it): a run
     * that ended in time left its notification waiting; the hand-over before the model call takes it. Never a turn of
     * its own. Fail-soft: a failed wait drives the step with what it has.
     */
    public function awaitSubAgentRunsOwedBeforeThisStepWithoutThrowing(AgentRun $agentRun, AIConversation $conversation, ?AgentConsoleStream $consoleStream): void
    {
        try {
            $this->awaitSubAgentRunsOwedBeforeThisStep($agentRun, $conversation, $consoleStream);
        } catch (Throwable $awaitError) {
            $this->aiTrace('turn.subAgent.awaitFailed', ['conversationId' => $conversation->id ?? null, 'message' => $awaitError->getMessage()]);
        }
    }

    /**
     * Before a model call of the turn: wait, bounded by the purpose's grace, for the sub-agent runs the turn declared it
     * needs (a read the following steps build on — a step that runs while the read is still running works without
     * its result and may refuse a correct value as "not grounded"). A run counts as
     * there once its end was told to the parent: its notification row waits for this turn's next model call
     * (the loop's hand-over before the call takes it, with the result), and the loaded `delegate` row is refreshed
     * so the run's whole verdict is there for the readers that ground values on it. On timeout nothing changes: the notification
     * arrives later and rides the next model call or the next genuine turn. Never a turn of its own. Returns how many
     * awaited runs were told.
     */
    public function awaitSubAgentRunsOwedBeforeThisStep(AgentRun $agentRun, AIConversation $conversation, ?AgentConsoleStream $consoleStream): int
    {
        $conversationId = isset($conversation->id) ? (int)$conversation->id : null;
        if ($conversationId === null || ($agentRun->subAgentRunsAwaitedBeforeNextStep[$conversationId] ?? []) === []) {
            return 0;
        }
        $reportedRunCount = 0;
        foreach ($agentRun->subAgentRunsAwaitedBeforeNextStep[$conversationId] as $delegateCallMessageId => $deadlineNs) {
            $waitStartedAt = hrtime(true);
            $reported = false;
            while (true) {
                try {
                    $reported = $this->hasSubAgentRunBeenReportedToParent((int)$delegateCallMessageId);
                } catch (Throwable) {
                    break;
                }
                if ($reported || hrtime(true) >= $deadlineNs) {
                    break;
                }
                usleep(self::BACKGROUND_AWAIT_POLL_MICROSECONDS);
            }
            if ($reported) {
                // the record's run on the loaded row: the readers that ground a value on a sub-agent's result (a
                // value the read verified) read the run's whole verdict there — the
                // notification carries at most its first 4,000 characters
                $this->messagesService()->refreshDelegateCallRowInLoadedMessages($conversation, (int)$delegateCallMessageId);
                $reportedRunCount++;
            }
            $consoleStream?->writeTiming('sub-agent await (row ' . $delegateCallMessageId . ', ' . ($reported ? 'ended' : 'timed out') . ')', (hrtime(true) - $waitStartedAt) / 1e9);
            $this->aiTrace('turn.subAgent.awaited', ['conversationId' => $conversationId, 'delegateCallMessageId' => $delegateCallMessageId, 'reported' => $reported, 'waitMs' => (int)round((hrtime(true) - $waitStartedAt) / 1e6)]);
            unset($agentRun->subAgentRunsAwaitedBeforeNextStep[$conversationId][$delegateCallMessageId]);
        }
        return $reportedRunCount;
    }

    /** The sub-agent on the row ended its current run and its notification was written (one raw read of the row). */
    protected function hasSubAgentRunBeenReportedToParent(int $delegateCallMessageId): bool
    {
        $storedRecord = $this->messagesService()->findStoredDelegateCallRow($delegateCallMessageId)?->delegationResults?->subAgent ?? null;
        $currentRun = $storedRecord?->getCurrentRun();
        return $currentRun !== null && $currentRun->hasEnded() && $currentRun->reportedToParentAt !== null;
    }

    /**
     * The conversation's sub-agent records as STORED (the loaded `delegate` rows keep the state of their spawn — a run's
     * end is written to the row, never to the loop's copy). Fail-soft: an unreadable record set is none, so a read
     * failure never withholds a warm.
     */
    protected function findSubAgentRecordsOfConversationWithoutThrowing(AIConversation $conversation): DelegationSubAgentRecords
    {
        try {
            return $this->messagesService()->findSubAgentRecordsOfConversation($conversation);
        } catch (Throwable $recordsReadError) {
            $this->aiTrace('backgroundWarm.subAgentRecordsReadFailed', ['conversationId' => $conversation->id ?? null, 'message' => $recordsReadError->getMessage()]);
            return new DelegationSubAgentRecords();
        }
    }

    /** The conversation's purpose ENTITY (the class carrying its individual logic), null for a purposeless conversation. */
    protected function resolveConversationPurposeEntity(AIConversation $conversation): ?AIConversationPurpose
    {
        return $conversation->findPurpose();
    }

    /**
     * The purpose entity the warm-up asks, resolved once per run and purpose name ({@see AgentRun::$purposesForBackgroundWarmByName}): a later step of the run reuses it instead of rebuilding it from config. A failed
     * resolution is not kept.
     */
    protected function resolveConversationPurposeEntityOnce(AgentRun $agentRun, AIConversation $conversation): ?AIConversationPurpose
    {
        $purposeName = (string)($conversation->purposeName ?? '');
        if (!array_key_exists($purposeName, $agentRun->purposesForBackgroundWarmByName)) {
            $agentRun->purposesForBackgroundWarmByName[$purposeName] = $this->resolveConversationPurposeEntity($conversation);
        }
        return $agentRun->purposesForBackgroundWarmByName[$purposeName];
    }

    /** The messages service — resolved inline (DI rule); a test double replaces it. */
    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }
}
