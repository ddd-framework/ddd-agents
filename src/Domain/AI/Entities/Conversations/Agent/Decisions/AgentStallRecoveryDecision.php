<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The STALL-RECOVERY decision at the SECOND stall of one owner turn: the model produced no usable
 * output twice in a row — which ONE move gets it unstuck? Asked once per owner turn, after the first stall got
 * today's informative nudge (GPT-5.x recovers on it); never on a fold turn (its nudge already says "answer from the
 * verdicts, call no tool"). The move comes from
 * {@see \DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentStallRecoveryDecision}; the consequence is the
 * loop's (a forced work-tool call, a capability-only nudge, an answer-now nudge, or today's ladder) and never adds a
 * model call beyond today's ladder: the decision-model-forced call REPLACES the ladder's last-resort re-drive of the turn.
 *
 * A stamp with {@see $decidedBy} = {@see DECIDED_BY_DECISION_MODEL} is APPLIED by the loop; every other value is a record only.
 *
 * @phpstan-consistent-constructor
 */
class AgentStallRecoveryDecision extends AgentDecision
{
    // --- the code's stall shapes ---
    public const string SHAPE_EMPTY = 'empty';
    public const string SHAPE_PROSE_AFTER_LOAD_SKILLS = 'proseAfterLoadSkills';
    public const string SHAPE_PRE_CALL_ANNOUNCE = 'preCallAnnounce';
    /** A prose answer without the block the turn's newest work result dictates; one nudge, never a decision or a forced call. */
    public const string SHAPE_DICTATED_BLOCK_MISSING = 'dictatedBlockMissing';
    /** A prose answer without the block of the turn's decided direct widget; one nudge, never a decision or a forced call. */
    public const string SHAPE_DIRECT_WIDGET_MISSING = 'directWidgetMissing';
    /** The answer's `ComposedView` reading dropped parts of the tree; the findings go back once, never a decision or a forced call. */
    public const string SHAPE_WIDGET_TREE_FINDINGS = 'widgetTreeFindings';
    /** A code fence or a LaTeX formula in the answer's prose on a kit surface; one nudge, never a decision or a forced call. */
    public const string SHAPE_CODE_OR_FORMULA_IN_PROSE = 'codeOrFormulaInProse';

    // --- the moves ---
    public const string MOVE_CALL_A_WORK_TOOL = 'call_a_work_tool';
    public const string MOVE_LOAD_REQUIRED_CAPABILITY = 'load_required_capability';
    public const string MOVE_END_THE_TURN_WITH_TEXT = 'end_the_turn_with_text';
    public const string MOVE_NONE = 'none';

    /**
     * The `decidedBy` of the decision record the FIRST stall of a run writes (no decision-model call; the record keeps
     * the response text the nudge discards — {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentStallRecoveryService::recordDiscardedTextOfFirstStall()}).
     */
    public const string RECORDED_BY_THE_FIRST_STALL = 'code:firstStall';

    /** @var string[] Every move, the order the question lists them in. */
    public const array MOVES = [self::MOVE_CALL_A_WORK_TOOL, self::MOVE_LOAD_REQUIRED_CAPABILITY, self::MOVE_END_THE_TURN_WITH_TEXT, self::MOVE_NONE];

    /**
     * The FORCED work-tool call needs a concentrated distribution: the pick's probability at or above this floor …
     * (a prior until the labelled stalls calibrate it: a `choice` confidence is distribution-derived
     * and the threshold belongs to the consequence — the two nudge moves change only a text, the forced call spends a
     * model call over the work tools).
     */
    public const float MINIMUM_FORCED_CALL_PROBABILITY = 0.8;

    /** … and this margin over the second candidate (a contested winner is not forced). */
    public const float MINIMUM_FORCED_CALL_MARGIN = 0.3;

    /** The legacy single floor, kept for the confidence-only path (no usable distribution). */
    public const float MINIMUM_MOVE_CONFIDENCE = 0.7;

