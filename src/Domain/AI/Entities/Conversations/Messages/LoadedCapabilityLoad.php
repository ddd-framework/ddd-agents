<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * A capability came into the set — appended at the end, like {@see LoadedCapabilities::withSkillsPromoted()} does.
 * Stored as `{"op":"load","e":{compact entry}}` (defaults omitted, {@see LoadedCapability::compactCanonicalEntry()}).
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilityLoad extends LoadedCapabilityChange
{
    public const string OPERATION = 'load';

    /** @var array<string, mixed> the loaded capability as a canonical entry (every field, full names) */
    public array $canonicalEntry = [];

    /** @param array<string, mixed> $canonicalEntry */
    public static function ofCanonicalEntry(array $canonicalEntry): static
    {
        $load = new static();
        $load->canonicalEntry = $canonicalEntry;
        return $load;
    }

    public function applyToCanonicalEntries(array $canonicalEntries): array
    {
        $name = (string)$this->canonicalEntry['name'];
        unset($canonicalEntries[$name]);
        $canonicalEntries[$name] = $this->canonicalEntry;
        return $canonicalEntries;
    }

    protected function hasRequiredFields(): bool
    {
        return $this->canonicalEntry !== [];
    }

    public function mapToRepository(): array
    {
        return parent::mapToRepository() + ['e' => LoadedCapability::compactCanonicalEntry($this->canonicalEntry)];
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $this->canonicalEntry = is_array($repoObject) && is_array($repoObject['e'] ?? null)
            ? LoadedCapability::expandCompactEntry($repoObject['e'])
            : [];
    }
}
