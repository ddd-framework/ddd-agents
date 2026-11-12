<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * Where a row about to be written stands in its conversation's capability chain
 * (replaces the by-reference `$isCheckpointDue` out parameter): the persisted state of the nearest earlier row that
 * carries a set, and whether the row must be a checkpoint anyway ({@see LoadedCapabilitiesChain::isCheckpointDue()}).
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilitiesChainPosition
{
    protected ?PersistedLoadedCapabilitiesState $previousPersistedState = null;

    protected bool $isCheckpointDue = true;

    public static function forPreviousPersistedState(?PersistedLoadedCapabilitiesState $previousPersistedState, bool $isCheckpointDue): static
    {
        $chainPosition = new static();
        $chainPosition->previousPersistedState = $previousPersistedState;
        $chainPosition->isCheckpointDue = $isCheckpointDue;
        return $chainPosition;
    }

    public function getPreviousPersistedState(): ?PersistedLoadedCapabilitiesState
    {
        return $this->previousPersistedState;
    }

    public function isCheckpointDue(): bool
    {
        return $this->isCheckpointDue;
    }
}
