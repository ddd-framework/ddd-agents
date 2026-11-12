<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes\AgentSpacesServiceWithStoredSpaces;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeNoteMCPPackage;
use DDD\Tests\Agents\Presentation\Services\Probes\TargetAgentSpaceObservingToolExecutor;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * The target space of a tool call, with the executor asking the space service itself
 * ({@see AgentSpacesService::findAgentSpaceOfToolCall()}): a space of another account and a space that does not
 * exist are refused with the same answer before the tool runs, another space of the same account is the target of
 * every gate, and a call that names none works for the space of its conversation without a load.
 *
 * No database: the space service answers from spaces held in memory.
 */
class MCPToolExecutorTargetAgentSpaceOfSpaceServiceTest extends IntegrationTestCase
{
    protected const int ACCOUNT_ID = 12;

    protected const int OTHER_ACCOUNT_ID = 13;

    protected const int AGENT_SPACE_ID_OF_CONVERSATION = 7;

    protected const string TOOL_NAME = 'probeRecordNote';

    protected const string REFUSAL_TEXT = "Tool 'probeRecordNote' was NOT executed: the space this call names does not exist or is not one this "
        . 'account may use. Do not retry with it. Leave the argument out to work for what this conversation works '
        . 'for, or name a space of this account.';

    protected function setUp(): void
    {
        parent::setUp();
        ProbeNoteMCPPackage::forgetRuns();
    }

    protected function onTearDown(): void
    {
        ProbeNoteMCPPackage::forgetRuns();
    }

    public function testASpaceOfAnotherAccountIsRefusedBeforeTheToolRuns(): void
    {
        $agentSpacesService = $this->replaceSpaceServiceWithStoredSpaces([9 => $this->buildAgentSpace(9, self::OTHER_ACCOUNT_ID)]);
        $executor = new TargetAgentSpaceObservingToolExecutor();

        $response = $executor->callDispatchTool(self::TOOL_NAME, ['agentSpaceId' => 9, 'note' => 'x'], [ProbeNoteMCPPackage::class], $this->buildProvenanceOfConversationWithSpace());

        $this->assertRefusedBeforeTheToolRan($response, $executor);
        self::assertSame([9], $agentSpacesService->idsFindWasAskedFor, 'the named space is read once per call');
    }

    public function testASpaceThatDoesNotExistIsRefusedWithTheSameAnswer(): void
    {
        $agentSpacesService = $this->replaceSpaceServiceWithStoredSpaces([]);
        $executor = new TargetAgentSpaceObservingToolExecutor();

        $response = $executor->callDispatchTool(self::TOOL_NAME, ['agentSpaceId' => 404], [ProbeNoteMCPPackage::class], $this->buildProvenanceOfConversationWithSpace());

        $this->assertRefusedBeforeTheToolRan($response, $executor);
        self::assertSame([404], $agentSpacesService->idsFindWasAskedFor);
    }

    public function testAnotherSpaceOfTheSameAccountIsTheTargetOfEveryGateAndOfTheTool(): void
    {
        $otherAgentSpaceOfAccount = $this->buildAgentSpace(8, self::ACCOUNT_ID);
        $agentSpacesService = $this->replaceSpaceServiceWithStoredSpaces([8 => $otherAgentSpaceOfAccount]);
        $executor = new TargetAgentSpaceObservingToolExecutor();

        $response = $executor->callDispatchTool(self::TOOL_NAME, ['agentSpaceId' => 8], [ProbeNoteMCPPackage::class], $this->buildProvenanceOfConversationWithSpace());

        self::assertFalse($response->isError, json_encode($response->content, JSON_THROW_ON_ERROR));
        self::assertSame(
            [
                'refuseUnentitledToolCall' => $otherAgentSpaceOfAccount,
                'refuseToolCallForIneligibleScope' => $otherAgentSpaceOfAccount,
                'refuseActionLackingMandate' => $otherAgentSpaceOfAccount,
            ],
            $executor->targetAgentSpaceHandedToGate
        );
        self::assertSame([8], ProbeNoteMCPPackage::$agentSpaceIdsOfRuns);
        self::assertSame([8], $agentSpacesService->idsFindWasAskedFor, 'one read for the call, not one per gate');
    }

