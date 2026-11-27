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

    /**
     * The row as one group of the `ConversationSearchResults` widget (the shape of the chat's own search); null for a
     * row without matching messages (a title match or a listed conversation), which the widget would not show.
     */
    public function toConversationSearchGroup(): ?AIConversationSearchGroup
    {
        if ($this->hits->isEmpty()) {
            return null;
        }
        $group = new AIConversationSearchGroup();
        $group->conversationId = $this->conversationId;
        $group->title = $this->title;
        $group->summary = $this->summary;
        $group->created = $this->created?->format('Y-m-d H:i:s');
        $group->updated = $this->updated?->format('Y-m-d H:i:s');
        $group->hits = array_values($this->hits->getElements());
        $group->relevance = $group->hits[0]->relevance ?? 0.0;
        return $group;
    }
}
