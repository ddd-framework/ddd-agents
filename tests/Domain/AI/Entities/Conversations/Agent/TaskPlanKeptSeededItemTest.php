<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanKeptSeededItem;
use DDD\Tests\Agents\Support\UnitTestCase;

/** A seeded item an `update_task_plan` call keeps carries its key and stored position and compares by that position. */
class TaskPlanKeptSeededItemTest extends UnitTestCase
{
    public function testTheItemComparesByItsStoredPosition(): void
    {
        $firstStoredItem = TaskPlanKeptSeededItem::forKeyAndStoredPosition('1', 0);
        $secondStoredItem = TaskPlanKeptSeededItem::forKeyAndStoredPosition('2', 1);

        self::assertSame('2', $secondStoredItem->key);
        self::assertSame(1, $secondStoredItem->storedPosition);
        self::assertTrue($firstStoredItem->isStoredBefore($secondStoredItem));
        self::assertFalse($secondStoredItem->isStoredBefore($firstStoredItem));
        self::assertFalse($firstStoredItem->isStoredBefore($firstStoredItem));
    }
}
