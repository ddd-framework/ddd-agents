<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One mail as an adapter reads it. Text written by others: untrusted content.
 */
class AgentConnectionMailMessage extends ValueObject
{
    public string $messageId = '';
    public ?string $threadId = null;
    /** @var string|null The RFC 5322 Message-ID header, for replies */
    public ?string $internetMessageId = null;
    public string $from = '';
    /** @var string[] */
    public array $to = [];
    /** @var string[] */
    public array $cc = [];
    public string $subject = '';
    public string $snippet = '';
    /** @var string|null The plain-text body (only when the message was read, not in a search) */
    public ?string $bodyText = null;
    public ?DateTime $sentAt = null;
    /** @var string[] Labels or folders */
    public array $labels = [];
    public bool $isDraft = false;

    /**
     * @var bool Whether the receiving server authenticated the sender (Authentication-Results: dmarc=pass, or dkim=pass
     * with a signing domain aligned to the From domain). A From header alone is forgeable; anything that acts on who
     * sent a mail (a trigger) requires this.
     */
    public bool $senderAuthenticated = false;
}
