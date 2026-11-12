<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE change of a loaded capability set between two rows (one class per operation,
 * replacing the untyped event arrays). Each change persists ITSELF: {@see mapToRepository()} returns the stored event
 * (`{"op":…, …}` with the compact keys of {@see LoadedCapability::FIELD_COMPACT_KEYS}), {@see mapFromRepository()} reads
 * one back; {@see fromStoredEvent()} picks the class by the stored `op`. The byte form is the established stored form —
 * every change written before this class existed reads back and writes again unchanged.
 */
abstract class LoadedCapabilityChange extends ValueObject
{
    /** The stored `op` of this change. */
    public const string OPERATION = '';

    /** @var array<string, class-string<LoadedCapabilityChange>> stored `op` → the class of that change */
    public const array CHANGE_CLASSES_BY_OPERATION = [
        LoadedCapabilityLoad::OPERATION => LoadedCapabilityLoad::class,
        LoadedCapabilityUnload::OPERATION => LoadedCapabilityUnload::class,
        LoadedCapabilityUse::OPERATION => LoadedCapabilityUse::class,
        LoadedCapabilityUpdate::OPERATION => LoadedCapabilityUpdate::class,
        LoadedCapabilityReorder::OPERATION => LoadedCapabilityReorder::class,
    ];

    /**
     * The change a stored event describes; null for an event this code cannot read (an unknown `op`, a missing name or
     * entry) — such an event changes nothing when applied, so leaving it out changes no resolved set.
     *
     * @param array<string, mixed> $storedEvent
     */
    public static function fromStoredEvent(array $storedEvent): ?self
    {
        $storedOperation = $storedEvent['op'] ?? null;
        $changeClass = is_string($storedOperation) ? (self::CHANGE_CLASSES_BY_OPERATION[$storedOperation] ?? null) : null;
        if ($changeClass === null) {
            return null;
        }
        $change = new $changeClass();
        $change->mapFromRepository($storedEvent);
        return $change->hasRequiredFields() ? $change : null;
    }

    /**
     * The canonical entries (name → every field, in set order) with this change applied.
     *
     * @param array<string, array<string, mixed>> $canonicalEntries
     * @return array<string, array<string, mixed>>
     */
    abstract public function applyToCanonicalEntries(array $canonicalEntries): array;

    /** Whether the stored event carried what this change needs (a name, an entry) — {@see fromStoredEvent()}. */
    abstract protected function hasRequiredFields(): bool;

    /**
     * The stored event of this change: its `op` first — every subclass adds its own fields.
     *
     * @return array<string, mixed>
     */
    public function mapToRepository(): array
    {
        return ['op' => static::OPERATION];
    }
}
