<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlans;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `get_task_plans` meta-tool: the FINISHED plans of this conversation, on
 * demand — the list (newest first, one line each with the outcome counts) or ONE plan with every item and its
 * status note. Offered only while the conversation holds at least one finished plan AND the plan-dynamics site
 * is `on` (a byte-identical tool surface otherwise). Loop classification = META like the plan tools: never a work
 * tool, never a write. Read-only, no repo — the finished set lives on the working state, which is never compacted,
 * so "what did I finish?" survives a compaction.
 *
 * @phpstan-consistent-constructor
 */
class GetTaskPlansTool extends ValueObject implements AgentMetaTool
{
    public const string NAME = 'get_task_plans';

    /** The effect the stored-call readers see ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog}). */
    public const ?string DECLARED_EFFECT = MCPToolEffect::READ;

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
    }

    public static function create(): static
    {
        return new static();
    }

    /**
     * the finished plans on demand — a META read of the loop's own state, deterministic.
     *
     * @param array<string, mixed> $arguments
     */
    public static function answerCall(array $arguments, AIConversation $conversation): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::completed(self::buildResult($arguments, $conversation->getAgentWorkingState()?->completedTaskPlans));
    }

    /** Nothing for the loop to do after this tool's call. */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return null;
    }

    /** No work request: the tool answers its call itself. */
    public static function workRequest(ToolCall $toolCall): ?AgentToolCallWorkRequest
    {
        return null;
    }

    public function getToolName(): string
    {
        return self::NAME;
    }

    /** @return array<string, mixed> the OpenAI function-tool wire entry */
    public function toOpenAiToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Read the task plans you already FINISHED in this conversation (newest first) — to '
                    . 'report what was done earlier, or to see how an earlier job ended. Without a number: the list, '
                    . 'one line per plan. With a number from that list: that plan with every item, status and note. '
                    . 'Your ACTIVE plan is always shown to you; never call this for it.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'planNumber' => [
                            'type' => 'integer',
                            'description' => 'The number from the list (1 = the newest finished plan). Omit for the list.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The deterministic tool result: the list, or one plan's detail; a number outside the list names the range.
     *
     * @param array<string, mixed> $arguments
     */
    public static function buildResult(array $arguments, ?TaskPlans $finishedPlans): string
    {
        if ($finishedPlans === null || $finishedPlans->count() === 0) {
            return 'No finished task plan exists in this conversation yet.';
        }
        $rawNumber = $arguments['planNumber'] ?? $arguments['plan_number'] ?? $arguments['number'] ?? null;
        if ($rawNumber === null || $rawNumber === '') {
            return "Finished task plans (newest first):\n" . $finishedPlans->toFinishedListText();
        }
        $numbered = $finishedPlans->newestFirstNumbered();
        $number = is_numeric($rawNumber) ? (int)$rawNumber : 0;
        if (!isset($numbered[$number])) {
            return sprintf('No finished plan #%s — use a number from 1 to %d.', (string)$rawNumber, count($numbered));
        }
        return $numbered[$number]->toFinishedDetailText($number);
    }
}
