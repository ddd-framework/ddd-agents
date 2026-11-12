<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentStallRecoveryDecision;
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
 * The TYPED stall-recovery move at the second stall of a turn, asked of a System One DECISION model (TypeSafe Jev): ONE `choice` question over the stalled step's output, the owner's request, the open plan item and
 * the capabilities at hand — which move gets the model unstuck. The moves are DISJOINT by construction: PHP removes
 * an inapplicable move before the call (no work-tool move without eligible work tools, no capability move when
 * nothing is loadable), so the model chooses only among moves the loop can take. The consequence is the loop's.
 *
 * Transport, gates, cost booking and outage behaviour: {@see ArgusAgentDecisionTrait}. Question design:  * one judgment, every state path backticked, criteria as situations, the traps named, the blind spot named (the
 * model cannot know whether a tool's inputs are really at hand — `residentToolNames` lists what is callable, the
 * owner's own words say what is known).
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/systemone',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE,
    timeout: 2.0
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_TYPESAFE_JEV_1_13,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_AGENT_STALL_RECOVERY_DECISION,
    aiUsageScope: AgentAIUsageScope::AI_AGENT_DECIDE_TURN,
    enforceBudget: true,
)]
class ArgusAgentStallRecoveryDecision extends ValueObject
{
    use ArgusTrait;
    use ArgusAILanguageModelTrait, ArgusAgentDecisionTrait {
        ArgusAgentDecisionTrait::getLoadPayloadInternal insteadof ArgusAILanguageModelTrait;
        ArgusAgentDecisionTrait::handleLoadResponseInternal insteadof ArgusAILanguageModelTrait;
    }

    public const string QUESTION_NEXT_MOVE = 'next_move';

    public const string STATE_STALL_SHAPE = 'stallShape';
    public const string STATE_NO_SKILL_LOADED = 'noSkillLoaded';
    public const string STATE_LAST_ASSISTANT_TEXT = 'lastAssistantText';
    public const string STATE_OWNER_MESSAGE = 'ownerMessage';
    public const string STATE_NEXT_PLAN_ITEM = 'nextPlanItem';
    public const string STATE_RESIDENT_TOOL_NAMES = 'residentToolNames';
    public const string STATE_LOADABLE_CAPABILITY_NAMES = 'loadableCapabilityNames';

    public const int MAX_TEXT_CHARS = 300;
    public const int MAX_OWNER_MESSAGE_CHARS = 600;
    public const int MAX_NAMES_IN_STATE = 30;

    /** @var string AgentStallRecoveryDecision::SHAPE_* of the stall. */
    public string $stallShape = AgentStallRecoveryDecision::SHAPE_EMPTY;

    public bool $noSkillLoaded = false;

    /** @var string What the stalled step produced ('' on an empty stall), capped. */
    public string $lastAssistantText = '';

    /** @var string The owner's message this turn, capped. */
    public string $ownerMessage = '';

    /** @var string The open plan item the turn is on ('' = none). */
    public string $nextPlanItem = '';

    /** @var string[] The work tools the model can call right now. */
    public array $residentToolNames = [];

    /** @var string[] The tools / skills the model could load (`load_tool` catalog + `load_skills` enum). */
    public array $loadableCapabilityNames = [];

    /** @var string[] The moves offered (AgentStallRecoveryDecision::MOVE_*), in order. */
    public array $offeredMoves = [];

    public ?string $move = null;
    public float $moveConfidence = 0.0;
    /** @var array<string, mixed> */
    public array $probabilities = [];

