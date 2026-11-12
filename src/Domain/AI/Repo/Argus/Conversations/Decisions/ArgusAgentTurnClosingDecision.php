<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Decisions;

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
 * The TYPED turn-closing facts of one final answer, asked of a System One DECISION model (TypeSafe Jev): two `noul` questions in ONE request over the assistant's final text and the plan items that still owe
 * evidence — does the text ANNOUNCE that work instead of delivering it, and does it REPORT changes as already made.
 * The consequence is not asked here; the loop resolves it in PHP
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnClosingDecision::resolveWouldBounce()}).
 *
 * Transport, gates, cost booking and outage behaviour: {@see ArgusAgentDecisionTrait}. Question design:  * one judgment per question, every state path backticked, criteria as situations, the negative branch enumerating
 * the traps (the interview question, the plain "not done", the conversational reply), the blind spot named (the text
 * alone cannot show whether earlier turns did the work — the `openWorkItems` list is what is still owed).
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/systemone',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE,
    timeout: 2.0
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_TYPESAFE_JEV_1_13,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_AGENT_TURN_CLOSING_DECISION,
    aiUsageScope: AgentAIUsageScope::AI_AGENT_DECIDE_TURN,
    enforceBudget: true,
)]
class ArgusAgentTurnClosingDecision extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait, ArgusAgentDecisionTrait {
        // The decision protocol REPLACES the chat protocol of the language-model trait (an override): payload and
        // response parsing come from the decision trait; scope/envelope/budget/cost/logging stay the language trait's.
        ArgusAgentDecisionTrait::getLoadPayloadInternal insteadof ArgusAILanguageModelTrait;
        ArgusAgentDecisionTrait::handleLoadResponseInternal insteadof ArgusAILanguageModelTrait;
    }

    public const string QUESTION_ANNOUNCES_INSTEAD_OF_DELIVERS = 'announces_instead_of_delivers';
    public const string QUESTION_REPORTS_COMPLETED_WRITES = 'reports_completed_writes';

    public const string STATE_ASSISTANT_ANSWER = 'assistantAnswer';
    public const string STATE_OPEN_WORK_ITEMS = 'openWorkItems';

    /** Position drift starts at long lists (the dossier): the state names at most this many open items. */
    public const int MAX_OPEN_WORK_ITEMS_IN_STATE = 8;

    /** @var string The assistant's final answer text of this turn — the ONLY text judged. */
    public string $assistantAnswer = '';

    /** @var string[] The open plan items that still owe tool / widget evidence, as path texts. */
    public array $openWorkItems = [];

    public ?float $announcesInsteadOfDeliversProbability = null;
    public ?float $reportsCompletedWritesProbability = null;

    /**
     * The two questions and their rules. Pure and public so the unit test can pin the rules.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function buildQuestions(): array
    {
        return [
            self::QUESTION_ANNOUNCES_INSTEAD_OF_DELIVERS => [
                'type' => 'noul',
                'instructions' => 'Does `' . self::STATE_ASSISTANT_ANSWER . '` end the assistant\'s turn by announcing the work in `'
                    . self::STATE_OPEN_WORK_ITEMS . '` as its own next step instead of doing it? Judge only `' . self::STATE_ASSISTANT_ANSWER
                    . '`; `' . self::STATE_OPEN_WORK_ITEMS . '` is the list of what is still owed, not evidence of what the text says.',
                'criteria' => [
                    'true' => 'the text says the assistant will now, next, shortly or in a moment do, prepare, set up, check, load or '
                        . 'add something from `' . self::STATE_OPEN_WORK_ITEMS . '` — the work is promised or described as about to '
                        . 'happen, and the text ends there without asking the owner for anything the work needs first',
                    'false' => 'the text asks the owner a question or offers a choice that the open work needs answered first; '
                        . 'or it presents results, data, a summary or a proposal for the owner to react to; or it says plainly '
                        . 'that something is NOT done, blocked or failed and why; or it is a conversational reply to thanks, '
                        . 'a greeting or small talk with no work promised; or it asks the owner to confirm before a change is made',
                ],
            ],
            self::QUESTION_REPORTS_COMPLETED_WRITES => [
                'type' => 'noul',
                'instructions' => 'Does `' . self::STATE_ASSISTANT_ANSWER . '` report that the assistant has already made, saved, '
                    . 'published or updated something? Judge only `' . self::STATE_ASSISTANT_ANSWER . '`.',
                'criteria' => [
                    'true' => 'the text states, in the past tense or as a completed fact, that a change was made — something was '
                        . 'added, saved, updated, published, sent or set',
                    'false' => 'the text reports no completed change: it proposes, asks, explains, announces future work, or '
                        . 'presents data it read without changing anything',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> The `state` object — the answer and the open items (capped), nothing else. */
    public function buildState(): array
    {
        return [
            self::STATE_ASSISTANT_ANSWER => $this->assistantAnswer,
            self::STATE_OPEN_WORK_ITEMS => array_values(array_slice($this->openWorkItems, 0, self::MAX_OPEN_WORK_ITEMS_IN_STATE)),
        ];
    }

    protected function buildQuestionsForRequest(): array
    {
        return self::buildQuestions();
    }

    protected function applyAnswers(object $answers): bool
    {
        $this->announcesInsteadOfDeliversProbability = $this->readNoulProbability($answers, self::QUESTION_ANNOUNCES_INSTEAD_OF_DELIVERS);
        $this->reportsCompletedWritesProbability = $this->readNoulProbability($answers, self::QUESTION_REPORTS_COMPLETED_WRITES);
        return $this->announcesInsteadOfDeliversProbability !== null;
    }
}
