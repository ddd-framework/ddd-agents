<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Symfony\Commands\Agents\AgentExecuteToolCallCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The tool-call child runs its tool inside an MCP tool-call envelope of its own, billed to the conversation's account,
 * and grants the conversation's `budgetBypass` on that envelope — the lift the same call gets inline in its turn. An
 * envelope that is already open is reused and its grant taken back. Nothing is granted for the process. A tool run
 * over the budget is written as the call's FAILED outcome.
 */
class AgentExecuteToolCallCommandBudgetBypassTest extends UnitTestCase
{
    public function testTheCommandKeepsItsNameAndIsHidden(): void
    {
        $agentExecuteToolCallCommand = new AgentExecuteToolCallCommand();

        self::assertSame('app:ai-agent:execute-tool-call', $agentExecuteToolCallCommand->getName());
        self::assertTrue($agentExecuteToolCallCommand->isHidden());
    }

    public function testABypassConversationsToolRunsUnderAGrantOnItsOwnEnvelope(): void
    {
        $usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
        $toolCallChild = new BudgetLiftRecordingExecuteToolCallCommand($usageEnvelopesServiceDouble);

        $toolCallChild->runToolInsideEnvelope($this->buildConversation(budgetBypass: true));

        self::assertSame(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS, $toolCallChild->grantReasonDuringTool);
        self::assertSame(1, $toolCallChild->envelopeDepthDuringTool);
        self::assertCount(1, $usageEnvelopesServiceDouble->openedEnvelopeContexts);
        $openedEnvelopeContext = $usageEnvelopesServiceDouble->openedEnvelopeContexts[0];
        self::assertSame(77, $openedEnvelopeContext->accountId);
        self::assertSame(AgentAIUsageScope::ENVELOPE_MCP_TOOL_CALL, $openedEnvelopeContext->scope);
        self::assertSame(AIUsageInitiator::ACCOUNT, $openedEnvelopeContext->initiatedBy);
        self::assertFalse($usageEnvelopesServiceDouble->hasActiveEnvelope(), 'the envelope ends with the call');
        self::assertNull($usageEnvelopesServiceDouble->getBudgetBypassGrantForTheProcess());
    }

    public function testAPlainConversationsToolRunsUnderTheCap(): void
    {
        $usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
        $toolCallChild = new BudgetLiftRecordingExecuteToolCallCommand($usageEnvelopesServiceDouble);

        $toolCallChild->runToolInsideEnvelope($this->buildConversation(budgetBypass: false));

        self::assertNull($toolCallChild->grantReasonDuringTool);
        self::assertCount(1, $usageEnvelopesServiceDouble->openedEnvelopeContexts, 'every forked call gets its envelope, granted or not');
    }

    public function testTheEnvelopeEndsWhenTheToolThrows(): void
    {
        $usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
        $toolCallChild = new BudgetLiftRecordingExecuteToolCallCommand($usageEnvelopesServiceDouble);
        $toolCallChild->toolFailure = new RuntimeException('tool timed out');

        try {
            $toolCallChild->runToolInsideEnvelope($this->buildConversation(budgetBypass: true));
            self::fail('the tool error reaches the catch that writes the FAILED outcome');
        } catch (RuntimeException $toolError) {
            self::assertSame('tool timed out', $toolError->getMessage());
        }

        self::assertFalse($usageEnvelopesServiceDouble->hasActiveEnvelope());
    }

    public function testAnAlreadyOpenEnvelopeIsReusedAndItsGrantRestored(): void
    {
        $usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
        $usageEnvelopesServiceDouble->openEnvelope(77, AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN);
        $toolCallChild = new BudgetLiftRecordingExecuteToolCallCommand($usageEnvelopesServiceDouble);

        $toolCallChild->runToolInsideEnvelope($this->buildConversation(budgetBypass: true));

        self::assertCount(1, $usageEnvelopesServiceDouble->openedEnvelopeContexts, 'no second envelope');
        self::assertSame(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS, $toolCallChild->grantReasonDuringTool);
        self::assertTrue($usageEnvelopesServiceDouble->hasActiveEnvelope());
        self::assertNull($usageEnvelopesServiceDouble->getBudgetBypassGrant(), 'the open envelope carries no grant after the call');
    }

    public function testAToolRunOverTheBudgetBecomesAFailedOutcome(): void
    {
        $toolCallChild = new BudgetLiftRecordingExecuteToolCallCommand(new RecordingUsageEnvelopesServiceDouble());
        $toolCallChild->toolFailure = new AiBudgetExceededException('the monthly AI budget is used up');
        $toolCallChild->conversationOfRun = $this->buildConversation(budgetBypass: false);
        $toolCallMessage = new AIConversationMessage();
        $toolCallMessage->id = 501;
        $toolCallMessage->aiConversationId = 4711;
        $toolCall = new ToolCall();
        $toolCall->name = 'searchArchive';
        $toolCallMessage->toolCall = $toolCall;
        $toolCallChild->toolCallMessageOfRun = $toolCallMessage;

        $exitCode = (new CommandTester($toolCallChild))->execute(['messageId' => '501', '--lease' => 'lease-of-the-worker']);

        self::assertSame(Command::SUCCESS, $exitCode, 'a failed tool is an outcome, not a crash of the child');
        self::assertSame([$toolCallMessage], $toolCallChild->messagesServiceDouble->writtenOutcomeMessages);
        self::assertSame(AIConversationMessage::STATUS_FAILED, $toolCall->status);
        self::assertStringStartsWith(AiBudgetExceededException::ERROR_CODE . ': the account\'s monthly AI budget is used up', (string)$toolCall->result);
        self::assertSame('lease-of-the-worker', $toolCall->executionLease, 'the lease stays on the written outcome');
    }

    public function testTheChildNeverGrantsForTheProcess(): void
    {
        $commandSource = (string)file_get_contents((string)(new ReflectionClass(AgentExecuteToolCallCommand::class))->getFileName());

        self::assertStringNotContainsString('grantBudgetBypassForTheProcess', $commandSource);
    }

    protected function buildConversation(bool $budgetBypass): AIConversation
    {
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->id = 4711;
        $conversation->accountId = 77;
        $conversation->budgetBypass = $budgetBypass;
        return $conversation;
    }
}
