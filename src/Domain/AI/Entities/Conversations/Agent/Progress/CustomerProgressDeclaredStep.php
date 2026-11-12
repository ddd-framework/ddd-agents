<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Progress;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One step as the purpose declares it (key and customer label), with what the task plan says about it: whether plan
 * items belong to it, whether it is done, and whether the dialog resolved one of its items. The intermediate form
 * {@see CustomerProgressSteps::fromPurposeDeclarationAndPlanItems()} derives the step statuses from.
 *
 * @phpstan-consistent-constructor
 */
class CustomerProgressDeclaredStep extends ValueObject
{
    /** @var string The step key of the purpose declaration */
    public string $key = '';

    /** @var string The label the customer sees */
    public string $customerLabel = '';

    /** @var bool Whether any plan item carries the step's key */
    public bool $hasMembers = false;

    /** @var bool Whether the step is done: the plan has no open item, or the step has items and none of them is open */
    public bool $isDone = false;

    /** @var bool Whether the dialog resolved one of the step's items */
    public bool $hasProgress = false;

    /**
     * @param array $declaredStep One entry of the purpose's step list: a map with `key` and `customerLabel`
     * @return static|null Null for an entry without a key or without a customer label
     */
    public static function fromPurposeDeclaration(array $declaredStep): ?static
    {
        $stepKey = trim((string)($declaredStep['key'] ?? ''));
        $stepCustomerLabel = trim((string)($declaredStep['customerLabel'] ?? ''));
        if ($stepKey === '' || $stepCustomerLabel === '') {
            return null;
        }
        $customerProgressDeclaredStep = new static();
        $customerProgressDeclaredStep->key = $stepKey;
        $customerProgressDeclaredStep->customerLabel = $stepCustomerLabel;
        return $customerProgressDeclaredStep;
    }

    /**
     * Take over what the plan says about the step.
     *
     * @param CustomerProgressStepMemberState $stepMemberState The plan items of the step, counted
     * @param bool $planHasOpenItems Whether the plan has any open item
     * @return void
     */
    public function applyMemberState(CustomerProgressStepMemberState $stepMemberState, bool $planHasOpenItems): void
    {
        $this->hasMembers = $stepMemberState->hasMembers();
        $this->isDone = !$planHasOpenItems || $stepMemberState->hasOnlyClosedMembers();
        $this->hasProgress = $stepMemberState->hasProgress();
    }
}
