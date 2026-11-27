<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailDraft;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessages;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;

/** Capability MAIL. */
interface MailAgentConnectionAdapter
{
    /** Newest first; `$query` in the provider's search syntax (Gmail: `from:x newer_than:7d`; IMAP: plain text) */
    public function searchMail(string $query, int $maximumResults): AgentConnectionMailMessages;

    /** Every message of the thread, oldest first, with bodies */
    public function readMailThread(string $threadId): AgentConnectionMailMessages;

    public function createMailDraft(AgentConnectionMailDraft $mailDraft): AgentConnectionSendResult;

    public function sendMail(AgentConnectionMailDraft $mailDraft): AgentConnectionSendResult;
}
