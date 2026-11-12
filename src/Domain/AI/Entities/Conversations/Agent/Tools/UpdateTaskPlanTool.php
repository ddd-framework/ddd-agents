<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `update_task_plan` meta-tool: the model creates and restructures its task plan for multi-part tasks.
 * KEYED contract: the call names every EXISTING item by its server-minted key
 * (`{"key": "2.1"}`, text fixed), adds a NEW item as `{"text": "…"}` without a key (the platform mints it, never
 * renumbered), drops an item only explicitly (`{"key": "3", "remove": true}`); an existing item sent without `subtasks`
 * keeps its subtree. Items may carry SUBTASKS (the {@see TaskPlanItem} tree) to any depth and a plan has no item limit.
 * The wire is parsed once ({@see UpdateTaskPlanArguments}), checked as a whole and applied
 * by the plan ({@see TaskPlan::checkUpdate()} / {@see TaskPlan::applyUpdate()} / {@see TaskPlan::createFromUpdate()}):
 * one violation rejects the whole call with every offending line named and the plan's keys listed, and nothing changes.
 * ALWAYS offered (like load_skills; snake_case = the loop meta-tool family). Loop classification = META: excluded from
 * the work-tool folds (forced tool_choice, hasCompletedWorkToolCall), never treated as a write by the read-dedup / reuse
 * hint, repeat-guard-exempt.
 *
 * The status discipline lives LAZY (revising the earlier static-prompt placement): the adoption trigger + the never-substitute and failed→blocked rules stay in the
 * cacheable base prompt (Agentic.md "Finish the task"); the per-run maintenance mechanics ride the plan-CREATION result
 * (clearing-exempt) and the tail render's header line, so plain chat no longer carries them. The schema declares
 * `subtasks` ONE level explicitly (OpenAI function schemas cannot recurse — a `$ref` cycle is the documented Gemini-400
 * trap, and so is a `pattern` keyword: the key grammar stands in the descriptions) with a "may nest to any depth"
 * description; the parser accepts any depth.
 *
 * @phpstan-consistent-constructor
 */
class UpdateTaskPlanTool extends ValueObject implements AgentSeededPlanRefusingMetaTool
{
    public const string NAME = 'update_task_plan';

    /** The effect the stored-call readers see ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog}). */
    public const ?string DECLARED_EFFECT = MCPToolEffect::WRITE;

