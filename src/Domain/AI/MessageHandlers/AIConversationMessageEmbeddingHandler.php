<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Worker that embeds one `AIConversationMessage` (async path). Thin per the convention: restore auth
 * → workspace guard → load the message → set its embedding via the ONE service seam → persist → log. All work lives
 * in {@see AIConversationMessagesService::embedMessage()}. The AI-usage envelope (scope `ENVELOPE.AI.CONVERSATION.EMBEDDING`)
 * is opened around this handler by `AIEnvelopeMiddleware` (the message's `#[WithAIEnvelope]`). Best-effort: an embedding
 * failure just leaves the message without an embedding row (simply not yet recall-searchable — the backfill
 * command catches it later).
 */
#[AsMessageHandler(fromTransport: AgentMessengerTransports::MESSAGE_EMBEDDING)]
class AIConversationMessageEmbeddingHandler extends AIDomainMessageHandler
{
    use AITraceLogTrait;

    public function __invoke(AIConversationMessageEmbeddingMessage $message): void
    {
        set_time_limit(600); // headroom for the embedding endpoint under load
        ini_set('memory_limit', '512M');

        $this->aiTrace('embedding.received', ['messageId' => $message->aiConversationMessageId]);

        $this->setAuthAccountFromMessage($message);

        try {
            $conversationMessage = AIConversationMessage::getService()->find($message->aiConversationMessageId);
            if ($conversationMessage === null) {
                $this->aiTrace('embedding.skipped', ['messageId' => $message->aiConversationMessageId, 'reason' => 'message not found in DB']);
                return;
            }
            // embeddings run through ArgusTrait without cost recording, so the model-call gate
            // never sees them — this entry check is what stops them on a deleted / locked scope.
            $embeddedConversation = AIConversation::getService()->find((int)$conversationMessage->aiConversationId);
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            if ($embeddedConversation instanceof AIConversation && $aIConversationsService->refusesAutomationForConversation($embeddedConversation, 'embedding')) {
                $this->aiTrace('embedding.suppressed', ['messageId' => $message->aiConversationMessageId]);
                return;
            }
            // All logic in the service (the sync path generates + PERSISTS); the handler stays thin.
            /** @var AIConversationMessagesService $messagesService */
            $messagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $messagesService->embedMessage($conversationMessage, async: false);
        } catch (Throwable $t) {
            $this->aiTrace('embedding.error', ['messageId' => $message->aiConversationMessageId, 'message' => $t->getMessage()]);
            $this->logShortException($this->getLogger(), "AIConversationMessageEmbeddingHandler: embedding failed for message {$message->aiConversationMessageId}", $t);
            $this->logIssue($t, $message, "AIConversationMessageEmbeddingHandler: embedding failed for message {$message->aiConversationMessageId}");
        }
    }
}
