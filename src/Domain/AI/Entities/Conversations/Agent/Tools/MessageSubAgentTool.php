<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `message_sub_agent` meta tool: the orchestrator writes to one of its sub-agents by number. A sub-agent
 * whose run has not ended reads the message at its next step (an `ORCHESTRATOR_MESSAGE` signal into the child); a
 * sub-agent whose run ended continues with it as a new run, whose end arrives as its own notification. There is no limit
 * on runs; the same message to the same sub-agent twice in one turn is refused. Offered beside
 * `check_delegations` from the conversation's first `delegate` row on, never to a sub-agent. The dispatch gathers and
 * writes ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService::messageSubAgent()});
 * the answers are built here.
 *
 * @phpstan-consistent-constructor
 */
class MessageSubAgentTool extends ValueObject implements AgentMetaTool
{
    public const string NAME = 'message_sub_agent';

    /**
     * Neither a read nor a write of the space's data ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog}).
     */
    public const ?string DECLARED_EFFECT = null;

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
    }

    public static function create(): static
    {
        return new static();
    }

    /** No sub-agent carries the number. */
    public static function answerUnknownSubAgent(int $subAgentNumber): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::failed('No sub-agent #' . $subAgentNumber . ' in this conversation — check_delegations lists them.');
    }

    /** The sub-agent takes no messages (a legacy one, one that never started, a tool run) — the record says why. */
    public static function answerRefusedByRecord(string $refusalText): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::failed($refusalText);
    }

    /** The same message went to the same sub-agent earlier in this turn. */
    public static function answerRepeatedInTurn(int $subAgentNumber): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::failed('You already sent this message to sub-agent #' . $subAgentNumber . ' in this turn.');
    }

    /** The child of the sub-agent cannot be reached from this run (a conversation that was never stored). */
    public static function answerUnreachable(int $subAgentNumber): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::failed('Sub-agent #' . $subAgentNumber . ' cannot be reached from this run — start a new delegate call with the full task.');
    }

    /**
     * The message reached a run that has not ended: a running sub-agent reads it at its next step, a queued one when it
     * starts.
     */
    public static function answerDelivered(DelegationSubAgentRecord $delegationSubAgentRecord): AgentMetaToolCallAnswer
    {
        $currentRun = $delegationSubAgentRecord->getCurrentRun();
        if ($currentRun !== null && $currentRun->isQueued()) {
            return AgentMetaToolCallAnswer::completed('Delivered to sub-agent #' . $delegationSubAgentRecord->number . ' (queued) — it reads it when it starts.');
        }
        return AgentMetaToolCallAnswer::completed('Delivered to sub-agent #' . $delegationSubAgentRecord->number . ' (running) — it reads it at its next step.');
    }

    /** The ended sub-agent continues with the message as run `$runNumber`, queued for a slot or already admitted. */
    public static function answerContinued(int $subAgentNumber, int $runNumber, bool $runIsAdmitted): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::completed(
            'Sub-agent #' . $subAgentNumber . ' continues (run ' . $runNumber . ', ' . ($runIsAdmitted ? 'running' : 'queued') . '). '
            . 'Its end arrives as a notification.'
        );
    }

    /** The sync path ran the continuation inline: run `$runNumber` has already ended; its notification follows. */
    public static function answerContinuedInline(DelegationSubAgentRecord $delegationSubAgentRecord): AgentMetaToolCallAnswer
    {
        $endedRun = $delegationSubAgentRecord->getCurrentRun();
        $endState = (string)($endedRun?->status ?? DelegationSubAgentRun::STATUS_FAILED);
        if ($endedRun?->failureReason !== null) {
            $endState .= ' (' . $endedRun->failureReason . ')';
        }
        return AgentMetaToolCallAnswer::completed(
            'Sub-agent #' . $delegationSubAgentRecord->number . ' continued (run ' . $delegationSubAgentRecord->currentRunNumber . ') and ended '
            . $endState . '. Its result follows as a notification.'
        );
    }

    /**
     * Nothing was written: the sub-agent's state changed before the message was sent (the sync path decides the run
     * before it writes the message).
     */
    public static function answerNotTakenUp(int $subAgentNumber): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::failed(
            'Not taken up: sub-agent #' . $subAgentNumber . ' changed its state before the message was sent. check_delegations shows it now; send the message again if it is still needed.'
        );
    }

    /**
     * The message is in the sub-agent's conversation, but neither a running run nor a new run took it up (the sub-agent
     * changed its state meanwhile): its next run reads it. Never "send again" — that would be a second copy.
     */
    public static function answerKeptForTheNextRun(int $subAgentNumber): AgentMetaToolCallAnswer
    {
        return AgentMetaToolCallAnswer::completed(
            'Sub-agent #' . $subAgentNumber . ' changed its state while the message was sent: the message is kept and its next run reads it. '
            . 'Do not send it again; check_delegations shows its state.'
        );
    }

    /**
     * Whether the same message went to the same sub-agent in an earlier call of `$toolCallRows` (the current turn's
     * call rows) that was not refused — the current call itself is skipped.
     *
     * @param AIConversationMessage[] $toolCallRows
     */
    public static function wasSentEarlierInTurn(ToolCall $currentToolCall, MessageSubAgentArguments $messageSubAgentArguments, array $toolCallRows): bool
    {
        foreach ($toolCallRows as $toolCallRow) {
            $earlierToolCall = isset($toolCallRow->toolCall) ? $toolCallRow->toolCall : null;
            if ($earlierToolCall === null || $earlierToolCall === $currentToolCall || ($earlierToolCall->name ?? null) !== self::NAME
                || ($earlierToolCall->toolCallId ?? null) === ($currentToolCall->toolCallId ?? null)
                || ($earlierToolCall->status ?? null) !== AIConversationMessage::STATUS_COMPLETED
            ) {
                continue;
            }
            if (MessageSubAgentArguments::fromToolCallArguments($earlierToolCall->arguments)->isSameMessageAs($messageSubAgentArguments)) {
                return true;
            }
        }
        return false;
    }

    /** Nothing for the loop to do after this tool's call. */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return null;
    }

    /** No work request: the dispatch gathers, writes and the tool builds the answer. */
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
                'description' => 'Send a message to one of your sub-agents by its number (#N). A running sub-agent reads it '
                    . 'at its next step — add a constraint or correct its task. A sub-agent that has ended continues with it '
                    . 'as a new run that keeps everything it did before; that run\'s end arrives as a notification. Prefer it '
                    . 'over a new delegate call when the sub-agent already holds the context. Tool-run sub-agents take no messages.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'subAgent' => [
                            'type' => 'integer',
                            'description' => 'The number of the sub-agent (#N) its delegate call answered with.',
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'What the sub-agent should do or know (at most ' . MessageSubAgentArguments::MESSAGE_MAX_LENGTH . ' characters).',
                        ],
                    ],
                    'required' => ['subAgent', 'message'],
                ],
            ],
        ];
    }
}