    /**
     * The answer to every `update_task_plan` on a purpose that SEEDS its plan: the platform's own
     * fixed text — the dispatch answers with it, and the legacy outcome reader recognizes a stored refusal by it
     * ({@see ToolCall::readTaskPlanCallOutcome()}: the refusal is stored COMPLETED).
     */
    public const string SEEDED_PLAN_REWRITE_REFUSAL_TEXT = 'The task plan of this conversation is SEEDED by its purpose and is never rewritten: every item you '
        . 'need is already in "Your task plan" — work it top-down and report status with set_task_status (done / '
        . 'blocked / not_applicable with a note). Nothing was changed.';

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
     * A purpose that SEEDS its plan never takes a rewrite — a full resend of a seeded plan
     * costs thousands of output tokens and many seconds, and every one is a
     * structure the plan already had. The seeded plan is found and worked, never written; the answer
     * costs no parse and no plan write.
     */
    public static function answerSeededPlanRewriteRefusal(): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::completed(self::SEEDED_PLAN_REWRITE_REFUSAL_TEXT);
    }

    /**
     * A purpose-seeded plan that exists is never rewritten ({@see answerSeededPlanRewriteRefusal()}); the call is stored
     * COMPLETED, yet it changed nothing, so the answer records the outcome `refused`. Without a plan the
     * call is applied (the plan is created).
     */
    public static function findSeededPlanRefusal(AgentSeededPlanPurposeFacts $agentSeededPlanPurposeFacts): ?AgentMetaToolCallAnswer
    {
        if ($agentSeededPlanPurposeFacts->purposeSeedsItsTaskPlan && $agentSeededPlanPurposeFacts->hasTaskPlan) {
            return self::answerSeededPlanRewriteRefusal()->withTaskPlanCallOutcome(ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED);
        }
        return null;
    }

    /**
     * The plan column persists for EVERY call of a plan tool, not only COMPLETED ones: a fully-REJECTED
     * set_task_status (updatedCount 0 → FAILED) can still have mutated the plan object (a transient obstacle re-opens a
     * record leaf with its note), and a FAILED-status skip would keep that mutation memory-only.
     * A rejected update_task_plan call mutated nothing, so its persist writes the standing plan unchanged — idempotent.
     */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return AgentAfterStepRequest::persistTaskPlan();
    }

    /** The call is applied to the standing plan (or creates it) — unless the dispatch refused it first. */
    public static function workRequest(ToolCall $toolCall): AgentToolCallWorkRequest
    {
        return AgentToolCallWorkRequest::applyTaskPlanUpdate($toolCall->arguments);
    }

    public function getToolName(): string
    {
        return self::NAME;
    }

    /** @return array<string, mixed> the OpenAI function-tool wire entry */
    public function toOpenAiToolSchema(): array
    {
        $itemProperties = [
            'key' => ['type' => 'string', 'description' => 'The item\'s number from Your task plan, exactly (e.g. "2.1"); only for existing items'],
            'text' => ['type' => 'string', 'description' => 'Only for a NEW item: the sub-task, short'],
            'status' => ['type' => 'string', 'enum' => [TaskPlanItem::STATUS_OPEN, TaskPlanItem::STATUS_DONE, TaskPlanItem::STATUS_BLOCKED, TaskPlanItem::STATUS_NOT_APPLICABLE]],
            'statusNote' => ['type' => 'string', 'description' => 'ONE short line justifying a terminal status — REQUIRED on done/blocked/not_applicable. done: what you produced or found. blocked: why, concretely. not_applicable: why this item does not apply to THIS ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . '.'],
            'requiresUserAction' => ['type' => 'boolean', 'description' => 'Blocked and only the user can unblock it'],
            'retryable' => ['type' => 'boolean', 'description' => 'Blocked by a transient external failure'],
            'remove' => ['type' => 'boolean', 'description' => 'true drops this existing item and its subtasks; send it with the key only'],
        ];
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Create or change your task plan for a multi-part task. Send the whole tree: every EXISTING '
                    . 'item as {"key": "<its number from Your task plan>"} — a key is that number only, e.g. "2.1", as a '
                    . 'string; its text is fixed and need not be sent. Every NEW item as {"text": "…"} WITHOUT a key (the '
                    . 'platform assigns one). Drop an item with {"key": "…", "remove": true}. An existing item sent without '
                    . '"subtasks" keeps its subtasks. Items may nest to any depth. For status changes use set_task_status.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => $itemProperties + [
                                    'subtasks' => [
                                        'type' => 'array',
                                        'description' => 'Optional sub-steps of this item (same shape; may nest to any depth)',
                                        'items' => ['type' => 'object', 'properties' => $itemProperties],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['items'],
                ],
            ],
        ];
    }

    /** Alias map: the reshapings weak reps actually produce → the canonical status. Public: shared by {@see SetTaskStatusArgumentsUpdate}. */
    public const array STATUS_ALIASES = [
        'open' => TaskPlanItem::STATUS_OPEN,
        'pending' => TaskPlanItem::STATUS_OPEN,
        'todo' => TaskPlanItem::STATUS_OPEN,
        'in_progress' => TaskPlanItem::STATUS_OPEN,
        'in-progress' => TaskPlanItem::STATUS_OPEN,
        'done' => TaskPlanItem::STATUS_DONE,
        'completed' => TaskPlanItem::STATUS_DONE,
        'complete' => TaskPlanItem::STATUS_DONE,
        'finished' => TaskPlanItem::STATUS_DONE,
        'blocked' => TaskPlanItem::STATUS_BLOCKED,
        'failed' => TaskPlanItem::STATUS_BLOCKED,
        'not_applicable' => TaskPlanItem::STATUS_NOT_APPLICABLE,
        'not-applicable' => TaskPlanItem::STATUS_NOT_APPLICABLE,
        'notapplicable' => TaskPlanItem::STATUS_NOT_APPLICABLE,
        'n/a' => TaskPlanItem::STATUS_NOT_APPLICABLE,
        'na' => TaskPlanItem::STATUS_NOT_APPLICABLE,
        'skipped' => TaskPlanItem::STATUS_NOT_APPLICABLE,
        'irrelevant' => TaskPlanItem::STATUS_NOT_APPLICABLE,
    ];
}
