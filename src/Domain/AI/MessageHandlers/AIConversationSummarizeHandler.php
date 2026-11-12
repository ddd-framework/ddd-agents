<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Worker that (re)generates one `AIConversation`'s display title + summary (async path). Thin per the
 * message-handler convention: restore auth → workspace guard → load the conversation → call the ONE service seam with
 * `async: false` → log. All work lives in {@see AIConversationsService::generateAndPersistSummary()}. Shares the
 * `ai_conversation_compaction` transport (no new worker). Best-effort: a failure just leaves the current summary.
 */
#[AsMessageHandler(fromTransport: AgentMessengerTransports::COMPACTION)]
class AIConversationSummarizeHandler extends AIDomainMessageHandler
{
    use AITraceLogTrait;

    public function __invoke(AIConversationSummarizeMessage $message): void
    {
        set_time_limit(600); // headroom for a slow summarizer model under load
        ini_set('memory_limit', '512M');

        $this->setAuthAccountFromMessage($message);

        $this->aiTrace('summary.received', ['conversationId' => $message->aiConversationId]);
        $this->getLogger()->info("AIConversationSummarizeHandler: summarizing conversation {$message->aiConversationId}");
        try {
            // Fresh load (no registry cache) — summarize the authoritative current row, not a prior-turn clone.
            $conversation = AIConversation::getService()->find($message->aiConversationId, useEntityRegistrCache: false);
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            // the worker's own entry check — a deleted / locked scope ends as a suppressed no-op.
            if ($conversation instanceof AIConversation && $aIConversationsService->refusesAutomationForConversation($conversation, 'summarize')) {
                $this->aiTrace('summary.suppressed', ['conversationId' => $message->aiConversationId]);
                return;
            }

            // Honour the conversation's persisted budget bypass, like the compaction handler: without it an admin /
            // 0-budget conversation's summarizer would throw AiBudgetExceededException and persist NO title. The grant
            // lives on this job's envelope (AIEnvelopeMiddleware), so it ends with the job.
            if ($conversation->budgetBypass) {
                /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
                $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
                $aIUsageEnvelopesService->grantBudgetBypassIfAnEnvelopeIsOpen(AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS));
            }
            $aIConversationsService->generateAndPersistSummary($conversation, async: false);
        } catch (Throwable $t) {
            $this->aiTrace('summary.error', ['conversationId' => $message->aiConversationId, 'message' => $t->getMessage()]);
            $this->logShortException($this->getLogger(), "AIConversationSummarizeHandler: summary failed for conversation {$message->aiConversationId}", $t);
            $this->logIssue($t, $message, "AIConversationSummarizeHandler: summary failed for conversation {$message->aiConversationId}");
        }
    }
}
