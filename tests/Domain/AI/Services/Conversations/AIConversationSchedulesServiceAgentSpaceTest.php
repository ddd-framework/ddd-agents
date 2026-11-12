<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/**
 * A schedule with and without a space: which space a new schedule is stored for, which account owns it, which
 * eligibility judges it, and that no edit moves it to another space. A schedule WITHOUT a space is a regular case
 * (account level) — and a space that is named but missing is never read as "no space".
 */
class AIConversationSchedulesServiceAgentSpaceTest extends UnitTestCase
{
    protected const int OWNER_OF_THE_SPACE_ACCOUNT_ID = 12;

    protected const int SUB_USER_ACCOUNT_ID = 13;

    protected const int ACCOUNT_SET_BY_CREATOR_ID = 14;

    public function testANewScheduleWithoutASpaceIsAtAccountLevel(): void
    {
        $schedule = new AIConversationSchedule();

        self::assertNull($this->invoke(new AIConversationSchedulesService(), 'findAgentSpaceOfNewSchedule', $schedule));
        self::assertNull($schedule->agentSpaceId ?? null);
    }

    public function testANewScheduleIsStoredForTheSpaceItsCreatorAttached(): void
    {
        $agentSpace = $this->buildStoredAgentSpace(7);
        $schedule = new AIConversationSchedule();
        $schedule->agentSpace = $agentSpace;

        self::assertSame($agentSpace, $this->invoke(new AIConversationSchedulesService(), 'findAgentSpaceOfNewSchedule', $schedule));
        self::assertSame(7, $schedule->agentSpaceId);
    }

    public function testTheStoredIdFollowsTheAttachedSpaceTheChecksRanFor(): void
    {
        $schedule = new AIConversationSchedule();
        $schedule->agentSpaceId = 99;
        $schedule->agentSpace = $this->buildStoredAgentSpace(7);

        $this->invoke(new AIConversationSchedulesService(), 'findAgentSpaceOfNewSchedule', $schedule);

        self::assertSame(7, $schedule->agentSpaceId);
    }

    public function testASpaceThatIsNotStoredIsNeverReadAsNoSpace(): void
    {
        $schedule = new AIConversationSchedule();
        $schedule->agentSpace = new AgentSpace();

        $this->expectException(InternalErrorException::class);
        $this->invoke(new AIConversationSchedulesService(), 'findAgentSpaceOfNewSchedule', $schedule);
    }

    public function testAScheduleOfASpaceNamesTheOwnerOfTheSpace(): void
    {
        $agentSpace = $this->buildStoredAgentSpace(7);
        $agentSpace->account = $this->buildAccount(self::OWNER_OF_THE_SPACE_ACCOUNT_ID);
        $schedule = new AIConversationSchedule();
        $schedule->account = $this->buildAccount(self::ACCOUNT_SET_BY_CREATOR_ID);

        $accountNamedForSchedule = $this->invoke(new AIConversationSchedulesService(), 'findAccountNamedForNewSchedule', $schedule, $agentSpace);

        self::assertSame(self::OWNER_OF_THE_SPACE_ACCOUNT_ID, $accountNamedForSchedule?->id);
    }

    public function testAScheduleWithoutASpaceNamesTheAccountItsCreatorSet(): void
    {
        $schedule = new AIConversationSchedule();
        $schedule->account = $this->buildAccount(self::ACCOUNT_SET_BY_CREATOR_ID);
        $scheduleWithoutAnAccount = new AIConversationSchedule();
        $schedulesService = new AIConversationSchedulesService();

        self::assertSame(self::ACCOUNT_SET_BY_CREATOR_ID, $this->invoke($schedulesService, 'findAccountNamedForNewSchedule', $schedule, null)?->id);
        self::assertNull($this->invoke($schedulesService, 'findAccountNamedForNewSchedule', $scheduleWithoutAnAccount, null));
    }

    public function testASpaceWithoutAnOwnerFallsBackToTheAccountTheCreatorSet(): void
    {
        $agentSpaceWithoutAnOwner = $this->buildStoredAgentSpace(7);
        $agentSpaceWithoutAnOwner->account = null;
        $schedule = new AIConversationSchedule();
        $schedule->account = $this->buildAccount(self::ACCOUNT_SET_BY_CREATOR_ID);

        $accountNamedForSchedule = $this->invoke(new AIConversationSchedulesService(), 'findAccountNamedForNewSchedule', $schedule, $agentSpaceWithoutAnOwner);

        self::assertSame(self::ACCOUNT_SET_BY_CREATOR_ID, $accountNamedForSchedule?->id);
    }

    public function testACustomerSideScheduleBelongsToTheSignedInAccountWhateverTheScheduleNames(): void
    {
        $namedAccount = $this->buildAccount(self::OWNER_OF_THE_SPACE_ACCOUNT_ID);

        self::assertSame(
            self::SUB_USER_ACCOUNT_ID,
            $this->resolveOwnerAccountId(AIConversationSchedule::INITIATOR_TYPE_AGENT, self::SUB_USER_ACCOUNT_ID, $namedAccount)
        );
        self::assertSame(
            self::SUB_USER_ACCOUNT_ID,
            $this->resolveOwnerAccountId(AIConversationSchedule::INITIATOR_TYPE_USER, self::SUB_USER_ACCOUNT_ID, $namedAccount)
        );
        // without a space and without a named account the signed-in account is still the owner
        self::assertSame(
            self::SUB_USER_ACCOUNT_ID,
            $this->resolveOwnerAccountId(AIConversationSchedule::INITIATOR_TYPE_USER, self::SUB_USER_ACCOUNT_ID, null)
        );
    }

