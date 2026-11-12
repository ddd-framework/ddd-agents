<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The nodes one slot of a `ComposedView` node holds: the slot's name (`children` = the default slot) and its nodes in
 * wire order.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewNodesOfSlot extends ValueObject
{
    /** @var string The slot's name. */
    public string $slotName;

    /** @var ComposedViewNode[] The nodes in the slot, in wire order. */
    public array $nodes = [];

    /**
     * @param ComposedViewNode[] $nodes
     */
    public static function forSlot(string $slotName, array $nodes): static
    {
        $composedViewNodesOfSlot = new static();
        $composedViewNodesOfSlot->slotName = $slotName;
        $composedViewNodesOfSlot->nodes = array_values($nodes);
        return $composedViewNodesOfSlot;
    }

    /** The key of a slot's nodes: the slot's name. */
    public static function uniqueKeyOfSlot(string $slotName): string
    {
        return self::class . '_' . $slotName;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyOfSlot($this->slotName);
    }
}
