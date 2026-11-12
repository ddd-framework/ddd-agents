<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Skills\AdmittedAISkills;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkillOfferRestrictions;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Entities\Skills\RequestedAISkills;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The skill catalog a turn OFFERS — the one place every offer policy is applied, so the `load_skills`
 * and `delegate` enums, the structural preloads and the decision model's answer set all read the same set.
 *
 * `skills` is exactly what {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService::buildOfferedToolSchemas()}
 * offered before the extraction: the general catalog plus the sub-skills of the loaded roots, then the flag, budget
 * policy, rights, billing, purpose binding, initiator binding and purpose-rail filters. The names each step dropped are
 * kept per step (`rejectedSkillNamesByStep`) so a preload decision can be traced ("why was X not offered?").
 *
 * Vision is deliberately NOT an offer filter: the offer ignores it (the wire gate degrades pictures on a blind model)
 * while the preload paths refuse a vision-requiring skill on a known-blind model. The names that rule would refuse
 * are recorded in `visionLockedSkillNames` and stay IN `skills` — the enums are byte-identical to before.
 *
 * @phpstan-consistent-constructor
 */
class OfferedSkillCatalog extends ValueObject
{
    /** @var AISkills The offered skills — the `load_skills` catalog before the already-loaded names are hidden. */
    public AISkills $skills;

    /** @var string[] Skills already loaded on the anchor message (hidden from the `load_skills` enum, still callable). */
    public array $alreadyLoadedSkillNames = [];

    /** @var array<string, string[]> Skill names each offer policy dropped, keyed by the policy step. */
    public array $rejectedSkillNamesByStep = [];

    /** @var string[] Offered names a preload must skip because the effective model is blind (the offer keeps them). */
    public array $visionLockedSkillNames = [];

    /**
     * The offer of one anchor, pure: the catalog narrowed to the general skills plus the sub-skills
     * of the loaded roots (`$assumeLoadedSkillNames` count as loaded too — a shadow follow-up pass), then every offer
     * step in order — flags, budget policy, rights, billing, purpose binding, initiator binding, purpose rail — each
     * step's drops recorded. Vision only records the locked names; it never filters the offer.
     *
     * @param string[] $assumeLoadedSkillNames skills treated as loaded, so their sub-skills join the offer
     */
    public static function buildFor(
        AISkills $skillCatalog,
        AISkillOfferRestrictions $aISkillOfferRestrictions,
        ?LoadedCapabilities $anchorLoadedCapabilities,
        array $assumeLoadedSkillNames = []
    ): static {
        $offeredSkillCatalog = new static();
        $alreadyLoadedSkillNames = $anchorLoadedCapabilities?->getLoadedSkillNames() ?? [];
        if ($assumeLoadedSkillNames !== []) {
            $alreadyLoadedSkillNames = array_values(array_unique(array_merge($alreadyLoadedSkillNames, $assumeLoadedSkillNames)));
        }
        $offeredSkillCatalog->alreadyLoadedSkillNames = $alreadyLoadedSkillNames;
        $skillCatalog = $skillCatalog->filterForLoadedRootSkills($alreadyLoadedSkillNames);
        $entitlement = $aISkillOfferRestrictions->entitlement ?? new AIConversationEntitlement();
        if ($entitlement->featureFlags !== null) {
            $skillCatalog = $offeredSkillCatalog->recordStep('featureFlags', $skillCatalog, $entitlement->filterSkillsByFeatureFlags($skillCatalog));
            // the exact AI-budget skill is not offered under a fair-use / hide-usage policy (a setting, not a flag)
            if ($entitlement->hidesExactAIBudget()) {
                $skillCatalog = $offeredSkillCatalog->recordStep('budgetPolicy', $skillCatalog, $skillCatalog->without(AISkill::ASSISTANT_BUDGET));
            }
        }
        if ($entitlement->accountRights !== null) {
            $skillCatalog = $offeredSkillCatalog->recordStep('accountRights', $skillCatalog, $entitlement->filterSkillsByAccountRights($skillCatalog));
        }
        if ($entitlement->accountHasBillingAccess === false) {
            $skillCatalog = $offeredSkillCatalog->recordStep('billingAccess', $skillCatalog, $skillCatalog->filterByAccountBillingAccess(false));
        }
        $skillCatalog = $offeredSkillCatalog->recordStep('purposeBinding', $skillCatalog, $skillCatalog->filterByPurposeName($aISkillOfferRestrictions->purposeName, $aISkillOfferRestrictions->rootPurposeName));
        $skillCatalog = $offeredSkillCatalog->recordStep('initiatorBinding', $skillCatalog, $skillCatalog->filterByInitiatorType($aISkillOfferRestrictions->initiatorType));
        if ($aISkillOfferRestrictions->railLoadableSkillNames !== null) {
            $skillCatalog = $offeredSkillCatalog->recordStep('purposeRail', $skillCatalog, $skillCatalog->filterToNames($aISkillOfferRestrictions->railLoadableSkillNames));
        }
        $offeredSkillCatalog->skills = $skillCatalog;
        if (!$aISkillOfferRestrictions->modelHasVision) {
            foreach ($skillCatalog->getElements() as $offeredSkill) {
                if ($offeredSkill->requiresVisionCapabilities) {
                    $offeredSkillCatalog->visionLockedSkillNames[] = (string)$offeredSkill->name;
                }
            }
        }
        return $offeredSkillCatalog;
    }

