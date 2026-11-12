<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;

/**
 * Re-ticking a container that is already terminal but still has open subtasks names the
 * open subtasks by their KEYS — the corrective once prefixed them with the model's raw address, so a container addressed
 * by its text produced "Profile.1". Now only the key addresses the container; a text or a copied title
 * rejects the call before the corrective.
 */
class TaskPlanContainerReTickCorrectiveTest extends UnitTestCase
{
    protected function buildPlanWithADoneContainerAndAnOpenSubtask(): TaskPlan
    {
        $items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Identify'],
            ['text' => 'Profile', 'subtasks' => [['text' => 'Contact details'], ['text' => 'Description']]],
        ]);
        $items->getElements()[1]->status = TaskPlanItem::STATUS_DONE;
        $taskPlan = new TaskPlan();
        $taskPlan->items = $items;
        return $taskPlan;
    }

    public function testTheCorrectiveNamesTheOpenSubtasksByTheirKeys(): void
    {
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '2', 'status' => 'done', 'statusNote' => 'profile written']]],
            $this->buildPlanWithADoneContainerAndAnOpenSubtask(),
            1,
            null
        );

        self::assertStringContainsString('work its open subtasks instead: 2.1, 2.2.', implode(' ', $result['feedback']));
    }

    public function testATextOrATitleAddressOfTheContainerRejectsTheCallBeforeTheCorrective(): void
    {
        foreach (['Profile', '2 Profile'] as $containerAddress) {
            $result = SetTaskStatusTestTick::apply(
                ['updates' => [['task' => $containerAddress, 'status' => 'done', 'statusNote' => 'profile written']]],
                $this->buildPlanWithADoneContainerAndAnOpenSubtask(),
                1,
                null
            );

            self::assertStringStartsWith('Task status NOT updated — nothing changed.', implode(' ', $result['feedback']), $containerAddress);
            self::assertStringNotContainsString('work its open subtasks', implode(' ', $result['feedback']), $containerAddress);
        }
    }

    public function testTheCorrectiveNamesTheSubtasksByTheirStoredKeysWhenKeysAndPositionsDiffer(): void
    {
        // a keyed tree whose keys are not the positions (constructed directly):
        // the container "3" stands second, its open subtasks are "3.4" and "3.1" in this stored order
        $taskPlan = TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'Identify', 'key' => '1', 'status' => TaskPlanItem::STATUS_OPEN],
            ['text' => 'Profile', 'key' => '3', 'status' => TaskPlanItem::STATUS_DONE, 'subtasks' => [
                ['text' => 'Description', 'key' => '3.4', 'status' => TaskPlanItem::STATUS_OPEN],
                ['text' => 'Contact details', 'key' => '3.1', 'status' => TaskPlanItem::STATUS_OPEN],
            ]],
        ], 'highestItemKeyNumber' => 3]);
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '3', 'status' => 'done', 'statusNote' => 'profile written']]],
            $taskPlan,
            1,
            null
        );

        self::assertStringContainsString('work its open subtasks instead: 3.4, 3.1.', implode(' ', $result['feedback']));
    }
}
