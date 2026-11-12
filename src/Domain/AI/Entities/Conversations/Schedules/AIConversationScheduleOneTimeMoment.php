<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * The single fire moment of a ONE-TIME schedule — resolved from the two
 * timing inputs of the agent's tools ({@see resolveFor()}: relative minutes or a wall-clock time local to the space) or
 * taken as an absolute moment ({@see fromMoment()}: the customer's edit). Pure; the clock is an input.
 *
 * One rule decides whether a moment is too far in the past, for the agent's tools and the customer's edit alike:
 * a moment up to {@see PAST_TOLERANCE_SECONDS} in the past is accepted ({@see isAcceptedAt()}).
 *
 * The agent's schedule tools still resolve their moments with their own copy of these rules until they switch to
 * this object; a differential test keeps both equal meanwhile.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationScheduleOneTimeMoment extends ValueObject
{
    /** @var int Upper bound for a relative wish — 7 days; anything farther away should be an absolute local time. */
    public const int MAX_RUN_IN_MINUTES = 10080;

    /** @var int A relative wish of this many minutes or more means a DAY, not a minute — its firing is kept to business hours. */
    public const int DAY_SCALE_RUN_IN_MINUTES = 1440;

    /** @var int The space-local hour a day-scale run is moved to when its minute-exact moment lands at night. */
    public const int DAY_SCALE_RUN_MORNING_HOUR = 9;

    /** @var int The space-local window a day-scale run may fire in as computed (start inclusive). */
    public const int DAY_SCALE_RUN_WINDOW_START_HOUR = 8;

    /** @var int The space-local window a day-scale run may fire in as computed (end exclusive). */
    public const int DAY_SCALE_RUN_WINDOW_END_HOUR = 20;

    /** @var int How far in the past a new one-time moment may lie (the caller's clock lags — a model's, a browser's). */
    public const int PAST_TOLERANCE_SECONDS = 120;

    /** @var DateTime|null The fire moment (an instant). */
    public ?DateTime $moment = null;

    public static function fromMoment(DateTime $moment): static
    {
        $oneTimeMoment = new static();
        $oneTimeMoment->moment = $moment;
        return $oneTimeMoment;
    }

    /**
     * The moment from the agent's two timing inputs: exactly one of `$runInMinutes` (relative, 1..{@see MAX_RUN_IN_MINUTES},
     * no timezone math; a day-scale wish is moved out of the night) or `$runAtLocalTime` ("YYYY-MM-DD HH:MM[:SS]" as a
     * wall-clock time in `$timezoneIdentifier`; an explicit offset in the string wins; a wall-clock time that does not
     * exist on a spring-forward day is refused with the next existing one named; a moment further than
     * {@see PAST_TOLERANCE_SECONDS} in the past is refused). Weak-model input tolerance: 0 / negative minutes mean
     * "not set"; when both are set the absolute local time wins.
     *
     * @throws BadRequestException corrective, for the model to act on
     */
    public static function resolveFor(?int $runInMinutes, ?string $runAtLocalTime, ?string $timezoneIdentifier, DateTime $now): self
    {
        if ($runInMinutes !== null && $runInMinutes <= 0) {
            $runInMinutes = null;
        }
        if (($runAtLocalTime ?? '') !== '') {
            $runInMinutes = null;
        }
        if ($runInMinutes === null && ($runAtLocalTime ?? '') === '') {
            throw new BadRequestException('Pass exactly ONE of runInMinutes / runAtLocalTime.');
        }
        if ($runInMinutes !== null) {
            if ($runInMinutes > self::MAX_RUN_IN_MINUTES) {
                throw new BadRequestException('runInMinutes must be between 1 and ' . self::MAX_RUN_IN_MINUTES . ' (7 days) — farther ahead, use runAtLocalTime.');
            }
            $relativeMoment = DateTime::fromTimestamp($now->getTimestamp() + $runInMinutes * 60);
            return self::fromMoment(self::shiftDayScaleRunOutOfTheNight($relativeMoment, $runInMinutes, $timezoneIdentifier));
        }
        $scheduleTimezone = new \DateTimeZone((string)$timezoneIdentifier);
        // Canonical, zone-aware parse: the literal digits are read in the schedule's zone; a nonexistent
        // wall-clock time throws its own corrective BadRequestException naming the next existing one.
        $localMoment = DateTime::fromStringInZone(trim((string)$runAtLocalTime), $scheduleTimezone);
        if ($localMoment === false) {
            throw new BadRequestException('runAtLocalTime must be "YYYY-MM-DD HH:MM" in the ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . '\'s local time (e.g. "2026-08-07 15:30").');
        }
        $oneTimeMoment = self::fromMoment($localMoment);
        if (!$oneTimeMoment->isAcceptedAt($now)) {
            // The current LOCAL date in the message grounds models whose internal date prior is stale.
            $nowInScheduleTimezone = (new \DateTimeImmutable('@' . $now->getTimestamp()))->setTimezone($scheduleTimezone)->format('Y-m-d H:i');
            throw new BadRequestException(
                "That local time ({$runAtLocalTime} {$timezoneIdentifier}) is in the past — right NOW it is {$nowInScheduleTimezone} there; pass a future time or use runInMinutes."
            );
        }
        return $oneTimeMoment;
    }

    /**
     * A day-scale relative wish ("in three days") names a day, not the minute the conversation ended on: inside
     * the space-local window the moment stands; before it the run moves to the morning hour of the same local day,
     * after it to the morning hour of the next. Minute-scale wishes ("in 10 minutes") are never moved.
     */
    public static function shiftDayScaleRunOutOfTheNight(DateTime $runAt, int $runInMinutes, ?string $timezoneIdentifier): DateTime
    {
        if ($runInMinutes < self::DAY_SCALE_RUN_IN_MINUTES || ($timezoneIdentifier ?? '') === '') {
            return $runAt;
        }
        try {
            $zone = new \DateTimeZone((string)$timezoneIdentifier);
        } catch (\Throwable) {
            return $runAt;
        }
        $local = (clone $runAt)->setTimezone($zone);
        $hour = (int)$local->format('G');
        if ($hour >= self::DAY_SCALE_RUN_WINDOW_START_HOUR && $hour < self::DAY_SCALE_RUN_WINDOW_END_HOUR) {
            return $runAt;
        }
        $morning = (clone $local)->setTime(self::DAY_SCALE_RUN_MORNING_HOUR, 0, 0);
        if ($hour >= self::DAY_SCALE_RUN_WINDOW_END_HOUR) {
            $morning = $morning->modify('+1 day');
        }
        return $morning;
    }

    /** Whether the moment may be scheduled at `$now`: not more than {@see PAST_TOLERANCE_SECONDS} before it. */
    public function isAcceptedAt(DateTime $now): bool
    {
        return $this->moment->getTimestamp() >= $now->getTimestamp() - self::PAST_TOLERANCE_SECONDS;
    }
}
