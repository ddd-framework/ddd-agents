<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Worker that sends one unattended run's OWNER-REPORT EMAIL. Thin per the message-handler
 * convention: restore auth → workspace guard → load the conversation → call the ONE service seam with
 * `async: false`. All composition (subject/body derivation from persisted state, markdown→HTML, the
 * AppEmailMessage build) lives in {@see AIConversationOwnerReportDeliveryService::composeAndSendReportEmail()}.
 *
 * FAILURE CONTRACT: the service treats an unsent mail as an ERROR and REVERTS
 * the delivery claim itself (owed stays set → the owed-reports recovery pass retries with its natural
 * backoff). The handler therefore NEVER rethrows — a Messenger redelivery on top of the sweep retry would
 * race the claim into a double-send window. Log + swallow; the sweep is the retry rail.
 *
 * ELIGIBILITY: the sync branch of `composeAndSendReportEmail()` this handler calls evaluates
 * the agent space / account state BEFORE the send — a message already queued when the agent space was deleted comes out
 * as a suppressed no-op (reason stamped on the conversation), never as a mail.
 */
#[AsMessageHandler(fromTransport: AgentMessengerTransports::COMPACTION)]
class AIConversationReportEmailSendHandler extends AIDomainMessageHandler
{
    use AITraceLogTrait;

    public function __invoke(AIConversationReportEmailSendMessage $message): void
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $this->setAuthAccountFromMessage($message);

        $this->aiTrace('reportEmail.received', ['conversationId' => $message->aiConversationId]);
        try {
            // Rights OFF for the load: the report email is a SYSTEM delivery of the conversation's own run —
            // recipient scoping happens via the conversation's account/space, not the worker's auth account.
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $conversation = AIConversation::getService()->find($message->aiConversationId, useEntityRegistrCache: false);
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
            if (!$conversation instanceof AIConversation) {
                return;
            }
            /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
            $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
            $aIConversationOwnerReportDeliveryService->composeAndSendReportEmail($conversation, async: false);
        } catch (Throwable $t) {
            $this->aiTrace('reportEmail.error', ['conversationId' => $message->aiConversationId, 'message' => $t->getMessage()]);
            $this->logShortException($this->getLogger(), "AIConversationReportEmailSendHandler: report email failed for conversation {$message->aiConversationId}", $t);
            $this->logIssue($t, $message, "AIConversationReportEmailSendHandler: report email failed for conversation {$message->aiConversationId}");
        }
    }
}
