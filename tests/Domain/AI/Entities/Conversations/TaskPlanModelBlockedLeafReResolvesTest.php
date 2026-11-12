<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteriaWithFixedVerdicts;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A leaf the MODEL blocked is a claim about the record; the record decides: the pin confirm saved the address and the
 * identify leaf stayed "blocked — the owner must give the address" to the handover. A platform-blocked leaf
 * (the owner's own deferral) and a record that still says blocked are untouched.
 */
class TaskPlanModelBlockedLeafReResolvesTest extends UnitTestCase
{
    public function testAModelBlockedLeafClosesWhenTheRecordSatisfiesItsCriterion(): void
    {
        $items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Import or capture', 'doneCriterion' => 'identity.captured'],
        ]);
        $leaf = $items->getElements()[0];
        $leaf->status = TaskPlanItem::STATUS_BLOCKED;
        $leaf->requiresUserAction = true;
        $leaf->statusNote = 'the owner must give the address';

        $resolved = $items->applyPreResolutions(TaskPlanDoneCriteriaWithFixedVerdicts::answeringEveryKeyWith(TaskPlanDoneCriterionVerdict::done('address saved by the pin')), TaskPlanDoneCriteriaWithFixedVerdicts::inputsFor($items));

        self::assertSame(1, $resolved);
        self::assertSame(TaskPlanItem::STATUS_DONE, $leaf->status);
        self::assertStringContainsString('address saved by the pin', (string)$leaf->statusNote);
    }

    public function testARecordThatStillSaysBlockedOrAPlatformBlockedLeafIsLeftAlone(): void
    {
        $items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Import or capture', 'doneCriterion' => 'identity.captured'],
            ['text' => 'Contact details', 'doneCriterion' => 'contact.details'],
        ]);
        $modelBlocked = $items->getElements()[0];
        $modelBlocked->status = TaskPlanItem::STATUS_BLOCKED;
        $modelBlocked->requiresUserAction = true;
        $modelBlocked->statusNote = 'the model says blocked';
        $platformBlocked = $items->getElements()[1];
        $platformBlocked->status = TaskPlanItem::STATUS_BLOCKED;
        $platformBlocked->platformResolvedAtMessageIndex = 12;
        $platformBlocked->statusNote = 'Owner declined this in their own words';

        $resolved = $items->applyPreResolutions(
            TaskPlanDoneCriteriaWithFixedVerdicts::answeringByKey(
                ['identity.captured' => TaskPlanDoneCriterionVerdict::blockedByOwnerStatement('still blocked by the record')],
                TaskPlanDoneCriterionVerdict::done('would close')
            ),
            TaskPlanDoneCriteriaWithFixedVerdicts::inputsFor($items)
        );

        self::assertSame(0, $resolved);
        self::assertSame('the model says blocked', $modelBlocked->statusNote, 'a blocked record never re-stamps a model block');
        self::assertSame(TaskPlanItem::STATUS_BLOCKED, $platformBlocked->status, 'the platform\'s own block stands');
    }

    /** A platform PARK of the address capture reopens when the record carries the very fact it waited for (the street). */
    public function testAPlatformParkedCaptureClosesOnADoneThatOverridesIt(): void
    {
        $items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Import or capture', 'doneCriterion' => 'identity.captured'],
        ]);
        $parked = $items->getElements()[0];
        $parked->status = TaskPlanItem::STATUS_BLOCKED;
        $parked->requiresUserAction = true;
        $parked->platformResolvedAtMessageIndex = 12;
        $parked->statusNote = 'the owner gave no street address — the capture is parked';

        $resolved = $items->applyPreResolutions(TaskPlanDoneCriteriaWithFixedVerdicts::answeringEveryKeyWith(TaskPlanDoneCriterionVerdict::doneOverridingPlatformBlock('already satisfied: street address on file, map spot approximate')), TaskPlanDoneCriteriaWithFixedVerdicts::inputsFor($items));

        self::assertSame(1, $resolved);
        self::assertSame(TaskPlanItem::STATUS_DONE, $parked->status);
        self::assertStringContainsString('street address on file', $parked->statusNote);
    }

    /** A PLATFORM n/a is re-read like a park: only a DONE overriding it reopens it ("nothing named", then a phone was saved). */
    public function testAPlatformNotApplicableLeafClosesOnlyOnADoneThatOverridesIt(): void
    {
        $items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Contact details', 'doneCriterion' => 'contact.details'],
        ]);
        $leaf = $items->getElements()[0];
        $leaf->status = TaskPlanItem::STATUS_NOT_APPLICABLE;
        $leaf->platformResolvedAtMessageIndex = 12;
        $leaf->statusNote = 'the owner\'s nod named none';

        $resolved = $items->applyPreResolutions(TaskPlanDoneCriteriaWithFixedVerdicts::answeringEveryKeyWith(TaskPlanDoneCriterionVerdict::done('a plain done')), TaskPlanDoneCriteriaWithFixedVerdicts::inputsFor($items));
        self::assertSame(0, $resolved);
        self::assertSame(TaskPlanItem::STATUS_NOT_APPLICABLE, $leaf->status);

        $resolved = $items->applyPreResolutions(TaskPlanDoneCriteriaWithFixedVerdicts::answeringEveryKeyWith(TaskPlanDoneCriterionVerdict::doneOverridingPlatformBlock('already satisfied: phone + website')), TaskPlanDoneCriteriaWithFixedVerdicts::inputsFor($items));
        self::assertSame(1, $resolved);
        self::assertSame(TaskPlanItem::STATUS_DONE, $leaf->status);
        self::assertStringContainsString('phone + website', (string)$leaf->statusNote);
    }
}
