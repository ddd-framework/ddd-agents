<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use Throwable;

/**
 * The limits a schedule is created and edited under: the active cap per
 * agent space, the cron frequency floor, the daily creation cap of agent-made recurring schedules, the `maxRuns` range,
 * the title length, the report-channel normalisation and the IANA zone check. Pure rules — the callers count the rows
 * and keep their own refusal texts where those differ; the texts every caller shares live here.
 *
 * The agent's schedule tools still carry their own copy of these numbers until they switch to this object;
 * a parity test keeps both equal meanwhile.
 */
class AIConversationScheduleCreationLimits extends ValueObject
{
    /** @var int Active-schedule cap per agent space — a runaway agent must not fill the fire queue. */
    public const int MAX_ACTIVE_SCHEDULES_PER_AGENT_SPACE = 20;

    /**
     * @var int Cron frequency floor: a recurring schedule fires at most this often per
     * day — every firing is a full agent run that costs real budget.
     */
    public const int MAX_CRON_FIRINGS_PER_DAY = 4;

    /** @var int Daily creation cap for AGENT-created recurring schedules per agent space. */
    public const int MAX_RECURRING_SCHEDULES_CREATED_PER_DAY = 5;

    /** @var int The smallest `maxRuns` cap a recurring schedule takes. */
    public const int MAX_RUNS_LOWEST = 1;

    /** @var int The largest `maxRuns` cap a recurring schedule takes. */
    public const int MAX_RUNS_HIGHEST = 365;

    /** @var int The owner-facing title is cut to the label length the surfaces show. */
    public const int TITLE_MAX_CHARS = AIConversationSchedule::OWNER_FACING_LABEL_MAX_CHARS;

    /** @var int How many occurrences the frequency floor counts at most within one day. */
    public const int CRON_FIRINGS_COUNT_CAP = 50;

    /** The title as stored: an already trimmed title cut to {@see TITLE_MAX_CHARS}. */
    public static function cutTitle(string $trimmedTitle): string
    {
        return mb_substr($trimmedTitle, 0, self::TITLE_MAX_CHARS);
    }

    /**
     * The report channel as stored (trimmed, upper case) — CHAT or EMAIL.
     *
     * @throws BadRequestException for any other channel
     */
    public static function normalizeReportChannel(string $reportChannel): string
    {
        $normalizedReportChannel = strtoupper(trim($reportChannel));
        if (!in_array($normalizedReportChannel, [AIConversationSchedule::REPORT_CHANNEL_CHAT, AIConversationSchedule::REPORT_CHANNEL_EMAIL], true)) {
            throw new BadRequestException("Unknown reportChannel '{$normalizedReportChannel}' — use EMAIL or CHAT.");
        }
        return $normalizedReportChannel;
    }

    /** Whether `$timezoneIdentifier` is an IANA identifier PHP knows. */
    public static function isKnownTimezoneIdentifier(string $timezoneIdentifier): bool
    {
        return in_array($timezoneIdentifier, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * @throws BadRequestException for an identifier PHP does not know
     */
    public static function assertKnownTimezoneIdentifier(string $timezoneIdentifier): void
    {
        if (!self::isKnownTimezoneIdentifier($timezoneIdentifier)) {
            throw new BadRequestException("Unknown time zone '{$timezoneIdentifier}' — use an IANA identifier such as Europe/Berlin.");
        }
    }

    /**
     * How often the schedule's cron fires within the day after `$now`, in the schedule's zone. An unparseable
     * expression counts 0 — the cron validation of the create / edit answers it with its own message.
     */
    public static function countCronFiringsPerDay(AIConversationSchedule $schedule, DateTime $now): int
    {
        try {
            return $schedule->countCronFiringsWithinDays($now, 1, countCap: self::CRON_FIRINGS_COUNT_CAP);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function exceedsCronFiringsPerDay(int $cronFiringsPerDay): bool
    {
        return $cronFiringsPerDay > self::MAX_CRON_FIRINGS_PER_DAY;
    }

    public static function isActiveScheduleCapReached(int $activeScheduleCount): bool
    {
        return $activeScheduleCount >= self::MAX_ACTIVE_SCHEDULES_PER_AGENT_SPACE;
    }

    public static function isDailyRecurringCreationCapReached(int $recurringSchedulesCreatedToday): bool
    {
        return $recurringSchedulesCreatedToday >= self::MAX_RECURRING_SCHEDULES_CREATED_PER_DAY;
    }

    /**
     * Where the daily creation count starts: midnight of `$now`'s day on the application clock (the PHP default zone),
     * not the midnight of the agent space's time zone.
     */
    public static function getStartOfCreationDay(DateTime $now): DateTime
    {
        return DateTime::fromTimestamp((int)strtotime('today', $now->getTimestamp()));
    }

    public static function isMaxRunsWithinRange(int $maxRuns): bool
    {
        return $maxRuns >= self::MAX_RUNS_LOWEST && $maxRuns <= self::MAX_RUNS_HIGHEST;
    }
}
