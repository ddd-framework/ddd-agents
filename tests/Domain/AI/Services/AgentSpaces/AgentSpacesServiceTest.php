<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Repo\DB\Attributes\DatabaseTranslation;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The defaults of the one place the core asks about a space: who may use a space, what a conversation with a space
 * answers, and that a conversation WITHOUT a space has an answer everywhere (never an exception for the missing space).
 */
class AgentSpacesServiceTest extends UnitTestCase
{
    public function testTheAccountOfASpaceMayUseIt(): void
    {
        $this->expectNotToPerformAssertions();
        (new AgentSpacesService())->requireAgentSpaceUsableByAccount($this->buildAgentSpaceOfAccount(12), $this->buildAccount(12, Role::LOGIN));
    }

    public function testAnotherAccountMayNotUseTheSpace(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        (new AgentSpacesService())->requireAgentSpaceUsableByAccount($this->buildAgentSpaceOfAccount(12), $this->buildAccount(13, Role::LOGIN));
    }

    public function testAnAdminWithAccessToAllResourcesMayUseEverySpace(): void
    {
        $this->expectNotToPerformAssertions();
        (new AgentSpacesService())->requireAgentSpaceUsableByAccount($this->buildAgentSpaceOfAccount(12), $this->buildAccount(99, Role::ADMIN));
    }

    public function testASpaceWithoutAnAccountIsUsableByNoOneUntilTheApplicationDecides(): void
    {
        $this->expectException(ForbiddenException::class);
        (new AgentSpacesService())->requireAgentSpaceUsableByAccount(new AgentSpace(), $this->buildAccount(12, Role::LOGIN));
    }

    public function testAConversationAnswersFromItsSpace(): void
    {
        $agentSpace = $this->buildAgentSpaceOfAccount(12);
        $agentSpace->timezone = 'Europe/Berlin';
        $conversation = new AIConversation();
        $conversation->agentSpace = $agentSpace;
        $agentSpacesService = new AgentSpacesService();

        $agentSpaceTimezone = $agentSpacesService->findTimezoneOfConversation($conversation);

        self::assertNotNull($agentSpaceTimezone);
        self::assertSame('Europe/Berlin', $agentSpaceTimezone->identifier);
        self::assertFalse($agentSpaceTimezone->unverified);
        self::assertNull($agentSpacesService->findBillingEntityOfConversation($conversation));
        self::assertNull($agentSpacesService->findLoadedBillingEntityOfConversation($conversation));
    }

    public function testAConversationWithoutASpaceHasAnAnswerEverywhere(): void
    {
        $conversation = new AIConversation();
        $conversation->agentSpace = null;
        $agentSpacesService = new AgentSpacesService();

        self::assertNull($agentSpacesService->findTimezoneOfConversation($conversation));
        self::assertNull($agentSpacesService->findBillingEntityOfConversation($conversation));
        self::assertNull($agentSpacesService->findLoadedBillingEntityOfConversation($conversation));
        self::assertSame(DatabaseTranslation::getDefaultLanguageCode(), $agentSpacesService->resolveUserLanguageCode($conversation));
        self::assertSame(DatabaseTranslation::getDefaultLanguageCode(), $agentSpacesService->resolveLocaleString($conversation));
        self::assertNull($agentSpacesService->buildOwnerAddressingInstruction($conversation));
        self::assertSame([], $agentSpacesService->buildStoredFactLines($conversation));
        self::assertTrue($agentSpacesService->isCrossConversationRecallEnabled($conversation));
        self::assertNull($agentSpacesService->buildAgentSpaceContextPromptSegment($conversation));
        self::assertNull($agentSpacesService->buildConversationSummaryAgentSpaceContextPromptSegment($conversation));
        self::assertSame('model-a', $agentSpacesService->findDefaultModelNameOfConversation($conversation, 'model-a'));
        self::assertNull($agentSpacesService->findPromptExtensionAliasOfConversation($conversation));
        self::assertNull($agentSpacesService->findProductNameOfConversation($conversation));
        self::assertNull($agentSpacesService->findProductOperatorNameOfConversation($conversation));
    }

    public function testTheConfirmedLanguageOfAConversationWinsOverTheDefault(): void
    {
        $conversation = new AIConversation();
        $conversation->agentSpace = null;
        $conversation->getOrCreateAgentWorkingState()->interfaceLanguageConfirmedCode = 'it';

        self::assertSame('it', (new AgentSpacesService())->resolveUserLanguageCode($conversation));
    }

    public function testWorkWithoutASpaceIsAllowedByDefault(): void
    {
        $this->expectNotToPerformAssertions();
        $agentSpacesService = new AgentSpacesService();
        $agentSpacesService->requireConversationStartWithoutAgentSpaceAllowed($this->buildAccount(12, Role::LOGIN), null, null);
        $agentSpacesService->requireConversationStartWithoutAgentSpaceAllowed($this->buildAccount(12, Role::LOGIN), 'Some.Purpose', 'some-entry-point');
        $schedule = new AIConversationSchedule();
        $schedule->accountId = 12;
        $schedule->agentSpace = null;
        $agentSpacesService->requireScheduleWithoutAgentSpaceAllowed($schedule);
    }

    public function testAnApplicationThatAllowsNoScheduleWithoutASpaceRefusesItsCreationWithItsOwnText(): void
    {
        $schedule = new AIConversationSchedule();
        $schedule->accountId = 12;
        self::assertTrue((new AgentSpacesService())->isScheduleWithoutAgentSpaceAllowed($schedule));

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('A schedule needs a shop.');
        (new AgentSpacesServiceRefusingSchedulesWithoutAgentSpaceStub())->requireScheduleWithoutAgentSpaceAllowed($schedule);
    }

    public function testANewScheduleTakesTheZoneOfItsSpaceAndNoneWithoutASpace(): void
    {
        $agentSpace = $this->buildAgentSpaceOfAccount(12);
        $agentSpace->timezone = 'Asia/Tokyo';
        $scheduleOfASpace = new AIConversationSchedule();
        $scheduleOfASpace->agentSpace = $agentSpace;
        $scheduleWithoutASpace = new AIConversationSchedule();
        $scheduleWithoutASpace->agentSpace = null;
        $agentSpacesService = new AgentSpacesService();

        self::assertSame('Asia/Tokyo', $agentSpacesService->findTimezoneForNewSchedule($scheduleOfASpace)?->identifier);
        self::assertNull($agentSpacesService->findTimezoneForNewSchedule($scheduleWithoutASpace));
    }

    protected function buildAgentSpaceOfAccount(int $accountId): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = 7;
        $agentSpace->accountId = $accountId;
        return $agentSpace;
    }

    protected function buildAccount(int $id, string $roleName): Account
    {
        $role = new Role();
        $role->setName($roleName);
        $roles = new Roles();
        $roles->add($role);
        $account = new Account();
        $account->id = $id;
        $account->roles = $roles;
        return $account;
    }
}
