<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLines;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartLineDecision;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/**
 * The activity stack of a turn whose turn-start decision said the assistant works before it
 * answers opens with ONE element for the owner message — its id the owner message's id, kind `turnStart`, `running`
 * until the turn's first step or text exists, `finished` after, in front of the tool elements, its line one sentence per
 * owner message whatever the state. The wire carries the line, the id and the state, nothing the narrator reads.
 */
class AgentActivityLinesServiceTurnStartElementTest extends UnitTestCase
{
    protected const int OWNER_MESSAGE_ID = 5501;

    protected function service(): AgentActivityLinesService
    {
        return new class extends AgentActivityLinesService {
            public function __construct()
            {
            }
        };
    }

    public function testAnAnnouncedTurnOpensWithARunningElementForTheOwnerMessage(): void
    {
        $conversation = $this->conversation(true);

        $element = $this->service()->buildTurnStartElement($conversation, null, new AgentActivityLines());

        self::assertNotNull($element);
        self::assertSame(self::OWNER_MESSAGE_ID, $element->toolCallMessageId);
        self::assertSame(AgentActivityLine::KIND_TURN_START, $element->kind);
        self::assertSame(AgentActivityLine::STATE_RUNNING, $element->state);
        self::assertNull($element->line);
        self::assertSame(0, $element->childCount);
        self::assertNull($element->helperIndex);
        self::assertTrue($element->wantsLine());
        $wireFields = json_decode((string)json_encode($element->toObject()), true);
        self::assertArrayNotHasKey('narratorToolName', $wireFields);
        self::assertArrayNotHasKey('narratorArguments', $wireFields);
    }

    public function testTheElementIsFinishedOnceTheTurnHasAStepOrAText(): void
    {
        $service = $this->service();
        $conversation = $this->conversation(true);
        $toolElements = new AgentActivityLines();
        $toolElement = new AgentActivityLine();
        $toolElement->toolCallMessageId = 5502;
        $toolElements->add($toolElement);
        self::assertSame(AgentActivityLine::STATE_FINISHED, $service->buildTurnStartElement($conversation, null, $toolElements)?->state, 'a first step');

        $assistantText = new AIConversationMessage();
        $assistantText->indexInConversation = 1;
        $assistantText->messageType = AIConversationMessage::MESSAGE_TEXT;
        $assistantText->role = AIConversationMessage::ROLE_ASSISTANT;
        $assistantText->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $assistantText->message = 'Deine Bewertungen sind gut.';
        $conversation->messages->add($assistantText);
        self::assertSame(AgentActivityLine::STATE_FINISHED, $service->buildTurnStartElement($conversation, null, new AgentActivityLines())?->state, 'a text');
    }

    public function testNoElementWithoutAnAnnouncingDecisionOfThisOwnerMessage(): void
    {
        $service = $this->service();
        self::assertNull($service->buildTurnStartElement($this->conversation(false), null, new AgentActivityLines()), 'answered at once');

        $conversationOfAnEarlierDecision = $this->conversation(true);
        $conversationOfAnEarlierDecision->agentWorkingState->turnStartLineDecision->ownerMessageIndex = 7;
        self::assertNull($service->buildTurnStartElement($conversationOfAnEarlierDecision, null, new AgentActivityLines()), 'a decision of another owner message');

        $withoutWorkingState = $this->conversation(true);
        $withoutWorkingState->agentWorkingState = new AgentWorkingState();
        self::assertNull($service->buildTurnStartElement($withoutWorkingState, null, new AgentActivityLines()));
    }

    public function testTheElementComesFirstAndItsLineIsOneSentenceWhateverTheState(): void
    {
        $service = $this->service();
        $toolElements = new AgentActivityLines();
        $toolElement = $service->buildElement($this->toolCallMessage());
        self::assertNotNull($toolElement);
        $toolElements->add($toolElement);

        $elements = (new ReflectionMethod(AgentActivityLinesService::class, 'withTurnStartElementFirst'))->invoke($service, $this->conversation(true), null, $toolElements);

        self::assertSame([AgentActivityLine::KIND_TURN_START, AgentActivityLine::KIND_TOOL], array_map(static fn (AgentActivityLine $element): string => $element->kind, $elements->getElements()));
        $turnStartElement = $elements->getElements()[0];
        $lineCacheKey = new ReflectionMethod(AgentActivityLinesService::class, 'lineCacheKey');
        $runningKey = $lineCacheKey->invoke($service, $turnStartElement, 'de');
        $turnStartElement->state = AgentActivityLine::STATE_FINISHED;
        self::assertSame($runningKey, $lineCacheKey->invoke($service, $turnStartElement, 'de'));
        self::assertStringContainsString(':turnStart:' . self::OWNER_MESSAGE_ID . ':de', $runningKey);
    }

    protected function conversation(bool $announces): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = new AIConversationMessages();
        $ownerMessage = new AIConversationMessage();
        $ownerMessage->id = self::OWNER_MESSAGE_ID;
        $ownerMessage->indexInConversation = 0;
        $ownerMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerMessage->role = AIConversationMessage::ROLE_USER;
        $ownerMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $ownerMessage->message = 'Wie haben sich meine Bewertungen entwickelt?';
        $conversation->messages->add($ownerMessage);
        $decision = AgentTurnStartLineDecision::build(0, self::OWNER_MESSAGE_ID);
        $decision->decidedBy = AgentTurnStartLineDecision::DECIDED_BY_DECISION_MODEL;
        $decision->resolveFromWorkBeforeAnswerProbability($announces ? 0.9 : 0.1);
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->turnStartLineDecision = $decision;
        $conversation->agentWorkingState = $agentWorkingState;
        return $conversation;
    }

    protected function toolCallMessage(): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->id = 5503;
        $message->indexInConversation = 1;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $toolCall = new ToolCall();
        $toolCall->name = 'listReviews';
        $toolCall->arguments = [];
        $message->toolCall = $toolCall;
        return $message;
    }
}