    /** @var string|null SHAPE_* of the stall that was judged. */
    public ?string $stallShape = null;

    /** @var bool True when no skill was loaded when the stall happened. */
    public bool $noSkillLoaded = false;

    /** @var int The consecutive-stall ordinal the decision was made at (2 = the second stall). */
    public int $stallCount = 0;

    /** @var string[] The moves that were offered (an inapplicable move is removed before the call). */
    public array $offeredMoves = [];

    /** @var string|null The move the model chose (MOVE_*), null when the request never answered. */
    public ?string $move = null;

    /** @var float The chosen move's confidence. */
    public float $moveConfidence = 0.0;

    /** @var array<string, mixed> The raw reported distribution over the moves. */
    public array $probabilities = [];

    /** @var bool True when the loop ACTED on {@see $move} (mode `on`, a real move, the floor cleared). */
    public bool $applied = false;

    /** The decision of this stall before the model answered: what PHP knows, the moves it admits, decided now. */
    public static function build(?int $ownerMessageIndex, string $stallShape, bool $noSkillLoaded, int $stallCount, bool $hasEligibleWorkTools, bool $hasLoadableCapabilities): static
    {
        $decision = new static();
        $decision->ownerMessageIndex = $ownerMessageIndex;
        $decision->decidedAt = (new DateTime())->format('Y-m-d H:i:s');
        $decision->stallShape = $stallShape;
        $decision->noSkillLoaded = $noSkillLoaded;
        $decision->stallCount = $stallCount;
        $decision->offeredMoves = self::resolveOfferedMoves($hasEligibleWorkTools, $hasLoadableCapabilities);
        return $decision;
    }

    /**
     * The decision record of the FIRST stall of a run of stalls ({@see RECORDED_BY_THE_FIRST_STALL}, applied false, no
     * model call): the shape and the response text the nudge discards, raw (fenced blocks included) and as visible prose.
     */
    public static function buildFirstStallRecord(
        ?int $ownerMessageIndex,
        string $stallShape,
        string $discardedText,
        string $discardedVisibleProse,
        ?string $modelName
    ): AgentDecisionRecord {
        return AgentDecisionRecord::build(
            site: AgentDecisionModelSettings::SITE_STALL_RECOVERY,
            ownerMessageIndex: $ownerMessageIndex,
            decidedBy: self::RECORDED_BY_THE_FIRST_STALL,
            applied: false,
            elapsedMs: null,
            modelVersion: $modelName,
            failureReason: null,
            state: ['stallShape' => $stallShape, 'discardedText' => $discardedText, 'discardedVisibleProse' => $discardedVisibleProse],
            answers: null,
            outcome: ['stallShape' => $stallShape, 'stallCount' => 1, 'move' => 'nudge']
        );
    }

    /**
     * The resolved consequence for the decision record and the turn event.
     *
     * @return array<string, mixed>
     */
    public function buildOutcome(string $siteMode, ?string $moveToApply): array
    {
        return [
            'applied' => $this->applied,
            'mode' => $siteMode,
            'stallShape' => $this->stallShape,
            'noSkillLoaded' => $this->noSkillLoaded,
            'stallCount' => $this->stallCount,
            'offeredMoves' => $this->offeredMoves,
            'move' => $this->move,
            'moveConfidence' => $this->moveConfidence,
            'moveToApply' => $moveToApply,
            'probabilities' => $this->probabilities,
            'minimumForcedCallProbability' => self::MINIMUM_FORCED_CALL_PROBABILITY,
            'minimumForcedCallMargin' => self::MINIMUM_FORCED_CALL_MARGIN,
        ];
    }

    public function isApplied(): bool
    {
        return $this->decidedBy === self::DECIDED_BY_DECISION_MODEL;
    }

