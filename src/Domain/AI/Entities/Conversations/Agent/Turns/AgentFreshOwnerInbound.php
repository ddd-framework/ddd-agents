<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the in-loop freshness probe found before a final text is accepted
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService::probeFreshOwnerInboundBeforeFinalText()}):
 * the indexes of the owner rows that landed above the loop's high-water mark and no note quoted yet, and the freshly
 * read message set to quote them from. Empty when nothing new landed or the read failed.
 *
 * @phpstan-consistent-constructor
 */
class AgentFreshOwnerInbound extends ValueObject
{
    /** @var int[] The indexes of the fresh owner rows, in the order the read found them. */
    public array $ownerInboundIndexes = [];

    /** The freshly read message set the note quotes the rows from — null when nothing new landed. */
    public ?AIConversationMessages $freshMessages = null;

    /** Nothing new landed (or the read failed — fail-soft). */
    public static function nothingFresh(): static
    {
        return new static();
    }

    /**
     * @param int[] $ownerInboundIndexes
     */
    public static function found(array $ownerInboundIndexes, AIConversationMessages $freshMessages): static
    {
        $agentFreshOwnerInbound = new static();
        $agentFreshOwnerInbound->ownerInboundIndexes = $ownerInboundIndexes;
        $agentFreshOwnerInbound->freshMessages = $freshMessages;
        return $agentFreshOwnerInbound;
    }

    /** Whether an owner row landed while the model worked — the retried step answers it. */
    public function hasFreshOwnerInbound(): bool
    {
        return $this->ownerInboundIndexes !== [] && $this->freshMessages !== null;
    }
}
