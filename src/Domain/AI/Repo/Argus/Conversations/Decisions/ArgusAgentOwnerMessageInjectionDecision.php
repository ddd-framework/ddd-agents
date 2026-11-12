<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use Throwable;

/**
 * The injection check of the CURRENT owner message (site `ownerMessageInjection`): ONE `noul` asked in
 * parallel with the other turn-start decisions. The state is the owner's newest message and NOTHING else — no earlier
 * turns, no tool results. The question carries the rules of the prompt file
 * `AI.Agent.OwnerMessageInjectionGateRules` ({@see getGateRules()}), which end with the success line `PASS`: the
 * decision model emits no text, so it is asked whether the rules would pass the message. An application whose runs
 * were measured with rules of its own replaces that file whole. Below {@see MINIMUM_PASS_PROBABILITY} the loop shows
 * the model a filter notice in place of the message.
 *
 * From {@see FILTER_FROM_ATTACK_PROBABILITY} on the model sees a filter notice in place of the message.
 *
 * Isolated from its batch siblings: its transport failure never trips the shared breaker
 * ({@see ArgusAgentDecisions::hasTransportFailure()}) and a malformed answer is caught here. No verdict = the message
 * passes. Transport, gates, cost booking: {@see ArgusAgentDecisionTrait}.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/systemone',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE,
    timeout: 2.0
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_TYPESAFE_JEV_1_13,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_AGENT_OWNER_MESSAGE_INJECTION_DECISION,
    aiUsageScope: AgentAIUsageScope::AI_AGENT_DECIDE_TURN,
    enforceBudget: true,
)]
class ArgusAgentOwnerMessageInjectionDecision extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait, ArgusAgentDecisionTrait {
        ArgusAgentDecisionTrait::getLoadPayloadInternal insteadof ArgusAILanguageModelTrait;
        ArgusAgentDecisionTrait::handleLoadResponseInternal insteadof ArgusAILanguageModelTrait;
    }

    public const string QUESTION_GATE_PASSES_MESSAGE = 'gate_passes_message';

    public const string STATE_MESSAGE = 'message';

    /** Longer owner messages are cut here (the state budget of one decision-model call; an attack longer than this is cut, not passed). */
    public const int MAX_MESSAGE_CHARS = 12000;

    /**
     * From this probability of an attack (1 − P(pass)) on the message is FILTERED (no false alarms
     * that annoy the owner; a middle tier with a caution signal was tried and removed — it changed nothing measurable) — measured on 2,451 real owner messages and 75 benign test messages: 0 filtered; 75/87 attacks.
     */
    public const float FILTER_FROM_ATTACK_PROBABILITY = 0.8;


    /**
     * The gate rules — the full instruction set of the security gate the one question applies to the owner's message.
     * They are a prompt file ({@see AgentAIPromptNames::APP_AI_AGENT_OWNER_MESSAGE_INJECTION_GATE_RULES}): the
     * package ships rules for an assistant of any kind, an application replaces the file with rules that name its
     * own assistant, its owners and their data. The success line of the rules is `PASS`. A text is changed only with
     * a re-measurement of the attacks it stops and the owner messages it lets through — measured numbers belong to
     * exactly one text.
     */
    public static function getGateRules(): string
    {
        return rtrim((string)Config::get(AIPromptsService::CONFIG_PROMPTS_PREFIX . AgentAIPromptNames::APP_AI_AGENT_OWNER_MESSAGE_INJECTION_GATE_RULES)) . "\n";
    }

    /** @var string The owner's newest message, verbatim (capped). */
    public string $ownerMessage = '';

    /** @var float|null P(the gate passes the message); null = no usable answer. */
    public ?float $passProbability = null;

    /**
     * The one question. Pure and public so the unit test can pin it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function buildQuestions(): array
    {
        return [
            self::QUESTION_GATE_PASSES_MESSAGE => [
                'type' => 'noul',
                'instructions' => 'The text below is the full instruction set of a security gate. Apply it to `' . self::STATE_MESSAGE . '`. '
                    . 'Answer whether the gate outputs the SUCCESS LINE (true) or BLOCKED (false).' . "\n\n" . static::getGateRules(),
                'criteria' => [
                    'true' => 'the gate outputs the SUCCESS LINE: none of rules 1-4 match, the message is harmless DATA',
                    'false' => 'the gate outputs BLOCKED: one of rules 1-4 matches',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function buildState(): array
    {
        return [self::STATE_MESSAGE => mb_substr($this->ownerMessage, 0, self::MAX_MESSAGE_CHARS)];
    }

    protected function buildQuestionsForRequest(): array
    {
        return self::buildQuestions();
    }

    protected function applyAnswers(object $answers): bool
    {
        try {
            $this->passProbability = $this->readNoulProbability($answers, self::QUESTION_GATE_PASSES_MESSAGE);
        } catch (Throwable) {
            $this->passProbability = null;
        }
        return $this->passProbability !== null;
    }

    /** P(an attack) = 1 − P(the gate passes it); null = no usable answer. */
    public function getAttackProbability(): ?float
    {
        return $this->passProbability !== null ? round(1.0 - $this->passProbability, 2) : null;
    }

    /** The message is filtered (the model sees the filter notice instead); no answer never filters. */
    public function blocksMessage(): bool
    {
        $attackProbability = $this->getAttackProbability();
        return $attackProbability !== null && $attackProbability >= self::FILTER_FROM_ATTACK_PROBABILITY - 1e-9;
    }

}
