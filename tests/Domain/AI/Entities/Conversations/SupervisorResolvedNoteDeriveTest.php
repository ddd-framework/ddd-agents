<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A resolved supervisor note consumes a decision the driver had already carried out — it is never owed work, while a
 * live steer or correction trailing the final text still demands a turn.
 */
class SupervisorResolvedNoteDeriveTest extends UnitTestCase
{
    protected function conversationEndingWith(string $signalText, string $signalSource): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = new AIConversationMessages();
        $conversation->appendMessage(new AIConversationMessage(), AIConversationMessage::MESSAGE_TEXT, 'goal', role: AIConversationMessage::ROLE_USER);
        $conversation->appendMessage(new AIConversationMessage(), AIConversationMessage::MESSAGE_TEXT, 'handover', role: AIConversationMessage::ROLE_ASSISTANT);
        $conversation->appendMessage(new AIConversationMessage(), AIConversationMessage::MESSAGE_SIGNAL, $signalText, role: AIConversationMessage::ROLE_SYSTEM, signalSource: $signalSource);
        return $conversation;
    }

    public function testAResolvedNoteAfterTheFinalTextOwesNoTurn(): void
    {
        $conversation = $this->conversationEndingWith(
            AIConversationMessage::SUPERVISOR_RESOLVED_NOTE_PREFIX . ' a `set_task_status` call completed after the supervisor\'s evidence.',
            AIConversationMessage::SIGNAL_SOURCE_SUPERVISOR_STEERING
        );
        self::assertFalse($conversation->hasUnansweredInboundMessage());
        self::assertTrue($conversation->lastWireMessageIsFinalAssistantText());
    }

    public function testALiveSteerAfterTheFinalTextStillDemandsATurn(): void
    {
        $conversation = $this->conversationEndingWith('[SUPERVISOR] Do this NOW: restore the link.', AIConversationMessage::SIGNAL_SOURCE_SUPERVISOR_STEERING);
        self::assertTrue($conversation->hasUnansweredInboundMessage());
        self::assertFalse($conversation->lastWireMessageIsFinalAssistantText());
    }
}
