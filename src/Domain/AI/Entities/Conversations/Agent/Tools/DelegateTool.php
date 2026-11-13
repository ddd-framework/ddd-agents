<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `delegate` meta-tool offered to the orchestrator: delegate a self-contained task to an isolated sub-agent
 * (a CHILD conversation). Its `skill_names` parameter (the skills the sub-agent preloads) is enum-constrained to the live catalog.
 * `toOpenAiToolSchema()` emits the OpenAI function-tool envelope — the wire shape lives here, not as a hand-built array in the loop.
 *
 * @phpstan-consistent-constructor
 */
class DelegateTool extends ValueObject implements AgentSeededPlanRefusingMetaTool
{
    public const string NAME = 'delegate';

    /** Neither a read nor a write for the stored-call readers. */
    public const ?string DECLARED_EFFECT = null;

    /** The skill catalog whose names constrain the preload `skill_names` enum. */
    protected AISkills $catalog;

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
    }

    public static function forCatalog(AISkills $catalog): static
    {
        $tool = new static();
        $tool->catalog = $catalog;
        return $tool;
    }

    /**
     * In a seeded-plan purpose the platform runs the background work itself (the detections and
     * warm-ups of the purpose's tools, as mechanical children); a delegation of the model's own
     * costs a turn to read the results and a model call for work the platform has already covered. Only
     * the closing act's self-contained packages (what the handover step prepares) may be delegated.
     */
    public static function answerSeededPurposeRefusal(): AgentMetaToolCallAnswer
    {
        $agentApplicationWording = AgentApplicationWording::current();
        $backgroundWorkExamples = $agentApplicationWording->seededPlanBackgroundWorkExamples !== ''
            ? ' (' . $agentApplicationWording->seededPlanBackgroundWorkExamples . ')'
            : '';
        return AgentMetaToolCallAnswer::completed(
            'Not delegated: in this ' . $agentApplicationWording->seededPlanConversationName . ' the platform already runs the '
            . 'background work' . $backgroundWorkExamples . ' and the acts are your own mid-dialogue work. Continue with the '
            . 'next plan item yourself; delegate only a self-contained package of the closing act.'
        );
    }

    /**
     * A conversation that may delegate refuses the call under a purpose-seeded plan outside the closing act — with or
     * without a task plan ({@see answerSeededPurposeRefusal()}). The plan-rewrite refusal of {@see UpdateTaskPlanTool}
     * needs a plan; the two conditions differ.
     */
    public static function findSeededPlanRefusal(AgentSeededPlanPurposeFacts $agentSeededPlanPurposeFacts): ?AgentMetaToolCallAnswer
    {
        if ($agentSeededPlanPurposeFacts->conversationMayDelegate
            && $agentSeededPlanPurposeFacts->purposeSeedsItsTaskPlan
            && !$agentSeededPlanPurposeFacts->nextPlanItemIsInClosingAct
        ) {
            return self::answerSeededPurposeRefusal();
        }
        return null;
    }

    /** Nothing for the loop to do after this tool's call. */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return null;
    }

    /** No work request: the tool answers its call itself (`delegate` still spawns through the loop). */
    public static function workRequest(ToolCall $toolCall): ?AgentToolCallWorkRequest
    {
        return null;
    }

    public function getToolName(): string
    {
        return self::NAME;
    }

    /**
     * The OpenAI function-tool envelope: ONE call starts ONE sub-agent — an agent task (`task` +
     * `skill_names`) or one call of an offered tool (`toolName` + `toolArguments`, the arguments as a JSON object text so
     * every provider of the catalog accepts the schema). Several calls in one response run sub-agents in parallel.
     *
     * @return array<string, mixed>
     */
    public function toOpenAiToolSchema(): array
    {
        $names = [];
        foreach ($this->catalog->getElements() as $skill) {
            $names[] = $skill->name;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Start ONE sub-agent for ONE self-contained task. It runs in its own context (it cannot see '
                    . 'this conversation — put everything it needs in the task), cannot delegate further and returns only its '
                    . 'result. To run several sub-agents in PARALLEL, call delegate several times in the SAME response, one '
                    . 'call per sub-agent. The call answers at once with the sub-agent\'s number (#N); its result arrives later '
                    . 'as a notification when it ends. Meanwhile do the work that does not need it; with nothing left, answer '
                    . 'with what you have and say in ONE sentence what is still running — never an empty answer, never a '
                    . 'result that has not arrived. '
                    . 'check_delegations shows what your sub-agents are doing; message_sub_agent sends one of them a message '
                    . '(a running one reads it at its next step, a finished one continues with it). Give EITHER `task` (with `skill_names`) for '
                    . 'agent work, OR `toolName` + `toolArguments` to run ONE call of a tool offered to you in the background '
                    . '(a call that names `toolName` runs exactly that tool call; a `task` text beside it is not read). '
                    . 'Delegate for parallel or long work; a single quick lookup, a dependent step or data you need raw is '
                    . 'faster inline.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'label' => [
                            'type' => 'string',
                            'description' => 'A short name for the sub-agent (at most 40 characters), shown with its number.',
                        ],
                        'task' => [
                            'type' => 'string',
                            'description' => 'The self-contained task of an agent sub-agent (include all context it needs).',
                        ],
                        'skill_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => $names],
                            'description' => 'Skill names to preload in the agent sub-agent. It starts with NONE of your '
                                . 'loaded skills — name what the task needs, or it has no work tools.',
                        ],
                        'toolName' => [
                            'type' => 'string',
                            'description' => 'Instead of a task: the ONE tool the sub-agent calls (a tool offered to you now).',
                        ],
                        'toolArguments' => [
                            'type' => 'string',
                            'description' => 'With toolName: the tool\'s arguments as a JSON object, e.g. {"url": "https://example.com"}.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }
}
