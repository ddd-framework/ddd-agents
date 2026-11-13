<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The PLAN-DYNAMICS decision of ONE owner turn: does this request need a
 * working plan? Asked of the decision model at the turn start when no plan with open items is active (folded into
 * the turn-start request; the former mid-turn re-ask was removed — it never led to a plan). The
 * consequence is resolved in PHP ({@see resolveBand()}):
 *
 * - {@see BAND_REQUIRED} (P ≥ {@see REQUIRED_THRESHOLD}): the directive tells the chat model to create the plan
 *   as its first call;
 * - {@see BAND_DIRECT} (P ≤ {@see DIRECT_THRESHOLD}): answer directly, no plan;
 * - {@see BAND_MIDDLE}: start without a plan, adopt one when the results show several separate work items.
 *
 * The verdict reaches the chat model ONLY when the site is `on` ("the model receives the verdict");
 * under `shadow` it is recorded on this slice, the decision log and the turn-events table. Never on a purposed
 * run that seeds its plan (an intake, a nightly digest) or a scheduled firing (the plan is mandatory there).
 *
 * A turn whose owner message follows right after the turn that finished a plan REOPENS that plan instead of asking
 * (only in that case): the stamp is the platform's
 * ({@see DECIDED_BY_PLATFORM}, {@see buildForReopenedPlan()}) and its directive tells the model to add the owner's new
 * parts as new items of that plan ({@see buildReopenedPlanDirective()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentPlanDynamicsDecision extends AgentDecision
{
    public const string BAND_REQUIRED = 'required';
    public const string BAND_DIRECT = 'direct';
    public const string BAND_MIDDLE = 'middle';

    /** The stamp of a turn that reopened the plan the previous owner turn finished — structural, no model asked. */
    public const string DECIDED_BY_PLATFORM = 'platform';

    /** P(needs a working plan) at or above which the plan is required (a prior; shadow-mode measurements refit it). */
    public const float REQUIRED_THRESHOLD = 0.80;

    /** P(needs a working plan) at or below which the turn answers directly. */
    public const float DIRECT_THRESHOLD = 0.10;

    /** @var float|null P(the request needs a working plan), the turn-start answer. */
    public ?float $needsWorkingPlanProbability = null;

    /** @var string|null The band PHP resolved from the probability (null on a fallback). */
    public ?string $band = null;

    /** @var bool True when a FINISHED plan was retired (moved to the finished set) in this turn. */
    public bool $retiredFinishedPlan = false;

    /** @var bool True when the retirement would have happened under `on` (recorded under `shadow`). */
    public bool $wouldRetireFinishedPlan = false;

    /**
     * @var bool True when a plan with items stood (not retired) at the moment the turn-start question was decided
     *      ({@see recordPlanStandingAtDecision()}) — the plan-creating bands then name the drop of its items until an
     *      `update_task_plan` call of this turn applied ({@see resolveEarlierPlanItemKeyToDrop()}). A plan the model
     *      creates later in the same turn never sets it.
     */
    public bool $earlierPlanStoodAtDecision = false;

    /** @var bool True when this turn REOPENED the plan the previous owner turn finished ({@see buildForReopenedPlan()}). */
    public bool $reopenedFinishedPlan = false;

    /** @var string[] The top-level keys of the reopened plan, in plan order — the items the directive tells the model to keep. */
    public array $reopenedPlanItemKeys = [];

    /** The decision of an owner turn's start before the model answered, decided now. */
    public static function build(int $ownerMessageIndex): static
    {
        $decision = new static();
        $decision->ownerMessageIndex = $ownerMessageIndex;
        $decision->decidedAt = (new DateTime())->format('Y-m-d H:i:s');
        return $decision;
    }

    /** The platform's stamp of an owner turn that reopened `$reopenedTaskPlan` (the plan the previous owner turn finished). */
    public static function buildForReopenedPlan(int $ownerMessageIndex, TaskPlan $reopenedTaskPlan): self
    {
        $decision = self::build($ownerMessageIndex);
        $decision->decidedBy = self::DECIDED_BY_PLATFORM;
        $decision->reopenedFinishedPlan = true;
        $decision->reopenedPlanItemKeys = $reopenedTaskPlan->getTopLevelItemKeys();
        return $decision;
    }

    /**
     * The resolved consequence of the turn-start question, for the decision record and the turn event.
     *
     * @return array<string, mixed>
     */
    public function buildStartOutcome(string $siteMode, int $finishedPlanCount, ?TaskPlan $standingTaskPlan = null): array
    {
        return [
            'applied' => $this->isApplied(),
            'mode' => $siteMode,
            'needsWorkingPlanProbability' => $this->needsWorkingPlanProbability,
            'band' => $this->band,
            'directive' => $this->isApplied() ? $this->toDirectiveText($finishedPlanCount, $this->resolveEarlierPlanItemKeyToDrop($standingTaskPlan, false)) : null,
            'requiredThreshold' => self::REQUIRED_THRESHOLD,
            'directThreshold' => self::DIRECT_THRESHOLD,
        ];
    }

    /** True when the loop must act on the stamp: the band (site `on` and a usable answer) or the platform's reopening. */
    public function isApplied(): bool
    {
        return $this->decidedBy === self::DECIDED_BY_DECISION_MODEL || $this->decidedBy === self::DECIDED_BY_PLATFORM;
    }

    /** The band, pure: required at/above the upper threshold, direct at/below the lower one, middle between; null without an answer. */
    public static function resolveBand(?float $needsWorkingPlanProbability, float $requiredThreshold = self::REQUIRED_THRESHOLD, float $directThreshold = self::DIRECT_THRESHOLD): ?string
    {
        if ($needsWorkingPlanProbability === null) {
            return null;
        }
        if ($needsWorkingPlanProbability >= $requiredThreshold) {
            return self::BAND_REQUIRED;
        }
        if ($needsWorkingPlanProbability <= $directThreshold) {
            return self::BAND_DIRECT;
        }
        return self::BAND_MIDDLE;
    }

    /** Stamp whether a plan with items stands at the moment of the turn-start decision ({@see $earlierPlanStoodAtDecision}). */
    public function recordPlanStandingAtDecision(?TaskPlan $standingTaskPlan): void
    {
        $this->earlierPlanStoodAtDecision = $standingTaskPlan?->hasItems() ?? false;
    }

    /**
     * The key the directive names for the drop of an earlier task's items: the standing plan's first droppable top-level
     * key ({@see TaskPlan::findFirstDroppableTopLevelItemKey()}) while a plan stood at the decision and no
     * `update_task_plan` call of this turn applied yet; null otherwise (the directive then names no drop).
     */
    public function resolveEarlierPlanItemKeyToDrop(?TaskPlan $standingTaskPlan, bool $aPlanUpdateOfThisTurnApplied): ?string
    {
        if (!$this->earlierPlanStoodAtDecision || $aPlanUpdateOfThisTurnApplied) {
            return null;
        }
        return $standingTaskPlan?->findFirstDroppableTopLevelItemKey();
    }

    /**
     * The sentence a plan-creating directive adds while a plan of an earlier task stands: a call that leaves its items out
     * is rejected. The example names a real key of the standing plan, so the model never sends an
     * unknown one.
     */
    public static function buildEarlierPlanStandsSentence(string $firstItemKeyToDrop): string
    {
        return ' A plan from an earlier task still stands: in that same update_task_plan call drop each of its items by its key'
            . ' (for item ' . $firstItemKeyToDrop . ': {"key": "' . $firstItemKeyToDrop . '", "remove": true}) and add the new items.';
    }

    /**
     * The directive of a turn that reopened the plan the previous owner turn finished: the plan is open again; an owner
     * message that adds work puts each new part into it as a new item with the first call, keeping the finished items by
     * their keys (`$reopenedPlanItemKeys`, the example of the keyed contract); a message that adds none is just answered,
     * and the plan closes again with that answer (its retirement at the accepted final answer).
     *
     * @param string[] $reopenedPlanItemKeys
     */
    public static function buildReopenedPlanDirective(array $reopenedPlanItemKeys): string
    {
        $keptItems = [];
        foreach ($reopenedPlanItemKeys as $reopenedPlanItemKey) {
            $keptItems[] = '{"key": "' . $reopenedPlanItemKey . '"}';
        }
        $keepClause = $keptItems !== [] ? 'keep the finished items by their keys (' . implode(', ', $keptItems) . ')' : 'keep every finished item by its key';
        return '[SIGNAL:plan] The task plan you finished in your last turn is open again for this message. If the message adds'
            . ' work, put each new part into that plan as a NEW item with update_task_plan as your FIRST call — ' . $keepClause
            . ' and send each new part as {"text": "…"} — then work the new items; tick each once its result is in your context,'
            . ' with the call for the next item or with your answer. If the message adds no work, just answer: the plan closes'
            . ' again with your answer.';
    }

    /**
     * The directive line the chat model receives at the wire tail (only under `on`) — one sentence per band, in the
     * language of the base prompt (English by construction); a turn that reopened a finished plan gets the reopening
     * directive instead ({@see buildReopenedPlanDirective()}). Null when nothing applies (a fallback without a band).
     *
     * @param string|null $earlierPlanItemKeyToDrop the standing earlier plan's first droppable key
     *        ({@see resolveEarlierPlanItemKeyToDrop()}) — the plan-creating bands then name the drop of its items
     *        ({@see buildEarlierPlanStandsSentence()}); null = no earlier plan to drop
     * @param bool $planUpdateAppliedThisTurn an `update_task_plan` of the current turn changed the plan — the reopening
     *        directive and the directives that ask for a plan (the required and the middle band) have done their job
     *        then and are no longer sent: the context is rebuilt on every call of the turn, a standing "create it as
     *        your FIRST call" made the model resend the plan, and "then work the items" spoke against an owner who
     *        had asked to be asked before the next item. From there the plan's own tail entry is the model's view.
     */
    public function toDirectiveText(int $finishedPlanCount = 0, ?string $earlierPlanItemKeyToDrop = null, bool $planUpdateAppliedThisTurn = false): ?string
    {
        $finishedNote = $finishedPlanCount > 0
            ? sprintf(' %d earlier plan%s of this conversation %s finished — `get_task_plans` shows them when you need to report on them.', $finishedPlanCount, $finishedPlanCount === 1 ? '' : 's', $finishedPlanCount === 1 ? 'is' : 'are')
            : '';
        if ($this->reopenedFinishedPlan) {
            return $planUpdateAppliedThisTurn ? null : self::buildReopenedPlanDirective($this->reopenedPlanItemKeys) . $finishedNote;
        }
        if ($planUpdateAppliedThisTurn && in_array($this->band, [self::BAND_REQUIRED, self::BAND_MIDDLE], true)) {
            return null;
        }
        $earlierPlanNote = $earlierPlanItemKeyToDrop !== null ? self::buildEarlierPlanStandsSentence($earlierPlanItemKeyToDrop) : '';
        return match ($this->band) {
            self::BAND_REQUIRED => '[SIGNAL:plan] This request needs a working plan: several separately accountable changes or '
                . 'deliverables, or work that must survive a pause. Create it with update_task_plan as your FIRST call (one '
                . 'item per part), then work the items; tick each once its result is in your context, with the call for the next item or with your answer.' . $earlierPlanNote . $finishedNote,
            self::BAND_DIRECT => '[SIGNAL:plan] Answer this directly — no task plan: it is a question, one action with its '
                . 'check, a preference, a follow-up or one deliverable. Read what you need, do what it asks — every call '
                . 'it needs —, then answer.' . $finishedNote,
            self::BAND_MIDDLE => '[SIGNAL:plan] Start WITHOUT a task plan. If the data you read shows several separate things to '
                . 'change or produce, create the plan then (update_task_plan) and work it; otherwise just answer.' . $earlierPlanNote . $finishedNote,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return [
            'ownerMessageIndex' => $this->ownerMessageIndex,
            'decidedAt' => $this->decidedAt,
            'decidedBy' => $this->decidedBy,
            'needsWorkingPlanProbability' => $this->needsWorkingPlanProbability,
            'band' => $this->band,
            'retiredFinishedPlan' => $this->retiredFinishedPlan,
            'wouldRetireFinishedPlan' => $this->wouldRetireFinishedPlan,
            'earlierPlanStoodAtDecision' => $this->earlierPlanStoodAtDecision,
            'reopenedFinishedPlan' => $this->reopenedFinishedPlan,
            'reopenedPlanItemKeys' => $this->reopenedPlanItemKeys,
            'modelVersion' => $this->modelVersion,
        ];
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $repoArray = is_array($repoObject) ? $repoObject : [];
        $readString = static function (string $key) use ($repoArray): ?string {
            $value = trim((string)($repoArray[$key] ?? ''));
            return $value !== '' ? $value : null;
        };
        $readFloat = static fn (string $key): ?float => isset($repoArray[$key]) && is_numeric($repoArray[$key]) ? (float)$repoArray[$key] : null;
        $this->ownerMessageIndex = isset($repoArray['ownerMessageIndex']) && is_numeric($repoArray['ownerMessageIndex']) ? (int)$repoArray['ownerMessageIndex'] : null;
        $this->decidedAt = $readString('decidedAt');
        $this->decidedBy = $readString('decidedBy');
        $this->needsWorkingPlanProbability = $readFloat('needsWorkingPlanProbability');
        $this->band = $readString('band');
        $this->retiredFinishedPlan = (bool)($repoArray['retiredFinishedPlan'] ?? false);
        $this->wouldRetireFinishedPlan = (bool)($repoArray['wouldRetireFinishedPlan'] ?? false);
        $this->earlierPlanStoodAtDecision = (bool)($repoArray['earlierPlanStoodAtDecision'] ?? false);
        $this->reopenedFinishedPlan = (bool)($repoArray['reopenedFinishedPlan'] ?? false);
        $this->reopenedPlanItemKeys = array_values(array_map('strval', array_filter((array)($repoArray['reopenedPlanItemKeys'] ?? []), 'is_scalar')));
        $this->modelVersion = $readString('modelVersion');
    }
}
