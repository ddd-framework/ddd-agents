<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;

/**
 * A DONE plan item stays done: a later `not_applicable` from the model is its own re-judgement of a finished leaf
 * (the marketing-history leaf the platform had closed was flipped to not_applicable "no marketing history detail
 * applies yet") — not applied, the feedback says why. A later `blocked` still applies: a finished
 * leaf can lose its channel afterwards (TaskPlanAndCheckedStopTest).
 */
class TaskPlanDoneItemStaysDoneTest extends UnitTestCase
{
    protected function planWithOneDoneLeaf(): TaskPlan
    {
        $items = TaskPlanItems::fromPurposeSeedDefinition([['text' => 'Marketing history line']]);
        $item = $items->getElements()[0];
        $item->status = TaskPlanItem::STATUS_DONE;
        $item->statusNote = 'the draft was approved';
        $taskPlan = new TaskPlan();
        $taskPlan->items = $items;
        return $taskPlan;
    }

    public function testADoneItemIgnoresALaterNotApplicable(): void
    {
        foreach (['not_applicable'] as $laterStatus) {
            $taskPlan = $this->planWithOneDoneLeaf();
            $result = SetTaskStatusTestTick::apply(
                ['updates' => [['task' => '1', 'status' => $laterStatus, 'statusNote' => 'no detail applies yet']]],
                $taskPlan,
                1,
                null
            );
            self::assertSame(0, $result['updatedCount'], $laterStatus);
            self::assertSame(TaskPlanItem::STATUS_DONE, $taskPlan->items->getElements()[0]->status, $laterStatus);
            self::assertSame('the draft was approved', $taskPlan->items->getElements()[0]->statusNote, $laterStatus);
            self::assertStringContainsString('a done item stays done', implode(' ', $result['feedback']), $laterStatus);
        }
    }

    public function testADoneItemMayStillBeBlockedLater(): void
    {
        $taskPlan = $this->planWithOneDoneLeaf();
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => 'channel missing']]],
            $taskPlan,
            1,
            null
        );
        self::assertSame(TaskPlanItem::STATUS_BLOCKED, $taskPlan->items->getElements()[0]->status);
        self::assertSame(1, $result['updatedCount']);
    }

    public function testADoneItemMayStillBeReopened(): void
    {
        $taskPlan = $this->planWithOneDoneLeaf();
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'open', 'statusNote' => '']]],
            $taskPlan,
            1,
            null
        );
        self::assertSame(TaskPlanItem::STATUS_OPEN, $taskPlan->items->getElements()[0]->status);
        self::assertSame(1, $result['updatedCount']);
    }
}
