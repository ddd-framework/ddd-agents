<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Delegation;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationRequestFromToolResult;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateToolArguments;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The ONE input of a sub-agent's spawn: one task, its label, who asked for it and how its run ends are
 * told to the parent, and the child profile a requesting tool declared. Built from the model's `delegate` call
 * ({@see forModelCall()}), from one task item of a tool result ({@see forToolResultTask()}) or by a purpose step
 * ({@see forPurposeStep()}). `requestedBy` decides which spawn guards apply: the skill-less guard and the tool offer
 * check are the model's only.
 *
 * @phpstan-consistent-constructor
 */
class AgentSubAgentRequest extends ValueObject
{
    /** The model's own `delegate` call. */
    public const string REQUESTED_BY_MODEL = 'MODEL';

    /** A task item of a tool's result (the platform writes a synthetic `delegate` row per item). */
    public const string REQUESTED_BY_TOOL_RESULT = 'TOOL_RESULT';

    /** A purpose step's platform work (a warm-up after a step). */
    public const string REQUESTED_BY_PURPOSE_STEP = 'PURPOSE_STEP';

    /** The one task of the sub-agent. */
    public AgentDelegationTask $task;

    /** The label the model or the requester gave; null = the fallbacks of {@see buildRecordLabel()}. */
    public ?string $label = null;

    /** One of the REQUESTED_BY_* constants. */
    public string $requestedBy = self::REQUESTED_BY_MODEL;

    /** The id of the tool call whose result asked for the sub-agent; null for the model and a purpose step. */
    public ?string $requestedByToolCallId = null;

    /** The name of that tool, for the line that names the request when all its sub-agents ended. */
    public ?string $requestedByToolName = null;

    /** The requesting tool's next step, shown once when every sub-agent of the request ended (`delegationNextStepWhenAllSubAgentsEnded`). */
    public ?string $nextStepWhenAllSubAgentsOfRequestEnded = null;

    /** The run's end is told to the parent without resuming it (platform and tool-requested work; never the model's). */
    public bool $notifiesParentWithoutResuming = false;

    /** The child's own step budget; null = inherited. */
    public ?int $childMaxStepsPerTurn = null;

    /** The child's pinned model; null = inherited. */
    public ?string $childModelName = null;

    /**
     * How long the parent's turn waits (bounded) for this run before its next model call — a purpose step's grace for
     * a read the turn needs (the post-import page read); 0 = no wait.
     */
    public int $awaitGraceSeconds = 0;

    /** The model's call; null when it names no task (the spawn then refuses it with the arguments' corrective). */
    public static function forModelCall(DelegateToolArguments $delegateToolArguments): ?static
    {
        if ($delegateToolArguments->task === null) {
            return null;
        }
        $agentSubAgentRequest = new static();
        $agentSubAgentRequest->task = $delegateToolArguments->task;
        $agentSubAgentRequest->label = $delegateToolArguments->label;
        return $agentSubAgentRequest;
    }

    /**
     * One task item of a COMPLETED tool's result, with its label ('' = the fallbacks of
     * {@see buildRecordLabel()}) and the profile, next step and flag the result declared.
     */
    public static function forToolResultTask(
        AgentDelegationTask $task,
        string $label,
        AgentDelegationRequestFromToolResult $agentDelegationRequestFromToolResult,
        ToolCall $producingToolCall
    ): static {
        $agentSubAgentRequest = new static();
        $agentSubAgentRequest->task = $task;
        $agentSubAgentRequest->label = trim($label) !== '' ? $label : null;
        $agentSubAgentRequest->requestedBy = self::REQUESTED_BY_TOOL_RESULT;
        $agentSubAgentRequest->requestedByToolCallId = $producingToolCall->toolCallId;
        $agentSubAgentRequest->requestedByToolName = $producingToolCall->name;
        $agentSubAgentRequest->nextStepWhenAllSubAgentsOfRequestEnded = $agentDelegationRequestFromToolResult->nextStepWhenAllSubAgentsEnded;
        $agentSubAgentRequest->notifiesParentWithoutResuming = $agentDelegationRequestFromToolResult->notifiesParentWithoutResuming;
        $agentSubAgentRequest->childMaxStepsPerTurn = $agentDelegationRequestFromToolResult->childMaxStepsPerTurn;
        $agentSubAgentRequest->childModelName = $agentDelegationRequestFromToolResult->childModelName;
        return $agentSubAgentRequest;
    }

    /**
     * One item of a COMPLETED tool's `backgroundOperations` (platform work the tool decided): its run
     * end never resumes the parent.
     */
    public static function forToolResultBackgroundOperation(AgentDelegationTask $task, ToolCall $producingToolCall): static
    {
        $agentSubAgentRequest = new static();
        $agentSubAgentRequest->task = $task;
        $agentSubAgentRequest->requestedBy = self::REQUESTED_BY_TOOL_RESULT;
        $agentSubAgentRequest->requestedByToolCallId = $producingToolCall->toolCallId;
        $agentSubAgentRequest->requestedByToolName = $producingToolCall->name;
        $agentSubAgentRequest->notifiesParentWithoutResuming = true;
        return $agentSubAgentRequest;
    }

    /** A purpose step's platform work: its run end never resumes the parent. */
    public static function forPurposeStep(AgentDelegationTask $task): static
    {
        $agentSubAgentRequest = new static();
        $agentSubAgentRequest->task = $task;
        $agentSubAgentRequest->requestedBy = self::REQUESTED_BY_PURPOSE_STEP;
        $agentSubAgentRequest->notifiesParentWithoutResuming = true;
        return $agentSubAgentRequest;
    }

    /**
     * The arguments of the synthetic `delegate` row the platform writes for this request (on the
     * parent's wire like the model's own call): the record's label, then the task in the model's shape.
     *
     * @return array<string, mixed>
     */
    public function buildDelegateCallArguments(): array
    {
        return ['label' => $this->buildRecordLabel()] + $this->task->buildDelegateCallArguments();
    }

    /**
     * The label of the sub-agent's record: the given label; for a tool run its tool and first scalar
     * argument; else the task's first sentence — at most {@see AgentDelegationTask::LABEL_MAX_LENGTH} characters.
     */
    public function buildRecordLabel(): string
    {
        if ($this->label !== null && trim($this->label) !== '') {
            return AgentDelegationTask::cutToLabel($this->label);
        }
        return $this->task->buildDefaultLabel();
    }
}
