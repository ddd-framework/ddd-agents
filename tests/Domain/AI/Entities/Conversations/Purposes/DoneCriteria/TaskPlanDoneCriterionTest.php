<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes\ProbeRecordDoneCriterion;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes\ProbeSecondRecordDoneCriterion;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\Probes\ProbeVerdictHelpersDoneCriterion;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The abstract done criterion: its key, its tool mapping, its identity and the verdict helpers. */
class TaskPlanDoneCriterionTest extends UnitTestCase
{
    public function testTheKeyAndTheToolMappingComeFromTheConstants(): void
    {
        $probeRecordDoneCriterion = new ProbeRecordDoneCriterion();
        self::assertSame('probe.record', $probeRecordDoneCriterion->getKey());
        self::assertTrue($probeRecordDoneCriterion->isChangedByTool('updateProbeRecord'));
        self::assertTrue($probeRecordDoneCriterion->isChangedByTool('importProbe'));
        self::assertFalse($probeRecordDoneCriterion->isChangedByTool('addGoal'));
        self::assertFalse($probeRecordDoneCriterion->isChangedByTool(''));
    }

    public function testTheIdentityIsTheKeyNotTheClass(): void
    {
        self::assertSame(
            (new ProbeRecordDoneCriterion())->uniqueKey(),
            (new ProbeSecondRecordDoneCriterion())->uniqueKey(),
            'two classes deciding one key are one criterion'
        );
        self::assertSame(TaskPlanDoneCriterion::uniqueKeyForDoneCriterionKey('probe.record'), (new ProbeRecordDoneCriterion())->uniqueKey());
        self::assertNotSame((new ProbeRecordDoneCriterion())->uniqueKey(), (new ProbeVerdictHelpersDoneCriterion())->uniqueKey());
    }

    public function testTheVerdictHelpersBuildTheVerdictsOfTheVerdictValueObject(): void
    {
        $expectedVerdictsByHelperName = [
            'done' => TaskPlanDoneCriterionVerdict::done('note'),
            'doneOverridingPlatformBlock' => TaskPlanDoneCriterionVerdict::doneOverridingPlatformBlock('note'),
            'notApplicable' => TaskPlanDoneCriterionVerdict::notApplicable('note'),
            'blockedByOwnerStatement' => TaskPlanDoneCriterionVerdict::blockedByOwnerStatement('note'),
            'stillOpen' => TaskPlanDoneCriterionVerdict::stillOpen('note'),
        ];
        $probeVerdictHelpersDoneCriterion = new ProbeVerdictHelpersDoneCriterion();
        foreach ($expectedVerdictsByHelperName as $helperName => $expectedVerdict) {
            $probeVerdictHelpersDoneCriterion->helperName = $helperName;
            $verdict = $probeVerdictHelpersDoneCriterion->resolve(new TaskPlanDoneCriterionInputs());
            self::assertSame(
                [$expectedVerdict->status, $expectedVerdict->statusNote, $expectedVerdict->requiresUserAction, $expectedVerdict->overridesPlatformBlock],
                [$verdict?->status, $verdict?->statusNote, $verdict?->requiresUserAction, $verdict?->overridesPlatformBlock],
                $helperName
            );
        }
        $probeVerdictHelpersDoneCriterion->helperName = 'stillOpen';
        self::assertTrue($probeVerdictHelpersDoneCriterion->resolve(new TaskPlanDoneCriterionInputs())?->isStillOpen());
        $probeVerdictHelpersDoneCriterion->helperName = 'blockedByOwnerStatement';
        self::assertSame(TaskPlanItem::STATUS_BLOCKED, $probeVerdictHelpersDoneCriterion->resolve(new TaskPlanDoneCriterionInputs())?->status);
    }

    public function testTheBaseKeyIsEmptyAndChangesNothing(): void
    {
        self::assertSame('', TaskPlanDoneCriterion::KEY);
        self::assertSame([], TaskPlanDoneCriterion::CHANGED_BY_TOOLS);
        self::assertSame([], TaskPlanDoneCriterion::GATHERED_FACTS);
    }
}
