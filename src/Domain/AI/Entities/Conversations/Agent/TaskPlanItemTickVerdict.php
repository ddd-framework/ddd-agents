<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What ONE update of a `set_task_status` call came to: the gate or the transition that decided it
 * ({@see TaskPlanItemTickGate}) and the feedback lines it adds to the call's result, in order. The call's outcome counts
 * it by its kind ({@see AgentTaskPlanTickOutcome::addVerdict()}). A working value of one update.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanItemTickVerdict extends ValueObject
{
    /** The platform closed the item in this turn and no earlier tick of the turn named it — an echo, nothing to correct. */
    public const string KIND_ECHO_OF_PLATFORM_CLOSING = 'echoOfPlatformClosing';

    /** A gate kept the item as it is; the record decides it (a plan touch, never progress). */
    public const string KIND_NOTED = 'noted';

    /** A transient obstacle on a leaf the platform closes from the record: the item stays open with the obstacle noted. */
    public const string KIND_NOTED_OBSTACLE = 'notedObstacle';

    /** The record's verdict landed instead of the tick (it counts as an update, not as a resolution). */
    public const string KIND_RECORD_VERDICT_APPLIED = 'recordVerdictApplied';

    /** A done item stays done: the not_applicable is not applied and nothing is counted. */
    public const string KIND_DONE_STAYS_DONE = 'doneStaysDone';

    /** A same-status re-tick of a container, or a repeat after the call's first lecture: no change, not counted. */
    public const string KIND_ALREADY_IN_STATUS = 'alreadyInStatus';

    /** The first true same-status no-op of the call, with the lecture — the all-no-op breaker counts it. */
    public const string KIND_ALREADY_IN_STATUS_WITH_LECTURE = 'alreadyInStatusWithLecture';

    /** The model's status applied without a transition into a terminal status (a re-open, an open → open). */
    public const string KIND_APPLIED = 'applied';

    /** The model's status applied as a transition into a terminal status — a resolution. */
    public const string KIND_APPLIED_AS_RESOLUTION = 'appliedAsResolution';

    /** One of the KIND_* constants. */
    public string $kind = self::KIND_NOTED;

    /** @var string[] The feedback lines this update adds, in order. */
    public array $feedbackLines = [];

    /** @param string[] $feedbackLines */
    public static function ofKind(string $kind, array $feedbackLines = []): static
    {
        $taskPlanItemTickVerdict = new static();
        $taskPlanItemTickVerdict->kind = $kind;
        $taskPlanItemTickVerdict->feedbackLines = $feedbackLines;
        return $taskPlanItemTickVerdict;
    }

    /** A gate NOTED the tick with one feedback line. */
    public static function noted(string $feedbackLine): self
    {
        return self::ofKind(self::KIND_NOTED, [$feedbackLine]);
    }
}
