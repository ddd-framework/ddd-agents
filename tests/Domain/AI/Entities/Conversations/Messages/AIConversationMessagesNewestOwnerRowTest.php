<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetReaction;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The owner's widget click is an owner row but no turn initiator: a reader of "what did the owner just do" walks the
 * owner's reactions ({@see ConversationWidgetReaction::findNewestIn()}).
 */
class AIConversationMessagesNewestOwnerRowTest extends UnitTestCase
{
    protected function message(int $index, string $type, string $text, ?string $role, ?string $authorType, ?string $signalSource = null): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = $type;
        $message->message = $text;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->signalSource = $signalSource;
        $message->toolCall = null;
        return $message;
    }

    protected function set(array $messages): AIConversationMessages
    {
        $set = self::instantiateWithoutConstructor(AIConversationMessages::class);
        self::setPropertyValue($set, 'elements', $messages);
        self::setPropertyValue($set, 'elementCount', count($messages));
        return $set;
    }

    public function testTheClickSignalIsTheNewestOwnerRowButTheTextStaysTheInitiator(): void
    {
        $ownerText = $this->message(10, AIConversationMessage::MESSAGE_TEXT, 'Passt so.', AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER);
        $agentText = $this->message(11, AIConversationMessage::MESSAGE_TEXT, 'Die Konkurrenten sind gespeichert.', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT);
        $click = $this->message(12, AIConversationMessage::MESSAGE_SIGNAL, 'User submitted the competitor selection on the ProposalSelection widget: remove=["a.com"].', AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK);
        $applied = $this->message(13, AIConversationMessage::MESSAGE_SIGNAL, 'PLATFORM APPLIED …', AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::SIGNAL_SOURCE_OWNER_WIDGET_EDIT_APPLIED);
        $set = $this->set([$ownerText, $agentText, $click, $applied]);

        self::assertSame(12, ConversationWidgetReaction::findNewestIn($set)?->indexInConversation);
        self::assertSame(10, $set->findNewestInitiatorMessage()?->indexInConversation);
        self::assertSame(13, $set->findNewestSignalOfSources([AIConversationMessage::SIGNAL_SOURCE_OWNER_WIDGET_EDIT_APPLIED])?->indexInConversation);
        self::assertSame('PLATFORM APPLIED …', $set->findNewestSignalTextOfSources([AIConversationMessage::SIGNAL_SOURCE_OWNER_WIDGET_EDIT_APPLIED]));
        self::assertNull(ConversationWidgetReaction::findNewestIn($this->set([$agentText])));
    }
}
