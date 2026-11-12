<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\CustomerSelection;

use DDD\Domain\AI\Entities\Models\Selection\AgentEligibleModel;
use DDD\Domain\AI\Entities\Models\Selection\AgentEligibleModels;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The AI models a CUSTOMER may pick in the agent chat's model picker — an ObjectSet of
 * {@see AgentCustomerSelectableModel}, ordered by the curated `displayOrder`. Assembled from the
 * agent-eligible catalog + the `agentCustomerSelection` blocks of the `AI.models` config
 * (see {@see self::fromAgentEligibleModels()}); served by the selectable-models read and
 * the whitelist the customer drive validates `aiModelName` against (fail-closed). An application may put a same-path
 * subclass under its own root namespace: the core assembles the container's class
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService::getCustomerSelectableAgentModels()}).
 *
 * @property AgentCustomerSelectableModel[] $elements
 * @method AgentCustomerSelectableModel[] getElements()
 * @method AgentCustomerSelectableModel|null first()
 * @phpstan-consistent-constructor
 */
class AgentCustomerSelectableModels extends ObjectSet
{
    /** Strength bands over the catalog's agentic intelligence score (0–100). ABSOLUTE, so a later added model never re-scales the existing levels. Band lower bounds for levels 1..5. */
    public const array STRENGTH_LEVEL_LOWER_BOUNDS = [0, 40, 60, 75, 93];

    /** Speed bands over the catalog's measured output tokens/sec. ABSOLUTE band lower bounds for levels 1..5. */
    public const array SPEED_LEVEL_LOWER_BOUNDS = [0, 40, 70, 100, 150];

    /**
     * Assembles the customer picker list: keep the eligible models that carry an `agentCustomerSelection`
     * config block, order them by its `displayOrder`, band strength/speed into 1–5 levels and express cost
     * as a credit-consumption multiple of the cheapest selectable model. The cost basis per model is its
     * MEASURED per-interaction cost (`measuredCostPerInteractionUsd`, from the canonical eval run — the honest
     * budget-burn ratio) when configured, else the catalog blended $/1M price (token MIX differs per model, so
     * the blended price can overstate a multiple by 3×; see the config block's key doc). Pure assembly over the
     * two inputs — no repo/config/service contact (the caller supplies both).
     *
     * @param AgentEligibleModels $agentEligibleModels the agent-eligible catalog (AIModelsService::getAgentEligibleModels())
     * @param array<string, array<string, mixed>> $modelsConfig the full `AI.models` config (Config::get('AI.models'))
     * @return static
     */
    public static function fromAgentEligibleModels(AgentEligibleModels $agentEligibleModels, array $modelsConfig): static
    {
        /** @var AgentCustomerCuratedModel[] $curatedModels */
        $curatedModels = [];
        foreach ($agentEligibleModels->getElements() as $agentEligibleModel) {
            $customerSelectionConfig = $modelsConfig[$agentEligibleModel->name]['agentCustomerSelection'] ?? null;
            if (!is_array($customerSelectionConfig)) {
                continue;
            }
            $curatedModels[] = AgentCustomerCuratedModel::forSelectionConfigAndEligibleModel($customerSelectionConfig, $agentEligibleModel);
        }
        usort(
            $curatedModels,
            static fn (AgentCustomerCuratedModel $a, AgentCustomerCuratedModel $b): int => $a->getDisplayOrder() <=> $b->getDisplayOrder()
        );

        // Unit consistency: measured $/interaction and blended $/1M must NEVER mix in one ratio. Use the measured
        // basis only when EVERY curated model carries it; otherwise fall back to the blended price for ALL.
        $useMeasuredCostBasis = $curatedModels !== [];
        foreach ($curatedModels as $curatedModel) {
            if (self::measuredCostPerInteractionUsd($curatedModel->customerSelectionConfig) === null) {
                $useMeasuredCostBasis = false;
                break;
            }
        }

        $cheapestCreditsCostBasisUsd = null;
        foreach ($curatedModels as $curatedModel) {
            $creditsCostBasisUsd = self::creditsCostBasisUsd($curatedModel->customerSelectionConfig, $curatedModel->eligibleModel, $useMeasuredCostBasis);
            if ($creditsCostBasisUsd !== null
                && ($cheapestCreditsCostBasisUsd === null || $creditsCostBasisUsd < $cheapestCreditsCostBasisUsd)) {
                $cheapestCreditsCostBasisUsd = $creditsCostBasisUsd;
            }
        }

        $agentCustomerSelectableModels = new static();
        foreach ($curatedModels as $curatedModel) {
            $eligibleModel = $curatedModel->eligibleModel;
            $selectionConfig = $curatedModel->customerSelectionConfig;

            $agentCustomerSelectableModel = AgentCustomerSelectableModel::newInstance();
            $agentCustomerSelectableModel->name = $eligibleModel->name;
            $agentCustomerSelectableModel->displayName = (string)($selectionConfig['displayName'] ?? $eligibleModel->name);
            $agentCustomerSelectableModel->description = (string)($selectionConfig['description'] ?? '');
            $agentCustomerSelectableModel->strengthLevel = self::levelForValue((float)($eligibleModel->agenticScore ?? 0), self::STRENGTH_LEVEL_LOWER_BOUNDS);
            $agentCustomerSelectableModel->speedLevel = self::levelForValue((float)($eligibleModel->tokensPerSecond ?? 0), self::SPEED_LEVEL_LOWER_BOUNDS);
            $creditsCostBasisUsd = self::creditsCostBasisUsd($selectionConfig, $eligibleModel, $useMeasuredCostBasis);
            $agentCustomerSelectableModel->creditsMultiplier = ($cheapestCreditsCostBasisUsd !== null && $creditsCostBasisUsd !== null)
                ? max(1.0, round($creditsCostBasisUsd / $cheapestCreditsCostBasisUsd, 1))
                : 1.0;
            $agentCustomerSelectableModels->add($agentCustomerSelectableModel);
        }
        return $agentCustomerSelectableModels;
    }

