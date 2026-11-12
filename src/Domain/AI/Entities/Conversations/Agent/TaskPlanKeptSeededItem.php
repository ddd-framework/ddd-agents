<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE seeded item an `update_task_plan` call keeps on a level: its key and the position it holds in the stored plan.
 * {@see TaskPlanUpdateCheck::checkLevel()} collects them in the order the call sends them;
 * {@see TaskPlanUpdateCheck::checkSeededOrder()} refuses a call that swaps two of them.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanKeptSeededItem extends ValueObject
{
    /** @var string The item's key as the call sends it */
    public string $key = '';

    /** @var int The item's position in the stored plan */
    public int $storedPosition = 0;

    /**
     * @param string $key
     * @param int $storedPosition
     * @return static
     */
    public static function forKeyAndStoredPosition(string $key, int $storedPosition): static
    {
        $taskPlanKeptSeededItem = new static();
        $taskPlanKeptSeededItem->key = $key;
        $taskPlanKeptSeededItem->storedPosition = $storedPosition;
        return $taskPlanKeptSeededItem;
    }

    /**
     * @param TaskPlanKeptSeededItem $taskPlanKeptSeededItem The item the call sends before this one
     * @return bool Whether this item stands before `$taskPlanKeptSeededItem` in the stored plan
     */
    public function isStoredBefore(TaskPlanKeptSeededItem $taskPlanKeptSeededItem): bool
    {
        return $this->storedPosition < $taskPlanKeptSeededItem->storedPosition;
    }
}
