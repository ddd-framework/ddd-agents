<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The conversational context the turn-start decision sites read: the assistant's last text and the recent turns before a message. */
class AIConversationMessagesRecentTurnTextsTest extends UnitTestCase
{
    protected function message(string $role, ?string $authorType, string $messageType, string $text, int $index): AIConversationMessage
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

    public function testOwnerAndAssistantTextsBeforeTheMessageNewestLastSignalsAndPreCallLinesSkipped(): void
    {
        $messages = new AIConversationMessages();
        $messages->add($this->message(AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::MESSAGE_SIGNAL, 'User picked the directory data task', 0));
        $messages->add($this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::PRE_CALL_MESSAGE_MARKER . 'Ich prüfe das.', 1));
        $messages->add($this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, 'Drei Einträge weichen ab. Soll ich veröffentlichen?', 2));
        $messages->add($this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, 'Ja, veröffentlichen', 3));
        $messages->add($this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, 'Erledigt. Was davon soll es sein?', 4));
        $messages->add($this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, 'google rankings', 5));
        $messages->add($this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, 'later text, after the message', 6));

        $recentTurnTexts = $messages->buildOwnerTurns()->buildRecentTurnTextsBefore(5);
        self::assertSame('Erledigt. Was davon soll es sein?', $recentTurnTexts->assistantTextBefore);
        self::assertSame([
            'assistant: Drei Einträge weichen ab. Soll ich veröffentlichen?',
            'owner: Ja, veröffentlichen',
            'assistant: Erledigt. Was davon soll es sein?',
        ], $recentTurnTexts->recentTurns);
    }

    public function testTheWindowAndTheCutAreCapped(): void
    {
        $messages = new AIConversationMessages();
        for ($i = 0; $i < 10; $i++) {
            $messages->add($this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, 'owner text ' . $i . ' ' . str_repeat('x', 400), $i));
        }
        $recentTurnTexts = $messages->buildOwnerTurns()->buildRecentTurnTextsBefore(10, 6, 20);
        self::assertCount(6, $recentTurnTexts->recentTurns);
        self::assertSame('owner: owner text 4 xxxxxxx', $recentTurnTexts->recentTurns[0]);
        self::assertSame('', $recentTurnTexts->assistantTextBefore);
    }
    /**
     * The skill preload's thread (characterized against its former own formatter): one line per turn,
     * whitespace folded. Two deliberate differences to that formatter: a pre-call line is not a turn, and an owner
     * line needs the owner as its author.
     */
    public function testThePreloadReadsOneLinePerTurnWithItsWhitespaceFolded(): void
    {
        $messages = new AIConversationMessages();
        $messages->add($this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, "Wie stehen\n\nmeine   Rankings?", 0));
        $messages->add($this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::PRE_CALL_MESSAGE_MARKER . 'Ich schaue nach.', 1));
        $messages->add($this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, "Platz 3\tfür „zahnarzt“.", 2));
        $messages->add($this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_AGENT, AIConversationMessage::MESSAGE_TEXT, 'a user-role row the agent wrote', 3));
        $messages->add($this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, 'Und die Bewertungen?', 4));

        self::assertSame([
            'owner: Wie stehen meine Rankings?',
            'assistant: Platz 3 für „zahnarzt“.',
        ], $messages->buildOwnerTurns()->buildRecentTurnTextsBefore(4, collapseWhitespace: true)->recentTurns);
    }
}
