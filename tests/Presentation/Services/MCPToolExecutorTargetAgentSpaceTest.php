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
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Client\AI\AgentMandatesMCPPackage;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeNoteMCPPackage;
use DDD\Tests\Agents\Presentation\Services\Probes\TargetAgentSpaceObservingToolExecutor;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The space a tool call targets, as the executor handles it: asked ONCE per call, after the conversation's space
 * filled the arguments, and handed to every gate. A refused target (a space that does not exist or that the account
 * may not use) is answered with a refusal the model reads — the tool does not run, no gate is asked, and the call
 * never runs for the conversation's space or without a space instead.
 *
 * The question itself is answered by the probe here; {@see MCPToolExecutorTargetAgentSpaceOfSpaceServiceTest} runs
 * the same calls against the space service.
 */
class MCPToolExecutorTargetAgentSpaceTest extends UnitTestCase
{
    protected const int ACCOUNT_ID = 12;

    protected const int AGENT_SPACE_ID_OF_CONVERSATION = 7;

    protected const string TOOL_NAME = 'probeRecordNote';

    protected ?Account $accountBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        static::authenticate(null);
        ProbeNoteMCPPackage::forgetRuns();
    }

    protected function tearDown(): void
    {
        ProbeNoteMCPPackage::forgetRuns();
        static::authenticate($this->accountBeforeTest);
        parent::tearDown();
    }

    public function testACallForASpaceTheAccountMayNotUseIsAnsweredWithARefusalAndTheToolDoesNotRun(): void
    {
        $executor = new TargetAgentSpaceObservingToolExecutor();
        $executor->refusalOfTargetAgentSpace = new ForbiddenException(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        $provenance = $this->buildProvenance($this->buildAgentSpace(self::AGENT_SPACE_ID_OF_CONVERSATION, self::ACCOUNT_ID), self::ACCOUNT_ID);

        $response = $executor->callDispatchTool(self::TOOL_NAME, ['agentSpaceId' => 9, 'note' => 'x'], [ProbeNoteMCPPackage::class], $provenance);

        self::assertTrue($response->isError);
        self::assertNull($response->refusalKind, 'no gate that opens later: the same call is refused again');
        self::assertSame(
            [
                [
                    'type' => 'text',
                    'text' => "Tool 'probeRecordNote' was NOT executed: the space this call names does not exist or is not one this "
                        . 'account may use. Do not retry with it. Leave the argument out to work for what this conversation works '
                        . 'for, or name a space of this account.',
                ],
            ],
            $response->content
        );
        self::assertNull($response->structuredContent);
        self::assertSame([], ProbeNoteMCPPackage::$agentSpaceIdsOfRuns, 'the tool did not run');
        self::assertSame([['agentSpaceId' => 9, 'note' => 'x']], $executor->argumentsOfTargetAgentSpaceLookups, 'asked once, with the id the model named');
        self::assertSame([], $executor->targetAgentSpaceHandedToGate, 'no gate is asked for the space of the conversation or for no space instead');
    }

    public function testARefusedTargetIsAnsweredTheSameWayWithoutAConversation(): void
    {
        $executor = new TargetAgentSpaceObservingToolExecutor();
        $executor->refusalOfTargetAgentSpace = new ForbiddenException(AgentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);

        $response = $executor->callDispatchTool(self::TOOL_NAME, ['agentSpaceId' => 9], [ProbeNoteMCPPackage::class], null);

        self::assertTrue($response->isError);
        self::assertStringStartsWith("Tool 'probeRecordNote' was NOT executed: the space this call names does not exist", $response->content[0]['text']);
        self::assertSame([], ProbeNoteMCPPackage::$agentSpaceIdsOfRuns);
        self::assertSame([], $executor->targetAgentSpaceHandedToGate);
    }

    public function testTheTargetIsAskedOnceWithTheFilledArgumentsAndHandedToEveryGate(): void
    {
        $agentSpaceOfConversation = $this->buildAgentSpace(self::AGENT_SPACE_ID_OF_CONVERSATION, self::ACCOUNT_ID);
        $executor = new TargetAgentSpaceObservingToolExecutor();
        $executor->answersWithStubbedTargetAgentSpace = true;
        $executor->stubbedTargetAgentSpace = $agentSpaceOfConversation;

        $response = $executor->callDispatchTool(
            self::TOOL_NAME,
            ['note' => 'x'],
            [ProbeNoteMCPPackage::class],
            $this->buildProvenance($agentSpaceOfConversation, self::ACCOUNT_ID)
        );

        self::assertFalse($response->isError, json_encode($response->content, JSON_THROW_ON_ERROR));
        self::assertSame([['type' => 'text', 'text' => ProbeNoteMCPPackage::RESULT_TEXT]], $response->content);
        self::assertSame(
            [['note' => 'x', 'agentSpaceId' => self::AGENT_SPACE_ID_OF_CONVERSATION]],
            $executor->argumentsOfTargetAgentSpaceLookups,
            'asked once, after the space of the conversation filled the argument the request declares'
        );
        self::assertSame(
            [
                'refuseUnentitledToolCall' => $agentSpaceOfConversation,
                'refuseToolCallForIneligibleScope' => $agentSpaceOfConversation,
                'refuseActionLackingMandate' => $agentSpaceOfConversation,
            ],
            $executor->targetAgentSpaceHandedToGate
        );
        self::assertSame([self::AGENT_SPACE_ID_OF_CONVERSATION], ProbeNoteMCPPackage::$agentSpaceIdsOfRuns, 'the tool ran once, with the filled argument');
    }

    public function testACallWithoutATargetRunsAtAccountLevel(): void
    {
        $executor = new TargetAgentSpaceObservingToolExecutor();
        $executor->answersWithStubbedTargetAgentSpace = true;

        $response = $executor->callDispatchTool(self::TOOL_NAME, ['note' => 'x'], [ProbeNoteMCPPackage::class], $this->buildProvenance(null, self::ACCOUNT_ID));

        self::assertFalse($response->isError, json_encode($response->content, JSON_THROW_ON_ERROR));
        self::assertSame([['note' => 'x']], $executor->argumentsOfTargetAgentSpaceLookups, 'a conversation without a space fills nothing');
        self::assertSame(
            [
                'refuseUnentitledToolCall' => null,
                'refuseToolCallForIneligibleScope' => null,
                'refuseActionLackingMandate' => null,
            ],
            $executor->targetAgentSpaceHandedToGate
        );
        self::assertSame([null], ProbeNoteMCPPackage::$agentSpaceIdsOfRuns);
    }

    public function testTheSpaceOfTheConversationFillsTheArgumentOfAToolWhoseRequestDeclaresIt(): void
    {
        $provenance = $this->buildProvenance($this->buildAgentSpace(self::AGENT_SPACE_ID_OF_CONVERSATION, self::ACCOUNT_ID), self::ACCOUNT_ID);
        $executor = new TargetAgentSpaceObservingToolExecutor();

        self::assertSame(
            ['agentSpaceId' => self::AGENT_SPACE_ID_OF_CONVERSATION],
            $executor->callApplyAgentSpaceDefaultsToToolArguments(new ReflectionMethod(AgentMandatesMCPPackage::class, 'listMandates'), [], $provenance)
        );
        self::assertSame(
            ['actionClass' => 'STANDING_AUTOMATION', 'agentSpaceId' => self::AGENT_SPACE_ID_OF_CONVERSATION],
            $executor->callApplyAgentSpaceDefaultsToToolArguments(
                new ReflectionMethod(AgentMandatesMCPPackage::class, 'grantMandate'),
                ['actionClass' => 'STANDING_AUTOMATION'],
                $provenance
            )
        );
    }

    public function testNothingIsFilledForAToolWhoseRequestDeclaresNoSuchArgument(): void
    {
        $provenance = $this->buildProvenance($this->buildAgentSpace(self::AGENT_SPACE_ID_OF_CONVERSATION, self::ACCOUNT_ID), self::ACCOUNT_ID);
        $executor = new TargetAgentSpaceObservingToolExecutor();

        // the tool takes the provenance, which carries the id of the space itself: what the server provides names no argument
        self::assertSame(
            ['mandateId' => 17],
            $executor->callApplyAgentSpaceDefaultsToToolArguments(new ReflectionMethod(AgentMandatesMCPPackage::class, 'revokeMandate'), ['mandateId' => 17], $provenance)
        );
    }

    public function testAnArgumentTheModelPassedIsNeverReplaced(): void
    {
        $provenance = $this->buildProvenance($this->buildAgentSpace(self::AGENT_SPACE_ID_OF_CONVERSATION, self::ACCOUNT_ID), self::ACCOUNT_ID);
        $executor = new TargetAgentSpaceObservingToolExecutor();

        self::assertSame(
            ['agentSpaceId' => 9],
            $executor->callApplyAgentSpaceDefaultsToToolArguments(new ReflectionMethod(AgentMandatesMCPPackage::class, 'listMandates'), ['agentSpaceId' => 9], $provenance)
        );
    }

    public function testNothingIsFilledWithoutASpaceOrWithoutAConversation(): void
    {
        $executor = new TargetAgentSpaceObservingToolExecutor();
        $listMandatesMethod = new ReflectionMethod(AgentMandatesMCPPackage::class, 'listMandates');

        self::assertSame([], $executor->callApplyAgentSpaceDefaultsToToolArguments($listMandatesMethod, [], $this->buildProvenance(null, self::ACCOUNT_ID)));
        self::assertSame([], $executor->callApplyAgentSpaceDefaultsToToolArguments($listMandatesMethod, [], null), 'no conversation: the HTTP path');
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
