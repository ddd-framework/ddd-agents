<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Every node of a task-plan tree in PRE-ORDER (a container before its subtasks), each with its
 * {@see TaskPlanItemAddress} — the one walk the plan's read questions filter instead of each recursing through the
 * tree itself. A question keeps its own descent rule as a filter on the address (a closed or not-owed
 * ancestor, a terminal container); the walk itself visits every node. Built by
 * {@see TaskPlanItems::buildItemAddresses()}; a mutation of the tree invalidates it.
 *
 * @property TaskPlanItemAddress[] $elements
 * @method TaskPlanItemAddress[] getElements()
 * @method TaskPlanItemAddress|null first()
 */
class TaskPlanItemAddresses extends ObjectSet
{
    /** The address of exactly this item (by identity), or null when it is not in the walk. */
    public function getByItem(TaskPlanItem $taskPlanItem): ?TaskPlanItemAddress
    {
        foreach ($this->getElements() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item === $taskPlanItem) {
                return $taskPlanItemAddress;
            }
        }
        return null;
    }

    /** @return TaskPlanItemAddress[] every address whose item carries this key ({@see TaskPlanItemAddress::$key}) — more than one only in a stored tree with a duplicate key */
    public function getAllByKey(string $key): array
    {
        return array_values(array_filter(
            $this->getElements(),
            static fn (TaskPlanItemAddress $taskPlanItemAddress): bool => $taskPlanItemAddress->key === $key
        ));
    }

    /**
     * Every node in POST-ORDER (the subtasks before their container) — derived from the pre-order walk by depth: a node
     * is emitted once the walk leaves its subtree.
     *
     * @return TaskPlanItemAddress[]
     */
    public function getElementsInPostOrder(): array
    {
        $postOrder = [];
        $openAncestors = [];
        foreach ($this->getElements() as $taskPlanItemAddress) {
            while ($openAncestors !== [] && end($openAncestors)->depth >= $taskPlanItemAddress->depth) {
                $postOrder[] = array_pop($openAncestors);
            }
            $openAncestors[] = $taskPlanItemAddress;
        }
        while ($openAncestors !== []) {
            $postOrder[] = array_pop($openAncestors);
        }
        return $postOrder;
    }

    /** @return TaskPlanItemAddress[] the leaves (no subtasks, or an empty subtask level), in plan order */
    public function getLeaves(): array
    {
        return array_values(array_filter(
            $this->getElements(),
            static fn (TaskPlanItemAddress $taskPlanItemAddress): bool => $taskPlanItemAddress->isLeaf
        ));
    }
}
