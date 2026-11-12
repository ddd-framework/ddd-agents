<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a conversation's model allows its compaction (one rule, formerly three copies in
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationCompactionService}): the model's own threshold (its
 * `effectiveContextTokens` under its context-rot wall, {@see CompactionContextRotWall}), the threshold the compaction
 * applies (that value under the {@see self::DEFAULT_EFFECTIVE_CONTEXT_TOKENS} ceiling, the ceiling itself without a
 * model value) and the clamp of a purpose's context budget to the wall. Built from the catalog model by
 * {@see self::forModel()}; the conversation's shorthand is
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::findCompactionModelLimits()}. Transient, never persisted.
 *
 * @phpstan-consistent-constructor
 */
class CompactionModelLimits extends ValueObject
{
    /**
     * Global fallback compaction threshold (absolute tokens) when the conversation's model carries no
     * {@see AIModel::$effectiveContextTokens} and there is no per-conversation `triggerTokens` override, and the ceiling
     * over every model's own value. 100k: the earlier 50k was set for
     * models whose 64k+ prefill was slow and expensive; GPT-6 Luna carries a 1.05M window at $0.10 / 1M input, and a web
     * research conversation lost its figures to a summary after two or three research rounds at 50k.
     */
    public const int DEFAULT_EFFECTIVE_CONTEXT_TOKENS = 100000;

    /** @var int|null The model's hard context window in tokens; null when there is no model or it carries none. */
    public ?int $contextWindowTokens = null;

    /**
     * @var int|null The model's own threshold: its `effectiveContextTokens` capped at 80 % of its window, WITHOUT the
     *      100k ceiling (the admin breakdown shows it so). Null when there is no model or its value is missing or not
     *      positive.
     */
    public ?int $modelEffectiveContextTokens = null;

    /**
     * @var int The compaction threshold for this model: {@see $modelEffectiveContextTokens} under the
     *      {@see self::DEFAULT_EFFECTIVE_CONTEXT_TOKENS} ceiling — a model with a genuinely lower reliable band keeps its
     *      lower value and compacts earlier —, the ceiling itself without a model value. A per-conversation
     *      `CompactionSettings::$triggerTokens` still wins downstream
     *      ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::getEffectiveCompactionThreshold()}).
     */
    public int $effectiveContextTokens = self::DEFAULT_EFFECTIVE_CONTEXT_TOKENS;

    /** The limits of `$aiModel` (null = no model: the 100k fallback, no window). */
    public static function forModel(?AIModel $aiModel): static
    {
        $compactionModelLimits = new static();
        $compactionModelLimits->contextWindowTokens = $aiModel?->contextWindowTokens;
        $modelEffectiveContextTokens = $aiModel?->effectiveContextTokens;
        if ($modelEffectiveContextTokens !== null && $modelEffectiveContextTokens > 0) {
            $compactionModelLimits->modelEffectiveContextTokens = CompactionContextRotWall::forContextWindowTokens($compactionModelLimits->contextWindowTokens)
                ->capTokens($modelEffectiveContextTokens);
            $compactionModelLimits->effectiveContextTokens = min($compactionModelLimits->modelEffectiveContextTokens, self::DEFAULT_EFFECTIVE_CONTEXT_TOKENS);
        }
        return $compactionModelLimits;
    }

    /**
     * A purpose's declared context budget clamped to the model's context-rot wall — deliberately WITHOUT the
     * 100k ceiling (raising the working context for ONE purpose is what the budget exists for). No window → the budget
     * as declared. A non-positive budget is not rejected here; the callers filter it.
     */
    public function clampContextBudget(int $contextBudgetTokens): int
    {
        return CompactionContextRotWall::forContextWindowTokens($this->contextWindowTokens)->capTokens($contextBudgetTokens);
    }
}
