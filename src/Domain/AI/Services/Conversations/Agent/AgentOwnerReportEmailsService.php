<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The report e-mail port: sends the owner report of a finished run by e-mail (subject, body, widget images — composing
 * the mail is the application's). The core decides WHEN a report mail is owed and handles its retry window
 * ({@see AIConversationOwnerReportDeliveryService::composeAndSendReportEmail()}); this service only sends.
 *
 * The default has no mail channel: it sends nothing and answers false (the caller's retry window gives up after a day).
 * An application puts a same-path subclass under its own root namespace; the core resolves this service only through
 * `DDDService::instance()->getService(AgentOwnerReportEmailsService::class)`.
 */
class AgentOwnerReportEmailsService extends Service
{
    /**
     * Composes and sends the report e-mail of `$conversation`'s run. True when the mail left; false when it was not sent
     * (no recipient, the transport refused, no mail channel).
     *
     * @param AIConversation $conversation the run conversation whose report is owed
     * @return bool
     * @throws Throwable when the transport fails (the caller treats it as a failed send and logs the message as reason)
     */
    public function sendReportEmail(AIConversation $conversation): bool
    {
        return false;
    }
}
