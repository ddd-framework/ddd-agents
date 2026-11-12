<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Per-conversation OVERRIDE of the compaction knobs — so the threshold, the model, and the shape can be
 * set on ONE conversation to TEST compaction without touching global config. Every field is nullable: null = inherit
 * the global default. Stored as a JSON column on {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$compactionSettings}
 * (the DDD ValueObject→JSON convention). Mirrors the existing per-conversation `aiModelNameOverride`.
 *
 * Threshold: a single ABSOLUTE token count, `triggerTokens` — the working-context size at which compaction fires. NO
 * percent / fraction-of-window knob exists anymore (context-rot is absolute, so the threshold is too). When unset, the
 * MODEL's `AIModel::$effectiveContextTokens` is the threshold ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::shouldConsiderCompaction()}).
 * The threshold drives the WHOLE tree shape — leaf-segment size, raw verbatim tail and summary budget are all derived
 * from it ({@see \DDD\Domain\AI\Entities\Conversations\Compaction\CompactionBudgets::fromMeasured()}).
 *
 * Consumption: `enabled` + `triggerTokens` are read by `shouldConsiderCompaction()` + `buildCompactionBudgets()`. The
 * message-count knobs (`keepRecentMessages`/`segmentMessages`/`tierFanIn`/`levelRetention`) are the DEGENERATE
 * FALLBACK only (when the token budget is too small to tile); `debugLeafSegmentTokensOverride` forces a deep tree for
 * testing. `keepRecentTurns`/`summaryMaxTokens`/`resummarizeFromSourceEvery`/`aiModelName` ride with the summarizer.
 */
class CompactionSettings extends ValueObject
{
    /** What a null {@see $keepRecentMessages} stands for (the degenerate fallback). */
    public const int DEFAULT_KEEP_RECENT_MESSAGES = 12;

    /** What a null {@see $segmentMessages} stands for (the degenerate fallback). */
    public const int DEFAULT_SEGMENT_MESSAGES = 20;

    /** What a null {@see $tierFanIn} stands for (the degenerate fallback). */
    public const int DEFAULT_TIER_FAN_IN = 4;

    /** What a null {@see $levelRetention} stands for (the degenerate fallback). */
    public const int DEFAULT_LEVEL_RETENTION = 2;

    /** @var bool|null Force compaction on/off for THIS conversation (null = on, the default). Lets a short test conversation be compacted (or a long one left raw) deliberately. */
    public ?bool $enabled = null;

    /** @var int|null The ABSOLUTE compaction threshold in tokens (override of the model's effectiveContextTokens). The ONLY threshold knob — set it low (e.g. 2000) to force compaction on a short test chat. Null = use the model's effectiveContextTokens. */
    public ?int $triggerTokens = null;

    /** @var int|null How many recent user turns to keep verbatim after the anchor (override of COMPACTION_KEEP_RECENT_TURNS). */
    public ?int $keepRecentTurns = null;

    /** @var int|null Token budget for the summary itself (override of COMPACTION_SUMMARY_MAX_TOKENS). */
    public ?int $summaryMaxTokens = null;

    /** @var int|null Re-summarize-from-source cadence (override of COMPACTION_RESUMMARIZE_FROM_SOURCE_EVERY) — set 1 to re-read source every time while testing drift. */
    public ?int $resummarizeFromSourceEvery = null;

    /** @var string|null The summarizer model for THIS conversation (override of the CHEAP-tier default) — to A/B compaction quality across models. References AIModel::$name. */
    public ?string $aiModelName = null;

    // --- message-count knobs — DEGENERATE FALLBACK only (used when the token budget is too small to tile) ---

    /** @var int|null How many of the most-recent MESSAGES to keep RAW (verbatim tail) — fallback path only. */
    public ?int $keepRecentMessages = null;

    /** @var int|null Messages per level-0 leaf segment (the leaf granularity) — fallback path only. */
    public ?int $segmentMessages = null;

    /** @var int|null Fan-in K: how many level-N nodes roll up into one level-(N+1) node — fallback path only. */
    public ?int $tierFanIn = null;

    /** @var int|null Per-level retention: how many most-recent exposed nodes stay FINE near the frontier before roll-up — fallback path only. */
    public ?int $levelRetention = null;

    /**
     * @var int|null Dedicated deep-tree DEBUG override of the token-budget `leafSegmentTokens` — set it LOW to force a
     * deep tree on a short test conversation WITHOUT lowering the threshold (the threshold can't go below the fixed
     * overhead). Decoupled from `triggerTokens` on purpose.
     */
    public ?int $debugLeafSegmentTokensOverride = null;

    /** Whether compaction is enabled for this conversation (default true when unset). */
    public function isEnabled(): bool
    {
        return $this->enabled ?? true;
    }
}
