<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The sub-skill request of one skill that was loaded before the turn: the loaded skill's name and the child request
 * of the turn-start request that asks its sub-skill question.
 *
 * @phpstan-consistent-constructor
 */
class AgentTurnStartSubSkillDecision extends ValueObject
{
    /** @var string The name of the loaded skill whose sub-skill question the request asks. */
    public string $loadedSkillName;

    /** @var ArgusAgentTurnStartDecision The child request and, once it answered, its answer. */
    public ArgusAgentTurnStartDecision $turnStartDecision;

    public static function forLoadedSkill(string $loadedSkillName, ArgusAgentTurnStartDecision $turnStartDecision): static
    {
        $turnStartSubSkillDecision = new static();
        $turnStartSubSkillDecision->loadedSkillName = $loadedSkillName;
        $turnStartSubSkillDecision->turnStartDecision = $turnStartDecision;
        return $turnStartSubSkillDecision;
    }

    /** The key of a loaded skill's request: the loaded skill's name. */
    public static function uniqueKeyOfLoadedSkill(string $loadedSkillName): string
    {
        return self::class . '_' . $loadedSkillName;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyOfLoadedSkill($this->loadedSkillName);
    }
}
