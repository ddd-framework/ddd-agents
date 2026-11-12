<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The render-time status-line dedupe: the shared predicate
 * (AIConversationMessage::isStatusOnlyAnnouncementLine — protocol-structural, marker-declared, never a
 * natural-language guess) and the ordered-list filter (a second-or-later status line since the last USER
 * message never renders; the first always does; a USER message resets the allowance). Persistence is
 * untouched — the filter runs only at user-visible serialization sites.
 */
class StatusLineRenderFilterTest extends UnitTestCase
{
    protected function message(string $role, string $content, string $messageType = AIConversationMessage::MESSAGE_TEXT): AIConversationMessage
    {
        $conversationMessage = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $conversationMessage->role = $role;
        $conversationMessage->messageType = $messageType;
        $conversationMessage->message = $content;
        return $conversationMessage;
    }

    public function testPredicateAcceptsOnlySingleLineMarkerPrefixedAssistantText(): void
    {
        $statusLine = $this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::PRE_CALL_MESSAGE_MARKER . ' Ich lade jetzt deine Rankings.');
        self::assertTrue($statusLine->isStatusOnlyAnnouncementLine());

        // a marker message carrying MORE lines transports results — a content carrier, never suppressed
        $markerWithContent = $this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::PRE_CALL_MESSAGE_MARKER . " Kurzer Status\nHier die Zahlen: 42 Bewertungen.");
        self::assertFalse($markerWithContent->isStatusOnlyAnnouncementLine());

        // marker mid-text, plain prose, user messages: never status lines
        self::assertFalse($this->message(AIConversationMessage::ROLE_ASSISTANT, 'Fertig. ' . AIConversationMessage::PRE_CALL_MESSAGE_MARKER)->isStatusOnlyAnnouncementLine());
        self::assertFalse($this->message(AIConversationMessage::ROLE_ASSISTANT, 'Deine Rankings sind da.')->isStatusOnlyAnnouncementLine());
        self::assertFalse($this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::PRE_CALL_MESSAGE_MARKER . ' hi')->isStatusOnlyAnnouncementLine());

        // the MANGLED JSON form some models emit is normalized before the check
        $mangledStatusLine = $this->message(AIConversationMessage::ROLE_ASSISTANT, '{ "preCallMessage": "Einen Moment bitte" }');
        self::assertTrue($mangledStatusLine->isStatusOnlyAnnouncementLine());
    }

    public function testSecondConsecutiveStatusLineIsFilteredAndAUserMessageResetsTheFold(): void
    {
        $marker = AIConversationMessage::PRE_CALL_MESSAGE_MARKER;
        $orderedMessages = [
            $userQuestion = $this->message(AIConversationMessage::ROLE_USER, 'Wie stehe ich da?'),
            $firstStatusLine = $this->message(AIConversationMessage::ROLE_ASSISTANT, $marker . ' Ich lese deine Rankings.'),
            $secondStatusLine = $this->message(AIConversationMessage::ROLE_ASSISTANT, $marker . ' Ich prüfe die Sichtbarkeit.'),
            $contentAnswer = $this->message(AIConversationMessage::ROLE_ASSISTANT, 'Du stehst auf Platz 3.'),
            $thirdStatusLine = $this->message(AIConversationMessage::ROLE_ASSISTANT, $marker . ' Ich speichere noch kurz.'),
            $nextUserMessage = $this->message(AIConversationMessage::ROLE_USER, 'Und weiter?'),
            $statusLineOfNextTurn = $this->message(AIConversationMessage::ROLE_ASSISTANT, $marker . ' Ich lade die Keywords.'),
        ];
        $renderableMessages = AIConversationMessages::filterRepeatedStatusLineMessages($orderedMessages);
        self::assertSame(
            [$userQuestion, $firstStatusLine, $contentAnswer, $nextUserMessage, $statusLineOfNextTurn],
            $renderableMessages,
            'second + third status line of the turn are suppressed; the next turn starts fresh'
        );
    }
}
