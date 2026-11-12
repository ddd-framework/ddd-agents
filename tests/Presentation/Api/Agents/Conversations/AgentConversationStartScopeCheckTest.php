<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\EntryPoints\AIConversationEntryPointStart;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes\AgentSpacesServiceWithStoredSpaces;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * The start of a conversation with and without a space, in its three steps:
 *
 * - the request names the space ({@see AgentConversationTraitsFixtureController::callFindAgentSpaceOfStartRequest()}):
 *   none is a regular start at account level, one the caller cannot read is refused and never read as "no space";
 * - the response builder refuses neither and hands the space (or none) to the drive;
 * - the service checks the start before a conversation is created
 *   ({@see AIConversationsService::findConversationToDrive()}): a start without a space is allowed by default and is
 *   the application's decision, a space of another account is refused.
 *
 * No database: every start here is one of a NEW conversation, and the drive itself is a double.
 */
class AgentConversationStartScopeCheckTest extends IntegrationTestCase
{
    protected const int ACCOUNT_ID = 12;

    protected const int OTHER_ACCOUNT_ID = 13;

    protected const int AGENT_SPACE_ID = 7;

    public function testARequestThatNamesNoSpaceIsAStartAtAccountLevel(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $this->replaceService(AgentSpacesService::class, $agentSpacesService);

        $agentSpace = (new AgentConversationTraitsFixtureController())->callFindAgentSpaceOfStartRequest($this->buildRequestDto());

        self::assertNull($agentSpace);
        self::assertSame([], $agentSpacesService->idsFindWasAskedFor, 'no space is looked up for a request that names none');
    }

    public function testTheSpaceARequestNamesIsReadThroughTheSpaceService(): void
    {
        $storedAgentSpace = $this->buildAgentSpaceOfAccount(self::ACCOUNT_ID);
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $agentSpacesService->storedAgentSpacesById[self::AGENT_SPACE_ID] = $storedAgentSpace;
        $this->replaceService(AgentSpacesService::class, $agentSpacesService);

        $agentSpace = (new AgentConversationTraitsFixtureController())->callFindAgentSpaceOfStartRequest($this->buildRequestDto(self::AGENT_SPACE_ID));

        self::assertSame($storedAgentSpace, $agentSpace);
        self::assertSame([self::AGENT_SPACE_ID], $agentSpacesService->idsFindWasAskedFor);
    }

    public function testASpaceTheCallerCannotReadIsRefusedAndNeverReadAsNoSpace(): void
    {
        $this->replaceService(AgentSpacesService::class, new AgentSpacesServiceWithStoredSpaces());

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        (new AgentConversationTraitsFixtureController())->callFindAgentSpaceOfStartRequest($this->buildRequestDto(self::AGENT_SPACE_ID));
    }

    public function testTheRefusalIsTheSameWhenTheSpaceServiceThrowsForAnUnknownId(): void
    {
        $agentSpacesService = $this->createMock(AgentSpacesService::class);
        $agentSpacesService->method('find')->willThrowException(new NotFoundException('not found or not authorized'));
        $this->replaceService(AgentSpacesService::class, $agentSpacesService);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        (new AgentConversationTraitsFixtureController())->callFindAgentSpaceOfStartRequest($this->buildRequestDto(self::AGENT_SPACE_ID));
    }

    public function testAnApplicationNamesTheSpaceOfAStartItsOwnWay(): void
    {
        $agentSpaceOfTheCaller = $this->buildAgentSpaceOfAccount(self::ACCOUNT_ID);
        $fixtureController = new AgentConversationScopeCheckingFixtureController();
        $fixtureController->agentSpaceOfTheCaller = $agentSpaceOfTheCaller;

        // the request names another id: the application's own rule decides, the request field is not read
        $agentSpace = $fixtureController->callFindAgentSpaceOfStartRequest($this->buildRequestDto(99));

        self::assertSame($agentSpaceOfTheCaller, $agentSpace);
        self::assertSame(1, $fixtureController->startRequestsAskedForTheirSpace);
    }

    public function testAStartWithoutASpaceIsHandedToTheDriveAndNotRefused(): void
    {
        $startedConversation = new AIConversation();
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::once())
            ->method('driveConversation')
            ->with(AIConversationDriveScope::CUSTOMER, null, null)
            ->willReturn($startedConversation);
        $aIConversationAgentService->expects(self::never())->method('startOrReopenConversationAtEntryPoint');

