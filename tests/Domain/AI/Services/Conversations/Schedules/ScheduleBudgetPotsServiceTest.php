<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Schedules;

use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Entities\Conversations\Schedules\ScheduleBudgetPotStatus;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\TestCase;

/**
 * The package's schedule budget pot without an application's budget: a zero pot that is never exhausted; with the
 * override points it is `budget × share`, a budget lift reports the zero pot, and a non-eligible account reads a pot
 * of 0. Runs inside an application (DDD_AGENTS_APP_TEST_BOOTSTRAP); no case reaches the spend query.
 */
class ScheduleBudgetPotsServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $aIUsageEnvelopesService->reset();
        parent::tearDown();
    }

    protected static function account(): Account
    {
        /** @var Account $account */
        $account = (new \ReflectionClass(Account::class))->newInstanceWithoutConstructor();
        $account->id = 31;
        return $account;
    }

    public function testWithoutABudgetThePotIsZeroAndNeverExhausted(): void
    {
        $scheduleBudgetPotsService = new ScheduleBudgetPotsService();
        $scheduleBudgetPotStatus = $scheduleBudgetPotsService->findScheduleBudgetPotStatusForAccount(self::account());

        self::assertInstanceOf(ScheduleBudgetPotStatus::class, $scheduleBudgetPotStatus);
        self::assertSame(0.0, $scheduleBudgetPotStatus->monthlyPotUsd);
        self::assertSame(0.0, $scheduleBudgetPotStatus->spentUsd);
        self::assertFalse($scheduleBudgetPotStatus->isExhausted());
        self::assertNull($scheduleBudgetPotStatus->getShareOfPotUsed());
        self::assertSame('', $scheduleBudgetPotStatus->buildAgentFacingSummary());
        self::assertSame(0.0, $scheduleBudgetPotsService->findMonthlyBudgetForAccount(self::account()));
        self::assertFalse($scheduleBudgetPotsService->hidesExactFiguresForAccount(self::account()));
    }

    public function testABudgetLiftReportsTheZeroPot(): void
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $aIUsageEnvelopesService->grantBudgetBypassForTheProcess(AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONSOLE_OPTION));

        $scheduleBudgetPotStatus = (new ScheduleBudgetPotsWithoutShareService())->findScheduleBudgetPotStatusForAccount(self::account());

        self::assertSame(0, $scheduleBudgetPotStatus->potSharePercent, 'nothing was read under the lift');
        self::assertFalse($scheduleBudgetPotStatus->hideExactFigures);
        self::assertSame(0.0, $scheduleBudgetPotStatus->monthlyPotUsd);
    }

    public function testTheOverridePointsShapeThePotAndANonEligibleAccountReadsZero(): void
    {
        $scheduleBudgetPotStatus = (new ScheduleBudgetPotsWithoutShareService())->findScheduleBudgetPotStatusForAccount(self::account());
        self::assertSame(0, $scheduleBudgetPotStatus->potSharePercent, 'share clamped from -5 to 0');
        self::assertTrue($scheduleBudgetPotStatus->hideExactFigures);
        self::assertSame(0.0, $scheduleBudgetPotStatus->monthlyPotUsd, 'a share of 0 caps nothing, the spend is not read');

        $notEligibleScheduleBudgetPotsService = new ScheduleBudgetPotsOfANonEligibleAccountService();
        $notEligibleStatus = $notEligibleScheduleBudgetPotsService->findScheduleBudgetPotStatusForAccount(self::account());
        self::assertTrue($notEligibleStatus->isScopeNotEligible());
        self::assertSame(0.0, $notEligibleStatus->monthlyPotUsd);
        self::assertSame($notEligibleStatus->eligibility->describeForModel(), $notEligibleStatus->buildAgentFacingSummary());
    }

    public function testTheMonthStartIsTheFirstSecondOfTheMonth(): void
    {
        self::assertSame(date('Y-m-01 00:00:00'), ScheduleBudgetPotsService::getCurrentCalendarMonthStartSqlDateTime());
    }
}
