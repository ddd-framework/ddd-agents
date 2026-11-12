<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationStatusReport;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `check_delegations` meta-tool: read-only status of the sub-agents
 * this conversation started — offered from its first `delegate` row on. Without arguments every sub-agent in a line;
 * with `subAgent` one sub-agent in full. The report is {@see DelegationStatusReport}, gathered by the dispatch.
 *
 * @phpstan-consistent-constructor
 */
class CheckDelegationsTool extends ValueObject implements AgentMetaTool
{
    public const string NAME = 'check_delegations';

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
     * The `check_delegations` answer: the status report the dispatch built from the sub-agent records
     * and the live look into the running children ({@see DelegationStatusReport::buildFor()}).
     */
    public static function answerCall(DelegationStatusReport $delegationStatusReport): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::completed($delegationStatusReport->toModelText());
    }

    /**
     * The sub-agent number the call asks about (`subAgent`), null for the overview — an integer or a numeric text,
     * `#` allowed.
     *
     * @param array<string, mixed> $arguments
     */
    public static function readSubAgentNumberFromArguments(array $arguments): ?int
    {
        $subAgentArgument = $arguments['subAgent'] ?? null;
        if (is_int($subAgentArgument)) {
            return $subAgentArgument > 0 ? $subAgentArgument : null;
        }
        if (is_string($subAgentArgument) && preg_match('/^\s*#?(\d+)\s*$/', $subAgentArgument, $numberMatch) === 1) {
            return (int)$numberMatch[1] > 0 ? (int)$numberMatch[1] : null;
        }
        return null;
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
                'description' => 'Show your sub-agents: each one\'s number, label, status (QUEUED, RUNNING, COMPLETED, '
                    . 'FAILED, TIMED_OUT, CANCELLED, NOT_RUN) and result, and what a running one is doing right now. Pass subAgent to see one sub-agent in '
                    . 'full (every run, its whole result, its last steps). Each sub-agent\'s end also arrives as a notification '
                    . 'by itself — no need to poll. Read-only.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'subAgent' => [
                            'type' => 'integer',
                            'description' => 'Optional: the number of one sub-agent (#N) to show in full.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }
}
