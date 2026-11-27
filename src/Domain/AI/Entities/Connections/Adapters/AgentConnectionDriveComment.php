<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One Drive comment with its replies (the proposal channel: a change without a yes is a comment).
 */
class AgentConnectionDriveComment extends ValueObject
{
    public string $commentId = '';
    public string $authorName = '';
    public string $content = '';
    public ?string $quotedText = null;
    public bool $isResolved = false;
    public ?DateTime $createdAt = null;
    public AgentConnectionDriveComments $replies;

    public function __construct()
    {
        $this->replies = new AgentConnectionDriveComments();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->commentId);
    }
}
