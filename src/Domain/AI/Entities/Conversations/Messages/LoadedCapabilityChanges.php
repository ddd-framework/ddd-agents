<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What changed in the loaded capability set ON one message, relative to the message before it. A row's
 * `loadedCapabilities` column stores either the complete set (a CHECKPOINT row) or these changes, wrapped as a change
 * record `{"v":2,"e":[…]}` ({@see LoadedCapabilities::asChangeRecord()}); the resolved set of row k = the newest
 * checkpoint ≤ k with every later row's changes applied in order ({@see LoadedCapabilitiesChain}).
 *
 * Every change carries explicit values — nothing is derived from other rows, so applying the changes reproduces exactly
 * the set the row held when it was persisted. One class per operation, each persisting itself:
 *   - {@see LoadedCapabilityLoad}    `load`   {e: compact entry};
 *   - {@see LoadedCapabilityUnload}  `unload` {n, why?};
 *   - {@see LoadedCapabilityUse}     `use`    {n, …changed fields} — a use stamp (applied like `set`);
 *   - {@see LoadedCapabilityUpdate}  `set`    {n, …changed fields};
 *   - {@see LoadedCapabilityReorder} `order`  {names} — only when the order is not the natural one.
 *
 * The compact keys of a `load` entry and of a field change are {@see LoadedCapability::FIELD_COMPACT_KEYS}; a `load`
 * entry omits defaults ({@see LoadedCapability::FIELD_DEFAULTS}), the decoder restores them.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilityChanges extends ValueObject
{
    /** @var LoadedCapabilityChange[] the changes, in application order */
    protected array $changes = [];

    /** @param LoadedCapabilityChange[] $changes */
    public static function ofChanges(array $changes): static
    {
        $loadedCapabilityChanges = new static();
        $loadedCapabilityChanges->changes = array_values($changes);
        return $loadedCapabilityChanges;
    }

    /** @return LoadedCapabilityChange[] the changes, in application order */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /** Whether nothing changed — the row then stores an empty change record (NULL means the row carries no set). */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /** @return string[] the names of the capabilities that left the set */
    public function getUnloadedCapabilityNames(): array
    {
        $unloadedNames = [];
        foreach ($this->changes as $change) {
            if ($change instanceof LoadedCapabilityUnload) {
                $unloadedNames[] = $change->capabilityName;
            }
        }
        return $unloadedNames;
    }

    /** @return array<string, string> capability name → the reason its `unload` change carries */
    public function getUnloadReasons(): array
    {
        $unloadReasons = [];
        foreach ($this->changes as $change) {
            if ($change instanceof LoadedCapabilityUnload && $change->unloadReason !== null) {
                $unloadReasons[$change->capabilityName] = $change->unloadReason;
            }
        }
        return $unloadReasons;
    }

    /**
     * The changes that turn `$previous` into `$current` — a value diff over the canonical entries, so every mutation
     * site (promotion, copy-on-write parents, usage stamps, pins, removals) is covered without a hook of its own.
     *
     * @param array<string, string> $unloadReasons capability name → why it was removed
     */
    public static function between(LoadedCapabilities $previous, LoadedCapabilities $current, array $unloadReasons = []): self
    {
        $previousEntries = $previous->toCanonicalEntries();
        $currentEntries = $current->toCanonicalEntries();
        $changes = [];
        foreach (array_keys($previousEntries) as $name) {
            if (!isset($currentEntries[$name])) {
                $changes[] = LoadedCapabilityUnload::ofCapabilityName((string)$name, $unloadReasons[$name] ?? null);
            }
        }
        $loadedNames = [];
        foreach ($currentEntries as $name => $currentEntry) {
            if (!isset($previousEntries[$name])) {
                $loadedNames[] = (string)$name;
                continue;
            }
            $changedFields = [];
            foreach ($currentEntry as $field => $value) {
                if ($field !== 'name' && $value !== ($previousEntries[$name][$field] ?? null)) {
                    $changedFields[$field] = $value;
                }
            }
            if ($changedFields === []) {
                continue;
            }
            $isUseStamp = array_diff(array_keys($changedFields), LoadedCapability::USE_FIELDS) === [];
            $changes[] = $isUseStamp
                ? LoadedCapabilityUse::ofCapabilityName((string)$name, $changedFields)
                : LoadedCapabilityUpdate::ofCapabilityName((string)$name, $changedFields);
        }
        foreach ($loadedNames as $loadedName) {
            $changes[] = LoadedCapabilityLoad::ofCanonicalEntry($currentEntries[$loadedName]);
        }
        $survivingNames = array_values(array_filter(
            array_map('strval', array_keys($previousEntries)),
            static fn (string $name): bool => isset($currentEntries[$name])
        ));
        $currentOrder = array_map('strval', array_keys($currentEntries));
        if (array_merge($survivingNames, $loadedNames) !== $currentOrder) {
            $changes[] = LoadedCapabilityReorder::ofCapabilityNames($currentOrder);
        }
        return self::ofChanges($changes);
    }

    /** `$base` with these changes applied — a NEW set; `$base` and its items stay untouched. */
    public function applyTo(LoadedCapabilities $base): LoadedCapabilities
    {
        $canonicalEntries = $base->toCanonicalEntries();
        foreach ($this->changes as $change) {
            $canonicalEntries = $change->applyToCanonicalEntries($canonicalEntries);
        }
        return LoadedCapabilities::fromCanonicalEntries($canonicalEntries);
    }

    /**
     * The stored events of these changes, in order — the `e` list of a change record.
     *
     * @return list<array<string, mixed>>
     */
    public function mapToRepository(): array
    {
        $storedEvents = [];
        foreach ($this->changes as $change) {
            $storedEvents[] = $change->mapToRepository();
        }
        return $storedEvents;
    }

    /** Read the changes from the stored events (a list, or its JSON); an event this code cannot read is left out. */
    public function mapFromRepository(mixed $repoObject): void
    {
        $storedEvents = is_string($repoObject) ? json_decode($repoObject, true) : $repoObject;
        $this->changes = [];
        foreach (is_array($storedEvents) ? $storedEvents : [] as $storedEvent) {
            $change = is_array($storedEvent) ? LoadedCapabilityChange::fromStoredEvent($storedEvent) : null;
            if ($change !== null) {
                $this->changes[] = $change;
            }
        }
    }
}
