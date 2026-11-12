<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * The surviving capabilities stand in another order than "previous order, minus unloads, plus loads appended". Stored
 * as `{"op":"order","names":[…]}`; a name not in the set is skipped, a capability not named keeps its place behind.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilityReorder extends LoadedCapabilityChange
{
    public const string OPERATION = 'order';

    /** @var string[] the capability names in their new order */
    public array $capabilityNames = [];

    /** @param string[] $capabilityNames */
    public static function ofCapabilityNames(array $capabilityNames): static
    {
        $order = new static();
        $order->capabilityNames = $capabilityNames;
        return $order;
    }

    public function applyToCanonicalEntries(array $canonicalEntries): array
    {
        $orderedEntries = [];
        foreach ($this->capabilityNames as $orderedName) {
            if (isset($canonicalEntries[$orderedName])) {
                $orderedEntries[$orderedName] = $canonicalEntries[$orderedName];
            }
        }
        return $orderedEntries + $canonicalEntries;
    }

    protected function hasRequiredFields(): bool
    {
        return true;
    }

    public function mapToRepository(): array
    {
        return parent::mapToRepository() + ['names' => $this->capabilityNames];
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $this->capabilityNames = is_array($repoObject) ? (array)($repoObject['names'] ?? []) : [];
    }
}
