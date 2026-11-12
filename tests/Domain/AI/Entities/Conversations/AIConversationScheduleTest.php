<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The PURE methods on {@see AIConversationSchedule}: next cron occurrence,
 * failure backoff (2^streak occurrences, capped), the expiry predicate, the run-settings composition for a
 * firing, and the seed-user-message composition. In-memory, no DB, no cron runner.
 */
class AIConversationScheduleTest extends UnitTestCase
{
    protected function schedule(string $cronExpression): AIConversationSchedule
    {
        $schedule = self::instantiateWithoutConstructor(AIConversationSchedule::class);
        $schedule->schedule = $cronExpression;
        return $schedule;
    }

    public function testComputeNextRunAtReturnsTheNextCronOccurrence(): void
    {
        $hourlySchedule = $this->schedule('0 * * * *');
        $nextRunAt = $hourlySchedule->computeNextRunAt(new DateTime('2026-07-23 10:15:00'));
        self::assertSame('2026-07-23 11:00:00', $nextRunAt->format('Y-m-d H:i:s'));
    }

    public function testFailureBackoffSkipsExponentiallyManyOccurrencesCapped(): void
    {
        $hourlySchedule = $this->schedule('0 * * * *');
        $from = new DateTime('2026-07-23 10:15:00');

        $hourlySchedule->failureStreak = 1; // 2^1 = 2 occurrences → 12:00
        self::assertSame('2026-07-23 12:00:00', $hourlySchedule->computeNextRunAtWithFailureBackoff($from)->format('Y-m-d H:i:s'));

        $hourlySchedule->failureStreak = 3; // 2^3 = 8 occurrences → 18:00
        self::assertSame('2026-07-23 18:00:00', $hourlySchedule->computeNextRunAtWithFailureBackoff($from)->format('Y-m-d H:i:s'));

        $hourlySchedule->failureStreak = 99; // capped at 2^5 = 32 occurrences → +32h from 11:00 = next day 18:00
        self::assertSame('2026-07-24 18:00:00', $hourlySchedule->computeNextRunAtWithFailureBackoff($from)->format('Y-m-d H:i:s'));
    }

    public function testExpiryByTtlAndByMaxIterations(): void
    {
        $now = new DateTime('2026-07-23 10:00:00');

        $freshSchedule = $this->schedule('0 7 * * 1');
        self::assertFalse($freshSchedule->isExpired($now)); // no ttl, no iteration cap

        $expiredByTtl = $this->schedule('0 7 * * 1');
        $expiredByTtl->ttlAt = new DateTime('2026-07-23 09:59:59');
        self::assertTrue($expiredByTtl->isExpired($now));

        $expiredByIterations = $this->schedule('0 7 * * 1');
        $expiredByIterations->maxIterations = 3;
        $expiredByIterations->iterationsUsed = 3;
        self::assertTrue($expiredByIterations->isExpired($now));
        $expiredByIterations->iterationsUsed = 2;
        self::assertFalse($expiredByIterations->isExpired($now));
    }

    public function testBuildRunSettingsForRunFoldsThePerRunCostCapIntoTheTurnCostCap(): void
    {
        $bareSchedule = $this->schedule('0 7 * * 1');
        self::assertNull($bareSchedule->buildRunSettingsForRun()); // nothing set → plan-adaptive defaults

        $costCappedSchedule = $this->schedule('0 7 * * 1');
        $costCappedSchedule->perRunCostCapUsd = 0.75;
        $runSettingsForRun = $costCappedSchedule->buildRunSettingsForRun();
        self::assertSame(0.75, $runSettingsForRun->maxTurnCostUsd);
        self::assertNull($runSettingsForRun->maxStepsPerTurn);

        $explicitSchedule = $this->schedule('0 7 * * 1');
        $explicitRunSettings = self::instantiateWithoutConstructor(RunSettings::class);
        $explicitRunSettings->maxStepsPerTurn = 40;
        $explicitRunSettings->maxTurnCostUsd = 1.25;
        $explicitSchedule->runSettings = $explicitRunSettings;
        $explicitSchedule->perRunCostCapUsd = 0.10; // an explicit turn cost cap WINS over the per-run fold
        $explicitRunSettingsForRun = $explicitSchedule->buildRunSettingsForRun();
        self::assertSame(40, $explicitRunSettingsForRun->maxStepsPerTurn);
        self::assertSame(1.25, $explicitRunSettingsForRun->maxTurnCostUsd);
        self::assertNotSame($explicitRunSettings, $explicitRunSettingsForRun); // a NEW composite, never the stored VO
    }