    /**
     * The move the loop acts on, pure, with a rule PER CONSEQUENCE:
     * - `end_the_turn_with_text` / `load_required_capability` change only the nudge text → applied when the move is
     *   the UNIQUE top choice of the validated distribution (`none` included), no confidence floor;
     * - `call_a_work_tool` spends a forced model call → applied only when its probability is at or above
     *   {@see MINIMUM_FORCED_CALL_PROBABILITY} AND its margin over the second candidate at or above
     *   {@see MINIMUM_FORCED_CALL_MARGIN};
     * - `none`, a tie, a move that was not offered, or no usable distribution (then the legacy confidence floor
     *   decides) → null = today's ladder step.
     *
     * @param string[] $offeredMoves
     * @param array<string, mixed> $probabilities The raw reported distribution over the moves.
     */
    public static function resolveMoveToApply(?string $move, float $moveConfidence, array $offeredMoves, array $probabilities = []): ?string
    {
        if ($move === null || $move === self::MOVE_NONE || !in_array($move, $offeredMoves, true)) {
            return null;
        }
        $validated = AgentDecisionAnswerProbabilities::fromReported($probabilities, $offeredMoves);
        if ($validated === null) {
            return $moveConfidence >= self::MINIMUM_MOVE_CONFIDENCE ? $move : null;
        }
        if (!$validated->isUniqueTop($move)) {
            return null; // not the unique top choice (a tie, or the model's pick disagrees with its own distribution)
        }
        if ($move === self::MOVE_CALL_A_WORK_TOOL) {
            return $validated->getTopProbability() >= self::MINIMUM_FORCED_CALL_PROBABILITY && $validated->getMarginOverSecond() >= self::MINIMUM_FORCED_CALL_MARGIN
                ? $move
                : null;
        }
        return $move;
    }

    /**
     * The moves that APPLY to this stall, pure: a work-tool call needs eligible work tools; a
     * capability load needs something loadable; the text move always applies; `none` always applies.
     *
     * @return string[]
     */
    public static function resolveOfferedMoves(bool $hasEligibleWorkTools, bool $hasLoadableCapabilities): array
    {
        $moves = [];
        if ($hasEligibleWorkTools) {
            $moves[] = self::MOVE_CALL_A_WORK_TOOL;
        }
        if ($hasLoadableCapabilities) {
            $moves[] = self::MOVE_LOAD_REQUIRED_CAPABILITY;
        }
        $moves[] = self::MOVE_END_THE_TURN_WITH_TEXT;
        $moves[] = self::MOVE_NONE;
        return $moves;
    }

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return [
            'ownerMessageIndex' => $this->ownerMessageIndex,
            'decidedAt' => $this->decidedAt,
            'decidedBy' => $this->decidedBy,
            'stallShape' => $this->stallShape,
            'noSkillLoaded' => $this->noSkillLoaded,
            'stallCount' => $this->stallCount,
            'offeredMoves' => $this->offeredMoves,
            'move' => $this->move,
            'moveConfidence' => $this->moveConfidence,
            'probabilities' => $this->probabilities,
            'applied' => $this->applied,
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
        $this->ownerMessageIndex = isset($repoArray['ownerMessageIndex']) && is_numeric($repoArray['ownerMessageIndex']) ? (int)$repoArray['ownerMessageIndex'] : null;
        $this->decidedAt = $readString('decidedAt');
        $this->decidedBy = $readString('decidedBy');
        $this->stallShape = $readString('stallShape');
        $this->noSkillLoaded = (bool)($repoArray['noSkillLoaded'] ?? false);
        $this->stallCount = (int)($repoArray['stallCount'] ?? 0);
        $this->offeredMoves = array_values(array_map('strval', is_array($repoArray['offeredMoves'] ?? null) ? $repoArray['offeredMoves'] : []));
        $this->move = $readString('move');
        $this->moveConfidence = isset($repoArray['moveConfidence']) && is_numeric($repoArray['moveConfidence']) ? (float)$repoArray['moveConfidence'] : 0.0;
        $this->probabilities = is_array($repoArray['probabilities'] ?? null) ? $repoArray['probabilities'] : [];
        $this->applied = (bool)($repoArray['applied'] ?? false);
        $this->modelVersion = $readString('modelVersion');
    }
}
