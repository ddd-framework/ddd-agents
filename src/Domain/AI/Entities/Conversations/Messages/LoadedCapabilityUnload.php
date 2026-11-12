<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * A capability left the set. Stored as `{"op":"unload","n":name,"why":reason}`; `why` uses the eviction turn-event
 * vocabulary (`sweep`, `skillUnloading`, `speculative`) and is left out when the reason is not known.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilityUnload extends LoadedCapabilityChange
{
    public const string OPERATION = 'unload';

    public string $capabilityName = '';

    /** Why the capability left — null when not known. */
    public ?string $unloadReason = null;

    public static function ofCapabilityName(string $capabilityName, ?string $unloadReason): static
    {
        $unload = new static();
        $unload->capabilityName = $capabilityName;
        $unload->unloadReason = $unloadReason;
        return $unload;
    }

    public function applyToCanonicalEntries(array $canonicalEntries): array
    {
        unset($canonicalEntries[$this->capabilityName]);
        return $canonicalEntries;
    }

    protected function hasRequiredFields(): bool
    {
        return $this->capabilityName !== '';
    }

    public function mapToRepository(): array
    {
        $storedEvent = parent::mapToRepository() + ['n' => $this->capabilityName];
        if ($this->unloadReason !== null) {
            $storedEvent['why'] = $this->unloadReason;
        }
        return $storedEvent;
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $this->capabilityName = is_array($repoObject) && isset($repoObject['n']) ? (string)$repoObject['n'] : '';
        $this->unloadReason = is_array($repoObject) && isset($repoObject['why']) ? (string)$repoObject['why'] : null;
    }
}
