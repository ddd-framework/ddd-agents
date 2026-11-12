<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Worker that compacts one `AIConversation` (async path). Thin per the message-handler convention: restore
 * auth → workspace guard → load the conversation → call the ONE service seam with `async: false` → log. All work lives
 * in {@see AIConversationCompactionService::compactConversation()}. The AI-usage envelope is opened around this handler
 * by `AIEnvelopeMiddleware` (the message's `#[WithAIEnvelope]`); compaction never blocks — a failure just leaves the
 * full windowed history.
 */
#[AsMessageHandler(fromTransport: AgentMessengerTransports::COMPACTION)]
class AIConversationCompactionHandler extends AIDomainMessageHandler
{
    use AITraceLogTrait;

    public function __invoke(AIConversationCompactionMessage $message): void
    {
        set_time_limit(1800); // a roll-up round can span several model calls across tree levels
        ini_set('memory_limit', '512M');

        $this->setAuthAccountFromMessage($message);

        $this->aiTrace('compaction.received', ['conversationId' => $message->aiConversationId]);
        $this->getLogger()->info("AIConversationCompactionHandler: compacting conversation {$message->aiConversationId}");
        try {
            // Fresh load (no registry cache) — must roll up the authoritative current row, not a prior-turn clone.
            $conversation = AIConversation::getService()->find($message->aiConversationId, useEntityRegistrCache: false);
            // independently queued work is checked at its OWN entry — a deleted / locked scope
            // ends here as a suppressed no-op, not as a budget error in the log.
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            if ($conversation instanceof AIConversation && $aIConversationsService->refusesAutomationForConversation($conversation, 'compaction')) {
                $this->aiTrace('compaction.suppressed', ['conversationId' => $message->aiConversationId]);
                return;
            }
            /** @var AIConversationCompactionService $compactionService */
            $compactionService = DDDService::instance()->getService(AIConversationCompactionService::class);

            // Honour the conversation's persisted budget bypass: without it an admin / 0-budget
            // conversation's summarizer would throw AiBudgetExceededException (caught + logged below) and compact
            // NOTHING — the "answered but never compacted" symptom. The grant lives on this job's envelope (opened and
            // finalized around the handler by AIEnvelopeMiddleware), so it ends with the job.
            if ($conversation->budgetBypass) {
                /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
                $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
                $aIUsageEnvelopesService->grantBudgetBypassIfAnEnvelopeIsOpen(AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS));
            }
            $compactionService->compactConversation($conversation, async: false);
        } catch (Throwable $t) {
            $this->aiTrace('compaction.error', ['conversationId' => $message->aiConversationId, 'message' => $t->getMessage()]);
            $this->logShortException($this->getLogger(), "AIConversationCompactionHandler: compaction failed for conversation {$message->aiConversationId}", $t);
            $this->logIssue($t, $message, "AIConversationCompactionHandler: compaction failed for conversation {$message->aiConversationId}");
        }
    }
}
