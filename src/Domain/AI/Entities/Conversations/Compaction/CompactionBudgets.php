<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The token-budget compaction shape — ONE absolute threshold (`workingContextLimit` = the per-conversation
 * `triggerTokens` override on {@see \DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings}, else the model's
 * `effectiveContextTokens` capped at 80% of its window), EVERYTHING else derived here by formula or measured at runtime.
 * There is no fraction-of-window term — the threshold is absolute and model-dependent. Replaces the four message-count
 * knobs (`keepRecentMessages`, `segmentMessages`, `tierFanIn`, `levelRetention`) with a COMPOSITION that fits the
 * budget: the cover + raw tail must fit `compactedTargetTokens`.
 *
 * Built by {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildCompactionBudgets()} from the resolved
 * `workingContextLimit` + the MEASURED `fixedOverheadTokens` (overhead is NOT frozen — load_skills injects tool-schema
 * tokens mid-conversation, so it is re-measured each round). Pure VO; never persisted.
 *
 * `isDegenerate` flags a budget too small to tile (history below `FINE_NODES_PER_LEVEL · MIN_LEAF_SEGMENT_TOKENS`, or a
 * non-positive history after overhead+reserve) — the caller then SKIPS the token path and keeps the message-count
 * behaviour rather than dividing nonsense (the review's mandatory guard).
 *
 * @phpstan-consistent-constructor
 */
class CompactionBudgets extends ValueObject
{
    /** The cascade depth cap of the compaction tree: no node exists above it (the round's roll-up loop and the exposed-node walk). */
    public const int MAX_TREE_LEVELS = 16;

    /** Hysteresis: after the trigger fires, compact DOWN to this fraction of the working limit so it does not thrash. */
    public const float COMPACTION_TARGET_RATIO = 0.60;
    /** Room reserved for the next user turn + its reply, as a fraction of the working limit. */
    public const float NEXT_TURN_RESERVE_RATIO = 0.10;
    /** Share of the history budget kept newest-VERBATIM (the rest is the recency-graded summary cover). */
    public const float RAW_RECENT_SHARE = 0.40;
    /** Industry-consensus summary compression (a summary is ~1/5 of its source); drives the self-similar fan-in. */
    public const float SUMMARY_COMPRESSION_RATIO = 0.20;
    /** Self-similar roll-up fan-in K = 1 / SUMMARY_COMPRESSION_RATIO — one parent ≈ same token size as one child. */
    public const int ROLLUP_FAN_IN = 5;
    /** Nodes kept FINE per level near the frontier before roll-up (retention). */
    public const int FINE_NODES_PER_LEVEL = 4;
    /** Hard floor: a level-0 leaf never spans fewer raw tokens than this. */
    public const int MIN_LEAF_SEGMENT_TOKENS = 256;

    public int $workingContextLimit = 0;
    public int $compactedTargetTokens = 0;
    public int $fixedOverheadTokens = 0;
    public int $nextTurnReserveTokens = 0;
    public int $historyBudgetTokens = 0;
    public int $rawRecentTokens = 0;
    public int $summaryBudgetTokens = 0;
    public int $rollupFanIn = self::ROLLUP_FAN_IN;
    public int $fineNodesPerLevel = self::FINE_NODES_PER_LEVEL;
    public int $leafSegmentTokens = self::MIN_LEAF_SEGMENT_TOKENS;
    /** True when the budget is too small to tile by formula → the caller keeps the message-count behaviour instead. */
    public bool $isDegenerate = false;

    /**
     * Derive the full budget from the resolved working limit + the measured fixed overhead.
     * `$debugLeafSegmentOverride` (the dedicated deep-tree debug knob — NOT the trigger) forces the leaf span when set.
     */
    public static function fromMeasured(int $workingContextLimit, int $fixedOverheadTokens, ?int $debugLeafSegmentOverride = null): static
    {
        $budgets = new static();
        $budgets->workingContextLimit = max(0, $workingContextLimit);
        $budgets->fixedOverheadTokens = max(0, $fixedOverheadTokens);
        $budgets->compactedTargetTokens = (int)(self::COMPACTION_TARGET_RATIO * $budgets->workingContextLimit);
        $budgets->nextTurnReserveTokens = (int)(self::NEXT_TURN_RESERVE_RATIO * $budgets->workingContextLimit);
        $budgets->historyBudgetTokens = max(
            0,
            $budgets->compactedTargetTokens - $budgets->fixedOverheadTokens - $budgets->nextTurnReserveTokens
        );
        $budgets->rawRecentTokens = (int)(self::RAW_RECENT_SHARE * $budgets->historyBudgetTokens);
        $budgets->summaryBudgetTokens = $budgets->historyBudgetTokens - $budgets->rawRecentTokens;

        if ($debugLeafSegmentOverride !== null && $debugLeafSegmentOverride > 0) {
            $budgets->leafSegmentTokens = $debugLeafSegmentOverride;
            // The override forces a DEEP tree (small leaves), it must NOT collapse the verbatim tail to zero on a tiny
            // budget — keep at least one leaf-worth raw so "keep recent verbatim" still holds.
            $budgets->rawRecentTokens = max($budgets->rawRecentTokens, self::MIN_LEAF_SEGMENT_TOKENS);
            return $budgets;
        }
        // Guard: a history budget too small to tile (overhead+reserve ate it, or below the fine-node floor) → degenerate.
        // The caller keeps today's message-count behaviour rather than dividing nonsense (review's mandatory guard).
        if ($budgets->historyBudgetTokens < self::FINE_NODES_PER_LEVEL * self::MIN_LEAF_SEGMENT_TOKENS) {
            $budgets->isDegenerate = true;
            $budgets->leafSegmentTokens = self::MIN_LEAF_SEGMENT_TOKENS;
            return $budgets;
        }
        $budgets->leafSegmentTokens = max(
            self::MIN_LEAF_SEGMENT_TOKENS,
            (int)($budgets->rawRecentTokens / self::FINE_NODES_PER_LEVEL)
        );
        return $budgets;
    }
}
