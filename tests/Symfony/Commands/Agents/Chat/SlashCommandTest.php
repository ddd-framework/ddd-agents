<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Chat;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentTurn;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Environment\AgentApplicationNames;
use DDD\Domain\Base\Entities\ChildrenSet;
use DDD\Infrastructure\Traits\AfterConstruct\Attributes\AfterConstruct;
use DDD\Presentation\Mcp\Agent\AgentConsolePrinter;
use DDD\Symfony\Commands\Agents\Chat\CopySlashCommand;
use DDD\Symfony\Commands\Agents\Chat\HelpSlashCommand;
use DDD\Symfony\Commands\Agents\Chat\SlashCommandContext;
use DDD\Symfony\Commands\Agents\Chat\SlashCommandRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The slash-command registry of the console chat and the transcript `/copy` takes: built from constructor-less
 * messages, no agent, no database.
 */
class SlashCommandTest extends UnitTestCase
{
    public static function setUpBeforeClass(): void
    {
        AfterConstruct::$afterConstructMethods = AfterConstruct::$afterConstructMethods ?? [];
        // renderTranscript builds the agent turns, which constructs these classes with `new`: registered here, their
        // construction skips the lazy-load preparation
        foreach ([AIConversationMessages::class, AgentTurn::class, ChildrenSet::class] as $constructedClass) {
            AfterConstruct::$afterConstructMethods[$constructedClass] = [];
        }
    }

    public function testTheRegistryDetectsAndRegistersCommands(): void
    {
        $slashCommandRegistry = $this->buildRegistry();

        self::assertTrue($slashCommandRegistry->isSlashCommand('/copy'));
        self::assertTrue($slashCommandRegistry->isSlashCommand('  /help me'));
        self::assertFalse($slashCommandRegistry->isSlashCommand('hello /not a command'));
        self::assertSame(['help', 'copy'], $slashCommandRegistry->commands()->getNames());
        self::assertInstanceOf(CopySlashCommand::class, $slashCommandRegistry->commands()->getByName('COPY'));
    }

    public function testACommandRegisteredAgainUnderTheSameNameReplacesTheFirstInPlace(): void
    {
        $slashCommandRegistry = $this->buildRegistry();
        $replacingCommand = new RecordingSlashCommandDouble('Help', 'An own help.');
        $slashCommandRegistry->register($replacingCommand);

        self::assertSame(['help', 'copy'], $slashCommandRegistry->commands()->getNames());
        self::assertSame($replacingCommand, $slashCommandRegistry->commands()->getByName('help'));
    }

    public function testDispatchFindsTheCommandWhateverTheCaseAndHandsOverTheArguments(): void
    {
        $slashCommandContext = $this->buildContext($this->buildConversation([]), new BufferedOutput());
        $recordingCommand = new RecordingSlashCommandDouble('note');
        $slashCommandContext->registry->register($recordingCommand);

        self::assertTrue($slashCommandContext->registry->dispatch('  /NOTE  first   words ', $slashCommandContext));
        self::assertSame(['first   words'], $recordingCommand->recordedArguments);
    }

    public function testHelpListsEveryCommand(): void
    {
        $output = new BufferedOutput();
        $slashCommandContext = $this->buildContext($this->buildConversation([]), $output);

        self::assertTrue($slashCommandContext->registry->dispatch('/help', $slashCommandContext));
        $renderedHelp = $output->fetch();
        self::assertStringContainsString('/help', $renderedHelp);
        self::assertStringContainsString('/copy', $renderedHelp);
        self::assertStringContainsString('multi-line', $renderedHelp);
    }

    public function testExitAndQuitEndTheLoop(): void
    {
        $slashCommandContext = $this->buildContext($this->buildConversation([]), new BufferedOutput());

        self::assertFalse($slashCommandContext->registry->dispatch('/exit', $slashCommandContext));
        self::assertFalse($slashCommandContext->registry->dispatch('/quit', $slashCommandContext));
    }

