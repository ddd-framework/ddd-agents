<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteriaWithFixedVerdicts;

/**
 * A model tick on a criterion leaf that the RECORD confirms carries the record's note and provenance (the
 * model's "none provided or found" stood on the social leaf where the platform's own verdict was the source).
 */
class TaskPlanRecordNoteOnConfirmedTickTest extends UnitTestCase
{
    protected function plan(): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Social links', 'doneCriterion' => 'social.links'],
            ['text' => 'Keywords', 'doneCriterion' => 'keywords.curated'],
        ]);
        return $taskPlan;
    }

    public function testTheRecordsNoteReplacesTheModelsWhenTheRecordAgrees(): void
    {
        $taskPlan = $this->plan();
        SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'not_applicable', 'statusNote' => 'none provided or found']]],
            $taskPlan,
            10,
            taskPlanDoneCriteria: TaskPlanDoneCriteriaWithFixedVerdicts::answeringByKey([
                'social.links' => TaskPlanDoneCriterionVerdict::notApplicable('none found: no page read of this conversation carried a verified social profile URL, the owner named none'),
            ]),
            criteriaDecideTicks: true
        );
        $leaf = $taskPlan->items->getElements()[0];
        self::assertSame(TaskPlanItem::STATUS_NOT_APPLICABLE, $leaf->status);
        self::assertStringStartsWith('none found: no page read', (string)$leaf->statusNote);
        self::assertSame(10, $leaf->platformResolvedAtMessageIndex);
    }

    public function testWithoutARecordVerdictTheModelsNoteStands(): void
    {
        $taskPlan = $this->plan();
        SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '2', 'status' => 'done', 'statusNote' => 'twelve keywords saved']]],
            $taskPlan,
            10,
            taskPlanDoneCriteria: TaskPlanDoneCriteriaWithFixedVerdicts::answeringEveryKeyWith(TaskPlanDoneCriterionVerdict::done('already satisfied: 12/5 keywords')),
            criteriaDecideTicks: true
        );
        $keywordLeaf = $taskPlan->items->getElements()[1];
        self::assertSame(TaskPlanItem::STATUS_DONE, $keywordLeaf->status);
        self::assertSame('already satisfied: 12/5 keywords', $keywordLeaf->statusNote, 'the record agrees → its note');

        $taskPlan = $this->plan();
        SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '2', 'status' => 'done', 'statusNote' => 'twelve keywords saved']]],
            $taskPlan,
            10,
            taskPlanDoneCriteria: TaskPlanDoneCriteriaWithFixedVerdicts::answeringEveryKeyWith(null)
        );
        self::assertSame('twelve keywords saved', $taskPlan->items->getElements()[1]->statusNote, 'no record verdict → the model\'s note');
    }
}
