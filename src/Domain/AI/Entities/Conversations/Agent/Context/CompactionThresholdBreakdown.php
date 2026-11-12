<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Context;

use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionModelLimits;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The CONVERSATION-LEVEL compaction-threshold breakdown for admin observability — answers "which compaction threshold
 * is in effect for THIS conversation, where does it come from, and are we over it yet?" in ONE value object. Built on
 * demand by {@see self::buildFor()} from what
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildCompactionThresholdBreakdown()} gathers (the
 * model catalog and the purpose registry are read by the conversation, never by this object);
 * never persisted. Shown at the TOP of the admin conversation inspector alongside the context composition so an operator
 * sees at a glance why a long conversation has (or has not) compacted.
 *
 * The three SOURCES mirror the resolution order in `shouldConsiderCompaction` →
 * `getEffectiveCompactionThreshold(findCompactionModelLimits()->effectiveContextTokens)`:
 *   - CONVERSATION_OVERRIDE — a per-conversation `CompactionSettings::$triggerTokens` is set (>0); it wins over the model.
 *   - MODEL_EFFECTIVE — no per-conversation override, but the conversation's `aiModelNameOverride` resolves to a model
 *     with a window-derived `effectiveContextTokens` (e.g. GLM_5_2's 1M window → 128k).
 *   - DEFAULT_FALLBACK — no override and no resolvable model effective → the global 100k default
 *     ({@see CompactionModelLimits::DEFAULT_EFFECTIVE_CONTEXT_TOKENS}).
 *
 * @phpstan-consistent-constructor
 */
class CompactionThresholdBreakdown extends ValueObject
{
    public const string SOURCE_CONVERSATION_OVERRIDE = 'conversationOverride';
    /** The override was stamped by the conversation's PURPOSE (`contextBudgetTokens`) — not by an admin. */
    public const string SOURCE_PURPOSE_CONTEXT_BUDGET = 'purposeContextBudget';
    public const string SOURCE_MODEL_EFFECTIVE = 'modelEffective';
    public const string SOURCE_DEFAULT_FALLBACK = 'defaultFallback';

    /** @var int The absolute token threshold actually in effect — compaction is considered once the live context reaches it. */
    public int $thresholdTokens = 0;

    /** @var string Where the threshold comes from: one of the SOURCE_* constants. */
    public string $source = self::SOURCE_DEFAULT_FALLBACK;

    /** @var int|null The current live context footprint (the latest assistant message's inputTokens) compared to the threshold. Null = no model call yet. */
    public ?int $latestContextInputTokens = null;

    /** @var bool True when the live footprint has reached the threshold (compaction is due). */
    public bool $isDue = false;

    /** @var string|null The conversation's resolved model (aiModelNameOverride). Null = none → the default fallback applies. */
    public ?string $modelName = null;

    /** @var int|null The model's window-derived effectiveContextTokens (the MODEL_EFFECTIVE candidate). Null when no model resolves one. */
    public ?int $modelEffectiveContextTokens = null;

    /** @var int|null The per-conversation CompactionSettings.triggerTokens override, when set (>0). Null = no override. */
    public ?int $conversationTriggerTokens = null;

    /** @var int The global default fallback threshold (50k) — shown so the operator sees what would apply without a model/override. */
    public int $defaultFallbackTokens = 0;

    /** @var string|null The conversation's purpose (e.g. `Website.Build`), when it has one. Null = a plain conversation. */
    public ?string $purposeName = null;

    /** @var int|null The purpose's declared `contextBudgetTokens`, clamped to the model's window — the value the purposed start stamps as triggerTokens. Null = the purpose declares none / no purpose. */
    public ?int $purposeContextBudgetTokens = null;

    /** @var int How many compaction nodes already exist for this conversation (0 = nothing compacted yet). */
    public int $compactionNodeCount = 0;

    /**
     * The breakdown of one conversation from what it gathered: the model's limits, its model override name (reported as
     * stored, an unknown or empty name included), its compaction settings, its purpose name and that purpose's budget
     * already clamped to the model's window, the threshold in effect and whether it is due (the conversation's own
     * answers), the newest footprint and the loaded node count. The SOURCE: an override equal to the clamped purpose
     * budget is the purpose's (the match is by value), any other positive override an admin's, else the model's value,
     * else the fallback. Pure.
     */
    public static function buildFor(
        CompactionModelLimits $compactionModelLimits,
        ?string $modelName,
        ?CompactionSettings $compactionSettings,
        ?string $purposeName,
        ?int $purposeContextBudgetTokens,
        int $thresholdTokens,
        bool $isDue,
        ?int $latestContextInputTokens,
        int $compactionNodeCount
    ): static {
        $triggerTokens = $compactionSettings?->triggerTokens ?? null;
        $hasOverride = $triggerTokens !== null && $triggerTokens > 0;
        $modelEffectiveContextTokens = $compactionModelLimits->modelEffectiveContextTokens;

        $compactionThresholdBreakdown = new static();
        $compactionThresholdBreakdown->thresholdTokens = $thresholdTokens;
        $compactionThresholdBreakdown->source = match (true) {
            $hasOverride && $purposeContextBudgetTokens !== null && $triggerTokens === $purposeContextBudgetTokens
                => self::SOURCE_PURPOSE_CONTEXT_BUDGET,
            $hasOverride => self::SOURCE_CONVERSATION_OVERRIDE,
            $modelEffectiveContextTokens !== null => self::SOURCE_MODEL_EFFECTIVE,
            default => self::SOURCE_DEFAULT_FALLBACK,
        };
        $compactionThresholdBreakdown->latestContextInputTokens = $latestContextInputTokens;
        $compactionThresholdBreakdown->isDue = $isDue;
        $compactionThresholdBreakdown->modelName = $modelName;
        $compactionThresholdBreakdown->modelEffectiveContextTokens = $modelEffectiveContextTokens;
        $compactionThresholdBreakdown->conversationTriggerTokens = $hasOverride ? $triggerTokens : null;
        $compactionThresholdBreakdown->purposeName = $purposeName;
        $compactionThresholdBreakdown->purposeContextBudgetTokens = $purposeContextBudgetTokens;
        $compactionThresholdBreakdown->defaultFallbackTokens = CompactionModelLimits::DEFAULT_EFFECTIVE_CONTEXT_TOKENS;
        $compactionThresholdBreakdown->compactionNodeCount = $compactionNodeCount;
        return $compactionThresholdBreakdown;
    }
}