    /**
     * Everything the conversation COULD be offered once its roots are loaded: the offer with every offered root (and,
     * in a second pass, every offered sub-skill) assumed loaded, so the sub-skills are part of it. Pure.
     * `rejectedSkillNamesByStep` names the offer policy that withheld a skill over all passes; nothing counts as loaded.
     */
    public static function buildReachableFor(AISkills $skillCatalog, AISkillOfferRestrictions $aISkillOfferRestrictions, ?LoadedCapabilities $anchorLoadedCapabilities): self
    {
        $reachableSkillCatalog = self::buildFor($skillCatalog, $aISkillOfferRestrictions, $anchorLoadedCapabilities);
        $rejectedSkillNamesByStep = $reachableSkillCatalog->rejectedSkillNamesByStep;
        for ($pass = 0; $pass < 2; $pass++) {
            $reachableSkillCatalog = self::buildFor($skillCatalog, $aISkillOfferRestrictions, $anchorLoadedCapabilities, $reachableSkillCatalog->getSkillNames());
            foreach ($reachableSkillCatalog->rejectedSkillNamesByStep as $step => $rejectedSkillNames) {
                $rejectedSkillNamesByStep[$step] = array_values(array_unique(array_merge($rejectedSkillNamesByStep[$step] ?? [], $rejectedSkillNames)));
            }
        }
        $reachableSkillCatalog->rejectedSkillNamesByStep = $rejectedSkillNamesByStep;
        $reachableSkillCatalog->alreadyLoadedSkillNames = [];
        return $reachableSkillCatalog;
    }

    /**
     * Which requested names a NON-model source may preload under this offer, request order, each name
     * once: a name must be known, not loaded yet and offered (a dropped one carries the offer step that dropped it); a
     * sub-skill only for a PLAN ITEM or a decision-model follow-up (the offer carries a sub-skill only when its root is
     * loaded, so being offered already vouches for the root); never a vision-locked one. Pure.
     */
    public function buildAdmissionFor(RequestedAISkills $requestedAISkills, string $source): AdmittedAISkills
    {
        $admittedAISkills = new AdmittedAISkills();
        foreach (array_values(array_unique($requestedAISkills->requestedSkillNames)) as $skillName) {
            $aiSkill = $requestedAISkills->getSkill($skillName);
            if ($aiSkill === null) {
                $admittedAISkills->rejectionReasonsBySkillName[$skillName] = AdmittedAISkills::REJECTED_UNKNOWN;
                continue;
            }
            if (in_array($skillName, $this->alreadyLoadedSkillNames, true)) {
                $admittedAISkills->rejectionReasonsBySkillName[$skillName] = AdmittedAISkills::REJECTED_ALREADY_LOADED;
                continue;
            }
            if (!$this->isOffered($skillName)) {
                $admittedAISkills->rejectionReasonsBySkillName[$skillName] = $this->getRejectionStep($skillName) ?? AdmittedAISkills::REJECTED_NOT_OFFERED;
                continue;
            }
            if ($aiSkill->isSubSkill && !in_array($source, [LoadedCapability::SOURCE_PLAN_ITEM, LoadedCapability::SOURCE_DECISION_MODEL], true)) {
                $admittedAISkills->rejectionReasonsBySkillName[$skillName] = AdmittedAISkills::REJECTED_SUB_SKILL;
                continue;
            }
            if (in_array($skillName, $this->visionLockedSkillNames, true)) {
                $admittedAISkills->rejectionReasonsBySkillName[$skillName] = AdmittedAISkills::REJECTED_VISION_LOCKED;
                continue;
            }
            $admittedAISkills->admittedSkillNames[] = $skillName;
        }
        return $admittedAISkills;
    }

