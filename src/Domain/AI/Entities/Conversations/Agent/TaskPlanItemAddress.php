<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One node of a task-plan tree with what its POSITION in the tree says about it: the item's
 * key the render labels and every address resolves ("2.1"), its number path, the text path the bounce and the run report name
 * ("Parent > Child"), the depth, whether it is a leaf, what it inherits from its ancestors (the skills, the owner-input
 * marker) and whether an ancestor prunes it for a walk that skips closed or not-owed subtrees. Built by
 * {@see TaskPlanItems::buildItemAddresses()} from the loaded tree; a mutation of the tree invalidates it. Holds references
 * into the tree ({@see $item}, {@see $ancestors}) — a working value, never serialized.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanItemAddress extends ValueObject
{
    public TaskPlanItem $item;

    /**
     * The item's KEY — the address the render prints and every consumer resolves; for an item built in
     * memory and not minted yet, its number path (the key such an item gets on its first read).
     */
    public string $key = '';

    /** 1-based positions per level, joined by a dot ("2.1") — the node's place in the walk, never an address. */
    public string $numberPath = '';

    /** The item texts from the root down, joined by " > ". */
    public string $textPath = '';

    /** 0 for a top-level item. */
    public int $depth = 0;

    /** No subtasks, or an empty subtask level. */
    public bool $isLeaf = true;

    /** @var string[] The item's own skill names, else the nearest ancestor's that names any. */
    public array $effectiveSkillNames = [];

    /** @var TaskPlanItem[] The ancestors, root first. */
    public array $ancestors = [];

    /** The node's own {@see TaskPlanItem::isOpen()}, read once by the walk — its children's pruning facts derive from it. */
    public bool $itemIsOpen = false;

    /** The node's own {@see TaskPlanItem::isOwedOpen()}, read once by the walk. */
    public bool $itemIsOwedOpen = false;

    /** An ancestor is not {@see TaskPlanItem::isOpen()} — a walk that skips closed subtrees never reaches this node. */
    public bool $hasClosedAncestor = false;

    /** An ancestor is not {@see TaskPlanItem::isOwedOpen()} — a walk that skips not-owed subtrees never reaches this node. */
    public bool $hasNotOwedAncestor = false;

    /** An ancestor is `blocked` or `not_applicable` — the subtree {@see TaskPlanItem::isOpen()} treats as covered. */
    public bool $isInsideTerminalContainer = false;

    /** An ancestor is `blocked` — the subtree the status counts prune (a `not_applicable` one they still count). */
    public bool $hasBlockedAncestor = false;

    /**
     * Whether an ancestor renders as done on the compact plan ({@see TaskPlanItem::rendersAsDoneOnPlanLine()}) — this node
     * then stands inside that ancestor's entry of the done summary ({@see TaskPlanItems::buildCompactTailHintLines()}).
     */
    public function hasAncestorRenderingAsDoneOnPlanLine(): bool
    {
        foreach ($this->ancestors as $ancestorItem) {
            if ($ancestorItem->rendersAsDoneOnPlanLine()) {
                return true;
            }
        }
        return false;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->numberPath);
    }

    /** The address of `$taskPlanItem` at `$position` below `$parentAddress` (null for a top-level item). */
    public static function ofChild(TaskPlanItem $taskPlanItem, int $position, ?self $parentAddress): static
    {
        $taskPlanItemAddress = new static();
        $taskPlanItemAddress->item = $taskPlanItem;
        $taskPlanItemAddress->numberPath = ($parentAddress === null ? '' : $parentAddress->numberPath . '.') . ($position + 1);
        $taskPlanItemAddress->key = $taskPlanItem->key ?? $taskPlanItemAddress->numberPath;
        $taskPlanItemAddress->textPath = ($parentAddress === null ? '' : $parentAddress->textPath . ' > ') . $taskPlanItem->text;
        $taskPlanItemAddress->depth = $parentAddress === null ? 0 : $parentAddress->depth + 1;
        $taskPlanItemAddress->isLeaf = $taskPlanItem->subtasks === null || $taskPlanItem->subtasks->count() === 0;
        $taskPlanItemAddress->effectiveSkillNames = $taskPlanItem->skillNames !== [] ? $taskPlanItem->skillNames : ($parentAddress->effectiveSkillNames ?? []);
        $taskPlanItemAddress->itemIsOpen = $taskPlanItem->isOpen();
        $taskPlanItemAddress->itemIsOwedOpen = $taskPlanItemAddress->itemIsOpen && $taskPlanItem->isOwedWhenOpen();
        if ($parentAddress !== null) {
            $parentItem = $parentAddress->item;
            $taskPlanItemAddress->ancestors = [...$parentAddress->ancestors, $parentItem];
            $taskPlanItemAddress->hasClosedAncestor = $parentAddress->hasClosedAncestor || !$parentAddress->itemIsOpen;
            $taskPlanItemAddress->hasNotOwedAncestor = $parentAddress->hasNotOwedAncestor || !$parentAddress->itemIsOwedOpen;
            $taskPlanItemAddress->isInsideTerminalContainer = $parentAddress->isInsideTerminalContainer
                || $parentItem->status === TaskPlanItem::STATUS_BLOCKED
                || $parentItem->status === TaskPlanItem::STATUS_NOT_APPLICABLE;
            $taskPlanItemAddress->hasBlockedAncestor = $parentAddress->hasBlockedAncestor || $parentItem->status === TaskPlanItem::STATUS_BLOCKED;
        }
        return $taskPlanItemAddress;
    }
}
