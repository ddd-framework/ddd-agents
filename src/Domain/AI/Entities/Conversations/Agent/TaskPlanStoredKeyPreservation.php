<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The keys of a task-plan tree just READ from storage (moved off {@see TaskPlanItems}):
 * a tree in which NO item carries a key (every plan stored before keys existed) gets today's number paths —
 * deterministic and idempotent, so the render keeps its bytes. A tree with keys keeps every stored key as stored — a
 * malformed key, a key under the wrong parent and a duplicate included; nothing is renumbered by position. An item
 * without a key there gets a FRESH key from its parent's counter, never its position and never a key the tree already
 * holds. The counters are raised to the highest segment seen at their level; every irregularity is listed on the
 * violations. A working value of one read.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanStoredKeyPreservation extends ValueObject
{
    /** @var array<string, int> every key the tree holds → how many items carry it (fresh keys are added) */
    protected array $storedKeyCounts = [];

    protected ?TaskPlanKeyIntegrityViolations $taskPlanKeyIntegrityViolations = null;

    /**
     * Derive or preserve the keys of `$taskPlanItems` (the top level of a tree just read) in place, listing every
     * irregularity on `$taskPlanKeyIntegrityViolations`. Returns the top-level counter.
     */
    public static function deriveOrPreserveKeys(
        TaskPlanItems $taskPlanItems,
        int $storedHighestItemKeyNumber,
        TaskPlanKeyIntegrityViolations $taskPlanKeyIntegrityViolations
    ): int {
        $taskPlanStoredKeyPreservation = new static();
        $taskPlanStoredKeyPreservation->taskPlanKeyIntegrityViolations = $taskPlanKeyIntegrityViolations;
        $taskPlanStoredKeyPreservation->countStoredKeys($taskPlanItems);
        if ($taskPlanStoredKeyPreservation->storedKeyCounts === []) {
            return max($storedHighestItemKeyNumber, $taskPlanItems->assignPositionalKeys());
        }
        foreach ($taskPlanStoredKeyPreservation->storedKeyCounts as $storedKey => $occurrences) {
            if ($occurrences > 1) {
                $taskPlanKeyIntegrityViolations->addLine('"' . $storedKey . '" appears ' . ($occurrences === 2 ? 'twice' : $occurrences . ' times'));
            }
        }
        return $taskPlanStoredKeyPreservation->preserveKeysOfLevel($taskPlanItems, '', $storedHighestItemKeyNumber);
    }

    /** Count every stored key of `$levelItems` and its subtrees. */
    protected function countStoredKeys(TaskPlanItems $levelItems): void
    {
        foreach ($levelItems->getElements() as $taskPlanItem) {
            if ($taskPlanItem->key !== null) {
                $this->storedKeyCounts[$taskPlanItem->key] = ($this->storedKeyCounts[$taskPlanItem->key] ?? 0) + 1;
            }
            if ($taskPlanItem->subtasks !== null) {
                $this->countStoredKeys($taskPlanItem->subtasks);
            }
        }
    }

    /**
     * One level of a keyed tree's read: check each stored key, raise the level's counter, give a keyless item a fresh
     * key, recurse. Returns the level's counter.
     */
    protected function preserveKeysOfLevel(TaskPlanItems $levelItems, string $parentKey, int $levelCounter): int
    {
        $levelLabel = $parentKey === '' ? 'at the top level' : 'under "' . $parentKey . '"';
        foreach ($levelItems->getElements() as $taskPlanItem) {
            if ($taskPlanItem->key === null) {
                continue;
            }
            if (!TaskPlanItem::isConformingKey($taskPlanItem->key)) {
                $this->taskPlanKeyIntegrityViolations->addLine('"' . $taskPlanItem->key . '" is not a key');
            } elseif (!self::keySitsUnderParentKey($taskPlanItem->key, $parentKey)) {
                $this->taskPlanKeyIntegrityViolations->addLine('"' . $taskPlanItem->key . '" sits ' . $levelLabel);
            } else {
                $levelCounter = max($levelCounter, (int)TaskPlanItem::getLastKeySegment($taskPlanItem->key));
            }
        }
        foreach ($levelItems->getElements() as $taskPlanItem) {
            if ($taskPlanItem->key !== null) {
                continue;
            }
            $freshKey = null;
            do {
                // the write side's allocation rule: never a segment above 999 — the item then keeps no key, and the
                // recorded violation pauses structure changes (R25)
                $keySegment = TaskPlanItem::allocateKeySegmentAfter($levelCounter);
                if ($keySegment === null) {
                    $freshKey = null;
                    break;
                }
                $levelCounter = $keySegment;
                $freshKey = TaskPlanItem::composeChildKey($parentKey, $levelCounter);
            } while (isset($this->storedKeyCounts[$freshKey]));
            if ($freshKey === null) {
                $this->taskPlanKeyIntegrityViolations->addLine('an item without a key ' . $levelLabel . ' got no key: the level used all ' . TaskPlanItem::KEY_SEGMENT_MAX . ' numbers');
                continue;
            }
            $taskPlanItem->key = $freshKey;
            $this->storedKeyCounts[$freshKey] = 1;
            $this->taskPlanKeyIntegrityViolations->addLine('an item without a key ' . $levelLabel . ' got "' . $freshKey . '"');
        }
        foreach ($levelItems->getElements() as $taskPlanItem) {
            if ($taskPlanItem->subtasks !== null) {
                $taskPlanItem->highestSubtaskKeyNumber = $this->preserveKeysOfLevel(
                    $taskPlanItem->subtasks,
                    (string)$taskPlanItem->key,
                    $taskPlanItem->highestSubtaskKeyNumber
                );
            }
        }
        $levelItems->regenerateElementsByUniqueKey();
        return $levelCounter;
    }

    /** Whether $key names a direct child of $parentKey ("" = the top level): the parent's key plus exactly one segment. */
    protected static function keySitsUnderParentKey(string $key, string $parentKey): bool
    {
        if ($parentKey === '') {
            return !str_contains($key, '.');
        }
        return str_starts_with($key, $parentKey . '.') && !str_contains(substr($key, strlen($parentKey) + 1), '.');
    }
}
