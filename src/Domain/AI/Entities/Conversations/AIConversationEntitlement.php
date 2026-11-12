<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Entities\Skills\RequestedAISkills;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What this conversation's space and acting user may use: the agent space's applied feature
 * flags (which carry the AI-budget policy), the acting user's account rights and the account's billing surface.
 * Built by {@see AIConversation::getEntitlement()}; reads nothing itself. A null axis is ungated — a conversation
 * without a space or without an account is never locked out. Each question keeps today's rule; the copies that
 * still differ (the load path applies no billing / budget policy, the loaded-skill re-check no budget policy) are
 * known and consolidated separately, not here.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEntitlement extends ValueObject
{
    /** @var AgentFeatureFlagsInterface|null The agent space's applied flags; null = no agent space, ungated. */
    public ?AgentFeatureFlagsInterface $featureFlags = null;

    /** @var AgentAccountRightsInterface|null The acting user's rights; null = no account, ungated. */
    public ?AgentAccountRightsInterface $accountRights = null;

    /**
     * @var bool|null Whether the exact AI budget figures may be reported to the model (the AI-budget policy of the
     * agent space, answered by {@see \DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService::isExactAIBudgetReportable()});
     * null = not asked (no flags), reportable.
     */
    public ?bool $exactAIBudgetReportable = null;

    /** @var bool|null Whether the account has a billing surface; null = unknown (no account), ungated. */
    public ?bool $accountHasBillingAccess = null;

    /** Whether the agent space's plan does not include the skill — a required flag or its whole any-of set missing (false without flags). */
    public function lacksFeatureFlagsFor(AISkill $aiSkill): bool
    {
        return $this->featureFlags !== null && !$aiSkill->isIncludedInFeatureFlags($this->featureFlags);
    }

    /**
     * The rights the skill declares that this user lacks; empty without rights (ungated) or when permitted.
     *
     * @return string[]
     */
    public function getMissingAccountRightNamesFor(AISkill $aiSkill): array
    {
        return $this->accountRights !== null ? $aiSkill->getMissingAccountRightNames($this->accountRights) : [];
    }

    /** Whether the plan includes the skill AND this user may use it — the load path's and the promotions' rule. */
    public function permits(AISkill $aiSkill): bool
    {
        return !$this->lacksFeatureFlagsFor($aiSkill) && $this->getMissingAccountRightNamesFor($aiSkill) === [];
    }

    /**
     * The requested names the plan and this user permit, request order; an unknown name passes (the execution
     * answers it).
     *
     * @return string[]
     */
    public function filterPermittedSkillNames(RequestedAISkills $requestedAISkills): array
    {
        $permittedSkillNames = [];
        foreach ($requestedAISkills->requestedSkillNames as $requestedSkillName) {
            $aiSkill = $requestedAISkills->getSkill($requestedSkillName);
            if ($aiSkill === null || $this->permits($aiSkill)) {
                $permittedSkillNames[] = $requestedSkillName;
            }
        }
        return $permittedSkillNames;
    }

    /** Whether neither the flags nor the rights restrict anything (a bare conversation). */
    public function isUngatedByFlagsAndRights(): bool
    {
        return $this->featureFlags === null && $this->accountRights === null;
    }

    /** Whether the AI-budget policy hides the exact budget skill (a fair-use / hide-usage setting); false when not asked. */
    public function hidesExactAIBudget(): bool
    {
        return $this->exactAIBudgetReportable === false;
    }

    /** The catalog without the skills the plan lacks (unchanged without flags). */
    public function filterSkillsByFeatureFlags(AISkills $aiSkills): AISkills
    {
        return $this->featureFlags !== null ? $aiSkills->filterByAvailableFeatureFlags($this->featureFlags) : $aiSkills;
    }

    /** The catalog without the skills this user may not use (unchanged without rights). */
    public function filterSkillsByAccountRights(AISkills $aiSkills): AISkills
    {
        return $this->accountRights !== null ? $aiSkills->filterByAccountRights($this->accountRights) : $aiSkills;
    }

    /**
     * The entitlement of a caller that still holds the two axes apart (the context render, the catalog block) — the
     * same rules over the given axes. A bridge: {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput}'s
     * two-axis shape is meant to go and takes it along — add no new caller. Billing is unknown here: never
     * ask the billing question of such an entitlement. `$exactAIBudgetReportable` null = the budget policy is not asked.
     */
    public static function forAxes(
        ?AgentFeatureFlagsInterface $featureFlags,
        ?AgentAccountRightsInterface $accountRights,
        ?bool $exactAIBudgetReportable = null
    ): static {
        $aIConversationEntitlement = new static();
        $aIConversationEntitlement->featureFlags = $featureFlags;
        $aIConversationEntitlement->accountRights = $accountRights;
        $aIConversationEntitlement->exactAIBudgetReportable = $exactAIBudgetReportable;
        return $aIConversationEntitlement;
    }

    /** The same entitlement on the feature-flag axis alone (the instruction block counts the plan gap separately). */
    public function withFeatureFlagsOnly(): self
    {
        return self::forAxes($this->featureFlags, null, $this->exactAIBudgetReportable);
    }

    /**
     * The skills the plan includes AND this user may use — the flag filter, then the rights filter. Billing and the
     * AI-budget policy are steps of the OFFER only ({@see \DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog::buildFor()}).
     */
    public function filterSkills(AISkills $aiSkills): AISkills
    {
        return $this->filterSkillsByAccountRights($this->filterSkillsByFeatureFlags($aiSkills));
    }

    /**
     * The tool definitions the plan includes AND this user may call (a tool may be gated tighter than its skill) —
     * the flag filter, then the rights filter, each only on a known axis.
     */
    public function filterToolDefinitions(ToolDefinitions $toolDefinitions): ToolDefinitions
    {
        if ($this->featureFlags !== null) {
            $toolDefinitions = $toolDefinitions->filterByAvailableFeatureFlags($this->featureFlags);
        }
        if ($this->accountRights !== null) {
            $toolDefinitions = $toolDefinitions->filterByAccountRights($this->accountRights);
        }
        return $toolDefinitions;
    }
}
