<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The owner-words state of the reply-language decision: owner text only, capped, newest last. */
class AIConversationMessagesOwnerWordsTest extends UnitTestCase
{
    protected function message(string $role, string $authorType, string $messageType, string $text, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = $messageType;
        $message->message = $text;
        $message->indexInConversation = $index;
        return $message;
    }

    protected function messages(AIConversationMessage ...$messages): AIConversationMessages
    {
        $set = new AIConversationMessages();
        foreach ($messages as $message) {
            $set->add($message);
        }
        return $set;
    }

    public function testOnlyOwnerAuthoredTextsCount(): void
    {
        $messages = $this->messages(
            $this->message(AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::MESSAGE_SIGNAL, 'The setup conversation begins. Work it TOP-DOWN.', 0),
            $this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, 'Hey Iulian! Welcome!', 1),
            $this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, 'Iulian este în regulă', 2),
            $this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::MESSAGE_SIGNAL, '[SIGNAL:LOOP_NUDGE] English harness text', 3),
        );
        self::assertSame('Iulian este în regulă', $messages->buildOwnerTurns()->buildRecentOwnerWords());
    }

    public function testTheLastThreeOwnerMessagesAndTheLastFortyWordsNewestLast(): void
    {
        $older = implode(' ', array_fill(0, 45, 'alt')); // 45 + 4 + 3 = 52 words in the last three messages → capped to the LAST 40
        $messages = $this->messages(
            $this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, 'uralt uralt', 0),
            $this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, $older, 1),
            $this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, 'mittel eins zwei drei', 2),
            $this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, 'neu   neu' . "\n" . 'neu', 3),
        );
        $ownerWords = $messages->buildOwnerTurns()->buildRecentOwnerWords(maxMessages: 3);
        $words = explode(' ', $ownerWords);
        self::assertCount(40, $words);
        // the DEFAULT window is the newest message only (a language read from an earlier turn is not what the owner writes now)
        self::assertSame('neu neu neu', $messages->buildOwnerTurns()->buildRecentOwnerWords());
        self::assertStringNotContainsString('uralt', $ownerWords);
        self::assertSame(['neu', 'neu', 'neu'], array_slice($words, -3));
        self::assertSame('alt', $words[0]);
    }

    public function testNoOwnerTextYieldsTheEmptyString(): void
    {
        $messages = $this->messages(
            $this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, 'Hello there', 0),
            $this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, '   ', 1),
        );
        self::assertSame('', $messages->buildOwnerTurns()->buildRecentOwnerWords());
        self::assertSame('', (new AIConversationMessages())->buildOwnerTurns()->buildRecentOwnerWords());
    }
}
