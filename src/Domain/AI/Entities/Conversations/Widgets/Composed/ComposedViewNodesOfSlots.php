<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The nodes of every slot one `ComposedView` node holds, one element per slot, in the order the slots were read.
 *
 * @property ComposedViewNodesOfSlot[] $elements
 * @method ComposedViewNodesOfSlot[] getElements()
 * @method ComposedViewNodesOfSlot|null first()
 */
class ComposedViewNodesOfSlots extends ObjectSet
{
    /**
     * Sets the nodes of a slot: a slot the set already holds gets the new nodes, a new slot is added.
     *
     * @param ComposedViewNode[] $nodes
     */
    public function setNodesOfSlot(string $slotName, array $nodes): void
    {
        $composedViewNodesOfSlot = $this->getByUniqueKey(ComposedViewNodesOfSlot::uniqueKeyOfSlot($slotName));
        if ($composedViewNodesOfSlot instanceof ComposedViewNodesOfSlot) {
            $composedViewNodesOfSlot->nodes = array_values($nodes);
            return;
        }
        $composedViewNodesOfSlot = ComposedViewNodesOfSlot::forSlot($slotName, $nodes);
        $this->add($composedViewNodesOfSlot);
    }

    /** @return ComposedViewNode[] The nodes of a slot, in wire order; empty when the slot holds none or was not read. */
    public function getNodesOfSlot(string $slotName): array
    {
        $composedViewNodesOfSlot = $this->getByUniqueKey(ComposedViewNodesOfSlot::uniqueKeyOfSlot($slotName));
        return $composedViewNodesOfSlot instanceof ComposedViewNodesOfSlot ? $composedViewNodesOfSlot->nodes : [];
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
