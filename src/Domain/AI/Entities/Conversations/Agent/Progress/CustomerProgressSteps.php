<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Progress;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The ORDERED customer-facing progress steps of a purposed conversation (a guided run's stepper) — assembled from
 * the purpose's `customerProgressSteps` frontmatter declaration (order + keys + labels) and the live task
 * plan's member items ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem::$customerProgressStepKey}).
 * The frontend contract is PRESENCE-BASED: a non-empty set on the conversation wire means "render the
 * stepper" — no purpose-name checks in the frontend, ever.
 *
 * @property CustomerProgressStep[] $elements
 * @method CustomerProgressStep[] getElements()
 * @method CustomerProgressStep|null first()
 * @phpstan-consistent-constructor
 */
class CustomerProgressSteps extends ObjectSet
{
    /**
     * Assemble the steps from the purpose's declaration and the live plan tree — pure logic, no repo:
     *
     * - a step is DONE when (a) it has member items and NONE of them is effectively open
     *   ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem::isOpen()} — blocked and
     *   not_applicable count as terminal on purpose: the step stays visible as completed instead of
     *   vanishing mid-flow), or (b) the WHOLE plan has no open items left (the finished-plan catch-all
     *   that also covers a step whose member keys were lost), or (c) it was PASSED: it has members, sits
     *   before the active step and the work moved on (a leftover leaf — an offer the owner deferred, a
     *   tick the gate rejected — is handled at the run's end or outside the conversation, it never holds the
     *   marker back while later acts run);
     * - ACTIVE is the step the work has REACHED: the first not-done step at or after the furthest step
     *   holding a member the DIALOG resolved (agent tick, owner deferral, platform deferral — never a leaf
     *   the platform pre-resolved from live state at seed time, {@see TaskPlanItem::$preResolvedAtSeed}:
     *   an item of the last act that never applied must not read as "wrap-up reached" on turn 0).
     *   Without any dialog progress yet, the first not-done step is active (the run starts at step one).
     *   When every step from the furthest progress on is done, the first not-done step overall is active —
     *   the marker then points BACK at the leftover, truthfully: with the handover delivered, only that
     *   leftover keeps the run open (a deferrable offer is parked by the reconciliation after the turn and
     *   closes the plan; a non-deferrable one is real unfinished work);
     * - every not-done step after the active one is UPCOMING; a MEMBERLESS step before the active one is
     *   never "passed" — it stays UPCOMING until the whole plan closes (a step whose member keys were lost
     *   through an agent restructure is not reported complete mid-run).
     *
     * Items without a key (agent-added mid-run) are invisible here — the stepper never jumps on dynamic
     * plan growth. Returns an EMPTY set when the declaration is empty (the frontend's "no stepper").
     *
     * @param array<int, array<string, mixed>> $declaredSteps The purpose frontmatter list — each entry a map
     *        with `key` and `customerLabel`.
     */
    public static function fromPurposeDeclarationAndPlanItems(array $declaredSteps, ?TaskPlanItems $taskPlanItems): static
    {
        $customerProgressSteps = new static();
        if ($declaredSteps === [] || $taskPlanItems === null || $taskPlanItems->count() === 0) {
            return $customerProgressSteps;
        }
        /** @var CustomerProgressStepMemberState[] $memberStatesByStepKey keyed by customer progress step key */
        $memberStatesByStepKey = [];
        $taskPlanItems->collectCustomerProgressStepMemberStates($memberStatesByStepKey);
        $planHasOpenItems = $taskPlanItems->hasOpenItems();

        // Pass 1 — the declared steps with their member facts (done / has-progress), in declared order.
        /** @var CustomerProgressDeclaredStep[] $customerProgressDeclaredSteps */
        $customerProgressDeclaredSteps = [];
        foreach ($declaredSteps as $declaredStep) {
            $customerProgressDeclaredStep = is_array($declaredStep) ? CustomerProgressDeclaredStep::fromPurposeDeclaration($declaredStep) : null;
            if ($customerProgressDeclaredStep === null) {
                continue;
            }
            $customerProgressDeclaredStep->applyMemberState(
                $memberStatesByStepKey[$customerProgressDeclaredStep->key] ?? CustomerProgressStepMemberState::forNoMembers(),
                $planHasOpenItems
            );
            $customerProgressDeclaredSteps[] = $customerProgressDeclaredStep;
        }

        // Pass 2 — the active step: the first not-done step at or after the furthest step with DIALOG progress.
        $furthestStepIndexWithProgress = null;
        foreach ($customerProgressDeclaredSteps as $stepIndex => $customerProgressDeclaredStep) {
            if ($customerProgressDeclaredStep->hasProgress) {
                $furthestStepIndexWithProgress = $stepIndex;
            }
        }
        $activeStepIndex = null;
        foreach ($customerProgressDeclaredSteps as $stepIndex => $customerProgressDeclaredStep) {
            if (!$customerProgressDeclaredStep->isDone && ($furthestStepIndexWithProgress === null || $stepIndex >= $furthestStepIndexWithProgress)) {
                $activeStepIndex = $stepIndex;
                break;
            }
        }
        if ($activeStepIndex === null) {
            // Every step from the furthest progress on is done — an earlier leftover step is the honest marker.
            foreach ($customerProgressDeclaredSteps as $stepIndex => $customerProgressDeclaredStep) {
                if (!$customerProgressDeclaredStep->isDone) {
                    $activeStepIndex = $stepIndex;
                    break;
                }
            }
        }

        foreach ($customerProgressDeclaredSteps as $stepIndex => $customerProgressDeclaredStep) {
            $customerProgressStep = new CustomerProgressStep();
            $customerProgressStep->key = $customerProgressDeclaredStep->key;
            $customerProgressStep->customerLabel = $customerProgressDeclaredStep->customerLabel;
            $isPassed = $customerProgressDeclaredStep->hasMembers && $activeStepIndex !== null && $stepIndex < $activeStepIndex;
            if ($customerProgressDeclaredStep->isDone || $isPassed) {
                $customerProgressStep->status = CustomerProgressStep::STATUS_DONE;
            } elseif ($stepIndex === $activeStepIndex) {
                $customerProgressStep->status = CustomerProgressStep::STATUS_ACTIVE;
            } else {
                $customerProgressStep->status = CustomerProgressStep::STATUS_UPCOMING;
            }
            $customerProgressSteps->add($customerProgressStep);
        }
        return $customerProgressSteps;
    }
}