    public function testARunOfAScheduleCreatedInsideAnEvalRunCarriesTheEvalMarker(): void
    {
        $bareSchedule = $this->schedule('0 7 * * 1');
        $evalRunSettingsForRun = $bareSchedule->buildRunSettingsForRun(isCreatedByAnEvalRun: true);
        self::assertTrue($evalRunSettingsForRun->evalRun, 'a schedule that sets nothing still hands the marker on');
        self::assertNull($evalRunSettingsForRun->maxTurnCostUsd);

        $costCappedSchedule = $this->schedule('0 7 * * 1');
        $costCappedSchedule->perRunCostCapUsd = 0.75;
        $costCappedEvalRunSettingsForRun = $costCappedSchedule->buildRunSettingsForRun(isCreatedByAnEvalRun: true);
        self::assertTrue($costCappedEvalRunSettingsForRun->evalRun);
        self::assertSame(0.75, $costCappedEvalRunSettingsForRun->maxTurnCostUsd);
        self::assertNull($costCappedSchedule->buildRunSettingsForRun()->evalRun, 'a customer schedule never marks its runs');

        $storedEvalSchedule = $this->schedule('0 7 * * 1');
        $storedRunSettings = self::instantiateWithoutConstructor(RunSettings::class);
        $storedRunSettings->evalRun = true;
        $storedEvalSchedule->runSettings = $storedRunSettings;
        self::assertTrue($storedEvalSchedule->buildRunSettingsForRun()->evalRun, 'a marker stored on the schedule itself is handed on');
    }

    public function testComposeSeedUserMessageTextAppendsThePreviousRunOutcomeLine(): void
    {
        $schedule = $this->schedule('0 7 * * 1');
        $schedule->task = 'Check the new reviews and draft replies.';

        self::assertSame(
            'Check the new reviews and draft replies.',
            $schedule->composeSeedUserMessageText(null, null) // first firing — no previous run
        );
        self::assertSame(
            'Check the new reviews and draft replies.',
            $schedule->composeSeedUserMessageText('   ', new DateTime('2026-07-16 07:00:00')) // blank outcome → no line
        );
        self::assertSame(
            "Check the new reviews and draft replies.\n\nLast run (2026-07-16) result: Replied to 2 reviews.",
            $schedule->composeSeedUserMessageText('Replied to 2 reviews.', new DateTime('2026-07-16 07:00:00'))
        );
        self::assertSame(
            "Check the new reviews and draft replies.\n\nLast run (earlier) result: Replied to 2 reviews.",
            $schedule->composeSeedUserMessageText('Replied to 2 reviews.', null)
        );
        // the delivery contract is the Schedule.Run purpose's, never the seed's — an EMAIL schedule seeds the same text
        $schedule->reportChannel = AIConversationSchedule::REPORT_CHANNEL_EMAIL;
        self::assertSame(
            "Check the new reviews and draft replies.\n\nLast run (earlier) result: Replied to 2 reviews.",
            $schedule->composeSeedUserMessageText('Replied to 2 reviews.', null)
        );
    }

    protected function oneTimeSchedule(string $oneTimeAt): AIConversationSchedule
    {
        $schedule = self::instantiateWithoutConstructor(AIConversationSchedule::class);
        $schedule->oneTimeAt = new DateTime($oneTimeAt);
        return $schedule;
    }

    public function testOneTimeScheduleFiresAtItsFixedMomentRegardlessOfBackoff(): void
    {
        $oneTimeSchedule = $this->oneTimeSchedule('2026-08-07 15:30:00');
        self::assertTrue($oneTimeSchedule->isOneTime());
        self::assertFalse($this->schedule('0 * * * *')->isOneTime());

        $from = new DateTime('2026-08-07 10:00:00');
        self::assertSame('2026-08-07 15:30:00', $oneTimeSchedule->computeNextRunAt($from)->format('Y-m-d H:i:s'));
        $oneTimeSchedule->failureStreak = 4; // backoff has no later occurrence to skip to on a one-time task
        self::assertSame('2026-08-07 15:30:00', $oneTimeSchedule->computeNextRunAtWithFailureBackoff($from)->format('Y-m-d H:i:s'));
    }

    public function testDeleteActionFollowsTheArchivePolicy(): void
    {
        // Never ran → hard delete, one-time and recurring alike ("bitte doch nicht anrufen heute").
        $pendingOneTime = $this->oneTimeSchedule('2026-08-07 15:30:00');
        $pendingOneTime->iterationsUsed = 0;
        self::assertSame(AIConversationSchedule::DELETE_ACTION_HARD_DELETE, $pendingOneTime->getDeleteAction());

        $neverFiredRecurring = $this->schedule('0 7 * * 1');
        $neverFiredRecurring->iterationsUsed = 0;
        self::assertSame(AIConversationSchedule::DELETE_ACTION_HARD_DELETE, $neverFiredRecurring->getDeleteAction());

        // Recurring with executions → archived ("ich will den täglichen Report nicht mehr").
        $ranRecurring = $this->schedule('0 7 * * 1');
        $ranRecurring->iterationsUsed = 5;
        self::assertSame(AIConversationSchedule::DELETE_ACTION_ARCHIVE, $ranRecurring->getDeleteAction());

        // One-time that already ran → history stays, nothing happens.
        $ranOneTime = $this->oneTimeSchedule('2026-08-06 15:30:00');
        $ranOneTime->iterationsUsed = 1;
        self::assertSame(AIConversationSchedule::DELETE_ACTION_KEEP_HISTORY, $ranOneTime->getDeleteAction());
    }
}
