<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationMessageEmbeddingsService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The recall-embedding GATE: only a real user/agent TEXT message that is persisted and has non-empty text is a recall
 * unit and gets an embedding row. SIGNAL (nudge / memory-conflict), TOOL_CALL, ERROR, CHECKPOINT, an empty TEXT turn
 * (an assistant turn that only emitted a tool call), and an unpersisted message must ALL be rejected — they carry no
 * embedding. This is what keeps junk out of the new embeddings table. {@see AIConversationMessageEmbeddingsService::isEmbeddable}.
 */
class AIConversationMessageEmbeddabilityTest extends UnitTestCase
{
    protected function service(): AIConversationMessageEmbeddingsService
    {
        // isEmbeddable is pure (reads only the message) — no injected state needed.
        return $this->instantiateWithoutConstructor(AIConversationMessageEmbeddingsService::class);
    }

    protected function message(string $messageType, ?string $role, ?string $text, ?int $id = 1): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->messageType = $messageType;
        $message->role = $role;
        $message->message = $text;
        if ($id !== null) {
            $this->setPropertyValue($message, 'id', $id);
        }
        return $message;
    }

    public function testOnlyRealUserAndAgentTextMessagesAreEmbeddable(): void
    {
        $service = $this->service();
        $U = AIConversationMessage::ROLE_USER;
        $A = AIConversationMessage::ROLE_ASSISTANT;

        // EMBEDDABLE — the two real conversation contents.
        self::assertTrue($service->isEmbeddable($this->message(AIConversationMessage::MESSAGE_TEXT, $U, 'What are my latest reviews?')), 'a real user TEXT message must be embeddable');
        self::assertTrue($service->isEmbeddable($this->message(AIConversationMessage::MESSAGE_TEXT, $A, 'You have 3 new 5-star reviews this week.')), 'a real assistant TEXT answer must be embeddable');

        // NOT EMBEDDABLE — every non-content row.
        self::assertFalse($service->isEmbeddable($this->message(AIConversationMessage::MESSAGE_TOOL_CALL, $A, 'listReviews(...)')), 'a TOOL_CALL message must NOT be embeddable');
        self::assertFalse($service->isEmbeddable($this->message(AIConversationMessage::MESSAGE_SIGNAL, $U, '[SIGNAL:LOOP_NUDGE] continue')), 'a SIGNAL message must NOT be embeddable');
        self::assertFalse($service->isEmbeddable($this->message(AIConversationMessage::MESSAGE_ERROR, $A, 'budget exceeded')), 'an ERROR message must NOT be embeddable');
        self::assertFalse($service->isEmbeddable($this->message(AIConversationMessage::MESSAGE_CHECKPOINT, $A, 'checkpoint')), 'a CHECKPOINT message must NOT be embeddable');

        // NOT EMBEDDABLE — a TEXT turn that produced no prose (only a tool call) → empty text.
        self::assertFalse($service->isEmbeddable($this->message(AIConversationMessage::MESSAGE_TEXT, $A, '')), 'an empty-text TEXT message must NOT be embeddable');
        self::assertFalse($service->isEmbeddable($this->message(AIConversationMessage::MESSAGE_TEXT, $A, '   ')), 'a whitespace-only TEXT message must NOT be embeddable');

        // NOT EMBEDDABLE — not persisted yet (no id to key the embedding row on).
        self::assertFalse($service->isEmbeddable($this->message(AIConversationMessage::MESSAGE_TEXT, $U, 'hello', id: null)), 'an unpersisted message must NOT be embeddable');
    }
}