    public function testAnUnknownCommandIsReportedAndTheLoopGoesOn(): void
    {
        $output = new BufferedOutput();
        $slashCommandContext = $this->buildContext($this->buildConversation([]), $output);

        self::assertTrue($slashCommandContext->registry->dispatch('/bogus', $slashCommandContext));
        self::assertStringContainsString('Unknown command: /bogus', $output->fetch());
    }

    public function testTheTranscriptOfCopyCarriesTheFullToolResult(): void
    {
        $longToolResult = str_repeat('X', 500);
        $conversation = $this->buildConversation([
            $this->buildTextMessage(1, AIConversationMessage::ROLE_USER, 'my open orders'),
            $this->buildToolCallMessage(2, 'getOrders', '{"status":"open"}', 'COMPLETED', $longToolResult),
            $this->buildTextMessage(3, AIConversationMessage::ROLE_ASSISTANT, 'You have three open orders.'),
        ]);

        /** @var AgentConsolePrinter $agentConsolePrinter */
        $agentConsolePrinter = self::instantiateWithoutConstructor(AgentConsolePrinter::class);
        $transcript = $agentConsolePrinter->renderTranscript($conversation, true, false, false);

        self::assertStringContainsString('you> my open orders', $transcript);
        self::assertStringContainsString('getOrders({"status":"open"})', $transcript);
        self::assertStringContainsString($longToolResult, $transcript, 'the full tool result is copied, not the collapsed summary');
        self::assertStringNotContainsString('chars collapsed', $transcript);
        self::assertStringContainsString(AgentApplicationNames::getConsoleAssistantLabel() . '> You have three open orders.', $transcript);
        self::assertStringContainsString('· turn 0:', $transcript, 'the per-turn metrics line is included');
    }

    protected function buildRegistry(): SlashCommandRegistry
    {
        $slashCommandRegistry = new SlashCommandRegistry();
        $slashCommandRegistry->register(new HelpSlashCommand());
        $slashCommandRegistry->register(new CopySlashCommand());
        return $slashCommandRegistry;
    }

    protected function buildContext(AIConversation $conversation, BufferedOutput $output): SlashCommandContext
    {
        /** @var AgentConsolePrinter $agentConsolePrinter */
        $agentConsolePrinter = self::instantiateWithoutConstructor(AgentConsolePrinter::class);
        return new SlashCommandContext($conversation, $output, $agentConsolePrinter, $this->buildRegistry(), true, false, false);
    }

    /** @param AIConversationMessage[] $messages */
    protected function buildConversation(array $messages): AIConversation
    {
        /** @var AIConversationMessages $conversationMessages */
        $conversationMessages = self::instantiateWithoutConstructor(AIConversationMessages::class);
        $conversationMessages->elements = $messages;
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->messages = $conversationMessages;
        return $conversation;
    }

    protected function buildTextMessage(int $messageId, string $role, string $messageText): AIConversationMessage
    {
        /** @var AIConversationMessage $textMessage */
        $textMessage = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $textMessage->id = $messageId;
        $textMessage->indexInConversation = $messageId;
        $textMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $textMessage->role = $role;
        $textMessage->authorType = $role === AIConversationMessage::ROLE_USER ? AIConversationMessage::AUTHOR_USER : AIConversationMessage::AUTHOR_AGENT;
        $textMessage->message = $messageText;
        return $textMessage;
    }

    protected function buildToolCallMessage(int $messageId, string $toolName, string $argumentsJson, string $status, string $toolResult): AIConversationMessage
    {
        /** @var AIConversationMessage $toolCallMessage */
        $toolCallMessage = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $toolCallMessage->id = $messageId;
        $toolCallMessage->indexInConversation = $messageId;
        $toolCallMessage->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->arguments = json_decode($argumentsJson, true) ?: [];
        $toolCall->status = $status;
        $toolCall->result = $toolResult;
        $toolCallMessage->toolCall = $toolCall;
        return $toolCallMessage;
    }
}
