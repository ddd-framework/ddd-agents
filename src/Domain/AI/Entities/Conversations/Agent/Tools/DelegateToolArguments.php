<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationTask;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The arguments of ONE `delegate` call: an optional `label` and ONE task — an agent task
 * (`task` + `skill_names`) or a tool run (`toolName` + `toolArguments`, the arguments as an object or a JSON string).
 * A call that names `toolName` is a tool run, whatever else it carries; a call with neither shape is no task and is
 * refused with {@see buildTaskShapeCorrective()}. Fields the model has no
 * say in (whether the run end resumes the parent) are not read. Old rows carry `tasks[]`, read by
 * {@see fromLegacyArguments()}. Pure parse.
 *
 * @phpstan-consistent-constructor
 */
class DelegateToolArguments extends ValueObject
{
    /**
     * The tail of a rejection whose call named no `toolName`: such a delegate often stands for a widget the model writes
     * itself. A call that named a tool needs that tool, so its rejection carries no tail.
     */
    public const string DIRECT_WIDGET_TAIL = 'A widget you write directly (the weather, the time, a draft) needs no sub-agent: '
        . 'answer now with one line, then its block.';

    /** The model's label for the sub-agent, cut to a label; null = none given. */
    public ?string $label = null;

    /** The one task; null when the call names neither task shape. */
    public ?AgentDelegationTask $task = null;

    /** The call names an agent task AND a tool run: the tool run is its task, the `task` text is not read. */
    public bool $namesBothTaskShapes = false;

    /** @param array<string, mixed> $arguments the call's parsed arguments */
    public static function fromToolCallArguments(array $arguments): static
    {
        $delegateToolArguments = new static();
        $label = $arguments['label'] ?? null;
        if (is_string($label) && trim($label) !== '') {
            $delegateToolArguments->label = AgentDelegationTask::cutToLabel($label);
        }
        $delegateToolArguments->task = AgentDelegationTask::fromArgumentItem($arguments);
        $delegateToolArguments->namesBothTaskShapes = is_string($arguments['task'] ?? null) && trim($arguments['task']) !== ''
            && is_string($arguments['toolName'] ?? null) && trim($arguments['toolName']) !== '';
        return $delegateToolArguments;
    }

    /**
     * The `tasks[]` of a call stored before a call carried ONE task (and of today's model calls): every item with a non-blank `task`
     * is an agent task, everything else is skipped ({@see AgentDelegationAgentTask::fromLegacyArgumentItem()}).
     *
     * @param array<string, mixed> $arguments
     * @return AgentDelegationAgentTask[]
     */
    public static function fromLegacyArguments(array $arguments): array
    {
        $rawTasks = $arguments['tasks'] ?? null;
        if (!is_array($rawTasks)) {
            return [];
        }
        $agentDelegationAgentTasks = [];
        foreach ($rawTasks as $rawTask) {
            $agentDelegationAgentTask = AgentDelegationAgentTask::fromLegacyArgumentItem($rawTask);
            if ($agentDelegationAgentTask !== null) {
                $agentDelegationAgentTasks[] = $agentDelegationAgentTask;
            }
        }
        return $agentDelegationAgentTasks;
    }

    public function hasTask(): bool
    {
        return $this->task !== null;
    }

    /** The corrective of a call without a task shape; null when the call names a task. */
    public function buildTaskShapeCorrective(): ?string
    {
        if ($this->task !== null) {
            return null;
        }
        // neither shape: the call named no `toolName` (a `toolName` is a tool run, with or without a `task` text)
        return 'delegate REJECTED — the call names no task: give `task` (with `skill_names`) for an agent task, or '
            . '`toolName` + `toolArguments` for one tool call. Nothing was started. ' . self::DIRECT_WIDGET_TAIL;
    }
}
