<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Widgets\OwnerQuestionPick;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * In a live setup the owner picked "I want an Amazon-like website" on the agent's own keyless widget and the next
 * turn read it as a typed wish and refused it — the pick on ANY widget is read back, with the option's description (read
 * by the owner-question widget's class).
 */
class AIConversationMessagesOwnerPickOnAnyWidgetTest extends UnitTestCase
{
    protected function message(int $index, string $role, string $text, string $authorType): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->message = $text;
        return $message;
    }

    protected function widgetText(array $arguments): string
    {
        $question = AskOwnerTool::checkArguments($arguments)->question;
        self::assertNotNull($question);
        return 'Here is what I need next.' . "\n\n" . AskOwnerTool::buildWidgetMarkdown($question, null);
    }

    /** The owner's pick on the newest owner-question widget, keyed or not, read by the widget's class. */
    protected static function readPickOnTheNewestQuestion(AIConversationMessages $messages): ?OwnerQuestionPick
    {
        $newestQuestionWidget = AskOwnerTool::findNewestShownOwnerQuestionWidget($messages->buildShownWidgets(), acceptKeylessWidgets: true);
        return $newestQuestionWidget !== null ? AskOwnerTool::readOwnerPickOnShownWidget($newestQuestionWidget) : null;
    }

    public function testAPickOnAKeylessWidgetIsReadBackWithItsDescription(): void
    {
        $messages = new AIConversationMessages();
        $messages->add($this->message(10, AIConversationMessage::ROLE_ASSISTANT, $this->widgetText([
            'question' => 'Is this what you offer, or something else?',
            'options' => [['label' => "That's our service"], ['label' => 'I want an Amazon-like website', 'description' => "I'm asking for a website similar to Amazon."]],
        ]), AIConversationMessage::AUTHOR_ASSISTANT));
        $messages->add($this->message(12, AIConversationMessage::ROLE_USER, 'I want an Amazon-like website', AIConversationMessage::AUTHOR_USER));
        $pick = self::readPickOnTheNewestQuestion($messages);
        self::assertNotNull($pick);
        self::assertNull($pick->questionKey);
        self::assertSame(12, $pick->reactionIndexInConversation);
        self::assertSame(['I want an Amazon-like website'], $pick->labels);
        self::assertSame(["I'm asking for a website similar to Amazon."], $pick->descriptions);
        $signal = AskOwnerTool::buildPickSignalTextForPick($pick);
        self::assertStringContainsString('The owner PICKED "I want an Amazon-like website" (I\'m asking for a website similar to Amazon.) on your widget', $signal);
        self::assertStringContainsString('never refuse what you offered', $signal);
    }

    public function testATypedAnswerIsNoPickAndAKeyedWidgetKeepsItsKey(): void
    {
        $messages = new AIConversationMessages();
        $messages->add($this->message(10, AIConversationMessage::ROLE_ASSISTANT, $this->widgetText(['question' => 'Keep the draft?', 'options' => ['Keep it', 'Change something'], 'questionKey' => 'draftApproval', 'optionMeanings' => ['approved', 'change']]), AIConversationMessage::AUTHOR_ASSISTANT));
        $messages->add($this->message(12, AIConversationMessage::ROLE_USER, 'Please change the phone number first.', AIConversationMessage::AUTHOR_USER));
        self::assertNull(self::readPickOnTheNewestQuestion($messages), 'the owner typed');
        $messages->add($this->message(14, AIConversationMessage::ROLE_ASSISTANT, $this->widgetText(['question' => 'Keep the draft?', 'options' => ['Keep it', 'Change something'], 'questionKey' => 'draftApproval', 'optionMeanings' => ['approved', 'change']]), AIConversationMessage::AUTHOR_ASSISTANT));
        $messages->add($this->message(16, AIConversationMessage::ROLE_USER, 'Keep it', AIConversationMessage::AUTHOR_USER));
        $pick = self::readPickOnTheNewestQuestion($messages);
        self::assertSame('draftApproval', $pick?->questionKey, 'a keyed widget feeds the platform read-back — no extra signal for it');
        self::assertSame(['Keep it'], $pick->labels);
    }

    public function testTheCurrentTurnKnowsItsSignalSources(): void
    {
        $messages = new AIConversationMessages();
        $messages->add($this->message(1, AIConversationMessage::ROLE_USER, 'hi', AIConversationMessage::AUTHOR_USER));
        self::assertFalse($messages->buildCurrentTurn()->hasSignalOfSource(AIConversationMessage::SIGNAL_SOURCE_OWNER_QUESTION_PICK));
        $signal = new AIConversationMessage();
        $signal->indexInConversation = 2;
        $signal->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $signal->role = AIConversationMessage::ROLE_SYSTEM;
        $signal->authorType = AIConversationMessage::AUTHOR_AGENT;
        $signal->signalSource = AIConversationMessage::SIGNAL_SOURCE_OWNER_QUESTION_PICK;
        $signal->message = 'The owner PICKED …';
        $messages->add($signal);
        self::assertTrue($messages->buildCurrentTurn()->hasSignalOfSource(AIConversationMessage::SIGNAL_SOURCE_OWNER_QUESTION_PICK));
    }
}
