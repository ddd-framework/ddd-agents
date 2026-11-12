<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Which names of a `load_skills` request pass the load path's gates and, for every refused name, why — pure over the resolved request and the offer restrictions. The gates run in the load path's order:
 * entitlement (flags, then account rights), vision, purpose and initiator binding, the purpose rail, the sub-skill
 * gate. A refused name stays out of `loadableSkillNames`; {@see renderCorrectiveSentences()} tells the model why and
 * what to do instead. Billing and the budget policy restrict the OFFER only — the load path never applied them.
 *
 * @phpstan-consistent-constructor
 */
class AISkillLoadRefusals extends ValueObject
{
    /** @var string[] The names that passed every gate, request order — what is loaded. */
    public array $loadableSkillNames = [];

    /** @var string[] Not in the customer's plan (a skill failing both entitlement axes counts here). */
    public array $flagLockedSkillNames = [];

    /**
     * @var array<string, string> Flag-locked skill name → its gate in words ("AI.AGENT and one of AI.AGENT.CHANNEL.APPVOICE,
     * AI.AGENT.CHANNEL.PHONE", {@see AISkill::describeFeatureFlagRequirement()}) — named beside the skill in the refusal, so
     * the model reads alternatives as alternatives.
     */
    public array $featureFlagRequirementBySkillName = [];

    /** @var array<string, string[]> Permitted by the plan but not to this user: skill name → the missing rights. */
    public array $missingAccountRightNamesBySkillName = [];

    /** @var string[] Needing vision on a model that cannot see images. */
    public array $visionLockedSkillNames = [];

    /** @var string[] Bound to another purpose (or excluded from this one). */
    public array $purposeBoundSkillNames = [];

    /** @var string[] Bound to a call initiator. */
    public array $initiatorBoundSkillNames = [];

    /** @var string[] Outside the purpose rail. */
    public array $railDroppedSkillNames = [];

    /** @var string[] Sub-skills whose root is neither loaded nor requested in the same call. */
    public array $unrootedSubSkillNames = [];

    public static function buildFor(RequestedAISkills $requestedAISkills, AISkillOfferRestrictions $aISkillOfferRestrictions): static
    {
        $aISkillLoadRefusals = new static();
        $requestedSkillNames = $requestedAISkills->requestedSkillNames;

        // entitlement: flags, then rights — an unknown name passes (the execution answers it)
        $entitlement = $aISkillOfferRestrictions->entitlement ?? new AIConversationEntitlement();
        $entitledSkillNames = $entitlement->isUngatedByFlagsAndRights()
            ? $requestedSkillNames
            : $entitlement->filterPermittedSkillNames($requestedAISkills);
        // the two answers are opposites: a plan gap is an upgrade, a missing right is the administrator's grant
        foreach (array_values(array_diff($requestedSkillNames, $entitledSkillNames)) as $droppedSkillName) {
            $aiSkill = $requestedAISkills->getSkill($droppedSkillName);
            $missingAccountRightNames = $aiSkill !== null ? $entitlement->getMissingAccountRightNamesFor($aiSkill) : [];
            $lacksFlags = $aiSkill !== null && $entitlement->lacksFeatureFlagsFor($aiSkill);
            if ($lacksFlags || $missingAccountRightNames === []) {
                $aISkillLoadRefusals->flagLockedSkillNames[] = $droppedSkillName;
                if ($aiSkill !== null) {
                    $aISkillLoadRefusals->featureFlagRequirementBySkillName[$droppedSkillName] = $aiSkill->describeFeatureFlagRequirement();
                }
                continue;
            }
            $aISkillLoadRefusals->missingAccountRightNamesBySkillName[$droppedSkillName] = $missingAccountRightNames;
        }

        // vision: refused only on a model known to be blind
        $visionCapableSkillNames = $aISkillOfferRestrictions->filterVisionCapableSkillNames($requestedAISkills->withRequestedSkillNames($entitledSkillNames));
        $aISkillLoadRefusals->visionLockedSkillNames = array_values(array_diff($entitledSkillNames, $visionCapableSkillNames));

        // purpose and initiator binding
        $boundSkillNames = $aISkillOfferRestrictions->filterBoundSkillNames($requestedAISkills->withRequestedSkillNames($visionCapableSkillNames));
        // a skill failing both binding axes is reported as purpose-bound
        foreach (array_values(array_diff($visionCapableSkillNames, $boundSkillNames)) as $droppedSkillName) {
            $aiSkill = $requestedAISkills->getSkill($droppedSkillName);
            $failsPurposeBinding = $aiSkill !== null && !$aiSkill->isOfferedUnderPurposeName($aISkillOfferRestrictions->purposeName, $aISkillOfferRestrictions->rootPurposeName);
            if ($failsPurposeBinding || $aiSkill === null || $aiSkill->onlyOnInitiatorTypes === []) {
                $aISkillLoadRefusals->purposeBoundSkillNames[] = $droppedSkillName;
                continue;
            }
            $aISkillLoadRefusals->initiatorBoundSkillNames[] = $droppedSkillName;
        }

        // the purpose rail, fail-closed
        $railedSkillNames = $aISkillOfferRestrictions->filterRailedSkillNames($boundSkillNames);
        if ($aISkillOfferRestrictions->railLoadableSkillNames !== null) {
            $aISkillLoadRefusals->railDroppedSkillNames = array_values(array_diff($boundSkillNames, $railedSkillNames));
        }

        // the sub-skill gate, fail-closed: a sub-skill needs a root listing it, loaded or requested in this call
        $offeredSubSkillNames = [];
        foreach (array_unique(array_merge($requestedAISkills->loadedSkillNames, $railedSkillNames)) as $rootCandidateName) {
            $rootCandidateSkill = $requestedAISkills->getSkill($rootCandidateName);
            if ($rootCandidateSkill !== null && $rootCandidateSkill->subSkillNames !== []) {
                $offeredSubSkillNames = array_merge($offeredSubSkillNames, $rootCandidateSkill->subSkillNames);
            }
        }
        foreach ($railedSkillNames as $railedSkillName) {
            $aiSkill = $requestedAISkills->getSkill($railedSkillName);
            if ($aiSkill !== null && $aiSkill->isSubSkill && !in_array($railedSkillName, $offeredSubSkillNames, true)) {
                continue;
            }
            $aISkillLoadRefusals->loadableSkillNames[] = $railedSkillName;
        }
        // a right-locked name is reported once, never also as an unrooted sub-skill
        $aISkillLoadRefusals->unrootedSubSkillNames = array_values(array_diff(
            $requestedSkillNames,
            $aISkillLoadRefusals->flagLockedSkillNames,
            array_keys($aISkillLoadRefusals->missingAccountRightNamesBySkillName),
            $aISkillLoadRefusals->railDroppedSkillNames,
            $aISkillLoadRefusals->visionLockedSkillNames,
            array_merge($aISkillLoadRefusals->purposeBoundSkillNames, $aISkillLoadRefusals->initiatorBoundSkillNames),
            $aISkillLoadRefusals->loadableSkillNames
        ));
        return $aISkillLoadRefusals;
    }

