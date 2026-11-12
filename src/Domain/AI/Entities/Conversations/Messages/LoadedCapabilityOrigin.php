<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Where and when a capability was loaded (the former "stamp"): the conversation's context
 * footprint and owner-turn count at the load — the two recency axes the eviction reads —, the skill-granular recency
 * initiator, and the provenance: who loaded it, in which turn, onto which message. The
 * provenance indexes exist ONLY with a source — a load without one (the model's own `load_skills`) persists exactly as
 * before provenance existed. Built by {@see AIConversationMessage::buildLoadedCapabilityOrigin()} for a load onto a row, or by a
 * seed with its own turn index ({@see atTurn()}).
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilityOrigin extends ValueObject
{
    /** The conversation's context input-token footprint at the load (the token-displacement recency axis). */
    public int $contextInputTokens = 0;

    /** The conversation's owner-turn count at the load (the turn-staleness axis). */
    public int $turnIndex = 0;

    /** The owner initiator's message index under skill-granular unloading; null under `tool` unloading. */
    public ?int $recencyInitiatorIndex = null;

    /** Who loaded it — a {@see LoadedCapability} SOURCE_* constant; null = the model itself. */
    public ?string $source = null;

    /** The message index of the turn's initiator at the load — set only with a source. */
    public ?int $turnInitiatorMessageIndex = null;

    /** The message index of the row the capability was loaded onto — set only with a source. */
    public ?int $messageIndex = null;

    /** A load at the given footprint and owner-turn count, without provenance. */
    public static function atTurn(int $contextInputTokens, int $turnIndex, ?int $recencyInitiatorIndex = null): static
    {
        $origin = new static();
        $origin->contextInputTokens = $contextInputTokens;
        $origin->turnIndex = $turnIndex;
        $origin->recencyInitiatorIndex = $recencyInitiatorIndex;
        return $origin;
    }

    /** This origin with its provenance; without a source the indexes are dropped (the row stays as before provenance existed). */
    public function withSource(?string $source, ?int $turnInitiatorMessageIndex, ?int $messageIndex): self
    {
        $origin = clone $this;
        $origin->source = $source;
        $origin->turnInitiatorMessageIndex = $source === null ? null : $turnInitiatorMessageIndex;
        $origin->messageIndex = $source === null ? null : $messageIndex;
        return $origin;
    }

    /** This origin without provenance — a tool promotion records none (only the skill load does). */
    public function withoutSource(): self
    {
        return $this->withSource(null, null, null);
    }
}
