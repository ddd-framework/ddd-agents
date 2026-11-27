<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** One earlier conversation found by the agent's conversation recall: what it is about, its space, its best hits */
class AgentConversationRecallRow extends ValueObject
{
    /** @var int The conversation's id (the argument of readConversation) */
    public int $conversationId = 0;

    /** @var string|null The conversation's title, null when it was never summarised */
    public ?string $title = null;

    /** @var string|null The conversation's stored summary */
    public ?string $summary = null;

    /** @var int|null The conversation's space; null at account level */
    public ?int $agentSpaceId = null;

    /** @var string The space as the model reads it */
    public string $agentSpace = '';

    /** @var DateTime|null When the conversation started */
    public ?DateTime $created = null;

    /** @var DateTime|null The conversation's latest activity */
    public ?DateTime $updated = null;

    /** @var SemanticSearchHits The best matching messages, best first (empty for a title match or a list) */
    public SemanticSearchHits $hits;

    public function __construct()
    {
        parent::__construct();
        $this->hits = new SemanticSearchHits();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->conversationId);
    }
}
