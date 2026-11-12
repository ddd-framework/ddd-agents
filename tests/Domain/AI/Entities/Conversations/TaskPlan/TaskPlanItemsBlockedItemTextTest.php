<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The eval gate `taskPlan.blockedRequiringUserActionItemTextContains` reads
 * {@see TaskPlanItems::hasBlockedItemRequiringUserActionWithTextContaining()} — the named item must be the one blocked
 * with requiresUserAction (a false PASS once blocked the reviews item instead of the Facebook one).
 */
class TaskPlanItemsBlockedItemTextTest extends UnitTestCase
{
    public function testOnlyTheNamedItemBlockedWithRequiresUserActionPasses(): void
    {
        $reviewsItem = $this->item('Neueste Bewertungen laden', TaskPlanItem::STATUS_BLOCKED, true);
        $facebookItem = $this->item('Herbstaktion auf Facebook veröffentlichen', TaskPlanItem::STATUS_DONE, false);
        $taskPlanItems = $this->itemSet([$reviewsItem, $facebookItem]);
        self::assertTrue($taskPlanItems->hasBlockedItemRequiringUserAction(), 'the old gate passes on any item');
        self::assertFalse($taskPlanItems->hasBlockedItemRequiringUserActionWithTextContaining('Facebook'));

        $facebookItem->status = TaskPlanItem::STATUS_BLOCKED;
        $facebookItem->requiresUserAction = true;
        self::assertTrue($taskPlanItems->hasBlockedItemRequiringUserActionWithTextContaining('facebook'), 'case-insensitive');
    }

    protected function item(string $text, string $status, bool $requiresUserAction): TaskPlanItem
    {
        $taskPlanItem = new TaskPlanItem();
        $taskPlanItem->text = $text;
        $taskPlanItem->status = $status;
        $taskPlanItem->requiresUserAction = $requiresUserAction;
        return $taskPlanItem;
    }

    /** @param TaskPlanItem[] $taskPlanItemList */
    protected function itemSet(array $taskPlanItemList): TaskPlanItems
    {
        $taskPlanItems = new TaskPlanItems();
        foreach ($taskPlanItemList as $taskPlanItem) {
            $taskPlanItems->add($taskPlanItem);
        }
        return $taskPlanItems;
    }
}
