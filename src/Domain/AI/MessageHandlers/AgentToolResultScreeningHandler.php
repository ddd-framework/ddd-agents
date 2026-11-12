<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Services\Conversations\AgentToolResultScreeningService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Worker of the tool-result injection screening. Thin: restore auth → load the call message → the
 * service's sync path. A conversation with `budgetBypass` (admin console, evals) screens with the budget check
 * lifted, request-scoped like the memory curator. Best-effort: a failure leaves the result `pending` — the turn never
 * waits for it.
 */
#[AsMessageHandler(fromTransport: AgentMessengerTransports::TOOL_RESULT_SCREENING)]
class AgentToolResultScreeningHandler extends AIDomainMessageHandler
{
    use AITraceLogTrait;

    public function __invoke(AgentToolResultScreeningMessage $message): void
    {
        set_time_limit(120);
        $this->setAuthAccountFromMessage($message);
        try {
            $callMessage = AIConversationMessage::getService()->find($message->aiConversationMessageId);
            if ($callMessage === null) {
                $this->aiTrace('toolResultScreening.skipped', ['messageId' => $message->aiConversationMessageId, 'reason' => 'message not found']);
                return;
            }
            $conversation = $callMessage->aiConversation;
            if ($conversation instanceof AIConversation && $conversation->budgetBypass) {
                // the grant lives on this job's envelope (AIEnvelopeMiddleware), so it ends with the job
                /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
                $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
                $aIUsageEnvelopesService->grantBudgetBypassIfAnEnvelopeIsOpen(AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS));
            }
            /** @var AgentToolResultScreeningService $agentToolResultScreeningService */
            $agentToolResultScreeningService = DDDService::instance()->getService(AgentToolResultScreeningService::class);
            $agentToolResultScreeningService->screenToolResult($callMessage, async: false);
        } catch (Throwable $t) {
            $this->aiTrace('toolResultScreening.error', ['messageId' => $message->aiConversationMessageId, 'message' => $t->getMessage()]);
            $this->logIssue($t, $message, "AgentToolResultScreeningHandler: screening failed for message {$message->aiConversationMessageId}");
        }
    }
}