    /**
     * The measured per-interaction cost from the config block, or null when absent/invalid.
     *
     * @param array<string, mixed> $customerSelectionConfig the model's `agentCustomerSelection` config block
     */
    protected static function measuredCostPerInteractionUsd(array $customerSelectionConfig): ?float
    {
        $measuredCostPerInteractionUsd = $customerSelectionConfig['measuredCostPerInteractionUsd'] ?? null;
        return (is_numeric($measuredCostPerInteractionUsd) && (float)$measuredCostPerInteractionUsd > 0)
            ? (float)$measuredCostPerInteractionUsd
            : null;
    }

    /**
     * The cost basis one model contributes to the credits-multiplier ratio — in the unit the WHOLE set uses:
     * the measured per-interaction cost when the set runs on the measured basis, else the catalog blended $/1M
     * price, else null (no basis → multiplier 1).
     *
     * @param array<string, mixed> $customerSelectionConfig the model's `agentCustomerSelection` config block
     */
    protected static function creditsCostBasisUsd(array $customerSelectionConfig, AgentEligibleModel $agentEligibleModel, bool $useMeasuredCostBasis): ?float
    {
        if ($useMeasuredCostBasis) {
            return self::measuredCostPerInteractionUsd($customerSelectionConfig);
        }
        $blendedCostPer1MUsd = $agentEligibleModel->blendedCostPer1MUsd;
        return ($blendedCostPer1MUsd !== null && $blendedCostPer1MUsd > 0) ? $blendedCostPer1MUsd : null;
    }

    /**
     * The client-supplied `aiModelName` when it is customer-selectable, else null — the fail-closed resolution
     * the customer drive applies before writing `AIConversation::$aiModelNameOverride`.
     *
     * Null means "ignore this pick", NOT "reject the request". The curated list changes under long-lived clients
     * (a de-curation, or a model name the frontend persisted in an earlier session), and a client that re-sends
     * such a name on every message would otherwise be hard-failed on every message. Ignoring keeps the
     * fail-closed guarantee that matters — a non-whitelisted model is never applied to the customer's budget —
     * while the turn proceeds on the conversation's stored model, or the agent default when it has none.
     */
    public function getModelNameIfSelectable(?string $aiModelName): ?string
    {
        if ($aiModelName === null || $aiModelName === '') {
            return null;
        }
        return $this->containsModelName($aiModelName) ? $aiModelName : null;
    }

    /**
     * Returns true when the given AIModel name is one of the customer-selectable models — the fail-closed
     * whitelist check the customer drive runs on a client-supplied `aiModelName`.
     */
    public function containsModelName(string $aiModelName): bool
    {
        foreach ($this->getElements() as $agentCustomerSelectableModel) {
            if ($agentCustomerSelectableModel->name === $aiModelName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Maps a value onto a 1–5 level via ABSOLUTE band lower bounds: the level is the highest band whose
     * lower bound the value reaches.
     *
     * @param float $value the catalog fact (agentic score / tokens per second)
     * @param int[] $levelLowerBounds lower bounds for levels 1..5, ascending
     */
    protected static function levelForValue(float $value, array $levelLowerBounds): int
    {
        $level = 1;
        foreach ($levelLowerBounds as $levelIndex => $lowerBound) {
            if ($value >= $lowerBound) {
                $level = $levelIndex + 1;
            }
        }
        return $level;
    }
}
