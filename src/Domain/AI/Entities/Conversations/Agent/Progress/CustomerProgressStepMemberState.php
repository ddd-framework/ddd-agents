<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Progress;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The task plan items that belong to ONE customer progress step, counted: how many there are, how many are still open,
 * and how many the dialog resolved. Built per step key by
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems::collectCustomerProgressStepMemberStates()} and read
 * by {@see CustomerProgressSteps::fromPurposeDeclarationAndPlanItems()}.
 *
 * @phpstan-consistent-constructor
 */
class CustomerProgressStepMemberState extends ValueObject
{
    /** @var int How many plan items carry the step's key */
    public int $memberCount = 0;

    /** @var int How many of them are still open */
    public int $openMemberCount = 0;

    /** @var int How many of them are terminal by the dialog's doing (not resolved by the platform before the first turn) */
    public int $progressMemberCount = 0;

    /**
     * @return static A state without members
     */
    public static function forNoMembers(): static
    {
        return new static();
    }

    /**
     * Count one member item of the step.
     *
     * @param bool $isOpen Whether the item is still open
     * @param bool $isPreResolvedAtSeed Whether the platform resolved the item from live state before the first turn
     * @return void
     */
    public function addMember(bool $isOpen, bool $isPreResolvedAtSeed): void
    {
        $this->memberCount++;
        if ($isOpen) {
            $this->openMemberCount++;
        } elseif (!$isPreResolvedAtSeed) {
            $this->progressMemberCount++;
        }
    }

    /** @return bool Whether the step has any member item */
    public function hasMembers(): bool
    {
        return $this->memberCount > 0;
    }

    /** @return bool Whether the step has member items and none of them is open */
    public function hasOnlyClosedMembers(): bool
    {
        return $this->memberCount > 0 && $this->openMemberCount === 0;
    }

    /** @return bool Whether the dialog resolved at least one member item */
    public function hasProgress(): bool
    {
        return $this->progressMemberCount > 0;
    }
}
