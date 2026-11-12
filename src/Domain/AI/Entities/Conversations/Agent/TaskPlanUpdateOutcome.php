<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What ONE keyed `update_task_plan` call did: rejected (the violations — nothing changed), unchanged
 * (the plan is already in exactly that state), or applied — the resulting plan with the items added (their keys and
 * places), the items dropped, and the feedback of the statuses the call sent.
 * The result texts are composed here; the caller adds the run-level sentences.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanUpdateOutcome extends ValueObject
{
    /** The sentence an applied change adds when an added key stands out of numeric order. */
    public const string KEYS_NEVER_CHANGE_SENTENCE = 'Keys never change — an added item keeps its number wherever it stands.';

    /** The sentence a created plan adds. */
    public const string CREATION_KEY_SENTENCE = 'Each item\'s number is its key: in a later update_task_plan send an existing item as {"key": "2"} and a new item as {"text": "…"}.';

    /** The sentence an applied call adds after its count when a gate refused a status it sent. */
    public const string STATUS_NOT_APPLIED_SENTENCE = '(A status you sent was NOT applied — see the lines below.)';

    /** The violations of a rejected call; null when the call was accepted. */
    public ?TaskPlanUpdateRejections $rejections = null;

    /** The plan the call produced; null when it was rejected or changed nothing. */
    public ?TaskPlan $taskPlan = null;

    /** The call changed nothing: the plan is already in exactly that state. */
    public bool $isUnchanged = false;

    /** No plan stood: the call created one. */
    public bool $isCreation = false;

    /** @var string[] One entry per added item in plan order: `<key> '<text>' (after 2.1 / first under 2 / at the end)`. */
    public array $addedItemDescriptions = [];

    /** @var string[] One entry per dropped item: `<key> '<text>'[ with its subtasks 3.1, 3.2][ — its state leaves with it]`. */
    public array $droppedItemDescriptions = [];

    /** An added key stands before a sibling with a lower number. */
    public bool $addedKeyStandsOutOfOrder = false;

    /** @var string[] One line per status normalization, refused status or ignored field. */
    public array $feedback = [];

    /** A gate (or the cascade cap) refused a status the call sent — the feedback names why; an unchanged call then reads as refused, not as a resend. */
    public bool $hasGateRefusedStatus = false;

    public static function rejected(TaskPlanUpdateRejections $taskPlanUpdateRejections): static
    {
        $taskPlanUpdateOutcome = new static();
        $taskPlanUpdateOutcome->rejections = $taskPlanUpdateRejections;
        return $taskPlanUpdateOutcome;
    }

    /** @param string[] $feedback */
    public static function unchanged(array $feedback, bool $hasGateRefusedStatus = false): static
    {
        $taskPlanUpdateOutcome = new static();
        $taskPlanUpdateOutcome->isUnchanged = true;
        $taskPlanUpdateOutcome->feedback = $feedback;
        $taskPlanUpdateOutcome->hasGateRefusedStatus = $hasGateRefusedStatus;
        return $taskPlanUpdateOutcome;
    }

    public function isRejected(): bool
    {
        return $this->rejections !== null && $this->rejections->hasRejections();
    }

    /** The plan was changed or created. */
    public function isApplied(): bool
    {
        return $this->taskPlan !== null;
    }

    /**
     * The applied call's result before the run-level sentences: `Task plan updated: 7 items (2 done, 5 open, 0 blocked).`,
     * the warning that a sent status was not applied when a gate refused one ({@see STATUS_NOT_APPLIED_SENTENCE}), then
     * (a change only) `Added: …`, `Dropped: …` and the keys-never-change sentence, then the feedback.
     */
    public function renderAppliedSummary(): string
    {
        $statusCounts = $this->taskPlan?->items?->countItemsByStatus() ?? new TaskPlanItemStatusCounts();
        $sentences = [sprintf(
            'Task plan updated: %d items (%d done, %d open, %d blocked).',
            $this->taskPlan?->items?->countAllItems() ?? 0,
            $statusCounts->done,
            $statusCounts->open,
            $statusCounts->blocked
        )];
        if ($this->hasGateRefusedStatus) {
            $sentences[] = self::STATUS_NOT_APPLIED_SENTENCE;
        }
        if (!$this->isCreation) {
            if ($this->addedItemDescriptions !== []) {
                $sentences[] = 'Added: ' . implode(', ', $this->addedItemDescriptions) . '.';
            }
            if ($this->droppedItemDescriptions !== []) {
                $sentences[] = 'Dropped: ' . implode(', ', $this->droppedItemDescriptions) . '.';
            }
            if ($this->addedKeyStandsOutOfOrder) {
                $sentences[] = self::KEYS_NEVER_CHANGE_SENTENCE;
            }
        }
        return implode(' ', array_merge($sentences, $this->feedback));
    }
}