    /** For every refused group, why it was refused and what to do instead — appended to the load's result. */
    public function renderCorrectiveSentences(): string
    {
        $correctiveSentences = '';
        if ($this->flagLockedSkillNames !== []) {
            $flagLockedDescriptions = [];
            foreach ($this->flagLockedSkillNames as $flagLockedSkillName) {
                $flagRequirement = $this->featureFlagRequirementBySkillName[$flagLockedSkillName] ?? '';
                $flagLockedDescriptions[] = $flagRequirement !== '' ? $flagLockedSkillName . ' (requires ' . $flagRequirement . ')' : $flagLockedSkillName;
            }
            $correctiveSentences .= ' NOT loaded (not included in this customer\'s plan): ' . implode(', ', $flagLockedDescriptions)
                . '. Do not retry loading these. If the customer wants this capability, offer the unlocking upgrade'
                . ' listed under "The customer\'s plan & capabilities".';
        }
        if ($this->missingAccountRightNamesBySkillName !== []) {
            $rightLockedDescriptions = [];
            foreach ($this->missingAccountRightNamesBySkillName as $rightLockedSkillName => $missingAccountRightNames) {
                $rightLockedDescriptions[] = $rightLockedSkillName . ' (right: ' . implode(', ', $missingAccountRightNames) . ')';
            }
            $correctiveSentences .= ' NOT loaded (this user account lacks the required permission): ' . implode(', ', $rightLockedDescriptions)
                . '. Do not retry loading these. This is NOT a plan upgrade — tell the customer their account'
                . ' administrator has to grant the named right.';
        }
        if ($this->visionLockedSkillNames !== []) {
            $correctiveSentences .= ' NOT loaded (this model cannot see images, and these skills need vision): ' . implode(', ', $this->visionLockedSkillNames)
                . '. Do not retry loading them on this model — image-based work needs a vision-capable model.';
        }
        if ($this->railDroppedSkillNames !== []) {
            $correctiveSentences .= ' NOT loaded (not part of this conversation\'s skill set): ' . implode(', ', $this->railDroppedSkillNames)
                . '. Use only the skills this conversation offers: "Available skills" and the sub-skills your loaded skills list.';
        }
        // how a purpose-bound skill's work is reached instead is the application's to say; an initiator-bound one does not exist off a call
        if ($this->purposeBoundSkillNames !== []) {
            $purposeBoundSkillLoadAdvice = AgentApplicationWording::current()->purposeBoundSkillLoadAdvice;
            $correctiveSentences .= ' NOT loaded (available only inside its own purposed conversation): ' . implode(', ', $this->purposeBoundSkillNames)
                . '. Do not retry loading these here.' . ($purposeBoundSkillLoadAdvice !== '' ? ' ' . $purposeBoundSkillLoadAdvice : '');
        }
        if ($this->initiatorBoundSkillNames !== []) {
            $correctiveSentences .= ' NOT loaded (available only in a conversation a call started): ' . implode(', ', $this->initiatorBoundSkillNames)
                . '. Do not retry loading these in a chat.';
        }
        if ($this->unrootedSubSkillNames !== []) {
            $correctiveSentences .= ' NOT loaded (sub-skills of a skill that is not loaded): ' . implode(', ', $this->unrootedSubSkillNames)
                . '. Load the skill that lists them first; a loaded skill names its sub-skills in its own block.';
        }
        return $correctiveSentences;
    }
}
