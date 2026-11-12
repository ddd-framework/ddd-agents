<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * How one delegation child is created and seeded: the seed text and skills, and the
 * differences the two creating paths have today, as named fields — the model's `delegate` / a tool's fan-out
 * ({@see forDelegateTask()}) and the platform's mechanical background operation ({@see forBackgroundOperation()}).
 * Merging the differences into one rule is a behaviour change of its own.
 *
 * @phpstan-consistent-constructor
 */
class AgentDelegationChildProfile extends ValueObject
{
    /** The seed USER message — the child's task. */
    public string $seedText = '';

    /** @var string[] the skills the seed preloads (after the parent's entitlement, vision and purpose-binding gates) */
    public array $skillNames = [];

    /** A tool-declared step budget for the child; null = inherited. */
    public ?int $maxStepsPerTurn = null;

    /** A tool-pinned model for the child; null = inherited. */
    public ?string $modelName = null;

    /** The child's purpose; null = none. */
    public ?string $purposeName = null;

    /** The child row is written only when the parent is persisted (the delegate path); otherwise always. */
    public bool $writesRowOnlyWhenParentPersisted = true;

    /** The seed gets a (possibly empty) capability set even without skills (the delegate path); otherwise only with skills. */
    public bool $seedsCapabilitiesWithoutSkills = true;

    /** The vision gate reads the CHILD's model (the delegate path — a fan-out may pin another model); otherwise the parent's. */
    public bool $visionGateReadsTheChild = true;

    /** @param string[] $skillNames */
    public static function forDelegateTask(string $taskText, array $skillNames, ?int $maxStepsPerTurn, ?string $modelName): static
    {
        $agentDelegationChildProfile = new static();
        $agentDelegationChildProfile->seedText = $taskText;
        $agentDelegationChildProfile->skillNames = $skillNames;
        $agentDelegationChildProfile->maxStepsPerTurn = $maxStepsPerTurn;
        $agentDelegationChildProfile->modelName = $modelName;
        return $agentDelegationChildProfile;
    }

    /** @param string[] $skillNames */
    public static function forBackgroundOperation(string $seedText, array $skillNames, string $purposeName): static
    {
        $agentDelegationChildProfile = new static();
        $agentDelegationChildProfile->seedText = $seedText;
        $agentDelegationChildProfile->skillNames = $skillNames;
        $agentDelegationChildProfile->purposeName = $purposeName;
        $agentDelegationChildProfile->writesRowOnlyWhenParentPersisted = false;
        $agentDelegationChildProfile->seedsCapabilitiesWithoutSkills = false;
        $agentDelegationChildProfile->visionGateReadsTheChild = false;
        return $agentDelegationChildProfile;
    }
}
