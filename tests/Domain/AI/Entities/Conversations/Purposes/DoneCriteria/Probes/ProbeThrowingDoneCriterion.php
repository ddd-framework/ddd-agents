<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use RuntimeException;

/** A probe criterion whose read fails. */
class ProbeThrowingDoneCriterion extends TaskPlanDoneCriterion
{
    public const string KEY = 'probe.throwing';

    public function resolve(TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    {
        throw new RuntimeException('the record could not be read');
    }
}
