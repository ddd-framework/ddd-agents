<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;

/**
 * A probe criterion that reads the LIVE plan: done once the `probe.record` leaf on the inputs' plan is done — so a walk
 * that closed that leaf earlier in the same pass shows the lazy evaluation over the plan as mutated so far.
 */
class ProbeReadsThePlanDoneCriterion extends TaskPlanDoneCriterion
{
    public const string KEY = 'probe.readsThePlan';

    public const array CHANGED_BY_TOOLS = ['importProbe'];

    public function resolve(TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    {
        $recordLeaf = $taskPlanDoneCriterionInputs->taskPlanItems?->getLeafByDoneCriterion(ProbeRecordDoneCriterion::KEY);
        if ($recordLeaf?->status === TaskPlanItem::STATUS_DONE) {
            return $this->notApplicable('the record leaf is done');
        }
        return null;
    }
}
