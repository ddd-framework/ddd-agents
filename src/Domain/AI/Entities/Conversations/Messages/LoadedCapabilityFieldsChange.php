<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * Fields of a capability that stays in the set changed — every changed field with its NEW value, a change TO a default
 * included (explicit null / 0 / false / []). Stored as `{"op":…, "n":name, <compact key>:value, …}` in canonical field
 * order. Two operations: {@see LoadedCapabilityUse} (only the use-stamp fields) and {@see LoadedCapabilityUpdate}.
 *
 * @phpstan-consistent-constructor
 */
abstract class LoadedCapabilityFieldsChange extends LoadedCapabilityChange
{
    public string $capabilityName = '';

    /** @var array<string, mixed> canonical field name → its new value, in canonical field order */
    public array $changedFields = [];

    /** @param array<string, mixed> $changedFields canonical field name → its new value */
    public static function ofCapabilityName(string $capabilityName, array $changedFields): static
    {
        $fieldsChange = new static();
        $fieldsChange->capabilityName = $capabilityName;
        $fieldsChange->changedFields = $changedFields;
        return $fieldsChange;
    }

    public function applyToCanonicalEntries(array $canonicalEntries): array
    {
        if (!isset($canonicalEntries[$this->capabilityName])) {
            return $canonicalEntries;
        }
        foreach ($this->changedFields as $field => $value) {
            $canonicalEntries[$this->capabilityName][$field] = $value;
        }
        return $canonicalEntries;
    }

    protected function hasRequiredFields(): bool
    {
        return $this->capabilityName !== '';
    }

    public function mapToRepository(): array
    {
        $storedEvent = parent::mapToRepository() + ['n' => $this->capabilityName];
        foreach ($this->changedFields as $field => $value) {
            $storedEvent[LoadedCapability::FIELD_COMPACT_KEYS[$field]] = $value;
        }
        return $storedEvent;
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $this->capabilityName = is_array($repoObject) && isset($repoObject['n']) ? (string)$repoObject['n'] : '';
        $this->changedFields = [];
        if (!is_array($repoObject)) {
            return;
        }
        // canonical field order, the order the encoder writes; a key this code does not know is not a field
        foreach (LoadedCapability::FIELD_COMPACT_KEYS as $field => $compactKey) {
            if ($field !== 'name' && array_key_exists($compactKey, $repoObject)) {
                $this->changedFields[$field] = $repoObject[$compactKey];
            }
        }
    }
}
