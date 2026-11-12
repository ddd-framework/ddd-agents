<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Skills\MCPPackageIdsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Client\AI\AgentMandatesMCPPackage;
use DDD\Presentation\Mcp\Client\AI\AgentMandateToolCallsMCPPackage;
use DDD\Presentation\Mcp\Client\AI\Dtos\MandateGrantRequestDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\MandateListRequestDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\MandateRevokeRequestDto;
use DDD\Tests\Agents\Presentation\Mcp\AI\Probes\AgentSpaceLookupRecordingAgentMandatesMCPPackage;
use DDD\Tests\Agents\Presentation\Mcp\AI\Probes\CallRecordingAgentMandatesService;
use DDD\Tests\Agents\Presentation\Mcp\AI\Probes\ProbeShopMandateListRequestDto;
use DDD\Tests\Agents\Presentation\Mcp\AI\Probes\ProbeShopMandatesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * A mandate tool is what the model sees (name, texts, arguments); what it does sits one class below, in
 * {@see AgentMandateToolCallsMCPPackage}. The package's own tools name the space by `agentSpaceId`; an application
 * declares its tools with its own request class and hands its own argument, by name, to the same work. In both cases
 * the space service is asked with the arguments of the call, the mandate service works for the authenticated account,
 * and the answered space decides what is listed and what a grant is bound to.
 */
class MandateToolsOfAnApplicationReachTheSameWorkTest extends UnitTestCase
{
    protected const int ACCOUNT_ID = 12;

