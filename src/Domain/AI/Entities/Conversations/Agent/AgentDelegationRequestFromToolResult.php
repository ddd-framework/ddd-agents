<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAwaitedRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequests;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The sub-agents a COMPLETED tool's result asks the loop to start (one synthetic `delegate` row per
 * task): read once off `structuredContent`. The keys:
 * - `delegationTasks` — the tasks: a string is an agent task with the shared skills (the short form), an object is
 *   `{task, skill_names?, label?}` (an agent task) or `{toolName, toolArguments, label?}` (a tool run);
 * - `delegationSkillNames` — the skills of every agent task that names none;
 * - `delegationTaskLabels` — the label of each task by position (a string task has no room for one);
 * - `delegationNextStepWhenAllSubAgentsEnded` — what the parent does once every sub-agent of the request ended (shown
 *   once, in the notification of the last run end of the request; formerly `foldNextStep`);
 * - `delegationAwaitedConversationIds` — conversations that already run on their own and become sub-agents of the
 *   request too (one record each);
 * - `delegationNotifiesParentWithoutResuming` — the sub-agents' run ends ride the parent's next genuine turn instead of
 *   resuming it (platform work the tool decided);
 * - `delegationMaxStepsPerTurn` / `delegationModelName` — the agent tasks' child profile.
 * Pure parse; the spawn decides what to do with it.
 *
 * @phpstan-consistent-constructor
 */
class AgentDelegationRequestFromToolResult extends ValueObject
{
    /** The result declares a non-empty `delegationTasks` list (before any task is checked). */
    public bool $declaresTasks = false;

    /** @var AgentDelegationTask[] the usable tasks in request order (a blank, malformed or two-shaped item is left out) */
    public array $tasks = [];

    /** @var string[] the label of each usable task, by the same position as {@see $tasks}; '' = no label given */
    public array $taskLabels = [];

    /** The requester's next step once all its sub-agents ended, trimmed; null when none. */
    public ?string $nextStepWhenAllSubAgentsEnded = null;

    /** @var int[] conversations the parent waits for together with the children (positive, not its own, unique, in order) */
    public array $awaitedConversationIds = [];

    /** The children's own step budget (clamped to the ceiling); null = inherited. */
    public ?int $childMaxStepsPerTurn = null;

    /** The children's pinned model, trimmed; null = inherited. */
    public ?string $childModelName = null;

    /** The run ends of these sub-agents do not resume the parent (platform work); never set by the model. */
    public bool $notifiesParentWithoutResuming = false;

