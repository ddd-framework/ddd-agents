<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

/**
 * Lifecycle of an {@see AIConversationSchedule}. String constants (not a native enum) to match the
 * project's {@see \DDD\Domain\AI\Entities\Conversations\AIConversationRunState} style and to key the fire-claim
 * UPDATE directly.
 */
class AIConversationScheduleStatus
{
    /** Fires on its cron occurrences ({@see AIConversationSchedule::$nextRunAt} is the queried anchor). */
    public const string ACTIVE = 'ACTIVE';

    /** Temporarily stopped by user/admin — keeps its state; RESUME recomputes nextRunAt and returns to ACTIVE. */
    public const string PAUSED = 'PAUSED';

    /** Ran past {@see AIConversationSchedule::$ttlAt} or {@see AIConversationSchedule::$maxIterations} — terminal. */
    public const string EXPIRED = 'EXPIRED';

    /** The {@see AIConversationSchedule::$doneCriteria} were judged fulfilled at a run terminal — terminal. */
    public const string DONE = 'DONE';

    /** Cancelled by user/admin — terminal (kept for history; the runs stay readable). */
    public const string CANCELLED = 'CANCELLED';

    /**
     * Deleted by user/agent AFTER it already ran at least once (a schedule with an
     * execution is never hard-deleted) — terminal, HIDDEN from every default list; visible only with an
     * explicit archived filter. A schedule with ZERO executions is hard-deleted instead of archived.
     */
    public const string ARCHIVED = 'ARCHIVED';
}