    /**
     * The one question, built over the OFFERED moves only. Pure and public so the unit test
     * can pin the rules.
     *
     * @param string[] $offeredMoves
     * @return array<string, array<string, mixed>>
     */
    public static function buildQuestions(array $offeredMoves): array
    {
        $criteria = [];
        foreach ($offeredMoves as $move) {
            $criteria[$move] = match ($move) {
                AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL => 'work in `' . self::STATE_NEXT_PLAN_ITEM . '` remains, a tool in `'
                    . self::STATE_RESIDENT_TOOL_NAMES . '` can do it now, and `' . self::STATE_OWNER_MESSAGE . '` already gives every input that '
                    . 'tool needs — the assistant only has to call it',
                AgentStallRecoveryDecision::MOVE_LOAD_REQUIRED_CAPABILITY => 'the work in `' . self::STATE_NEXT_PLAN_ITEM . '` is blocked ONLY '
                    . 'because the tool or skill it needs is not loaded yet, and an entry in `' . self::STATE_LOADABLE_CAPABILITY_NAMES
                    . '` is that capability — nothing in `' . self::STATE_RESIDENT_TOOL_NAMES . '` can do it',
                AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT => 'no tool in `' . self::STATE_RESIDENT_TOOL_NAMES . '` has work left in `'
                    . self::STATE_NEXT_PLAN_ITEM . '` it could do now, and the owed response can be written from what is at hand — '
                    . 'or the missing input, choice or consent can only come from the owner, so the assistant must answer now or ask for exactly that',
                default => 'none of the other moves clearly applies: `' . self::STATE_LAST_ASSISTANT_TEXT . '`, `' . self::STATE_OWNER_MESSAGE
                    . '` and `' . self::STATE_NEXT_PLAN_ITEM . '` do not show what is blocking the assistant',
            };
        }
        return [
            self::QUESTION_NEXT_MOVE => [
                'type' => 'choice',
                'instructions' => 'The assistant produced no usable output twice in a row (`' . self::STATE_STALL_SHAPE . '` says how: `empty` = '
                    . 'nothing at all; `proseAfterLoadSkills` = it loaded a skill and then only wrote prose; `preCallAnnounce` = it announced '
                    . 'an action and called nothing). Which ONE move gets it unstuck? Judge from `' . self::STATE_LAST_ASSISTANT_TEXT . '`, `'
                    . self::STATE_OWNER_MESSAGE . '`, `' . self::STATE_NEXT_PLAN_ITEM . '`, `' . self::STATE_RESIDENT_TOOL_NAMES . '` and `'
                    . self::STATE_LOADABLE_CAPABILITY_NAMES . '`. Whether a tool\'s inputs are really at hand is visible only in `'
                    . self::STATE_OWNER_MESSAGE . '`; do not assume them.',
                'criteria' => $criteria,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function buildState(): array
    {
        return [
            self::STATE_STALL_SHAPE => $this->stallShape,
            self::STATE_NO_SKILL_LOADED => $this->noSkillLoaded,
            self::STATE_LAST_ASSISTANT_TEXT => mb_substr($this->lastAssistantText, 0, self::MAX_TEXT_CHARS),
            self::STATE_OWNER_MESSAGE => mb_substr($this->ownerMessage, 0, self::MAX_OWNER_MESSAGE_CHARS),
            self::STATE_NEXT_PLAN_ITEM => $this->nextPlanItem,
            self::STATE_RESIDENT_TOOL_NAMES => array_values(array_slice($this->residentToolNames, 0, self::MAX_NAMES_IN_STATE)),
            self::STATE_LOADABLE_CAPABILITY_NAMES => array_values(array_slice($this->loadableCapabilityNames, 0, self::MAX_NAMES_IN_STATE)),
        ];
    }

    protected function buildQuestionsForRequest(): array
    {
        return self::buildQuestions($this->offeredMoves);
    }

    protected function applyAnswers(object $answers): bool
    {
        $nextMoveAnswer = $this->readChoiceWithProbabilitiesFrom(
            $answers,
            self::QUESTION_NEXT_MOVE,
            $this->offeredMoves,
            AgentStallRecoveryDecision::MOVE_NONE
        );
        $this->move = $nextMoveAnswer->choice;
        $this->moveConfidence = $nextMoveAnswer->confidence;
        $this->probabilities = $nextMoveAnswer->reportedProbabilities;
        return $this->move !== null;
    }
}
