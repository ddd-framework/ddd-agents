<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;

/** A probe that exposes the base's verdict helpers; `resolve()` answers the verdict named by `$helperName`. */
class ProbeVerdictHelpersDoneCriterion extends TaskPlanDoneCriterion
{
    public const string KEY = 'probe.helpers';

    public string $helperName = 'done';

    public function resolve(TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    {
        return match ($this->helperName) {
            'done' => $this->done('note'),
            'doneOverridingPlatformBlock' => $this->doneOverridingPlatformBlock('note'),
            'notApplicable' => $this->notApplicable('note'),
            'blockedByOwnerStatement' => $this->blockedByOwnerStatement('note'),
            'stillOpen' => $this->stillOpen('note'),
            default => null,
        };
    }
}
