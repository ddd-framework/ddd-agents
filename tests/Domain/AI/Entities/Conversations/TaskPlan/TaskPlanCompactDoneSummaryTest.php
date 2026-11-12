<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentTaskPlanTickOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * On the compact plan a blocked / n-a item under a done item rides the `✓ done:` summary — rendered as a
 * line of its own without its parent it read as owed and was re-ticked; and the all-no-op tick never takes the tools
 * while a `← next` item is workable.
 */
class TaskPlanCompactDoneSummaryTest extends UnitTestCase
{
    protected function item(string $text, string $status = TaskPlanItem::STATUS_OPEN, ?string $statusNote = null): TaskPlanItem
    {
        $taskPlanItem = new TaskPlanItem();
        $taskPlanItem->text = $text;
        $taskPlanItem->status = $status;
        $taskPlanItem->statusNote = $statusNote;
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

    public function testATerminalChildOfADoneItemStandsInsideTheDoneSummary(): void
    {
        $identifyItem = $this->item('Identify');
        $identifyItem->subtasks = $this->itemSet([
            $this->item('Find the business', TaskPlanItem::STATUS_NOT_APPLICABLE, 'owner has nothing online'),
            $this->item('Capture basics', TaskPlanItem::STATUS_DONE),
        ]);
        $profileItem = $this->item('Profile');
        $blockedContainer = $this->item('Photos', TaskPlanItem::STATUS_BLOCKED, 'owner sends them later');
        $blockedContainer->subtasks = $this->itemSet([$this->item('Logo', TaskPlanItem::STATUS_NOT_APPLICABLE, 'none')]);
        $profileItem->subtasks = $this->itemSet([
            $this->item('Description', TaskPlanItem::STATUS_DONE),
            $blockedContainer,
        ]);
        $taskPlanItems = $this->itemSet([$identifyItem, $profileItem, $this->item('Handover')]);

        self::assertSame([
            '✓ done: 1, 2 (1.1 n/a, 2.2 blocked)',
            '[ ] 3 Handover ← next',
        ], $taskPlanItems->buildCompactTailHintLines());
    }

    public function testATerminalItemWithoutADoneAncestorKeepsItsOwnLine(): void
    {
        $postsItem = $this->item('Posts');
        $postsItem->subtasks = $this->itemSet([
            $this->item('Team post', TaskPlanItem::STATUS_BLOCKED, 'no photo'),
            $this->item('Hours post'),
        ]);
        $taskPlanItems = $this->itemSet([$this->item('Profile', TaskPlanItem::STATUS_DONE), $postsItem]);

        self::assertSame([
            '✓ done: 1',
            '[ ] 2 Posts',
            '  [✗ blocked: no photo] 2.1 Team post',
            '  [ ] 2.2 Hours post ← next',
        ], $taskPlanItems->buildCompactTailHintLines());
    }

    public function testTheAllNoOpTickKeepsTheToolsWhileANextItemIsWorkable(): void
    {
        $agentTaskPlanTickOutcome = new AgentTaskPlanTickOutcome();
        $agentTaskPlanTickOutcome->updateCount = 1;
        $agentTaskPlanTickOutcome->alreadyInStatusCount = 1;

        self::assertFalse($agentTaskPlanTickOutcome->requiresToolLessAnswerStep(2, 1, true), 'a workable ← next item keeps the tools');
        self::assertTrue($agentTaskPlanTickOutcome->requiresToolLessAnswerStep(2, 1, false), 'every open item waits for the owner');
        self::assertFalse($agentTaskPlanTickOutcome->requiresToolLessAnswerStep(2, 0, false), 'the first redundant tick never forces');
        self::assertTrue($agentTaskPlanTickOutcome->requiresToolLessAnswerStep(0, 0, false), 'nothing workable is left');
        self::assertFalse(
            $agentTaskPlanTickOutcome->requiresToolLessAnswerStep(2, AgentTaskPlanTickOutcome::LOOPING_FAILED_TICKS_IN_A_TURN - 1, true),
            'a short loop beside a workable ← next item keeps the tools'
        );
        self::assertTrue(
            $agentTaskPlanTickOutcome->requiresToolLessAnswerStep(2, AgentTaskPlanTickOutcome::LOOPING_FAILED_TICKS_IN_A_TURN, true),
            'a long re-tick loop is bounded even beside a workable ← next item'
        );
    }
}
