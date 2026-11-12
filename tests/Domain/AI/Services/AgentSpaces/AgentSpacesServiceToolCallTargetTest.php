<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes\AgentSpacesServiceWithStoredSpaces;
use DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes\ProbeRequestDtoNamingAnAgentSpace;
use DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes\ProbeRequestDtoNamingNoAgentSpace;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionProperty;

/**
 * The space a tool call targets, and the arguments the conversation's space fills. A call without the argument works
 * for the conversation's space (none: account level); an argument that names another space is checked against the
 * account the call acts for and is refused — never silently replaced by the conversation's space or by "no space".
 */
class AgentSpacesServiceToolCallTargetTest extends UnitTestCase
{
    protected ?Account $accountBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        static::authenticate(null);
    }

    protected function tearDown(): void
    {
        static::authenticate($this->accountBeforeTest);
        parent::tearDown();
    }

    public function testWithoutTheArgumentTheCallTargetsTheSpaceOfItsConversation(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $agentSpaceOfConversation = $this->buildAgentSpace(7, 12);

        self::assertSame($agentSpaceOfConversation, $agentSpacesService->findAgentSpaceOfToolCall([], $this->buildProvenance($agentSpaceOfConversation, 12)));
        self::assertSame($agentSpaceOfConversation, $agentSpacesService->findAgentSpaceOfToolCall(['note' => 'x'], $this->buildProvenance($agentSpaceOfConversation, 12)));
        self::assertSame([], $agentSpacesService->idsFindWasAskedFor);
    }

    public function testWithoutTheArgumentAndWithoutASpaceTheCallWorksAtAccountLevel(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();

        self::assertNull($agentSpacesService->findAgentSpaceOfToolCall([], $this->buildProvenance(null, 12)));
        self::assertNull($agentSpacesService->findAgentSpaceOfToolCall([], null), 'no conversation: the HTTP path');
    }

    public function testAnArgumentThatIsNoIdCountsAsNotPassed(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $agentSpaceOfConversation = $this->buildAgentSpace(7, 12);
        $provenance = $this->buildProvenance($agentSpaceOfConversation, 12);

        foreach ([0, -3, '', null, 'abc'] as $argumentThatIsNoId) {
            self::assertSame($agentSpaceOfConversation, $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => $argumentThatIsNoId], $provenance));
        }
        self::assertSame([], $agentSpacesService->idsFindWasAskedFor);
    }

    public function testTheIdOfTheConversationsOwnSpaceNeedsNoLookup(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $agentSpaceOfConversation = $this->buildAgentSpace(7, 12);

        self::assertSame(
            $agentSpaceOfConversation,
            $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => '7'], $this->buildProvenance($agentSpaceOfConversation, 12))
        );
        self::assertSame([], $agentSpacesService->idsFindWasAskedFor);
    }

    public function testAnotherSpaceOfTheSameAccountIsTheTarget(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $otherAgentSpaceOfTheAccount = $this->buildAgentSpace(8, 12);
        $agentSpacesService->storedAgentSpacesById = [8 => $otherAgentSpaceOfTheAccount];

        self::assertSame(
            $otherAgentSpaceOfTheAccount,
            $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => 8], $this->buildProvenance($this->buildAgentSpace(7, 12), 12))
        );
        self::assertSame(
            $otherAgentSpaceOfTheAccount,
            $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => 8], $this->buildProvenance(null, 12)),
            'a conversation at account level may name a space of its account'
        );
    }

    public function testASpaceOfAnotherAccountIsRefused(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $agentSpacesService->storedAgentSpacesById = [9 => $this->buildAgentSpace(9, 13)];

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => 9], $this->buildProvenance($this->buildAgentSpace(7, 12), 12));
    }

    public function testASpaceThatDoesNotExistIsRefusedWithTheSameText(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => 404], $this->buildProvenance($this->buildAgentSpace(7, 12), 12));
    }

    public function testWithoutAConversationTheAuthenticatedAccountDecides(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $agentSpaceOfAccount12 = $this->buildAgentSpace(8, 12);
        $agentSpacesService->storedAgentSpacesById = [8 => $agentSpaceOfAccount12];

        static::authenticate($this->buildAccount(12));
        self::assertSame($agentSpaceOfAccount12, $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => 8], null));

        static::authenticate($this->buildAccount(13));
        $this->expectException(ForbiddenException::class);
        $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => 8], null);
    }

    public function testWithNobodyToActForANamedSpaceIsRefused(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $agentSpacesService->storedAgentSpacesById = [8 => $this->buildAgentSpace(8, 12)];

        $this->expectException(ForbiddenException::class);
        $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => 8], null);
    }

    public function testTheSpaceOfTheConversationFillsTheArgumentARequestDeclares(): void
    {
        $agentSpacesService = new AgentSpacesService();
        $agentSpace = $this->buildAgentSpace(7, 12);

        self::assertSame(
            ['note' => 'x', AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => 7],
            $agentSpacesService->applyAgentSpaceDefaultsToToolArguments(['note' => 'x'], [ProbeRequestDtoNamingNoAgentSpace::class, ProbeRequestDtoNamingAnAgentSpace::class], $agentSpace)
        );
    }

    public function testAnArgumentTheModelPassedIsNeverReplaced(): void
    {
        $agentSpacesService = new AgentSpacesService();
        $toolArguments = [AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => 8];

        self::assertSame($toolArguments, $agentSpacesService->applyAgentSpaceDefaultsToToolArguments($toolArguments, [ProbeRequestDtoNamingAnAgentSpace::class], $this->buildAgentSpace(7, 12)));
    }

    public function testNothingIsFilledForARequestThatDeclaresNoSuchArgumentOrForASpaceThatIsNotStored(): void
    {
        $agentSpacesService = new AgentSpacesService();

        self::assertSame(['note' => 'x'], $agentSpacesService->applyAgentSpaceDefaultsToToolArguments(['note' => 'x'], [ProbeRequestDtoNamingNoAgentSpace::class], $this->buildAgentSpace(7, 12)));
        self::assertSame([], $agentSpacesService->applyAgentSpaceDefaultsToToolArguments([], [], $this->buildAgentSpace(7, 12)));
        self::assertSame([], $agentSpacesService->applyAgentSpaceDefaultsToToolArguments([], [ProbeRequestDtoNamingAnAgentSpace::class], new AgentSpace()));
    }

    public function testTheModelReadsAPrefixForEachArgumentTheSpaceFills(): void
    {
        $agentSpacesService = new AgentSpacesService();

        self::assertSame(
            [AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => AgentSpacesService::DESCRIPTION_PREFIX_OF_TOOL_ARGUMENT_FILLED_FROM_AGENT_SPACE],
            $agentSpacesService->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()
        );
    }

    public function testAConversationWithoutASpaceOffersTheToolArgumentsAsDeclared(): void
    {
        $conversation = new AIConversation();
        $conversation->agentSpaceId = null;

        self::assertSame([], $conversation->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace());
    }

    public function testTheWidgetBlocksOfAConversationHydrateFromTheKeyOfItsSpace(): void
    {
        $agentSpacesService = new AgentSpacesService();
        $conversationOfASpace = new AIConversation();
        $conversationOfASpace->agentSpace = $this->buildAgentSpace(7, 12);
        $conversationAtAccountLevel = new AIConversation();
        $conversationAtAccountLevel->agentSpace = null;

        self::assertSame(7, $agentSpacesService->findWidgetSelfHydrationKeyOfConversation($conversationOfASpace));
        self::assertNull($agentSpacesService->findWidgetSelfHydrationKeyOfConversation($conversationAtAccountLevel));
    }

    public function testTheProvenanceOfACallCarriesTheSpaceAndItsId(): void
    {
        $agentSpace = $this->buildAgentSpace(7, 12);

        $provenanceOfASpace = new AgentToolCallProvenance(agentSpace: $agentSpace);
        self::assertSame($agentSpace, $provenanceOfASpace->agentSpace);
        self::assertSame(7, $provenanceOfASpace->agentSpaceId);

        $provenanceAtAccountLevel = new AgentToolCallProvenance();
        self::assertNull($provenanceAtAccountLevel->agentSpace);
        self::assertNull($provenanceAtAccountLevel->agentSpaceId);

        $evalHarnessProvenance = AgentToolCallProvenance::forEvalHarness($agentSpace);
        self::assertSame($agentSpace, $evalHarnessProvenance->agentSpace);
        self::assertSame(7, $evalHarnessProvenance->agentSpaceId);
        self::assertNull(AgentToolCallProvenance::forEvalHarness(null)->agentSpaceId);
    }

    /** The provenance of a call in a conversation of account `$accountId` that works for `$agentSpace` (null: account level). */
    protected function buildProvenance(?AgentSpace $agentSpace, int $accountId): AgentToolCallProvenance
    {
        $conversation = new AIConversation();
        $conversation->id = 55;
        $conversation->accountId = $accountId;
        $conversation->account = $this->buildAccount($accountId);
        $conversation->agentSpace = $agentSpace;
        return new AgentToolCallProvenance(conversationId: 55, agentSpace: $agentSpace, conversation: $conversation);
    }

    protected function buildAgentSpace(int $id, int $accountId): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $id;
        $agentSpace->accountId = $accountId;
        return $agentSpace;
    }

    protected function buildAccount(int $id): Account
    {
        $role = new Role();
        $role->setName(Role::LOGIN);
        $roles = new Roles();
        $roles->add($role);
        $account = new Account();
        $account->id = $id;
        $account->roles = $roles;
        return $account;
    }

    /** Sets the authenticated account directly (an application's `setAccount()` may expect its own account class). */
    protected static function authenticate(?Account $account): void
    {
        (new ReflectionProperty(AuthService::class, 'account'))->setValue(null, $account);
    }
}
