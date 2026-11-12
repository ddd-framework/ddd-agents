<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentProbeDecisionAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentProbeDecisionAnswers;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * A PROBE decision: questions and state come from the caller (a fixture), the answers come back raw — the decision-layer
 * recipe's step 5 ("probe the questions live BEFORE wiring") without a site class per idea. Used
 * only by an application's probe command; never wired into the loop.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/systemone',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE,
    timeout: 4.0
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_TYPESAFE_JEV_1_13,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_AGENT_PROBE_DECISION,
    aiUsageScope: AgentAIUsageScope::AI_AGENT_DECIDE_TURN,
    enforceBudget: true,
)]
class ArgusAgentProbeDecision extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait, ArgusAgentDecisionTrait {
        ArgusAgentDecisionTrait::getLoadPayloadInternal insteadof ArgusAILanguageModelTrait;
        ArgusAgentDecisionTrait::handleLoadResponseInternal insteadof ArgusAILanguageModelTrait;
    }

    /** @var array<string, array<string, mixed>> The questions exactly as the fixture writes them. */
    public array $probeQuestions = [];

    /** @var array<string, mixed> The state exactly as the fixture writes it. */
    public array $probeState = [];

    /** @var AgentProbeDecisionAnswers|null The answers the model gave, one per answered question; null before an answer was read. */
    public ?AgentProbeDecisionAnswers $probeAnswers = null;

    /** @return array<string, mixed> */
    public function buildState(): array
    {
        return $this->probeState;
    }

    protected function buildQuestionsForRequest(): array
    {
        return $this->probeQuestions;
    }

    protected function applyAnswers(object $answers): bool
    {
        $this->probeAnswers ??= new AgentProbeDecisionAnswers();
        foreach (array_keys($this->probeQuestions) as $questionName) {
            $questionName = (string)$questionName;
            $type = (string)($this->probeQuestions[$questionName]['type'] ?? '');
            if ($type === 'noul') {
                $probability = $this->readNoulProbability($answers, $questionName);
                if ($probability !== null) {
                    $probeDecisionAnswer = AgentProbeDecisionAnswer::forYesProbability($questionName, $probability);
                    $this->probeAnswers->replace($probeDecisionAnswer);
                }
                continue;
            }
            if ($type === 'choice') {
                $allowed = array_map('strtolower', array_keys((array)($this->probeQuestions[$questionName]['criteria'] ?? [])));
                $choiceAnswer = $this->readChoiceFrom($answers, $questionName, $allowed, 'outside');
                if ($choiceAnswer->choice !== null) {
                    $probeDecisionAnswer = AgentProbeDecisionAnswer::forChoice($questionName, $choiceAnswer->choice, $choiceAnswer->confidence);
                    $this->probeAnswers->replace($probeDecisionAnswer);
                }
            }
        }
        return !$this->probeAnswers->isEmpty();
    }
}
