<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\Base\Entities\EntitySet;

/**
 * Collection of {@see AISkill}s — the agent's loadable capability catalog. File-based config (no DB), mirroring
 * the AIModel / AIModels pair. `getService()` resolves to {@see AISkillsService} via the SERVICE_NAME const
 * below (the flat namespace convention would miss the `Services\Skills\…` sub-folder).
 *
 * @property AISkill[] $elements
 * @method AISkill|null first()
 * @method AISkill|null getByUniqueKey(string $uniqueKey)
 * @method AISkill[] getElements()
 * @method static AISkillsService getService()
 */
class AISkills extends EntitySet
{
    /** Pin the service to its sub-folder (getService()'s flat convention would miss `Services\Skills\…`). */
    public const string SERVICE_NAME = AISkillsService::class;

    /** @var array<int, class-string>|null Union of all skills' MCPPackage classes — resolved once, then kept here. */
    protected ?array $packages = null;

    /**
     * The MCPPackage classes of all these skills (deduped union), cached after the first call. These are the tools
     * the agent loop offers to the model for the loaded skills.
     *
     * @return array<int, class-string>
     */
    public function getPackages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }
        $packages = [];
        foreach ($this->getElements() as $skill) {
            foreach ($skill->getPackages() as $package) {
                if (!in_array($package, $packages, true)) {
                    $packages[] = $package;
                }
            }
        }
        return $this->packages = $packages;
    }

    /**
     * The loaded skills' instructions joined into one model-facing block (one `## <name>\n<instruction>` section per
     * skill, blank line between). This is the skill content the agent hands to the model when skills are loaded.
     * `$featureFlags` and `$accountRightsSetting` → each skill's tools line lists only the
     * tools the plan includes AND the user may call ({@see AISkill::getInstructionBlock()}), each exclusion carrying
     * its own sentence: a plan gap is an upgrade, a missing right is a request to the account administrator. Without
     * the rights half a partially-restricted skill keeps instructing the model in a write it no longer has a tool for.
     */
    public function getInstructions(
        ?AgentFeatureFlagsInterface $featureFlags = null,
        ?AgentAccountRightsInterface $accountRightsSetting = null,
        ?AISkills $offeredSkillsForSubSkillLines = null,
        ?AIConversationVariants $variants = null
    ): string {
        $blocks = [];
        foreach ($this->getElements() as $skill) {
            $block = $skill->getInstructionBlock($featureFlags, $accountRightsSetting, $offeredSkillsForSubSkillLines, $variants);
            if ($block !== '') {
                $blocks[] = $block;
            }
        }
        return implode("\n\n", $blocks);
    }

    /**
     * {@see getInstructions()} with every skill rendered without its conditional sections
     * ({@see AISkill::getInstructionBlockWithoutConditionalSections()}).
     */
    public function getInstructionsWithoutConditionalSections(
        ?AgentFeatureFlagsInterface $featureFlags = null,
        ?AgentAccountRightsInterface $accountRightsSetting = null,
        ?AISkills $offeredSkillsForSubSkillLines = null
    ): string {
        $blocks = [];
        foreach ($this->getElements() as $skill) {
            $block = $skill->getInstructionBlockWithoutConditionalSections($featureFlags, $accountRightsSetting, $offeredSkillsForSubSkillLines);
            if ($block !== '') {
                $blocks[] = $block;
            }
        }
        return implode("\n\n", $blocks);
    }

    /** Whether any skill of the set is CUT into conditional sections (the situation line is owed only then). */
    public function hasConditionalSections(): bool
    {
        foreach ($this->getElements() as $skill) {
            if ($skill->getSections()->hasConditionalSections()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The conditional section ids the loaded skills render for `$variants`, skill-prefixed, in block order
     * ({@see AISkill::getRenderedSectionIds()}).
     *
     * @return string[]
     */
    public function getRenderedSectionIds(?AIConversationVariants $variants): array
    {
        $ids = [];
        foreach ($this->getElements() as $skill) {
            foreach ($skill->getRenderedSectionIds($variants) as $id) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * A NEW set of only the skills the agent space is entitled to: a skill survives iff the agent space has ALL of its
     * `requiredFeatureFlags` and — when declared — ONE of its `anyOfRequiredFeatureFlags` available
     * ({@see AISkill::isIncludedInFeatureFlags()}). Returns a fresh set — the source (the memoized full catalog) is never mutated.
     * A locked skill is thus structurally absent from the load_skills / delegate enums — the agent cannot load it.
     */
    public function filterByAvailableFeatureFlags(AgentFeatureFlagsInterface $featureFlags): AISkills
    {
        $entitledSkills = new AISkills();
        foreach ($this->getElements() as $aiSkill) {
            if ($aiSkill->isIncludedInFeatureFlags($featureFlags)) {
                $entitledSkills->add($aiSkill);
            }
        }
        return $entitledSkills;
    }

    /**
     * A NEW set of only the skills the acting USER is permitted to use: a skill survives iff every one of
     * its `requiredAccountRights` is true on the account. The second axis, applied beside
     * {@see filterByAvailableFeatureFlags()} and never instead of it.
     *
     * A dropped skill is absent from the load_skills / delegate enums exactly like a flag-locked one. What differs is
     * what the agent then SAYS: a flag-locked skill is an upgrade offer, a right-locked one is "your account
     * administrator has to grant X" — that half lives in the restricted-capabilities context segment, not here.
     *
     * An unknown right name counts as NOT granted, the same fail-closed rule the tool filter and the execution guard
     * apply, so a mistyped declaration cannot silently open the gate anywhere.
     */
    public function filterByAccountRights(AgentAccountRightsInterface $accountRightsSetting): AISkills
    {
        $permittedSkills = new AISkills();
        foreach ($this->getElements() as $aiSkill) {
            if ($aiSkill->getMissingAccountRightNames($accountRightsSetting) === []) {
                $permittedSkills->add($aiSkill);
            }
        }
        return $permittedSkills;
    }

    /**
     * A NEW set without the skills that need a billing surface, when this account has none. The THIRD
     * axis, and the only one with no follow-up for the customer at all: a flag gap can be bought, a right can be
     * granted, but an account that a third party provisions and bills has no invoices or payment method in the host —
     * so the capability is not mentioned anywhere either, not as an upgrade and not in the restricted block.
     *
     * `$hasAccountBillingAccess` comes from the host's account (its billing-access check),
     * passed in rather than resolved here so this stays a pure fold with no entity reach-through.
     */
    public function filterByAccountBillingAccess(bool $hasAccountBillingAccess): AISkills
    {
        if ($hasAccountBillingAccess) {
            return $this;
        }
        $offerableSkills = new AISkills();
        foreach ($this->getElements() as $aiSkill) {
            if (!$aiSkill->requiresAccountBillingAccess) {
                $offerableSkills->add($aiSkill);
            }
        }
        return $offerableSkills;
    }

    /**
     * The RESTRICTED-capabilities awareness block: what the space HAS but this USER may not
     * use, one line per capability with the right that unlocks it. Empty string when nothing is restricted.
     *
     * Why it exists at all, given the schemas are already gone: without it the agent answers "can you confirm this
     * booking?" with "that is not possible", which is false and leaves the customer stuck. With it the agent names the
     * capability and the person who can grant it. It must NEVER read as an upgrade — a right is granted by the
     * account administrator, never bought — which is exactly why this is a separate block from the plan awareness
     * rather than more lines inside it.
     *
     * Two levels, both real: a whole SKILL the user may not use, and single TOOLS inside a skill they otherwise may
     * (the view/manage split — they can read bookings but not change them). A pure pass over this set; the caller
     * passes the full catalog.
     */
    public function buildRestrictedCapabilitiesMarkdown(AgentAccountRightsInterface $accountRightsSetting): string
    {
        $restrictedLines = [];
        foreach ($this->getElements() as $aiSkill) {
            $missingSkillRightNames = $aiSkill->getMissingAccountRightNames($accountRightsSetting);
            if ($missingSkillRightNames !== []) {
                $restrictedLines[] = '- ' . ($aiSkill->shortDescription !== '' ? $aiSkill->shortDescription : $aiSkill->name)
                    . ' (right: ' . implode(', ', $missingSkillRightNames) . ')';
                continue; // the whole skill is out — its tools need no separate lines
            }
            foreach (ToolDefinitions::forPackages($aiSkill->getPackages())->getElements() as $toolDefinition) {
                $missingToolRightNames = $accountRightsSetting->getMissingRightNames($toolDefinition->requiredAccountRightNames);
                if ($missingToolRightNames !== []) {
                    $restrictedLines[] = '- ' . $toolDefinition->name . ' in ' . $aiSkill->name
                        . ' (right: ' . implode(', ', $missingToolRightNames) . ')';
                }
            }
        }
        if ($restrictedLines === []) {
            return '';
        }
        return 'This ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . " HAS these capabilities, but YOUR user account is not permitted to use them. Never claim\n"
            . "they do not exist and never offer a plan upgrade for them — name the capability and say the customer's\n"
            . "account administrator has to grant the named right.\n\n"
            . implode("\n", array_unique($restrictedLines));
    }

    /**
     * A NEW set without the named skill(s) — the policy gate on top of the flag-entitlement filter (e.g. the exact
     * AI-budget skill is dropped under a fair-use / hide-usage policy, which is a SETTING value, not a flag). Returns
     * a fresh set; the source is never mutated.
     */
    /**
     * The OFFERABLE catalog given the currently loaded skills (sub-skill visibility):
     * keeps every general skill (`!isSubSkill`) plus each sub-skill listed in the `subSkillNames` of a LOADED
     * root (union over the loaded roots — a sub-skill under several expert roots is visible while ANY of them is
     * loaded). Scan-free: visibility is read off the skill itself, edges off the loaded roots in THIS set.
     *
     * @param string[] $loadedSkillNames
     */
    public function filterForLoadedRootSkills(array $loadedSkillNames): AISkills
    {
        $offeredSubSkillNames = [];
        foreach ($this->getElements() as $aiSkill) {
            if ($aiSkill->subSkillNames !== [] && in_array($aiSkill->name, $loadedSkillNames, true)) {
                $offeredSubSkillNames = array_merge($offeredSubSkillNames, $aiSkill->subSkillNames);
            }
        }
        $offerableSkills = new AISkills();
        foreach ($this->getElements() as $aiSkill) {
            if (!$aiSkill->isSubSkill || in_array($aiSkill->name, $offeredSubSkillNames, true)) {
                $offerableSkills->add($aiSkill);
            }
        }
        return $offerableSkills;
    }

    /**
     * A NEW set without the skills bound to OTHER purposes: a skill declaring `onlyUnderPurposeNames` survives only when
     * this conversation's purpose name is one of them; a general skill (empty list) always survives. Null (an open
     * chat, no purpose) keeps only the general skills — the build orchestrator is never offered outside its run. A skill
     * excluding this purpose (`notUnderPurposeNames`) is dropped too ({@see AISkill::isOfferedUnderPurposeName()}).
     */
    public function filterByPurposeName(?string $purposeName, ?string $rootPurposeName = null): AISkills
    {
        $offerableSkills = new AISkills();
        foreach ($this->getElements() as $aiSkill) {
            if ($aiSkill->isOfferedUnderPurposeName($purposeName, $rootPurposeName)) {
                $offerableSkills->add($aiSkill);
            }
        }
        return $offerableSkills;
    }

    /**
     * A NEW set without the skills bound to OTHER initiator types: a skill declaring `onlyOnInitiatorTypes` survives
     * only when this conversation's initiator type is one of them; a general skill (empty list) always survives. Null
     * (a plain chat) keeps only the general skills — a skill made for calls is never offered outside a call.
     */
    public function filterByInitiatorType(?string $initiatorType): AISkills
    {
        $offerableSkills = new AISkills();
        foreach ($this->getElements() as $aiSkill) {
            if ($aiSkill->onlyOnInitiatorTypes === [] || ($initiatorType !== null && in_array($initiatorType, $aiSkill->onlyOnInitiatorTypes, true))) {
                $offerableSkills->add($aiSkill);
            }
        }
        return $offerableSkills;
    }

    /**
     * A NEW set with the skills BOUND to `$initiatorType` (a non-empty `onlyOnInitiatorTypes` naming it) — the skills a
     * call conversation preloads from its first turn (see {@see AISkill::$onlyOnInitiatorTypes}).
     */
    public function filterBoundToInitiatorType(?string $initiatorType): AISkills
    {
        $boundSkills = new AISkills();
        if ($initiatorType === null) {
            return $boundSkills;
        }
        foreach ($this->getElements() as $aiSkill) {
            if ($aiSkill->onlyOnInitiatorTypes !== [] && in_array($initiatorType, $aiSkill->onlyOnInitiatorTypes, true)) {
                $boundSkills->add($aiSkill);
            }
        }
        return $boundSkills;
    }

    /**
     * A NEW set with the skills that declare at least one of `$widgetFenceTypes` in `preloadOnWidgetTypes` — the
     * handlers a customer-composed widget in the inbound message preloads (see {@see AISkill::$preloadOnWidgetTypes}).
     *
     * @param string[] $widgetFenceTypes the distinct fence `type`s the message carries
     */
    public function filterByPreloadWidgetTypes(array $widgetFenceTypes): AISkills
    {
        $triggeredSkills = new AISkills();
        if ($widgetFenceTypes === []) {
            return $triggeredSkills;
        }
        foreach ($this->getElements() as $aiSkill) {
            if (array_intersect($aiSkill->preloadOnWidgetTypes, $widgetFenceTypes) !== []) {
                $triggeredSkills->add($aiSkill);
            }
        }
        return $triggeredSkills;
    }

    public function without(string ...$skillNames): AISkills
    {
        $remainingSkills = new AISkills();
        foreach ($this->getElements() as $aiSkill) {
            if (!in_array($aiSkill->name, $skillNames, true)) {
                $remainingSkills->add($aiSkill);
            }
        }
        return $remainingSkills;
    }

    /** The skill of this set named `$skillName`, null when the set does not hold it. */
    public function findByName(string $skillName): ?AISkill
    {
        foreach ($this->getElements() as $aiSkill) {
            if ($aiSkill->name === $skillName) {
                return $aiSkill;
            }
        }
        return null;
    }

    /**
     * The root skill of this set that lists `$subSkillName` among its sub-skills — the first by name when several do;
     * null when none does (a sub-skill is visible only under its loaded root).
     */
    public function findRootSkillListingSubSkill(string $subSkillName): ?AISkill
    {
        $rootSkillsListingSubSkill = [];
        foreach ($this->getElements() as $aiSkill) {
            if (in_array($subSkillName, $aiSkill->subSkillNames, true)) {
                $rootSkillsListingSubSkill[$aiSkill->name] = $aiSkill;
            }
        }
        ksort($rootSkillsListingSubSkill);
        return $rootSkillsListingSubSkill === [] ? null : reset($rootSkillsListingSubSkill);
    }

    /**
     * Restrict the catalog to an explicit allow-list of skill names — the inverse of {@see without()}. A purpose that
     * declares `allowedLoadSkillNames` (e.g. an intake: only the skills of its own steps, such as `Intake.Chat`)
     * uses this so the load_skills / delegate enums cannot drift onto the general-domain roots (the agent would load
     * a general skill of the same subject instead of the skill of its next step — which
     * derails the whole run). Empty allow-list = no restriction (returns $this unchanged).
     *
     * @param string[] $allowedSkillNames
     */
    public function filterToNames(array $allowedSkillNames): AISkills
    {
        if ($allowedSkillNames === []) {
            return $this;
        }
        $restrictedSkills = new AISkills();
        foreach ($this->getElements() as $aiSkill) {
            if (in_array($aiSkill->name, $allowedSkillNames, true)) {
                $restrictedSkills->add($aiSkill);
            }
        }
        return $restrictedSkills;
    }

    /**
     * Of `$toolNames`, those some skill of this set declares but NO skill the entitlement permits offers (the skill
     * permitted and the tool itself not gated tighter) — a done-gate the account can never satisfy. A tool no skill
     * declares (a meta or plan tool) is never reported.
     *
     * @param string[] $toolNames
     * @return string[]
     */
    public function getToolNamesNoPermittedSkillProvides(array $toolNames, AIConversationEntitlement $entitlement): array
    {
        $lockedToolNames = [];
        foreach ($toolNames as $toolName) {
            $declaredBySomeSkill = false;
            foreach ($this->getElements() as $aiSkill) {
                if (!in_array($toolName, $aiSkill->getToolNames(), true)) {
                    continue;
                }
                $declaredBySomeSkill = true;
                if ($entitlement->permits($aiSkill) && in_array($toolName, $aiSkill->getToolNames($entitlement), true)) {
                    continue 2;
                }
            }
            if ($declaredBySomeSkill) {
                $lockedToolNames[] = $toolName;
            }
        }
        return $lockedToolNames;
    }
}
