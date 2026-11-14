<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Symfony\Commands\Agents\AgentActivityLinesCommand;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The replay of `app:ai-agent:activity-lines` over a scripted narrator: per model the lines of the started and the
 * finished moment of every tool call of a turn, the calls per moment (pending elements ÷ batch size, rounded up), the
 * summed figures, lines against missing lines, and the `--json` printout with a key only for the moments the narrator
 * was asked for. Two owner turns (@0, @5) and a third (@8) without tool calls; #31 sits behind the start of turn @8.
 */
class AgentActivityLinesCommandReplayTest extends IntegrationTestCase
{
    public const int CONVERSATION_ID = 990100;

    protected ScriptedActivityLinesNarratorServiceDouble $narratorServiceDouble;

    protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->narratorServiceDouble = new ScriptedActivityLinesNarratorServiceDouble();
        $this->narratorServiceDouble->scriptedElementsByTurnIndex = [
            0 => [
                $this->buildActivityLine(11, 'searchArchive', AgentActivityLine::STATE_FINISHED, AgentActivityLine::KIND_TOOL),
                $this->buildActivityLine(12, 'listFolders', AgentActivityLine::STATE_SKIPPED, AgentActivityLine::KIND_TOOL),
                $this->buildActivityLine(13, ScriptedActivityLinesNarratorServiceDouble::REFUSED_TOOL_NAME, AgentActivityLine::STATE_FAILED, AgentActivityLine::KIND_TOOL),
            ],
            5 => [
                $this->buildActivityLine(21, 'delegate', AgentActivityLine::STATE_RUNNING, AgentActivityLine::KIND_DELEGATION),
                $this->buildActivityLine(22, 'updateNotes', AgentActivityLine::STATE_FINISHED, AgentActivityLine::KIND_TOOL),
                $this->buildActivityLine(31, 'rowOfTheNextTurn', AgentActivityLine::STATE_FINISHED, AgentActivityLine::KIND_TOOL),
            ],
        ];
        $replayedConversation = AIConversation::newInstance();
        $replayedConversation->id = self::CONVERSATION_ID;
        $replayedConversation->accountId = 77;
        $conversationsServiceDouble = new ConversationsServiceWithScriptedConversationDouble();
        $conversationsServiceDouble->scriptedConversation = $replayedConversation;
        $messagesServiceDouble = new MessagesServiceWithScriptedMessagesDouble();
        $messagesServiceDouble->scriptedMessages = $this->buildReplayedMessages();
        $this->usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
        $this->replaceService(AgentActivityLinesService::class, $this->narratorServiceDouble);
        $this->replaceService(AIConversationsService::class, $conversationsServiceDouble);
        $this->replaceService(AIConversationMessagesService::class, $messagesServiceDouble);
        $this->replaceService(AIUsageEnvelopesService::class, $this->usageEnvelopesServiceDouble);
        StaticRegistry::$entityServices = [];
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$entityServices = [];
    }

    public function testTheJsonPrintoutCarriesTheLinesAndFiguresOfEveryModel(): void
    {
        $commandTester = new CommandTester(new AgentActivityLinesCommand());

        $exitCode = $commandTester->execute([
            '--conversation' => (string)self::CONVERSATION_ID,
            '--models' => 'model-a,model-b',
            '--effort' => 'low',
            '--batch' => '2',
            '--json' => true,
        ]);

        self::assertSame(0, $exitCode);
        $display = $commandTester->getDisplay();
        $jsonReport = json_decode(substr($display, (int)strpos($display, "{\n")), true);
        self::assertSame(
            ['conversationId' => self::CONVERSATION_ID, 'models' => [$this->buildExpectedModelJson('model-a'), $this->buildExpectedModelJson('model-b')]],
            $jsonReport
        );
        self::assertSame(
            [
                'turn=@0 model=model-a effort=low pending=2', 'turn=@0 model=model-a effort=low pending=2',
                'turn=@5 model=model-a effort=low pending=2', 'turn=@5 model=model-a effort=low pending=2',
                'turn=@0 model=model-b effort=low pending=2', 'turn=@0 model=model-b effort=low pending=2',
                'turn=@5 model=model-b effort=low pending=2', 'turn=@5 model=model-b effort=low pending=2',
            ],
            $this->narratorServiceDouble->narrateCalls
        );
        self::assertCount(8, $this->usageEnvelopesServiceDouble->openedEnvelopeContexts, 'one envelope per narrator round');
        self::assertSame(AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_ACTIVITY, $this->usageEnvelopesServiceDouble->openedEnvelopeContexts[0]->scope);
        self::assertSame(77, $this->usageEnvelopesServiceDouble->openedEnvelopeContexts[0]->accountId);
    }

    public function testTheTextPrintoutListsTheLinesPerTurnAndTheTotalsOfTheModel(): void
    {
        $commandTester = new CommandTester(new AgentActivityLinesCommand());

        self::assertSame(0, $commandTester->execute(['--conversation' => (string)self::CONVERSATION_ID, '--turn' => '0']));

        $display = $commandTester->getDisplay();
        self::assertStringContainsString('turn @0', $display);
        self::assertStringContainsString('started : Line for #11 (' . AgentActivityLine::STATE_QUEUED . ')', $display);
        self::assertStringContainsString('finished: Line for #11 (' . AgentActivityLine::STATE_FINISHED . ')', $display);
        self::assertStringContainsString('started : (none)', $display, 'the skipped element was never narrated when started');
        self::assertStringContainsString('answer was not the expected JSON', $display);
        self::assertStringContainsString('scripted-narrator: 2 lines, 2 missing, 2 narrator calls, 0.5 s, 400 in / 40 out tokens, $0.5000', $display);
        self::assertStringNotContainsString('turn @5', $display);
    }

    /**
     * @return array<string, mixed> the expected figures and turns of one model with batches of two
     */
    protected function buildExpectedModelJson(string $modelName): array
    {
        $queued = AgentActivityLine::STATE_QUEUED;
        $finished = AgentActivityLine::STATE_FINISHED;
        return [
            'model' => $modelName,
            'turns' => [
                ['turnIndex' => 0, 'elements' => [
                    ['id' => 11, 'tool' => 'searchArchive', 'state' => $queued, 'kind' => AgentActivityLine::KIND_TOOL, 'started' => "Line for #11 ({$queued})", 'finished' => "Line for #11 ({$finished})"],
                    ['id' => 13, 'tool' => ScriptedActivityLinesNarratorServiceDouble::REFUSED_TOOL_NAME, 'state' => $queued, 'kind' => AgentActivityLine::KIND_TOOL, 'started' => null, 'finished' => null],
                    ['id' => 12, 'tool' => 'listFolders', 'state' => AgentActivityLine::STATE_SKIPPED, 'kind' => AgentActivityLine::KIND_TOOL, 'finished' => null],
                ]],
                ['turnIndex' => 5, 'elements' => [
                    ['id' => 21, 'tool' => 'delegate', 'state' => $queued, 'kind' => AgentActivityLine::KIND_DELEGATION, 'started' => "Line for #21 ({$queued})", 'finished' => 'Line for #21 (' . AgentActivityLine::STATE_RUNNING . ')'],
                    ['id' => 22, 'tool' => 'updateNotes', 'state' => $queued, 'kind' => AgentActivityLine::KIND_TOOL, 'started' => "Line for #22 ({$queued})", 'finished' => "Line for #22 ({$finished})"],
                ]],
            ],
            'inputTokens' => 800,
            'outputTokens' => 80,
            'costUsd' => 1,
            'seconds' => 1,
            'calls' => 4,
            'lines' => 6,
            'missing' => 2,
        ];
    }

    protected function buildReplayedMessages(): AIConversationMessages
    {
        $messages = new AIConversationMessages();
        foreach ([501 => 0, 11 => 1, 12 => 2, 13 => 3, 506 => 5, 21 => 6, 22 => 7, 507 => 8, 31 => 9] as $messageId => $indexInConversation) {
            $isOwnerRow = in_array($messageId, [501, 506, 507], true);
            $message = new AIConversationMessage();
            $message->id = $messageId;
            $message->indexInConversation = $indexInConversation;
            $message->messageType = AIConversationMessage::MESSAGE_TEXT;
            $message->role = $isOwnerRow ? AIConversationMessage::ROLE_USER : AIConversationMessage::ROLE_ASSISTANT;
            $message->authorType = $isOwnerRow ? AIConversationMessage::AUTHOR_USER : AIConversationMessage::AUTHOR_ASSISTANT;
            $messages->add($message);
        }
        return $messages;
    }

    protected function buildActivityLine(int $toolCallMessageId, string $toolName, string $state, string $kind): AgentActivityLine
    {
        $activityLine = new AgentActivityLine();
        $activityLine->toolCallMessageId = $toolCallMessageId;
        $activityLine->narratorToolName = $toolName;
        $activityLine->state = $state;
        $activityLine->kind = $kind;
        $activityLine->narratorResultHead = 'result head of #' . $toolCallMessageId;
        return $activityLine;
    }
}