    /** @param array<string, mixed>|null $structuredContent null = the result carried no structured content (nothing declared) */
    public static function fromStructuredContent(?array $structuredContent, int $ownConversationId): static
    {
        $structuredContent ??= [];
        $request = new static();
        $declaredTasks = $structuredContent['delegationTasks'] ?? null;
        $request->declaresTasks = is_array($declaredTasks) && $declaredTasks !== [];
        $declaredNextStep = $structuredContent['delegationNextStepWhenAllSubAgentsEnded'] ?? null;
        $request->nextStepWhenAllSubAgentsEnded = is_string($declaredNextStep) && trim($declaredNextStep) !== '' ? trim($declaredNextStep) : null;
        if (!$request->declaresTasks) {
            return $request;
        }
        $delegationSkillNames = array_values(array_filter(array_map(
            'strval',
            is_array($structuredContent['delegationSkillNames'] ?? null) ? $structuredContent['delegationSkillNames'] : []
        )));
        $declaredTaskLabels = is_array($structuredContent['delegationTaskLabels'] ?? null) ? array_values($structuredContent['delegationTaskLabels']) : [];
        foreach (array_values($declaredTasks) as $taskPosition => $declaredTask) {
            $task = self::readTask($declaredTask, $delegationSkillNames);
            if ($task === null) {
                continue;
            }
            $request->tasks[] = $task;
            $itemLabel = is_array($declaredTask) && is_string($declaredTask['label'] ?? null) ? $declaredTask['label'] : null;
            $positionLabel = is_string($declaredTaskLabels[$taskPosition] ?? null) ? $declaredTaskLabels[$taskPosition] : null;
            $request->taskLabels[] = trim((string)($itemLabel ?? $positionLabel ?? ''));
        }
        $awaitedConversationIds = [];
        foreach (is_array($structuredContent['delegationAwaitedConversationIds'] ?? null) ? $structuredContent['delegationAwaitedConversationIds'] : [] as $awaitedId) {
            if (is_int($awaitedId) && $awaitedId > 0 && $awaitedId !== $ownConversationId) {
                $awaitedConversationIds[] = $awaitedId;
            }
        }
        $request->awaitedConversationIds = array_values(array_unique($awaitedConversationIds));
        $declaredChildMaxSteps = $structuredContent['delegationMaxStepsPerTurn'] ?? null;
        if (is_int($declaredChildMaxSteps) && $declaredChildMaxSteps > 0) {
            $request->childMaxStepsPerTurn = min(RunSettings::MAX_STEPS_PER_TURN_CEILING, $declaredChildMaxSteps);
        }
        $declaredChildModelName = $structuredContent['delegationModelName'] ?? null;
        $request->childModelName = is_string($declaredChildModelName) && trim($declaredChildModelName) !== '' ? trim($declaredChildModelName) : null;
        $request->notifiesParentWithoutResuming = ($structuredContent['delegationNotifiesParentWithoutResuming'] ?? false) === true;
        return $request;
    }

    /**
     * One declared task: a non-blank string is an agent task with the shared skills; an object is read by
     * {@see AgentDelegationTask::fromArgumentItem()} (an agent task without its own skills gets the shared ones); anything
     * else is no task.
     *
     * @param string[] $delegationSkillNames
     */
    protected static function readTask(mixed $declaredTask, array $delegationSkillNames): ?AgentDelegationTask
    {
        if (is_string($declaredTask)) {
            return trim($declaredTask) !== '' ? AgentDelegationAgentTask::forTask($declaredTask, $delegationSkillNames) : null;
        }
        if (!is_array($declaredTask)) {
            return null;
        }
        $task = AgentDelegationTask::fromArgumentItem($declaredTask);
        if ($task instanceof AgentDelegationAgentTask && !$task->hasSkillNames()) {
            $task->skillNames = $delegationSkillNames;
        }
        return $task;
    }

    /**
     * One request per usable task: the task, its label, the producing call as its requester, the tool's
     * next step, child profile and flag on each.
     */
    public function buildSubAgentRequests(ToolCall $producingToolCall): AgentSubAgentRequests
    {
        $agentSubAgentRequests = new AgentSubAgentRequests();
        foreach ($this->tasks as $taskPosition => $task) {
            $agentSubAgentRequest = AgentSubAgentRequest::forToolResultTask($task, $this->taskLabels[$taskPosition] ?? '', $this, $producingToolCall);
            $agentSubAgentRequests->add($agentSubAgentRequest);
        }
        return $agentSubAgentRequests;
    }

    /**
     * One request per awaited conversation that can still be joined: an awaited run of the same request
     * — its end belongs to the request's "all ended" like a task's.
     *
     * @param int[] $joinableAwaitedConversationIds
     */
    public function buildAwaitedRunRequests(ToolCall $producingToolCall, array $joinableAwaitedConversationIds): AgentSubAgentRequests
    {
        $agentSubAgentRequests = new AgentSubAgentRequests();
        foreach ($joinableAwaitedConversationIds as $awaitedConversationId) {
            $agentSubAgentRequest = AgentSubAgentRequest::forToolResultTask(
                AgentDelegationAwaitedRunTask::forConversation((int)$awaitedConversationId),
                '',
                $this,
                $producingToolCall
            );
            $agentSubAgentRequests->add($agentSubAgentRequest);
        }
        return $agentSubAgentRequests;
    }
}
