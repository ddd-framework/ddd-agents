<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Send the OWNER-REPORT EMAIL of an unattended run asynchronously — the async half of the
 * `bool $async` seam on {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService::composeAndSendReportEmail()}.
 * Dispatched from the ONE central delivery point (`stampReportDelivered`/`claimReportDelivery` win, channel
 * EMAIL) — never from a terminal site directly. Subject and body are NOT on the wire: the handler derives them
 * from persisted state (the `send_owner_report` call's stored args + the OWNER_REPORT chat message), so a
 * redelivery composes exactly what the run persisted.
 *
 * **Ids on the wire, nothing else:** only the conversation id; the handler re-`find()`s it. `accountId` +
 * workspace are captured by {@see AppMessage::__construct()} at DISPATCH time. Routed to the existing
 * `ai_conversation_compaction` transport (same cadence/weight class as the summarizer — no new worker/queue).
 */
class AIConversationReportEmailSendMessage extends AppMessage
{
    public int $aiConversationId;

    public static string $messageHandler = AIConversationReportEmailSendHandler::class;

    public function __construct(int $aiConversationId)
    {
        parent::__construct();
        $this->aiConversationId = $aiConversationId;
    }
}
