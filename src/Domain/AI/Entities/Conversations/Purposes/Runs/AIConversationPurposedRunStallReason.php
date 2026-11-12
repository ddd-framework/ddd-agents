<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\Runs;

/**
 * Why a purposed run counts as STALLED — the documented rule `findPurposedRuns` computes per row; the
 * first reason that applies wins (the order of {@see ORDER}). The thresholds are the recovery machinery's own: a run
 * the reaper or the owed-turn pass would pick up is stalled here too. An IDLE run WITHOUT an open task plan is never
 * stalled by this rule (an inline platform run such as an `Agent.LocalSignals` root stays IDLE by design and holds no
 * plan). The admin guide `purposed-runs` states the same rule for a reader without the code.
 */
class AIConversationPurposedRunStallReason
{
    /** The run ended FAILED (over the resume cap, a budget or provider terminal). */
    public const string FAILED = 'FAILED';

    /** RUNNING with a heartbeat older than the hung threshold, or AWAITING_DELEGATES past the fold window — the reaper's set. */
    public const string HUNG = 'HUNG';

    /** IDLE with a turn handover marker older than the quiet window — a lost re-fire or a failed turn owed again. */
    public const string TURN_OWED = 'TURN_OWED';

    /** Not running, a report obligation stamped and never delivered — the owed-reports pass's set. */
    public const string REPORT_OWED = 'REPORT_OWED';

    /** IDLE, the plan still open, quiet past the quiet window, the newest text is NOT the agent's: an input went unanswered. */
    public const string OPEN_PLAN_UNANSWERED = 'OPEN_PLAN_UNANSWERED';

    /** IDLE, the plan still open, quiet past the quiet window, the agent spoke last: the run waits for an owner who left. */
    public const string OPEN_PLAN_OWNER_AWAY = 'OPEN_PLAN_OWNER_AWAY';

    /** @var string[] The evaluation order: the first reason that applies is the row's reason. */
    public const array ORDER = [
        self::FAILED,
        self::HUNG,
        self::TURN_OWED,
        self::REPORT_OWED,
        self::OPEN_PLAN_UNANSWERED,
        self::OPEN_PLAN_OWNER_AWAY,
    ];

    /** @var array<string, string> What each reason means, for a reader of the result. */
    public const array DESCRIPTIONS = [
        self::FAILED => 'ended FAILED',
        self::HUNG => 'RUNNING without a heartbeat for 300 s (AWAITING_DELEGATES for 30 s) — the reaper re-drives it',
        self::TURN_OWED => 'IDLE with a turn handover older than 300 s — the owed-turn pass re-drives it',
        self::REPORT_OWED => 'a report owed and never delivered — the owed-reports pass delivers it late',
        self::OPEN_PLAN_UNANSWERED => 'IDLE, plan open, quiet past the quiet window, the newest text is not the agent\'s — an input went unanswered',
        self::OPEN_PLAN_OWNER_AWAY => 'IDLE, plan open, quiet past the quiet window, the agent spoke last — waiting for an owner who left',
    ];

    /** The description of a reason; '' for an unknown one. */
    public static function describe(?string $stallReason): string
    {
        return $stallReason !== null ? (self::DESCRIPTIONS[$stallReason] ?? '') : '';
    }
}
