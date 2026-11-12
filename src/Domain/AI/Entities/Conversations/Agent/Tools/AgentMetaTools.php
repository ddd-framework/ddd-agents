<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;

/**
 * The loop's meta tools by the question the loop asks about them: each list names one rule of
 * the dispatch or the step's tool offer, with TODAY's members — the lists differ on purpose or by history (one family of
 * definitions, pinned by a test of the application); merging them is a separate, measured change.
 */
class AgentMetaTools
{
    /**
     * The twelve meta tools, in the order the effect catalog registers them.
     *
     * @var array<int, class-string<AgentMetaTool>>
     */
    public const array TOOL_CLASSES = [
        LoadSkillsTool::class,
        LoadToolTool::class,
        CheckDelegationsTool::class,
        GetTaskPlansTool::class,
        UpdateTaskPlanTool::class,
        SendOwnerReportTool::class,
        DelegateTool::class,
        SetTaskStatusTool::class,
        AskOwnerTool::class,
        WaitForSubAgentsTool::class,
        MessageSubAgentTool::class,
        FindWidgetsTool::class,
    ];

    /** What the loop must do after $toolCall was dispatched, when it is a meta tool's call that asks for anything. */
    public static function findAfterStepRequest(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        foreach (self::TOOL_CLASSES as $metaToolClass) {
            if ($metaToolClass::NAME === ($toolCall->name ?? '')) {
                return $metaToolClass::afterStep($toolCall);
            }
        }
        return null;
    }

    /**
     * The meta tool of $toolCall when it refuses its call under a purpose-seeded task plan
     * ({@see AgentSeededPlanRefusingMetaTool}): `update_task_plan` and `delegate`.
     *
     * @return class-string<AgentSeededPlanRefusingMetaTool>|null
     */
    public static function findSeededPlanRefusingToolClass(ToolCall $toolCall): ?string
    {
        foreach (self::TOOL_CLASSES as $metaToolClass) {
            if ($metaToolClass::NAME === ($toolCall->name ?? '') && is_subclass_of($metaToolClass, AgentSeededPlanRefusingMetaTool::class)) {
                return $metaToolClass;
            }
        }
        return null;
    }

    /** The work $toolCall asks the dispatch to execute, when it is a meta tool's call that asks for work. */
    public static function findWorkRequest(ToolCall $toolCall): ?AgentToolCallWorkRequest
    {
        foreach (self::TOOL_CLASSES as $metaToolClass) {
            if ($metaToolClass::NAME === ($toolCall->name ?? '')) {
                return $metaToolClass::workRequest($toolCall);
            }
        }
        return null;
    }

    /**
     * Never stopped by the repeat guard (a status poll and the plan tools are byte-identical by nature; turn-killing them
     * aborted whole runs). A tool declaring #[MCPToolRefreshesState] is exempt too, from its definition.
     */
    public const array REPEAT_GUARD_EXEMPT_NAMES = [CheckDelegationsTool::NAME, ...TaskPlan::PLAN_TOOL_NAMES];

    /**
     * Exempt from the repeat guard while their identical calls succeed: `wait_for_sub_agents` takes
     * no arguments and ends one stretch per run end, so a turn with four sub-agents waits four times; three identical
     * REFUSED waits still get the corrective and the tool-less step.
     */
    public const array REPEAT_GUARD_EXEMPT_WHILE_SUCCEEDING_NAMES = [WaitForSubAgentsTool::NAME];

    /** Never ABORT the turn on an identical repeat (they execute nothing); the corrective and the tool-less step still apply. */
    public const array REPEAT_ABORT_EXEMPT_NAMES = [LoadSkillsTool::NAME, AskOwnerTool::NAME, FindWidgetsTool::NAME];

    /** Left out of the step offer when a supervisor steer or a work signal forces the first step's tools. */
    public const array FORCED_FIRST_STEP_LEFT_OUT_NAMES = TaskPlan::PLAN_TOOL_NAMES;

    /** Not "work" for the stall recovery's forced tool choice: a forced step never picks one of them. */
    public const array NOT_WORK_FOR_THE_FORCED_STEP_NAMES = [LoadSkillsTool::NAME, DelegateTool::NAME, ...TaskPlan::PLAN_TOOL_NAMES, SendOwnerReportTool::NAME, WaitForSubAgentsTool::NAME, MessageSubAgentTool::NAME, FindWidgetsTool::NAME];

    /** Never in the forced re-drive's set (a forced capability load or widget search is not work). */
    public const array NOT_IN_THE_FORCED_REDRIVE_NAMES = [LoadToolTool::NAME, FindWidgetsTool::NAME];

    /**
     * Left out of the compaction telemetry's work counts and write excerpts
     * ({@see \DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionToolTelemetry::isCountedCompletion()});
     * `get_task_plans` and `send_owner_report` count as work there.
     */
    public const array EXCLUDED_FROM_COMPACTION_WORK_COUNTS_NAMES = [
        LoadSkillsTool::NAME,
        LoadToolTool::NAME,
        DelegateTool::NAME,
        CheckDelegationsTool::NAME,
        AskOwnerTool::NAME,
        WaitForSubAgentsTool::NAME,
        MessageSubAgentTool::NAME,
        FindWidgetsTool::NAME,
        ...TaskPlan::PLAN_TOOL_NAMES,
    ];

    /** Whether $toolName is left out of the compaction telemetry's work counts ({@see EXCLUDED_FROM_COMPACTION_WORK_COUNTS_NAMES}). */
    public static function isExcludedFromCompactionWorkCounts(string $toolName): bool
    {
        return in_array($toolName, self::EXCLUDED_FROM_COMPACTION_WORK_COUNTS_NAMES, true);
    }
}