        $responseDto = (new AgentConversationScopeCheckingFixtureController())->callBuildConversationStartedResponse($this->buildRequestDto(), null, $aIConversationAgentService);

        self::assertSame($startedConversation, $responseDto->aiConversation);
        self::assertFalse($responseDto->reopenedExistingConversation);
    }

    public function testAStartHandsItsSpaceToTheDrive(): void
    {
        $agentSpace = $this->buildAgentSpaceOfAccount(self::ACCOUNT_ID);
        $startedConversation = new AIConversation();
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::once())
            ->method('driveConversation')
            ->with(AIConversationDriveScope::CUSTOMER, null, self::identicalTo($agentSpace))
            ->willReturn($startedConversation);

        $responseDto = (new AgentConversationScopeCheckingFixtureController())->callBuildConversationStartedResponse($this->buildRequestDto(), $agentSpace, $aIConversationAgentService);

        self::assertSame($startedConversation, $responseDto->aiConversation);
    }

    public function testAStartFromAnEntryPointWorksWithAndWithoutASpace(): void
    {
        $agentSpace = $this->buildAgentSpaceOfAccount(self::ACCOUNT_ID);
        $startedConversation = new AIConversation();
        $requestDto = $this->buildRequestDto();
        $requestDto->entryPointKey = 'some-entry-point';

        $aIConversationAgentServiceWithoutASpace = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentServiceWithoutASpace->expects(self::once())
            ->method('startOrReopenConversationAtEntryPoint')
            ->with('some-entry-point', null)
            ->willReturn(AIConversationEntryPointStart::started($startedConversation));
        $aIConversationAgentServiceWithoutASpace->expects(self::never())->method('driveConversation');
        $responseDtoWithoutASpace = (new AgentConversationScopeCheckingFixtureController())->callBuildConversationStartedResponse($requestDto, null, $aIConversationAgentServiceWithoutASpace);

        $aIConversationAgentServiceWithASpace = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentServiceWithASpace->expects(self::once())
            ->method('startOrReopenConversationAtEntryPoint')
            ->with('some-entry-point', self::identicalTo($agentSpace))
            ->willReturn(AIConversationEntryPointStart::started($startedConversation));
        $responseDtoWithASpace = (new AgentConversationScopeCheckingFixtureController())->callBuildConversationStartedResponse($requestDto, $agentSpace, $aIConversationAgentServiceWithASpace);

        self::assertSame($startedConversation, $responseDtoWithoutASpace->aiConversation);
        self::assertSame($startedConversation, $responseDtoWithASpace->aiConversation);
    }

    public function testTheServiceAllowsACustomerStartWithoutASpaceByDefault(): void
    {
        $account = $this->authenticate($this->buildAccount(self::ACCOUNT_ID, Role::LOGIN));

        $driveTarget = $this->getConversationsService()->findConversationToDrive(false, null, null, null);

        self::assertNull($driveTarget->existingConversation);
        self::assertNull($driveTarget->agentSpace, 'no space: the conversation works at account level');
        self::assertSame($account, $driveTarget->account);
    }

    public function testWhetherAStartWithoutASpaceIsOpenIsTheApplicationsDecision(): void
    {
        $account = $this->authenticate($this->buildAccount(self::ACCOUNT_ID, Role::LOGIN));
        $agentSpacesService = $this->createMock(AgentSpacesService::class);
        $agentSpacesService->expects(self::once())
            ->method('requireConversationStartWithoutAgentSpaceAllowed')
            ->with(self::identicalTo($account), 'Some.Purpose', 'some-entry-point')
            ->willThrowException(new ForbiddenException('refused by the application'));
        $agentSpacesService->expects(self::never())->method('requireAgentSpaceUsableByAccount');
        $this->replaceService(AgentSpacesService::class, $agentSpacesService);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('refused by the application');
        $this->getConversationsService()->findConversationToDrive(false, null, null, null, 'Some.Purpose', 'some-entry-point');
    }

    public function testTheServiceAllowsACustomerStartWithASpaceOfTheAccount(): void
    {
        $account = $this->authenticate($this->buildAccount(self::ACCOUNT_ID, Role::LOGIN));
        $agentSpace = $this->buildAgentSpaceOfAccount(self::ACCOUNT_ID);

        $driveTarget = $this->getConversationsService()->findConversationToDrive(false, null, $agentSpace, null);

        self::assertNull($driveTarget->existingConversation);
        self::assertSame($agentSpace, $driveTarget->agentSpace);
        self::assertSame($account, $driveTarget->account);
    }

    public function testTheServiceRefusesACustomerStartWithASpaceOfAnotherAccount(): void
    {
        $this->authenticate($this->buildAccount(self::ACCOUNT_ID, Role::LOGIN));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        $this->getConversationsService()->findConversationToDrive(false, null, $this->buildAgentSpaceOfAccount(self::OTHER_ACCOUNT_ID), null);
    }

    public function testACustomerStartIsOnlyAskedForTheSpaceCheckWhenItHasASpace(): void
    {
        $account = $this->authenticate($this->buildAccount(self::ACCOUNT_ID, Role::LOGIN));
        $agentSpace = $this->buildAgentSpaceOfAccount(self::ACCOUNT_ID);
        $agentSpacesService = $this->createMock(AgentSpacesService::class);
        $agentSpacesService->expects(self::once())
            ->method('requireAgentSpaceUsableByAccount')
            ->with(self::identicalTo($agentSpace), self::identicalTo($account));
        $agentSpacesService->expects(self::never())->method('requireConversationStartWithoutAgentSpaceAllowed');
        $this->replaceService(AgentSpacesService::class, $agentSpacesService);

        $this->getConversationsService()->findConversationToDrive(false, null, $agentSpace, null);
    }

    public function testAStartWithoutAnAuthenticatedAccountIsRefusedOnTheCustomerPath(): void
    {
        $this->authenticate(null);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Authentication is required to drive a conversation.');
        $this->getConversationsService()->findConversationToDrive(false, null, null, null);
    }

    public function testAnAdminStartWithoutASpaceRunsAsTheAccountTheCallerNames(): void
    {
        $this->authenticate($this->buildAccount(99, Role::ADMIN));
        $runAsAccount = $this->buildAccount(self::ACCOUNT_ID, Role::LOGIN);

        $driveTarget = $this->getConversationsService()->findConversationToDrive(true, null, null, $runAsAccount);

        self::assertNull($driveTarget->agentSpace);
        self::assertSame($runAsAccount, $driveTarget->account);
    }

    public function testAnAdminStartWithoutASpaceAndWithoutAnAccountIsRefused(): void
    {
        $this->authenticate($this->buildAccount(99, Role::ADMIN));

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage((string)(new ReflectionClassConstant(AIConversationsService::class, 'NO_ACCOUNT_TO_DRIVE_AS_MESSAGE'))->getValue());
        $this->getConversationsService()->findConversationToDrive(true, null, null, null);
    }

    public function testAnAdminStartWithASpaceFallsBackToTheOwnerOfTheSpace(): void
    {
        $this->authenticate($this->buildAccount(99, Role::ADMIN));
        $ownerAccount = $this->buildAccount(self::ACCOUNT_ID, Role::LOGIN);
        $agentSpace = $this->buildAgentSpaceOfAccount(self::ACCOUNT_ID);
        $agentSpace->account = $ownerAccount;

        $driveTarget = $this->getConversationsService()->findConversationToDrive(true, null, $agentSpace, null);

        self::assertSame($agentSpace, $driveTarget->agentSpace);
        self::assertSame($ownerAccount, $driveTarget->account);
    }

    public function testTheAccountAnAdminStartRunsAsMustBeAllowedToUseTheSpace(): void
    {
        $this->authenticate($this->buildAccount(99, Role::ADMIN));
        $agentSpace = $this->buildAgentSpaceOfAccount(self::ACCOUNT_ID);
        $agentSpace->account = $this->buildAccount(self::ACCOUNT_ID, Role::LOGIN);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        $this->getConversationsService()->findConversationToDrive(true, null, $agentSpace, $this->buildAccount(self::OTHER_ACCOUNT_ID, Role::LOGIN));
    }

    protected function getConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    protected function authenticate(?Account $account): ?Account
    {
        AuthService::instance()->setAccount($account);
        return $account;
    }

    protected function buildRequestDto(?int $agentSpaceId = null): AIConversationStartWithMessagePostRequestDto
    {
        /** @var AIConversationStartWithMessagePostRequestDto $requestDto */
        $requestDto = (new ReflectionClass(AIConversationStartWithMessagePostRequestDto::class))->newInstanceWithoutConstructor();
        $requestDto->agentSpaceId = $agentSpaceId;
        return $requestDto;
    }

    protected function buildAgentSpaceOfAccount(int $accountId): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = self::AGENT_SPACE_ID;
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
