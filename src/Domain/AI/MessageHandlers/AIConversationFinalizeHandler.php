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
 * Worker for the idle-finalize COMPLETION compaction. Thin per the message-handler convention: restore auth
 * → workspace guard → load fresh → the ONE service seam
 * ({@see AIConversationCompactionService::finalizeConversationCompaction()}) → log. Rides the EXISTING
 * `ai_conversation_compaction` transport (same work profile as a compaction round: a few cheap-model calls); the
 * budget-bypass grant mirrors {@see AIConversationCompactionHandler} — an admin / 0-budget conversation must
 * finalize too. Failures are logged, never rethrown into retry storms: the compact-finalized sweep re-selects the
 * row on its next 5-minute pass (the `finalizeCompactedAt` stamp only lands on success).
 */
#[AsMessageHandler(fromTransport: AgentMessengerTransports::COMPACTION)]
class AIConversationFinalizeHandler extends AIDomainMessageHandler
{
    use AITraceLogTrait;

    public function __invoke(AIConversationFinalizeMessage $message): void
    {
        set_time_limit(1800); // a completion pass over a long-idle backlog can span several model calls
        ini_set('memory_limit', '512M');

        $this->setAuthAccountFromMessage($message);

        $this->aiTrace('compaction.finalize.received', ['conversationId' => $message->aiConversationId]);
        try {
            $conversation = AIConversation::getService()->find($message->aiConversationId, useEntityRegistrCache: false);
            // this handler lifts the BUDGET check unconditionally below, so the budget gate can
            // never stop it — ONLY this entry check (and the model-call eligibility gate) does on a deleted / locked scope.
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            if ($conversation instanceof AIConversation && $aIConversationsService->refusesAutomationForConversation($conversation, 'finalize')) {
                $this->aiTrace('compaction.finalize.suppressed', ['conversationId' => $message->aiConversationId]);
                return;
            }
            /** @var AIConversationCompactionService $compactionService */
            $compactionService = DDDService::instance()->getService(AIConversationCompactionService::class);

            // The finalize chain is SYSTEM-INITIATED infrastructure (idle maintenance the customer never asked for),
            // so it runs BUDGET-EXEMPT — unconditionally, not just on the conversation's budgetBypass. Enforcing the
            // budget here poison-loops the sweep on a budget-spent account: the summarizer throws, no stamp lands,
            // and compact-finalized re-selects the row every 5 minutes forever (proven live). The
            // spend is still LOGGED under the compaction envelope scope (exemption ≠ invisibility) and bounded (one
            // completion pass per finalize). The grant lives on this job's envelope (AIEnvelopeMiddleware), so it ends
            // with the job (SYSTEM_MAINTENANCE).
            /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
            $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
            $aIUsageEnvelopesService->grantBudgetBypassIfAnEnvelopeIsOpen(AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_SYSTEM_MAINTENANCE));
            try {
                $compactionService->finalizeConversationCompaction($conversation);
            } finally {
                // the conversation's scorecard + quality analysis as its OWN message — nothing here waits for
                // it, and it reads the tree this compaction just completed. Dispatched whether or not the compaction
                // succeeded (the metrics need no tree); a failed dispatch is logged, the scorecard sweep catches up.
                if ($conversation instanceof AIConversation && !$conversation->isDelegation()) {
                    try {
                        (new AIConversationScorecardMessage((int)$conversation->id))->dispatch();
                    } catch (Throwable $scorecardDispatchError) {
                        $this->aiTrace('scorecard.dispatchError', ['conversationId' => $message->aiConversationId, 'message' => $scorecardDispatchError->getMessage()]);
                    }
                }
            }
        } catch (Throwable $t) {
            $this->aiTrace('compaction.finalize.error', ['conversationId' => $message->aiConversationId, 'message' => $t->getMessage()]);
            $this->logShortException($this->getLogger(), "AIConversationFinalizeHandler: finalize failed for conversation {$message->aiConversationId}", $t);
            $this->logIssue($t, $message, "AIConversationFinalizeHandler: finalize failed for conversation {$message->aiConversationId}");
        }
    }
}
