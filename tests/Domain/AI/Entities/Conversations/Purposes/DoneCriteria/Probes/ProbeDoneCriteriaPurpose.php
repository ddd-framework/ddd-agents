<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/** A purpose that only registers the probe criteria — the generic hooks of the base, nothing overridden. */
class ProbeDoneCriteriaPurpose extends AIConversationPurpose
{
    public const array DONE_CRITERION_CLASSES = [ProbeRecordDoneCriterion::class, ProbeReadsThePlanDoneCriterion::class, ProbeThrowingDoneCriterion::class];

    public const array TOOLS_CHANGING_NO_DONE_CRITERION = ['remember', 'addGoal'];
}
