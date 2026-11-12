<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * A loaded capability set's state as a value — its capabilities and the unload reasons not yet persisted
 * (replaces the two-key array): taken by {@see LoadedCapabilities::buildSnapshot()},
 * put back by {@see LoadedCapabilities::restoreSnapshot()} when an admission's write rolls back.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilitiesSnapshot
{
    /** @var array<string, array<string, mixed>> the capabilities as canonical entries, in set order */
    protected array $canonicalEntries = [];

    /** @var array<string, string> name → why, the reasons not yet written into an `unload` change */
    protected array $unloadReasons = [];

    /** The state `$loadedCapabilities` is in right now. */
    public static function ofLoadedCapabilities(LoadedCapabilities $loadedCapabilities): static
    {
        $snapshot = new static();
        $snapshot->canonicalEntries = $loadedCapabilities->toCanonicalEntries();
        $snapshot->unloadReasons = $loadedCapabilities->getUnloadReasons();
        return $snapshot;
    }

    /** @return array<string, array<string, mixed>> */
    public function getCanonicalEntries(): array
    {
        return $this->canonicalEntries;
    }

    /** @return array<string, string> */
    public function getUnloadReasons(): array
    {
        return $this->unloadReasons;
    }
}
