<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;

/** A probe criterion over the inputs alone: done when messages are on the inputs, else still open (its reason names the key). */
class ProbeRecordDoneCriterion extends TaskPlanDoneCriterion
{
    public const string KEY = 'probe.record';

    public const array CHANGED_BY_TOOLS = ['updateProbeRecord', 'importProbe'];

    public function resolve(TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    {
        if ($taskPlanDoneCriterionInputs->conversationMessages === null) {
            return $this->stillOpen('no messages for ' . static::KEY);
        }
        return $this->done('already satisfied: ' . static::KEY);
    }
}
