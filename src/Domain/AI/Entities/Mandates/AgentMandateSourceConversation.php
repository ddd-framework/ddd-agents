<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Mandates;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Where a mandate was granted: the conversation + the customer message that triggered the grant, resolved from
 * {@see AgentMandate::$sourceConversationId}/{@see AgentMandate::$sourceUserMessageId} and carried WITH the
 * mandate (a CLASS_METHOD lazy-load the list `expand()` brings along — the treatment an application's memory
 * provenance gets). A pure presentation value object (no persistence, no rights of its own — the underlying
 * message/conversation load through their rights-gated services). The Mandates drawer's "Show source" panel
 * renders it: title + summary + the triggering message excerpt + when it was said, plus the open-conversation
 * deep link.
 */
class AgentMandateSourceConversation extends ValueObject
{
    /** @var int|null The conversation the mandate was granted in */
    public ?int $conversationId = null;

    /** @var string|null The conversation's display title (from its persisted/cached summary) */
    public ?string $conversationTitle = null;

    /** @var string|null The conversation's brief summary (from its persisted/cached summary) */
    public ?string $conversationSummary = null;

    /** @var string|null The triggering customer message's text, truncated for display */
    public ?string $messageExcerpt = null;

    /** @var DateTime|null When the triggering message was created */
    public ?DateTime $messageCreatedAt = null;

    public function uniqueKey(): string
    {
        return parent::uniqueKeyStatic((string)($this->conversationId ?? spl_object_id($this)));
    }
}
