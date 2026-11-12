<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * The capability column of ONE row as last persisted or loaded (replaces the
 * four-key array): what {@see LoadedCapabilitiesChain} encodes the next row against. Kept on the row
 * ({@see AIConversationMessage::getPersistedLoadedCapabilitiesState()}), never persisted, never serialized for the API.
 *
 * @phpstan-consistent-constructor
 */
class PersistedLoadedCapabilitiesState
{
    /** @var array<string, array<string, mixed>> the row's resolved set as canonical entries (name → every field) */
    protected array $canonicalEntries = [];

    /** Whether the row stores its complete set (a checkpoint) rather than its changes. */
    protected bool $isCheckpoint = true;

    /** @var array<string, string> the unload reasons the row's stored `unload` changes carry — name → why */
    protected array $unloadReasons = [];

    /** @var array<int, array<string, mixed>>|null the row's stored change events; null on a checkpoint */
    protected ?array $storedEvents = null;

    /** @param array<string, array<string, mixed>> $canonicalEntries */
    public static function ofCheckpoint(array $canonicalEntries): static
    {
        $persistedState = new static();
        $persistedState->canonicalEntries = $canonicalEntries;
        return $persistedState;
    }

    /**
     * @param array<string, array<string, mixed>> $canonicalEntries
     * @param array<string, string> $unloadReasons
     * @param array<int, array<string, mixed>> $storedEvents
     */
    public static function ofChangeRow(array $canonicalEntries, array $unloadReasons, array $storedEvents): static
    {
        $persistedState = new static();
        $persistedState->canonicalEntries = $canonicalEntries;
        $persistedState->isCheckpoint = false;
        $persistedState->unloadReasons = $unloadReasons;
        $persistedState->storedEvents = $storedEvents;
        return $persistedState;
    }

    /** @return array<string, array<string, mixed>> */
    public function getCanonicalEntries(): array
    {
        return $this->canonicalEntries;
    }

    /** @return string[] the loaded SKILL names of the row's set, in order — read from the entries, nothing is built */
    public function getLoadedSkillNames(): array
    {
        $skillNames = [];
        foreach ($this->canonicalEntries as $canonicalEntry) {
            if (($canonicalEntry['type'] ?? null) === LoadedCapability::TYPE_SKILL) {
                $skillNames[] = (string)$canonicalEntry['name'];
            }
        }
        return $skillNames;
    }

    public function isCheckpoint(): bool
    {
        return $this->isCheckpoint;
    }

    /** @return array<string, string> */
    public function getUnloadReasons(): array
    {
        return $this->unloadReasons;
    }

    /** @return array<int, array<string, mixed>>|null */
    public function getStoredEvents(): ?array
    {
        return $this->storedEvents;
    }
}