    public function testACallThatNamesNoSpaceWorksForTheSpaceOfItsConversationWithoutALoad(): void
    {
        $agentSpacesService = $this->replaceSpaceServiceWithStoredSpaces([]);
        $executor = new TargetAgentSpaceObservingToolExecutor();
        $provenance = $this->buildProvenanceOfConversationWithSpace();

        $response = $executor->callDispatchTool(self::TOOL_NAME, ['note' => 'x'], [ProbeNoteMCPPackage::class], $provenance);

        self::assertFalse($response->isError, json_encode($response->content, JSON_THROW_ON_ERROR));
        self::assertSame($provenance->agentSpace, $executor->targetAgentSpaceHandedToGate['refuseActionLackingMandate']);
        self::assertSame([self::AGENT_SPACE_ID_OF_CONVERSATION], ProbeNoteMCPPackage::$agentSpaceIdsOfRuns);
        self::assertSame([], $agentSpacesService->idsFindWasAskedFor);
    }

    public function testWithoutAConversationANamedSpaceIsCheckedAgainstTheAuthenticatedAccount(): void
    {
        $agentSpaceOfAccount = $this->buildAgentSpace(8, self::ACCOUNT_ID);
        $this->replaceSpaceServiceWithStoredSpaces([8 => $agentSpaceOfAccount]);

        $otherAccount = $this->buildAccount(self::OTHER_ACCOUNT_ID);
        AuthService::instance()->setAccount($otherAccount);
        $refusedExecutor = new TargetAgentSpaceObservingToolExecutor();
        $this->assertRefusedBeforeTheToolRan(
            $refusedExecutor->callDispatchTool(self::TOOL_NAME, ['agentSpaceId' => 8], [ProbeNoteMCPPackage::class], null),
            $refusedExecutor
        );

        $accountOfTheSpace = $this->buildAccount(self::ACCOUNT_ID);
        AuthService::instance()->setAccount($accountOfTheSpace);
        $executor = new TargetAgentSpaceObservingToolExecutor();
        $response = $executor->callDispatchTool(self::TOOL_NAME, ['agentSpaceId' => 8], [ProbeNoteMCPPackage::class], null);

        self::assertFalse($response->isError, json_encode($response->content, JSON_THROW_ON_ERROR));
        self::assertSame($agentSpaceOfAccount, $executor->targetAgentSpaceHandedToGate['refuseToolCallForIneligibleScope']);
        self::assertSame([8], ProbeNoteMCPPackage::$agentSpaceIdsOfRuns);
    }

    /** The refusal the model reads, and that neither a gate nor the tool saw the call. */
    protected function assertRefusedBeforeTheToolRan(MCPToolResponse $response, TargetAgentSpaceObservingToolExecutor $executor): void
    {
        self::assertTrue($response->isError);
        self::assertNull($response->refusalKind);
        self::assertSame([['type' => 'text', 'text' => self::REFUSAL_TEXT]], $response->content);
        self::assertCount(1, $executor->argumentsOfTargetAgentSpaceLookups, 'the target is asked once per call');
        self::assertSame([], $executor->targetAgentSpaceHandedToGate, 'no gate is asked for the space of the conversation or for no space instead');
        self::assertSame([], ProbeNoteMCPPackage::$agentSpaceIdsOfRuns, 'the tool did not run');
    }

    /**
     * @param AgentSpace[] $storedAgentSpacesById
     */
    protected function replaceSpaceServiceWithStoredSpaces(array $storedAgentSpacesById): AgentSpacesServiceWithStoredSpaces
    {
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $agentSpacesService->storedAgentSpacesById = $storedAgentSpacesById;
        $this->replaceService(AgentSpacesService::class, $agentSpacesService);
        return $agentSpacesService;
    }

    /** The provenance of a call in a conversation of the account that works for its space. */
    protected function buildProvenanceOfConversationWithSpace(): AgentToolCallProvenance
    {
        $agentSpace = $this->buildAgentSpace(self::AGENT_SPACE_ID_OF_CONVERSATION, self::ACCOUNT_ID);
        $conversation = new AIConversation();
        $conversation->id = 55;
        $conversation->accountId = self::ACCOUNT_ID;
        $conversation->account = $this->buildAccount(self::ACCOUNT_ID);
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
}
