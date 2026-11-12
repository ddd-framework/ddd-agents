<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * How many OWNER TURNS ago each loaded capability was last used (replaces the
 * `$turnsSince` closure): from the compaction-proof initiator stamp over the loaded tail when the capability carries
 * one; a stamp-less row (legacy, `tool`-mode history) falls back to the turn count minus the current turn (the stamp's
 * semantics). Computed once for the whole set; the skill-granular unloading reads it.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilityRecencies extends ValueObject
{
    /** @var array<string, int> capability name → owner turns since its last use */
    protected array $ownerTurnsSinceByName = [];

    /** The recencies of every capability of `$loadedCapabilities` at the current turn. */
    public static function buildFor(LoadedCapabilities $loadedCapabilities, AIConversationOwnerTurns $ownerTurns, ?int $currentInitiatorIndex, int $currentTurnIndex): self
    {
        $turnsByName = [];
        foreach ($loadedCapabilities->getElements() as $capability) {
            $initiatorIndex = $capability->resolveRecencyInitiatorIndex();
            $turnsByName[$capability->name] = $initiatorIndex === null
                // a row without a stamp: the earlier turn count, minus the current turn (the stamp semantics)
                ? max(0, $capability->turnsSinceLastUse($currentTurnIndex) - 1)
                : $ownerTurns->countTurnsBetweenInitiators($initiatorIndex, $currentInitiatorIndex);
        }
        return self::fromTurnsByName($turnsByName);
    }

    /** @param array<string, int> $ownerTurnsSinceByName capability name → owner turns since its last use */
    public static function fromTurnsByName(array $ownerTurnsSinceByName): static
    {
        $loadedCapabilityRecencies = new static();
        $loadedCapabilityRecencies->ownerTurnsSinceByName = $ownerTurnsSinceByName;
        return $loadedCapabilityRecencies;
    }

    /** Owner turns since the capability's last use (0 for a capability not known here — it was loaded just now). */
    public function getOwnerTurnsSince(LoadedCapability $capability): int
    {
        return $this->ownerTurnsSinceByName[$capability->name] ?? 0;
    }
}