    public function testAnAdminCreatedScheduleBelongsToTheNamedAccountNeverToTheAdmin(): void
    {
        self::assertSame(
            self::OWNER_OF_THE_SPACE_ACCOUNT_ID,
            $this->resolveOwnerAccountId(AIConversationSchedule::INITIATOR_TYPE_ADMIN, 777, $this->buildAccount(self::OWNER_OF_THE_SPACE_ACCOUNT_ID))
        );
        self::assertNull($this->resolveOwnerAccountId(AIConversationSchedule::INITIATOR_TYPE_ADMIN, 777, null));
    }

    public function testWithoutASignedInAccountTheNamedAccountOwnsTheSchedule(): void
    {
        self::assertSame(
            self::OWNER_OF_THE_SPACE_ACCOUNT_ID,
            $this->resolveOwnerAccountId(AIConversationSchedule::INITIATOR_TYPE_AGENT, null, $this->buildAccount(self::OWNER_OF_THE_SPACE_ACCOUNT_ID))
        );
        self::assertNull($this->resolveOwnerAccountId(AIConversationSchedule::INITIATOR_TYPE_AGENT, null, null));
    }

    public function testAnEditThatNamesTheSpaceIsRefused(): void
    {
        $schedule = new AIConversationSchedule();
        $schedule->id = 5;
        $schedule->status = AIConversationScheduleStatus::ACTIVE;
        $schedule->agentSpaceId = 8;

        $this->expectException(BadRequestException::class);
        (new AIConversationSchedulesService())->persistScheduleEdit($schedule, ['task', 'agentSpaceId']);
    }

    public function testAStoredScheduleWithoutASpaceHasNoSpaceToLoad(): void
    {
        $schedule = new AIConversationSchedule();
        $schedule->accountId = 34;

        self::assertNull($this->invoke(new AIConversationSchedulesService(), 'findAgentSpaceOfSchedule', $schedule));
    }

    public function testAnAttachedSpaceOfAStoredScheduleIsTakenAsItIs(): void
    {
        $agentSpace = $this->buildStoredAgentSpace(7);
        $schedule = new AIConversationSchedule();
        $schedule->agentSpaceId = 7;
        $schedule->agentSpace = $agentSpace;

        self::assertSame($agentSpace, $this->invoke(new AIConversationSchedulesService(), 'findAgentSpaceOfSchedule', $schedule));
    }

    public function testAScheduleCanNeverRunWhenTheSpaceItNamesIsGone(): void
    {
        $scheduleOfAMissingSpace = new AIConversationSchedule();
        $scheduleOfAMissingSpace->agentSpaceId = 7;
        $scheduleWithoutASpace = new AIConversationSchedule();
        $scheduleWithoutASpace->accountId = 34;
        $schedulesService = new AIConversationSchedulesService();

        self::assertTrue($this->invoke($schedulesService, 'isScheduleUnableToRunForLackOfAgentSpace', $scheduleOfAMissingSpace, null));
        self::assertFalse($this->invoke($schedulesService, 'isScheduleUnableToRunForLackOfAgentSpace', $scheduleOfAMissingSpace, $this->buildStoredAgentSpace(7)));
        // without a space: the application's decision, which the default leaves open (a run at account level)
        self::assertFalse($this->invoke($schedulesService, 'isScheduleUnableToRunForLackOfAgentSpace', $scheduleWithoutASpace, null));
    }

    public function testAScheduleIsJudgedByItsSpaceAndWithoutOneByItsAccount(): void
    {
        $schedule = new AIConversationSchedule();
        $schedule->accountId = 34;
        $schedulesService = new AIConversationSchedulesService();

        /** @var AgentAutomationEligibility $eligibilityAtAccountLevel */
        $eligibilityAtAccountLevel = $this->invoke($schedulesService, 'findEligibilityOfSchedule', $schedule, null);
        /** @var AgentAutomationEligibility $eligibilityOfTheSpace */
        $eligibilityOfTheSpace = $this->invoke($schedulesService, 'findEligibilityOfSchedule', $schedule, $this->buildStoredAgentSpace(7));

        // the default of the account question names the account it evaluated, the default of the space question none
        self::assertTrue($eligibilityAtAccountLevel->isEligible());
        self::assertSame(34, $eligibilityAtAccountLevel->accountId);
        self::assertTrue($eligibilityOfTheSpace->isEligible());
        self::assertNull($eligibilityOfTheSpace->accountId);
    }

    protected function resolveOwnerAccountId(string $initiatorType, ?int $authenticatedAccountId, ?Account $accountNamedForSchedule): ?int
    {
        $schedulesServiceStub = new AuthenticatedAccountSchedulesServiceStub();
        $schedulesServiceStub->authenticatedAccount = $authenticatedAccountId !== null ? $this->buildAccount($authenticatedAccountId) : null;
        $schedule = new AIConversationSchedule();
        $schedule->initiatorType = $initiatorType;
        /** @var Account|null $ownerAccount */
        $ownerAccount = $this->invoke($schedulesServiceStub, 'resolveScheduleOwnerAccount', $schedule, $accountNamedForSchedule);
        return $ownerAccount !== null ? (int)$ownerAccount->id : null;
    }

    protected function invoke(AIConversationSchedulesService $schedulesService, string $methodName, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(AIConversationSchedulesService::class, $methodName))->invoke($schedulesService, ...$arguments);
    }

    protected function buildStoredAgentSpace(int $id): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $id;
        return $agentSpace;
    }

    protected function buildAccount(int $id): Account
    {
        $account = new Account();
        $account->id = $id;
        return $account;
    }
}
