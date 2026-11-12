<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The verdict a DONE CRITERION pronounces over one seeded task-plan leaf from the record
 * ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion::resolve()}; still the
 * purposes' own switches behind {@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::preResolveSeedTaskPlanItems()}):
 * the leaf is either already DONE (the data exists) or NOT_APPLICABLE (the space's kind rules it out) — with
 * the reason as its statusNote. A leaf the purpose cannot decide gets NO resolution (null) and stays open for
 * the conversation. Pure carrier — the walk ({@see TaskPlanItems::applyPreResolutions()}) applies it.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanDoneCriterionVerdict extends ValueObject
{
    /** @var string The resolved status — TaskPlanItem::STATUS_DONE or TaskPlanItem::STATUS_NOT_APPLICABLE. */
    public string $status = TaskPlanItem::STATUS_DONE;

    /** @var string The full statusNote to stamp on the leaf (composed by the purpose, e.g. "already satisfied: 38/5 keywords"). */
    public string $statusNote = '';

    /** Only for a BLOCKED pre-resolution: the block is the owner's own decision (a recorded DECLINE / DEFERRAL). */
    public bool $requiresUserAction = false;

    /**
     * Only for a DONE pre-resolution: the record overrides a PLATFORM park or a PLATFORM not_applicable of this leaf
     * (the platform parked the capture of a value, then the value arrived by another way; a leaf closed
     * "nothing named", then a value was saved). An owner's decline of an OFFER is never overridden by a plain
     * done; only a criterion that reads the fact the park or the n/a ruled on sets this.
     */
    public bool $overridesPlatformBlock = false;

    public static function done(string $statusNote): static
    {
        $taskPlanDoneCriterionVerdict = new static();
        $taskPlanDoneCriterionVerdict->status = TaskPlanItem::STATUS_DONE;
        $taskPlanDoneCriterionVerdict->statusNote = $statusNote;
        return $taskPlanDoneCriterionVerdict;
    }

    /** DONE from the very fact a platform park waited for — reopens and closes the parked leaf. */
    public static function doneOverridingPlatformBlock(string $statusNote): self
    {
        $taskPlanDoneCriterionVerdict = self::done($statusNote);
        $taskPlanDoneCriterionVerdict->overridesPlatformBlock = true;
        return $taskPlanDoneCriterionVerdict;
    }

    /** A leaf parked on the owner's RECORDED words (a DECLINE / DEFERRAL statement) — blocked, owner-caused. */
    public static function blockedByOwnerStatement(string $statusNote): static
    {
        $taskPlanDoneCriterionVerdict = new static();
        $taskPlanDoneCriterionVerdict->status = TaskPlanItem::STATUS_BLOCKED;
        $taskPlanDoneCriterionVerdict->statusNote = $statusNote;
        $taskPlanDoneCriterionVerdict->requiresUserAction = true;
        return $taskPlanDoneCriterionVerdict;
    }

    public static function notApplicable(string $statusNote): static
    {
        $taskPlanDoneCriterionVerdict = new static();
        $taskPlanDoneCriterionVerdict->status = TaskPlanItem::STATUS_NOT_APPLICABLE;
        $taskPlanDoneCriterionVerdict->statusNote = $statusNote;
        return $taskPlanDoneCriterionVerdict;
    }

    /** The marker status of a STILL OPEN verdict — never written onto a leaf. */
    public const string STATUS_STILL_OPEN = 'still_open';

    /**
     * The THIRD verdict: the record says the leaf must stay OPEN — evidence the platform owns is not yet on the
     * record, or is still being fetched — with the reason a model tick (done / not_applicable) is refused with.
     * The pre-resolution walk leaves the leaf untouched; the tick tool rejects the model's verdict naming it.
     */
    public static function stillOpen(string $reason): static
    {
        $taskPlanDoneCriterionVerdict = new static();
        $taskPlanDoneCriterionVerdict->status = self::STATUS_STILL_OPEN;
        $taskPlanDoneCriterionVerdict->statusNote = $reason;
        return $taskPlanDoneCriterionVerdict;
    }

    public function isStillOpen(): bool
    {
        return $this->status === self::STATUS_STILL_OPEN;
    }
}