    protected ?Account $accountBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        $account = new Account();
        $account->id = self::ACCOUNT_ID;
        static::authenticate($account);
    }

    protected function tearDown(): void
    {
        static::authenticate($this->accountBeforeTest);
        parent::tearDown();
    }

    public function testTheClassWithTheWorkDeclaresNoToolAndIsNoPackageOfItsOwn(): void
    {
        $reflectionClass = new ReflectionClass(AgentMandateToolCallsMCPPackage::class);
        self::assertTrue($reflectionClass->isAbstract());
        foreach ($reflectionClass->getMethods() as $reflectionMethod) {
            self::assertSame([], $reflectionMethod->getAttributes(MCPTool::class), $reflectionMethod->getName() . ' must not be a tool');
            self::assertFalse(
                $reflectionMethod->getDeclaringClass()->getName() === AgentMandateToolCallsMCPPackage::class && $reflectionMethod->isPublic(),
                $reflectionMethod->getName() . ' must not be callable from outside'
            );
        }
        /** @var MCPPackageIdsService $mCPPackageIdsService */
        $mCPPackageIdsService = new MCPPackageIdsService();
        self::assertNotContains(AgentMandateToolCallsMCPPackage::class, $mCPPackageIdsService->getPackageClassesById());
        self::assertSame(AgentMandatesMCPPackage::class, $mCPPackageIdsService->findPackageClassForId('agent.mandates'));
    }

    public function testThePackagesOwnToolsStayTheThreeToolsWithTheSpaceNamedByAgentSpaceId(): void
    {
        $toolNames = [];
        foreach ((new ReflectionClass(AgentMandatesMCPPackage::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if ($reflectionMethod->getAttributes(MCPTool::class) !== []) {
                $toolNames[] = $reflectionMethod->getName();
            }
        }
        sort($toolNames);
        self::assertSame(['grantMandate', 'listMandates', 'revokeMandate'], $toolNames);

        $agentSpace = $this->buildAgentSpace(7);
        $agentMandatesMCPPackage = new AgentSpaceLookupRecordingAgentMandatesMCPPackage();
        $agentMandatesMCPPackage->agentSpaceTheLookupAnswers = $agentSpace;
        $agentMandatesService = new CallRecordingAgentMandatesService();
        $requestDto = new MandateListRequestDto();
        $requestDto->agentSpaceId = 7;

        $agentMandatesMCPPackage->listMandates($requestDto, $agentMandatesService, new AgentToolCallProvenance());

        self::assertSame([[AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => 7]], $agentMandatesMCPPackage->toolArgumentsOfAgentSpaceLookups);
        self::assertSame([['accountId' => self::ACCOUNT_ID, 'agentSpace' => $agentSpace]], $agentMandatesService->listReads);
    }

    public function testAnApplicationsToolHandsItsOwnArgumentByNameAndListsTheViewOfTheAnsweredSpace(): void
    {
        $agentSpace = $this->buildAgentSpace(9);
        $probeShopMandatesMCPPackage = new ProbeShopMandatesMCPPackage();
        $probeShopMandatesMCPPackage->agentSpaceTheLookupAnswers = $agentSpace;
        $agentMandatesService = new CallRecordingAgentMandatesService();
        $requestDto = new ProbeShopMandateListRequestDto();
        $requestDto->shopId = 31;

        $probeShopMandatesMCPPackage->listMandates($requestDto, $agentMandatesService, new AgentToolCallProvenance());

        self::assertSame([['shopId' => 31]], $probeShopMandatesMCPPackage->toolArgumentsOfAgentSpaceLookups);
        self::assertSame([['accountId' => self::ACCOUNT_ID, 'agentSpace' => $agentSpace]], $agentMandatesService->listReads);
    }

    public function testAGrantIsBoundToTheAnsweredSpaceAndAnAccountWideGrantAsksForNoSpace(): void
    {
        $agentSpace = $this->buildAgentSpace(7);
        $agentMandatesMCPPackage = new AgentSpaceLookupRecordingAgentMandatesMCPPackage();
        $agentMandatesMCPPackage->agentSpaceTheLookupAnswers = $agentSpace;
        $agentMandatesService = new CallRecordingAgentMandatesService();
        $agentMandatesService->agentMandateTheServiceAnswers = $this->buildMandate();
        $agentToolCallProvenance = new AgentToolCallProvenance(conversationId: 44, rootConversationId: 40, conversationMessages: new AIConversationMessages());
        $requestDto = new MandateGrantRequestDto();
        $requestDto->actionClass = AgentMandate::ACTION_STANDING_AUTOMATION;
        $requestDto->scopeType = AgentMandate::SCOPE_UNLIMITED;

        $agentMandatesMCPPackage->grantMandate($requestDto, $agentMandatesService, $agentToolCallProvenance);
        $requestDto->accountWide = true;
        $agentMandatesMCPPackage->grantMandate($requestDto, $agentMandatesService, $agentToolCallProvenance);

        self::assertSame([[AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => null]], $agentMandatesMCPPackage->toolArgumentsOfAgentSpaceLookups);
        self::assertCount(2, $agentMandatesService->grants);
        self::assertSame($agentSpace, $agentMandatesService->grants[0]['agentSpace']);
        self::assertNull($agentMandatesService->grants[1]['agentSpace']);
        self::assertSame(self::ACCOUNT_ID, $agentMandatesService->grants[0]['accountId']);
        self::assertSame(40, $agentMandatesService->grants[0]['sourceConversationId']);
        self::assertSame(AgentMandate::ACTION_STANDING_AUTOMATION, $agentMandatesService->grants[0]['grantArguments']->actionClass);
    }

    public function testAGrantOutsideAConversationIsRefusedBeforeTheSpaceIsAsked(): void
    {
        $agentMandatesMCPPackage = new AgentSpaceLookupRecordingAgentMandatesMCPPackage();
        $agentMandatesService = new CallRecordingAgentMandatesService();
        $requestDto = new MandateGrantRequestDto();
        $requestDto->actionClass = AgentMandate::ACTION_STANDING_AUTOMATION;
        $requestDto->scopeType = AgentMandate::SCOPE_UNLIMITED;

        try {
            $agentMandatesMCPPackage->grantMandate($requestDto, $agentMandatesService, new AgentToolCallProvenance());
            self::fail('A grant outside a conversation must be refused.');
        } catch (BadRequestException $badRequestException) {
            self::assertStringContainsString('only works inside an agent conversation', $badRequestException->getMessage());
        }
        self::assertSame([], $agentMandatesMCPPackage->toolArgumentsOfAgentSpaceLookups);
        self::assertSame([], $agentMandatesService->grants);
    }

    public function testARevokeOfAMandateTheAccountDoesNotHaveIsAnsweredNotFound(): void
    {
        $requestDto = new MandateRevokeRequestDto();
        $requestDto->mandateId = 17;

        $this->expectException(NotFoundException::class);
        (new AgentSpaceLookupRecordingAgentMandatesMCPPackage())->revokeMandate($requestDto, new CallRecordingAgentMandatesService(), new AgentToolCallProvenance());
    }

    public function testEveryToolIsRefusedWithoutAnAuthenticatedAccount(): void
    {
        static::authenticate(null);

        $this->expectException(ForbiddenException::class);
        (new ProbeShopMandatesMCPPackage())->listMandates(new ProbeShopMandateListRequestDto(), new CallRecordingAgentMandatesService(), new AgentToolCallProvenance());
    }

    protected function buildAgentSpace(int $agentSpaceId): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $agentSpaceId;
        return $agentSpace;
    }

    protected function buildMandate(): AgentMandate
    {
        $agentMandate = new AgentMandate();
        $agentMandate->id = 17;
        $agentMandate->accountId = self::ACCOUNT_ID;
        $agentMandate->actionClass = AgentMandate::ACTION_STANDING_AUTOMATION;
        $agentMandate->scopeType = AgentMandate::SCOPE_UNLIMITED;
        $agentMandate->status = AgentMandate::STATUS_ACTIVE;
        $agentMandate->validFrom = new DateTime('2026-09-14 10:00:00');
        $agentMandate->customerApprovalExcerpt = 'Yes, from now on.';
        return $agentMandate;
    }

    /** Sets the authenticated account directly (an application's `setAccount()` may expect its own account class). */
    protected static function authenticate(?Account $account): void
    {
        (new ReflectionProperty(AuthService::class, 'account'))->setValue(null, $account);
    }
}
