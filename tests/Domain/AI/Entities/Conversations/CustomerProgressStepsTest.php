<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteriaWithFixedVerdicts;
use DDD\Domain\AI\Entities\Conversations\Agent\Progress\CustomerProgressStep;
use DDD\Domain\AI\Entities\Conversations\Agent\Progress\CustomerProgressSteps;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The customer-facing progress STEPPER: seed-key parsing incl. subtask
 * inheritance, the repository round-trip of {@see TaskPlanItem::$customerProgressStepKey}, the full-replace
 * carry, and the {@see CustomerProgressSteps::fromPurposeDeclarationAndPlanItems()} status aggregation
 * (done / active / upcoming, terminal-counts-as-complete, finished-plan catch-all).
 */
class CustomerProgressStepsTest extends UnitTestCase
{
    protected const array DECLARED_STEPS = [
        ['key' => 'identify', 'customerLabel' => 'Find your business'],
        ['key' => 'profile', 'customerLabel' => 'Set up your profile'],
        ['key' => 'handover', 'customerLabel' => 'Wrap-up & handover'],
    ];

    public function testSeedParsingAssignsKeysAndSubtasksInheritTheParentKey(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Identify the business', 'customerProgressStepKey' => 'identify'],
            [
                'text' => 'Profile round',
                'customerProgressStepKey' => 'profile',
                'subtasks' => [
                    ['text' => 'Contact details'],
                    ['text' => 'Google connect', 'customerProgressStepKey' => 'connect'],
                ],
            ],
            ['text' => 'No stepper membership'],
        ]);
        $itemElements = array_values($taskPlanItems->getElements());
        self::assertSame('identify', $itemElements[0]->customerProgressStepKey);
        self::assertSame('profile', $itemElements[1]->customerProgressStepKey);
        $profileSubtasks = array_values($itemElements[1]->subtasks->getElements());
        self::assertSame('profile', $profileSubtasks[0]->customerProgressStepKey, 'a key-less subtask inherits the parent key');
        self::assertSame('connect', $profileSubtasks[1]->customerProgressStepKey, 'an own key overrides the inherited one');
        self::assertNull($itemElements[2]->customerProgressStepKey);
    }

    public function testOwnerFacingTitleParsesFromSeedSurvivesTheRoundTripAndTheModelResend(): void
    {
        // Seed parse + repository round-trip (the snapshot rebuild every turn runs on).
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Act 2 — Keyword set agreed & saved (load Setup.Keywords)', 'ownerFacingTitle' => 'Keywords'],
            ['text' => 'Untitled internal item'],
        ]);
        self::assertSame('Keywords', $taskPlanItems->first()->ownerFacingTitle);
        $rebuiltItems = TaskPlanItems::fromRepositoryArray($taskPlanItems->toRepositoryArray());
        $rebuiltElements = array_values($rebuiltItems->getElements());
        self::assertSame('Keywords', $rebuiltElements[0]->ownerFacingTitle);
        self::assertNull($rebuiltElements[1]->ownerFacingTitle);
    }

    public function testCustomerProgressStepKeySurvivesTheRepositoryRoundTrip(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Identify', 'customerProgressStepKey' => 'identify', 'subtasks' => [['text' => 'Seed question']]],
        ]);
        $rebuiltItems = TaskPlanItems::fromRepositoryArray($taskPlanItems->toRepositoryArray());
        $rebuiltFirstItem = $rebuiltItems->first();
        self::assertSame('identify', $rebuiltFirstItem->customerProgressStepKey);
        self::assertSame('identify', $rebuiltFirstItem->subtasks->first()->customerProgressStepKey);
    }

    public function testStatusAggregationDoneActiveUpcoming(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Personal level', 'customerProgressStepKey' => 'identify'],
            ['text' => 'Identify the business', 'customerProgressStepKey' => 'identify'],
            ['text' => 'Profile round', 'customerProgressStepKey' => 'profile'],
            ['text' => 'Handover', 'customerProgressStepKey' => 'handover'],
        ]);
        $itemElements = array_values($taskPlanItems->getElements());
        $itemElements[0]->status = TaskPlanItem::STATUS_DONE;
        // Terminal-counts-as-complete: not_applicable closes the member like done — the step must not hang.
        $itemElements[1]->status = TaskPlanItem::STATUS_NOT_APPLICABLE;

        $customerProgressSteps = CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(self::DECLARED_STEPS, $taskPlanItems);
        $stepElements = array_values($customerProgressSteps->getElements());
        self::assertCount(3, $stepElements);
        self::assertSame(['identify', 'profile', 'handover'], array_map(static fn ($step) => $step->key, $stepElements));
        self::assertSame(CustomerProgressStep::STATUS_DONE, $stepElements[0]->status);
        self::assertSame(CustomerProgressStep::STATUS_ACTIVE, $stepElements[1]->status);
        self::assertSame(CustomerProgressStep::STATUS_UPCOMING, $stepElements[2]->status);
        self::assertSame('Find your business', $stepElements[0]->customerLabel);
    }

    /**
     * The regression: a deferred connect offer in the profile step, or a competitor tick the
     * gate rejected in the baseline step, kept THAT step active while the agent already worked the later acts
     * — the owner saw the marker frozen on step 2/3 and then jump straight to the last step. The marker
     * follows the furthest step with real progress; the passed step with a leftover leaf reads done.
     */
    public function testActiveStepFollowsTheFurthestProgressNotTheFirstLeftoverLeaf(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Identify the business', 'customerProgressStepKey' => 'identify'],
            ['text' => 'Profile round', 'customerProgressStepKey' => 'profile'],
            ['text' => 'Social connect offer', 'customerProgressStepKey' => 'profile'],
            ['text' => 'Quick win', 'customerProgressStepKey' => 'handover'],
            ['text' => 'Handover', 'customerProgressStepKey' => 'handover'],
        ]);
        $itemElements = array_values($taskPlanItems->getElements());
        $itemElements[0]->status = TaskPlanItem::STATUS_DONE;
        $itemElements[1]->status = TaskPlanItem::STATUS_DONE;
        // The offer leaf stays OPEN (deferred by the owner, never parked) while act 3 already delivered the quick win.
        $itemElements[3]->status = TaskPlanItem::STATUS_DONE;

        $stepElements = array_values(CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(self::DECLARED_STEPS, $taskPlanItems)->getElements());
        self::assertSame(CustomerProgressStep::STATUS_DONE, $stepElements[0]->status);
        self::assertSame(CustomerProgressStep::STATUS_DONE, $stepElements[1]->status, 'the passed step with a leftover offer reads done');
        self::assertSame(CustomerProgressStep::STATUS_ACTIVE, $stepElements[2]->status, 'the marker sits where the work is');

        // No progress beyond the leftover step yet → the leftover step itself is the honest active marker.
        $itemElements[3]->status = TaskPlanItem::STATUS_OPEN;
        $stepElements = array_values(CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(self::DECLARED_STEPS, $taskPlanItems)->getElements());
        self::assertSame(CustomerProgressStep::STATUS_ACTIVE, $stepElements[1]->status);
        self::assertSame(CustomerProgressStep::STATUS_UPCOMING, $stepElements[2]->status);

        // Later steps all done, only the earlier leftover open → that step is active (truthful, not hidden).
        $itemElements[3]->status = TaskPlanItem::STATUS_DONE;
        $itemElements[4]->status = TaskPlanItem::STATUS_DONE;
        $stepElements = array_values(CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(self::DECLARED_STEPS, $taskPlanItems)->getElements());
        self::assertSame(CustomerProgressStep::STATUS_ACTIVE, $stepElements[1]->status);
        self::assertSame(CustomerProgressStep::STATUS_DONE, $stepElements[2]->status);
    }

    /**
     * Turn 0: the seed pre-resolves a leaf of the last step (the social links) to not_applicable from live state. That is NOT progress — the marker stays on step one, nothing reads done.
     * Once the agent ticks the first item, the marker still stays on the identify step while it has open work.
     */
    public function testSeedPreResolvedLeavesInLaterStepsAreNotProgress(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Personal level', 'customerProgressStepKey' => 'identify'],
            ['text' => 'Identify the business', 'customerProgressStepKey' => 'identify'],
            ['text' => 'Profile round', 'customerProgressStepKey' => 'profile'],
            ['text' => 'Social media links', 'customerProgressStepKey' => 'handover', 'doneCriterion' => 'social.links'],
            ['text' => 'Handover', 'customerProgressStepKey' => 'handover'],
        ]);
        $resolved = $taskPlanItems->applyPreResolutions(
            TaskPlanDoneCriteriaWithFixedVerdicts::answeringByKey(['social.links' => TaskPlanDoneCriterionVerdict::notApplicable('none found')]),
            TaskPlanDoneCriteriaWithFixedVerdicts::inputsFor($taskPlanItems)
        );
        self::assertSame(1, $resolved);
        $itemElements = array_values($taskPlanItems->getElements());
        self::assertSame(TaskPlanItem::STATUS_NOT_APPLICABLE, $itemElements[3]->status);
        self::assertTrue($itemElements[3]->preResolvedAtSeed);

        $stepElements = array_values(CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(self::DECLARED_STEPS, $taskPlanItems)->getElements());
        self::assertSame(
            [CustomerProgressStep::STATUS_ACTIVE, CustomerProgressStep::STATUS_UPCOMING, CustomerProgressStep::STATUS_UPCOMING],
            array_map(static fn ($step) => $step->status, $stepElements),
            'turn 0: nothing reached, the marker is on step one'
        );

        // Turn 1: the agent ticks the personal level — identify still has open work, the marker stays there.
        $itemElements[0]->status = TaskPlanItem::STATUS_DONE;
        $stepElements = array_values(CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(self::DECLARED_STEPS, $taskPlanItems)->getElements());
        self::assertSame(CustomerProgressStep::STATUS_ACTIVE, $stepElements[0]->status);
        self::assertSame(CustomerProgressStep::STATUS_UPCOMING, $stepElements[1]->status);

        // The flag survives the repository round-trip (the snapshot rebuilds the plan every turn).
        $rebuilt = TaskPlanItems::fromRepositoryArray($taskPlanItems->toRepositoryArray());
        self::assertTrue(array_values($rebuilt->getElements())[3]->preResolvedAtSeed);
    }

    /** A memberless step (its keys lost through a restructure) is never "passed" — it stays upcoming, not done. */
    public function testMemberlessStepBeforeTheActiveOneStaysUpcoming(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Identify the business', 'customerProgressStepKey' => 'identify'],
            // no 'profile' member at all
            ['text' => 'Quick win', 'customerProgressStepKey' => 'handover'],
            ['text' => 'Handover', 'customerProgressStepKey' => 'handover'],
        ]);
        $itemElements = array_values($taskPlanItems->getElements());
        $itemElements[0]->status = TaskPlanItem::STATUS_DONE;
        // An owner deferral (blocked) is dialog progress like a tick.
        $itemElements[1]->status = TaskPlanItem::STATUS_BLOCKED;

        $stepElements = array_values(CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(self::DECLARED_STEPS, $taskPlanItems)->getElements());
        self::assertSame(CustomerProgressStep::STATUS_DONE, $stepElements[0]->status);
        self::assertSame(CustomerProgressStep::STATUS_UPCOMING, $stepElements[1]->status, 'memberless, before the active step — honest upcoming');
        self::assertSame(CustomerProgressStep::STATUS_ACTIVE, $stepElements[2]->status);
    }

    public function testFinishedPlanClosesEveryStepIncludingMemberlessOnes(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Identify the business', 'customerProgressStepKey' => 'identify'],
            ['text' => 'Profile round', 'customerProgressStepKey' => 'profile'],
            // No item carries the 'handover' key — e.g. lost through an agent restructure.
        ]);
        foreach ($taskPlanItems->getElements() as $taskPlanItem) {
            $taskPlanItem->status = TaskPlanItem::STATUS_DONE;
        }
        $customerProgressSteps = CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(self::DECLARED_STEPS, $taskPlanItems);
        foreach ($customerProgressSteps->getElements() as $customerProgressStep) {
            self::assertSame(CustomerProgressStep::STATUS_DONE, $customerProgressStep->status);
        }
    }

    public function testEmptyDeclarationOrMissingPlanYieldsAnEmptySet(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([['text' => 'Anything', 'customerProgressStepKey' => 'identify']]);
        self::assertSame(0, CustomerProgressSteps::fromPurposeDeclarationAndPlanItems([], $taskPlanItems)->count());
        self::assertSame(0, CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(self::DECLARED_STEPS, null)->count());
        self::assertSame(0, CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(self::DECLARED_STEPS, new TaskPlanItems())->count());
    }

    public function testAgentAddedItemsWithoutKeysNeverShiftTheStepper(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Identify the business', 'customerProgressStepKey' => 'identify'],
        ]);
        $identifyItem = $taskPlanItems->first();
        $identifyItem->status = TaskPlanItem::STATUS_DONE;
        // An agent-added, key-less item stays open — it must not keep the 'identify' step active.
        $agentAddedItem = new TaskPlanItem();
        $agentAddedItem->text = 'Follow up on the map pin';
        $taskPlanItems->add($agentAddedItem);

        $customerProgressSteps = CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(
            [['key' => 'identify', 'customerLabel' => 'Find your business']],
            $taskPlanItems
        );
        self::assertSame(CustomerProgressStep::STATUS_DONE, $customerProgressSteps->first()->status);
    }
}
