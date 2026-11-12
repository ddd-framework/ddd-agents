<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The decision model's answers to the questions of one probe request, one per answered question.
 *
 * @property AgentProbeDecisionAnswer[] $elements
 * @method AgentProbeDecisionAnswer[] getElements()
 * @method AgentProbeDecisionAnswer|null first()
 */
class AgentProbeDecisionAnswers extends ObjectSet
{
    /** The answer to a question; null when the model gave none that could be read. */
    public function getAnswerOfQuestion(string $questionName): ?AgentProbeDecisionAnswer
    {
        $probeDecisionAnswer = $this->getByUniqueKey(AgentProbeDecisionAnswer::uniqueKeyOfQuestion($questionName));
        return $probeDecisionAnswer instanceof AgentProbeDecisionAnswer ? $probeDecisionAnswer : null;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
