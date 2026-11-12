<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `set_task_status` meta-tool (the CHEAP per-step path): flip the status of one or more EXISTING
 * task-plan items by their KEY ("2.1" — the tail render doubles as the address book) — a flat
 * list of tiny updates, no nesting, no full-plan resend (~20–40 output tokens per tick vs 150–400+ for a
 * full replace). STRUCTURE changes (create the plan, add/remove items, spawn subtasks) stay on
 * {@see UpdateTaskPlanTool} — the heavy nested shape appears once per run, progress ticks are trivial.
 *
 * Addressing is STRICT: only a string that conforms to the key grammar and names
 * exactly one item addresses it ({@see SetTaskStatusAddressCheck}); any other address — a title behind the key, an item
 * text, a number, a boolean, an unknown key — rejects the WHOLE call, nothing applies, and the rejection names the key to
 * send and lists the open keys. The field aliases (`task` / `item` / `number`) stay. Same
 * META loop classification and token-fenced column persist as `update_task_plan`. The tick itself is the plan's
 * application ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan::applyStatusUpdates()}), run by the record
 * service; this class is the tool's name, schema and loop classification.
 *
 * @phpstan-consistent-constructor
 */
class SetTaskStatusTool extends ValueObject implements AgentMetaTool
{
    public const string NAME = 'set_task_status';

    /** Neither a read nor a write for the stored-call readers. */
    public const ?string DECLARED_EFFECT = null;

    /** Leads the result of a call whose every update named an item the platform resolved after the model wrote it. */
    public const string RESULT_PREFIX_NO_CHANGE_NEEDED = 'No change needed:';

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
    }

    public static function create(): static
    {
        return new static();
    }

    /**
     * The plan column persists for EVERY call of a plan tool, not only COMPLETED ones: a fully-REJECTED
     * set_task_status (updatedCount 0 → FAILED) can still have mutated the plan object (a transient obstacle re-opens a
     * record leaf with its note), and a FAILED-status skip would keep that mutation memory-only.
     * A rejected update_task_plan parse mutated nothing, so its persist writes the standing plan unchanged — idempotent.
     */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return AgentAfterStepRequest::persistTaskPlan();
    }

    /** The call's status updates are applied to the plan. */
    public static function workRequest(ToolCall $toolCall): AgentToolCallWorkRequest
    {
        return AgentToolCallWorkRequest::applyTaskStatusUpdates($toolCall->arguments);
    }

    public function getToolName(): string
    {
        return self::NAME;
    }

    /** @return array<string, mixed> the OpenAI function-tool wire entry */
    public function toOpenAiToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Update the status of existing task-plan items by the key shown in "Your task plan" (e.g. '
                    . '"2.1"). ' . TaskPlan::buildTickRules() . ' Batch EVERY update you owe into ONE call via the updates array — '
                    . 'never tick items in separate single-update calls; each extra call burns one of your work steps. For '
                    . 'structure changes (new items, subtasks) use update_task_plan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'updates' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'task' => ['type' => 'string', 'description' => 'The key shown in "Your task plan", e.g. "2.1" — the number only, as a string'],
                                    'status' => ['type' => 'string', 'enum' => [TaskPlanItem::STATUS_OPEN, TaskPlanItem::STATUS_DONE, TaskPlanItem::STATUS_BLOCKED, TaskPlanItem::STATUS_NOT_APPLICABLE]],
                                    'statusNote' => ['type' => 'string', 'description' => 'ONE short line justifying a terminal status — REQUIRED on done/blocked/not_applicable. done: what you actually produced or found. blocked: why, concretely. not_applicable: why this item does not apply to THIS ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . ' (e.g. a pure online shop has no opening hours).'],
                                    'requiresUserAction' => ['type' => 'boolean', 'description' => 'Blocked and only the user can unblock it'],
                                    'retryable' => ['type' => 'boolean', 'description' => 'Blocked by a transient external failure'],
                                ],
                                'required' => ['task', 'status'],
                            ],
                        ],
                    ],
                    'required' => ['updates'],
                ],
            ],
        ];
    }
}