    /**
     * The tools of the offered, NOT YET LOADED sub-skills of the loaded roots, each with its sub-skill — the
     * tools a `load_tool` call may name beside the unloaded tools of the loaded skills. A loaded root thereby makes
     * its sub-skills' tools reachable in one call; their bodies and full schemas stay unloaded until then. Gated like
     * the wire schemas (feature flags, account rights): this offer is already gated, so a sub-skill the plan, the
     * rights, the purpose rail or a binding withholds never appears; a sub-skill this model cannot use (vision) is
     * left out as well. Pure: `$loadedSkills` are the resolved skills of `$loadedCapabilities`, the gatherer is
     * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildLoadableSubSkillTools()}.
     *
     * @return LoadableSubSkillTools every such tool with its sub-skill and one-line summary
     */
    public function buildLoadableSubSkillTools(LoadedCapabilities $loadedCapabilities, AISkills $loadedSkills, AIConversationEntitlement $entitlement): LoadableSubSkillTools
    {
        $loadedSkillNames = $loadedCapabilities->getLoadedSkillNames();
        $loadableSubSkillTools = new LoadableSubSkillTools();
        if ($loadedSkillNames === []) {
            return $loadableSubSkillTools; // no root loaded — no sub-skill is offered
        }
        $toolNamesOfLoadedSkills = [];
        foreach ($loadedSkills->getElements() as $loadedAiSkill) {
            foreach ($loadedAiSkill->getToolNames() as $toolNameOfLoadedSkill) {
                $toolNamesOfLoadedSkills[] = $toolNameOfLoadedSkill;
            }
        }
        foreach ($this->skills->getElements() as $aiSkill) {
            if (!$aiSkill->isSubSkill
                || in_array($aiSkill->name, $loadedSkillNames, true)
                || in_array($aiSkill->name, $this->visionLockedSkillNames, true)
            ) {
                continue;
            }
            foreach ($aiSkill->getToolSummaries($entitlement) as $toolName => $toolSummary) {
                if (!in_array($toolName, $toolNamesOfLoadedSkills, true)) {
                    $loadableSubSkillTools->addToolOfSubSkillOnce((string)$toolName, $aiSkill->name, $toolSummary);
                }
            }
        }
        return $loadableSubSkillTools;
    }

    /** @return string[] Every offered skill name, catalog order. */
    public function getSkillNames(): array
    {
        $skillNames = [];
        foreach ($this->skills->getElements() as $aiSkill) {
            $skillNames[] = (string) $aiSkill->name;
        }
        return $skillNames;
    }

    /** @return string[] The offered ROOT skills (never a sub-skill — a sub-skill is visible only under its loaded root). */
    public function getRootSkillNames(): array
    {
        $rootSkillNames = [];
        foreach ($this->skills->getElements() as $aiSkill) {
            if (!$aiSkill->isSubSkill) {
                $rootSkillNames[] = (string) $aiSkill->name;
            }
        }
        return $rootSkillNames;
    }

    /** @return string[] The `load_skills` enum: offered names minus the already-loaded ones. */
    public function getLoadableSkillNames(): array
    {
        return array_values(array_diff($this->getSkillNames(), $this->alreadyLoadedSkillNames));
    }

