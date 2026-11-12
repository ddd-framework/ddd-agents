<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The ONE occurrence policy of the four cron evaluators with a schedule-owned time zone: cron digits are
 * local wall-clock time, instants are compared by timestamp, the repeated fall-back hour fires once, a spring-gap
 * occurrence is shifted forward, and a legacy row (no zone) keeps evaluating in UTC. Pure, no DB.
 */
class AIConversationScheduleTimezoneTest extends UnitTestCase
{
    protected function schedule(string $cronExpression, ?string $timezone): AIConversationSchedule
    {
        $schedule = self::instantiateWithoutConstructor(AIConversationSchedule::class);
        $schedule->schedule = $cronExpression;
        $schedule->timezone = $timezone;
        return $schedule;
    }

    protected static function utc(string $moment): DateTime
    {
        return DateTime::fromTimestamp((new \DateTimeImmutable($moment, new \DateTimeZone('UTC')))->getTimestamp());
    }

    protected static function local(DateTime $instant, string $timezone): string
    {
        return (clone $instant)->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i:sP');
    }

    public function testCronDigitsAreLocalTimeOfTheSchedulesZone(): void
    {
        $newYorkDaily = $this->schedule('0 8 * * *', 'America/New_York');
        $nextRunAt = $newYorkDaily->computeNextRunAt(self::utc('2026-07-01 10:00:00'));
        self::assertSame('2026-07-01 08:00:00-04:00', self::local($nextRunAt, 'America/New_York'));
        self::assertSame('2026-07-01 12:00:00+00:00', self::local($nextRunAt, 'UTC'));

        $tokyoDaily = $this->schedule('0 8 * * *', 'Asia/Tokyo');
        $nextRunAt = $tokyoDaily->computeNextRunAt(self::utc('2026-07-01 10:00:00'));
        self::assertSame('2026-07-02 08:00:00+09:00', self::local($nextRunAt, 'Asia/Tokyo'));
    }

    public function testLegacyRowWithoutZoneKeepsEvaluatingInUtc(): void
    {
        $legacy = $this->schedule('0 8 * * *', null);
        self::assertSame('UTC', $legacy->getCronTimezoneIdentifier());
        $nextRunAt = $legacy->computeNextRunAt(self::utc('2026-07-01 10:00:00'));
        self::assertSame('2026-07-02 08:00:00+00:00', self::local($nextRunAt, 'UTC'));
    }

    public function testRepeatedFallBackHourFiresOnlyOnceInNewYork(): void
    {
        // 01:30 EDT (05:30Z) and 01:30 EST (06:30Z) both exist — the second instance is skipped.
        $halfHourly = $this->schedule('*/30 * * * *', 'America/New_York');
        $occurrences = $halfHourly->computeCronOccurrencesWithinDays(self::utc('2026-11-01 04:45:00'), 1, countCap: 6);
        $rendered = array_map(static fn (DateTime $occurrence): string => self::local($occurrence, 'America/New_York'), $occurrences);
        self::assertSame(
            [
                '2026-11-01 01:00:00-04:00',
                '2026-11-01 01:30:00-04:00',
                // 01:00-05:00 and 01:30-05:00 render the same local digits as the two instants an hour earlier → skipped
                '2026-11-01 02:00:00-05:00',
                '2026-11-01 02:30:00-05:00',
                '2026-11-01 03:00:00-05:00',
                '2026-11-01 03:30:00-05:00',
            ],
            $rendered,
            'the SECOND instance of an ambiguous local slot never fires'
        );
    }

    public function testRepeatedFallBackHourSkipsTheSecondInstanceOfTheSameLocalMinute(): void
    {
        // Daily at 01:30 local on the fall-back day in Sydney (03:00 AEDT → 02:00 AEST): 02:30 exists twice.
        $sydneyDaily = $this->schedule('30 2 * * *', 'Australia/Sydney');
        $nextRunAt = $sydneyDaily->computeNextRunAt(self::utc('2026-04-04 14:00:00')); // 2026-04-05 01:00 AEDT
        self::assertSame('2026-04-05 02:30:00+11:00', self::local($nextRunAt, 'Australia/Sydney'));
        $afterThat = $sydneyDaily->computeNextRunAt($nextRunAt);
        self::assertSame('2026-04-06 02:30:00+10:00', self::local($afterThat, 'Australia/Sydney'), 'the repeated 02:30 AEST instance is not a second firing');
    }

    public function testSpringGapOccurrenceIsShiftedForward(): void
    {
        // 2026-03-08 in New York: 02:30 does not exist — the library shifts it to 03:30 EDT, taken by timestamp.
        $newYorkDaily = $this->schedule('30 2 * * *', 'America/New_York');
        $nextRunAt = $newYorkDaily->computeNextRunAt(self::utc('2026-03-08 04:00:00')); // 23:00 EST the evening before
        self::assertSame('2026-03-08 03:30:00-04:00', self::local($nextRunAt, 'America/New_York'));
        $afterThat = $newYorkDaily->computeNextRunAt($nextRunAt);
        self::assertSame('2026-03-09 02:30:00-04:00', self::local($afterThat, 'America/New_York'));
    }

    public function testCountAndAgendaAgreeWithNextRunAcrossTheSwitch(): void
    {
        $berlinSixHourly = $this->schedule('0 */6 * * *', 'Europe/Berlin');
        $from = self::utc('2026-10-24 12:00:00'); // the fall-back weekend (2026-10-25 03:00 CEST → 02:00 CET)
        $occurrences = $berlinSixHourly->computeCronOccurrencesWithinDays($from, 2, countCap: 50);
        self::assertSame(8, $berlinSixHourly->countCronFiringsWithinDays($from, 2, countCap: 50));
        self::assertSame(self::local($occurrences[0], 'UTC'), self::local($berlinSixHourly->computeNextRunAt($from), 'UTC'));
        $cursor = $from;
        foreach ($occurrences as $occurrence) {
            self::assertGreaterThan($cursor->getTimestamp(), $occurrence->getTimestamp(), 'strictly increasing instants');
            $cursor = $occurrence;
        }
    }

    public function testFailureBackoffSkipsOccurrencesInTheZone(): void
    {
        $newYorkDaily = $this->schedule('0 8 * * *', 'America/New_York');
        $newYorkDaily->failureStreak = 1; // 2^1 = 2 occurrences → the day after tomorrow
        $nextRunAt = $newYorkDaily->computeNextRunAtWithFailureBackoff(self::utc('2026-07-01 10:00:00'));
        self::assertSame('2026-07-02 08:00:00-04:00', self::local($nextRunAt, 'America/New_York'));
    }

    public function testOneTimeScheduleReturnsItsInstantRegardlessOfZone(): void
    {
        $oneTime = self::instantiateWithoutConstructor(AIConversationSchedule::class);
        $oneTime->timezone = 'Asia/Tokyo';
        $oneTime->oneTimeAt = self::utc('2026-07-01 10:00:00');
        self::assertSame('2026-07-01 10:00:00+00:00', self::local($oneTime->computeNextRunAt(self::utc('2026-06-01 00:00:00')), 'UTC'));
    }
}
