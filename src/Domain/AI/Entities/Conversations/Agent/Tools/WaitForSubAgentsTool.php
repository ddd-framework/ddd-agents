<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `wait_for_sub_agents` meta tool: the way a turn that sub-agent notifications
 * started ends WITHOUT a text while other sub-agents still run. No required argument. Offered only to a root conversation, only
 * on a turn opened by sub-agent notifications alone (no owner row, no other signal since the last answer) and only while
 * a sub-agent had an unfinished run at the turn's start ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedTools::buildFor()}).
 * A completed call ends the turn after its step ({@see AgentAfterStepRequest::endTurnWithoutText()}): no assistant text,
 * no owner-facing message; the next run end's notification resumes the conversation. The tool never waits itself — it
 * ends the turn, and the notifier is what resumes it.
 *
 * @phpstan-consistent-constructor
 */
class WaitForSubAgentsTool extends ValueObject implements AgentMetaTool
{
    public const string NAME = 'wait_for_sub_agents';

    /** Neither a read nor a write ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog}). */
    public const ?string DECLARED_EFFECT = null;

    /** How many unfinished sub-agents the answer names by number; the rest are counted. */
    public const int NAMED_SUB_AGENTS_LIMIT = 8;

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
     * The answer to a call: COMPLETED with the sub-agents it waits for when the turn was opened by
     * sub-agent notifications only and a sub-agent still runs — the call then ends the turn. Refused (FAILED) on a turn
     * the owner (or any other inbound) started: the owner waits for an answer. Refused with a tool-less answer step when
     * nothing runs any more: no notification will come, so the turn must answer now. Refused with a tool-less answer step,
     * before anything else, when the owner wrote while the turn ran (`$freshOwnerInboundNoteText`: the note quoting
     * their rows) — the owner is never waited past.
     */
    public static function answerCall(
        bool $turnOpenedBySubAgentNotificationsOnly,
        bool $hasUnfinishedSubAgentRun,
        DelegationSubAgentRecords $unfinishedSubAgentRecords,
        ?string $freshOwnerInboundNoteText = null,
        ?string $declaredNextStep = null
    ): AgentMetaToolCallAnswer {
        if ($freshOwnerInboundNoteText !== null) {
            // the owner wrote while this turn ran (their message's resume met the held claim): this turn answers them
            return AgentMetaToolCallAnswer::failed(
                "Not done: the owner wrote while you worked — this turn answers them now.\n" . $freshOwnerInboundNoteText
            )->withToolLessAnswerStep();
        }
        if (!$turnOpenedBySubAgentNotificationsOnly) {
            return AgentMetaToolCallAnswer::failed(
                'Not done: wait_for_sub_agents ends only a turn that a sub-agent notification started. This turn answers '
                . 'the owner — answer them now (say in ONE sentence what is still running).'
            );
        }
        if (!$hasUnfinishedSubAgentRun && $declaredNextStep !== null) {
            // the requesting tool named what comes next (a research run's close, a build's rebuild): that step may need a
            // tool, so it is named and no tool-less answer is forced (a research root wrote its summary instead of closing
            // the research)
            return AgentMetaToolCallAnswer::failed('Not done: no sub-agent is running any more — every result is in the notifications above. ' . $declaredNextStep);
        }
        if (!$hasUnfinishedSubAgentRun) {
            return AgentMetaToolCallAnswer::failed(
                'Not done: no sub-agent is running any more — every result is in the notifications above. Answer the owner now.'
            )->withToolLessAnswerStep();
        }
        return AgentMetaToolCallAnswer::completed(self::buildWaitingText($unfinishedSubAgentRecords));
    }

    /**
     * "Waiting for sub-agents #4, #5 — each one's end arrives as a notification." The numbered unfinished sub-agents in
     * order, at most {@see NAMED_SUB_AGENTS_LIMIT} named; without a numbered one (a legacy delegation) no number is named.
     */
    public static function buildWaitingText(DelegationSubAgentRecords $unfinishedSubAgentRecords): string
    {
        $subAgentNumbers = [];
        foreach ($unfinishedSubAgentRecords->getNumbered()->getElements() as $unfinishedSubAgentRecord) {
            $subAgentNumbers[] = '#' . $unfinishedSubAgentRecord->number;
        }
        if ($subAgentNumbers === []) {
            return 'Waiting for your sub-agents — each one\'s end arrives as a notification.';
        }
        $namedNumbers = array_slice($subAgentNumbers, 0, self::NAMED_SUB_AGENTS_LIMIT);
        $countedRest = count($subAgentNumbers) - count($namedNumbers);
        return 'Waiting for ' . (count($subAgentNumbers) === 1 ? 'sub-agent ' : 'sub-agents ') . implode(', ', $namedNumbers)
            . ($countedRest > 0 ? ' and ' . $countedRest . ' more' : '')
            . ' — each one\'s end arrives as a notification.';
    }

    /** A completed call ends the turn after its step; a refused one changes nothing. */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return ($toolCall->status ?? null) === AIConversationMessage::STATUS_COMPLETED ? AgentAfterStepRequest::endTurnWithoutText() : null;
    }

    /** No work request: the dispatch gathers the facts and the tool answers its call itself. */
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
                'description' => 'End this turn WITHOUT a message and wait for your other sub-agents. Use it only when '
                    . 'nothing in the notifications that started this turn is worth telling the owner yet and no work is '
                    . 'left that does not need the running sub-agents. Each sub-agent\'s end arrives as its own notification '
                    . 'and starts your next turn. Never instead of an answer the owner is waiting for.',
                'parameters' => [
                    'type' => 'object',
                    // one optional property: an object schema without properties is refused by a model of the
                    // catalog; the call needs no argument
                    'properties' => [
                        'reason' => [
                            'type' => 'string',
                            'description' => 'Optional: what you wait for, in a few words (never shown to the owner).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }
}