    /** @return string[] The decision model's answer set: offered roots not yet loaded and not vision-locked. */
    public function getPreloadableRootSkillNames(): array
    {
        return array_values(array_diff($this->getRootSkillNames(), $this->alreadyLoadedSkillNames, $this->visionLockedSkillNames));
    }

    /**
     * The follow-up answer set of a preload pass: the sub-skills a NEWLY loaded root unlocks —
     * offered under that root, not yet loaded, not vision-locked. Empty when the root has no sub-skill edges or none
     * of them is offered; the caller re-runs the decision only then.
     *
     * @return string[]
     */
    public function getPreloadableSubSkillNamesUnlockedBy(string $rootSkillName): array
    {
        $subSkillNames = [];
        foreach ($this->skills->getElements() as $aiSkill) {
            if ((string) $aiSkill->name === $rootSkillName) {
                $subSkillNames = $aiSkill->subSkillNames;
                break;
            }
        }
        if ($subSkillNames === []) {
            return [];
        }
        return array_values(array_diff(array_intersect($subSkillNames, $this->getSkillNames()), $this->alreadyLoadedSkillNames, $this->visionLockedSkillNames));
    }

    /**
     * The skills that are ALREADY loaded and still have sub-skills to offer (offered, not loaded, not vision-locked) —
     * the skills whose sub-skills the decision model is asked about at the turn start, newest load last.
     *
     * @return string[]
     */
    public function getLoadedSkillNamesWithPreloadableSubSkills(): array
    {
        $skillNames = [];
        foreach ($this->alreadyLoadedSkillNames as $loadedSkillName) {
            if ($this->getPreloadableSubSkillNamesUnlockedBy((string) $loadedSkillName) !== []) {
                $skillNames[] = (string) $loadedSkillName;
            }
        }
        return array_values(array_unique($skillNames));
    }

    public function isOffered(string $skillName): bool
    {
        return in_array($skillName, $this->getSkillNames(), true);
    }

    /** @return string[] Every name any policy dropped, de-duplicated. */
    public function getRejectedSkillNames(): array
    {
        $rejectedSkillNames = [];
        foreach ($this->rejectedSkillNamesByStep as $skillNames) {
            foreach ($skillNames as $skillName) {
                $rejectedSkillNames[$skillName] = true;
            }
        }
        return array_keys($rejectedSkillNames);
    }

    /** The policy step that dropped $skillName, or null when it was never in the catalog or is offered. */
    public function getRejectionStep(string $skillName): ?string
    {
        foreach ($this->rejectedSkillNamesByStep as $step => $skillNames) {
            if (in_array($skillName, $skillNames, true)) {
                return (string) $step;
            }
        }
        return null;
    }

    /**
     * Records the names $before carried and $after no longer does under $step; returns $after unchanged.
     */
    public function recordStep(string $step, AISkills $before, AISkills $after): AISkills
    {
        $beforeNames = [];
        foreach ($before->getElements() as $aiSkill) {
            $beforeNames[] = (string) $aiSkill->name;
        }
        $afterNames = [];
        foreach ($after->getElements() as $aiSkill) {
            $afterNames[] = (string) $aiSkill->name;
        }
        $droppedNames = array_values(array_diff($beforeNames, $afterNames));
        if ($droppedNames !== []) {
            $this->rejectedSkillNamesByStep[$step] = $droppedNames;
        }
        return $after;
    }

    /**
     * The offered direct sub-skills of the loaded skills that are not loaded themselves — what the stall signal names
     * under the hierarchy. The offer carries the gates (plan, rights, purpose rail, bindings); a sub-skill the model
     * cannot use (vision) is left out, its load would be refused.
     *
     * @param string[] $loadedSkillNames
     * @return string[]
     */
    public function getUnloadedSubSkillNames(array $loadedSkillNames): array
    {
        $unloadedSubSkillNames = [];
        foreach ($this->skills->getElements() as $offeredSkill) {
            if (
                $offeredSkill->isSubSkill
                && !in_array($offeredSkill->name, $loadedSkillNames, true)
                && !in_array($offeredSkill->name, $this->visionLockedSkillNames, true)
            ) {
                $unloadedSubSkillNames[] = (string)$offeredSkill->name;
            }
        }
        return $unloadedSubSkillNames;
    }
}
