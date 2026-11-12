<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What ONE skill load through the gates of the load path did (formerly the narrowed
 * by-reference arguments of the loop's `loadSkillsThroughGates`): the result text the model reads and the skill names
 * that passed the gates, which the load received. A `load_skills` call hands those names to the decision model's
 * sub-skill preload, a `load_tool` call names them as the sub-skills that came with its tools.
 *
 * @phpstan-consistent-constructor
 */
class AgentSkillLoadOutcome extends ValueObject
{
    /** The tool result: what was loaded and, for every refused name, why and what to do instead. */
    public string $resultText = '';

    /**
     * The names that passed the gates; null when the request named no skill (nothing reached the gates).
     *
     * @var string[]|null
     */
    public ?array $admittedSkillNames = null;

    /** Why the gates refused names of the request, and what to do instead ({@see \DDD\Domain\AI\Entities\Skills\AISkillLoadRefusals::renderCorrectiveSentences()}); each sentence starts with a space, '' when nothing was refused. */
    public string $refusalSentences = '';

    /** The answer to a request that named no skill: nothing reached the gates. */
    public static function withoutSkillNames(string $resultText): static
    {
        $agentSkillLoadOutcome = new static();
        $agentSkillLoadOutcome->resultText = $resultText;
        return $agentSkillLoadOutcome;
    }

    /** @param string[] $admittedSkillNames */
    public static function afterTheGates(string $resultText, array $admittedSkillNames, string $refusalSentences = ''): self
    {
        $agentSkillLoadOutcome = self::withoutSkillNames($resultText);
        $agentSkillLoadOutcome->admittedSkillNames = $admittedSkillNames;
        $agentSkillLoadOutcome->refusalSentences = $refusalSentences;
        return $agentSkillLoadOutcome;
    }
}
