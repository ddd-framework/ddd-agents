<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Everything that decides which skills a conversation is OFFERED: the entitlement (applied
 * feature flags with the budget policy, account rights, billing surface), the purpose and initiator bindings,
 * the purpose rail and the effective model's vision. Gathered once by
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildAISkillOfferRestrictions()}; read by the pure
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog::buildFor()}, and answers the per-axis
 * name questions (vision, binding, rail) the load refusals and the conversation's seeding gatherers ask. Reads nothing
 * itself — a null axis does not restrict (a conversation without a space or without an account is never locked out).
 */
class AISkillOfferRestrictions extends ValueObject
{
    /** @var AIConversationEntitlement|null The flags (with the budget policy), the rights and the billing surface; null = ungated. */
    public ?AIConversationEntitlement $entitlement = null;

    /** @var string|null The conversation's own purpose name (purpose binding). */
    public ?string $purposeName = null;

    /** @var string|null The delegation tree root's purpose name of a delegation child (purpose-binding exclusion). */
    public ?string $rootPurposeName = null;

    /** @var string|null Who started the conversation (initiator binding). */
    public ?string $initiatorType = null;

    /** @var string[]|null The purpose rail's loadable skill names; null = no rail. */
    public ?array $railLoadableSkillNames = null;

    /** @var bool Whether the effective model can see images; when false, vision-requiring skills are recorded as locked for a preload. */
    public bool $modelHasVision = true;

    /**
     * The requested names this model can use, request order: on a model known to be blind a skill
     * needing vision is dropped; otherwise every name stays.
     *
     * @return string[]
     */
    public function filterVisionCapableSkillNames(RequestedAISkills $requestedAISkills): array
    {
        if ($this->modelHasVision) {
            return array_values($requestedAISkills->requestedSkillNames);
        }
        $visionCapableSkillNames = [];
        foreach ($requestedAISkills->requestedSkillNames as $requestedSkillName) {
            if ($requestedAISkills->getSkill($requestedSkillName)?->requiresVisionCapabilities === true) {
                continue;
            }
            $visionCapableSkillNames[] = $requestedSkillName;
        }
        return $visionCapableSkillNames;
    }

    /**
     * The requested names the purpose binding ({@see AISkill::isOfferedUnderPurposeName()}) and the initiator binding
     * ({@see AISkill::$onlyOnInitiatorTypes}) admit, request order; an unknown or unbound name passes.
     *
     * @return string[]
     */
    public function filterBoundSkillNames(RequestedAISkills $requestedAISkills): array
    {
        $boundSkillNames = [];
        foreach ($requestedAISkills->requestedSkillNames as $requestedSkillName) {
            $aiSkill = $requestedAISkills->getSkill($requestedSkillName);
            if ($aiSkill !== null && !$aiSkill->isOfferedUnderPurposeName($this->purposeName, $this->rootPurposeName)) {
                continue; // bound to another purpose, or excluded from this one
            }
            if ($aiSkill !== null && $aiSkill->onlyOnInitiatorTypes !== []
                && !($this->initiatorType !== null && in_array($this->initiatorType, $aiSkill->onlyOnInitiatorTypes, true))) {
                continue; // bound to a call
            }
            $boundSkillNames[] = $requestedSkillName;
        }
        return $boundSkillNames;
    }

    /**
     * The names inside the purpose rail, in the given order; without a rail the names stay as given.
     * Takes plain names: the rail needs no resolved skill.
     *
     * @param string[] $skillNames
     * @return string[]
     */
    public function filterRailedSkillNames(array $skillNames): array
    {
        if ($this->railLoadableSkillNames === null) {
            return $skillNames;
        }
        return array_values(array_intersect($skillNames, $this->railLoadableSkillNames));
    }
}
