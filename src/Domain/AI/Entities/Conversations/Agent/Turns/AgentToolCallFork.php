<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use Symfony\Component\Process\Process;

/**
 * The parallel-safe tool calls of ONE model response that run at once, each in its own child process
 * (the fork-join). The loop opens one fork per step and drops it with
 * the step, so everything the fork records — the calls' rows, the children's processes, leases, message ids and
 * timeouts, the inspector row — ends with the step. {@see \DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService}
 * starts, joins and stops the children of a fork; this object holds what one step knows about them. A plain mutable
 * runtime object, never persisted.
 *
 * @phpstan-consistent-constructor
 */
class AgentToolCallFork
{
    /** @var ToolCall[] the forked calls, in the order the model emitted them */
    public array $toolCalls = [];

    /** The id every call of the response shares (the step's first call id), the key of the inspector row. */
    public string $emittedBatchId = '';

    /** @var array<string, true> the forked calls by id — decided at selection, before any child starts */
    public array $forkedToolCallIds = [];

    /** @var AIConversationMessage[] the PENDING call row of each forked call, keyed by tool call id */
    public array $callMessagesByToolCallId = [];

    /** @var array<string, int> the message id of each forked call's row */
    public array $callMessageIdsByToolCallId = [];

    /** @var array<string, int> the tool's own execution ceiling per forked call */
    public array $declaredTimeoutSecondsByToolCallId = [];

    /**
     * True while the children wait for the inline calls of the response: an inline call may WRITE, so the children start
     * only at the first forked call of the processing order.
     */
    public bool $childrenStartDeferredBehindInlineWrites = false;

    /** @var Process[] the started children not yet joined or stopped, keyed by tool call id */
    public array $processesByToolCallId = [];

    /** @var array<string, string> the lease each child was started with */
    public array $leasesByToolCallId = [];

    /** @var array<string, int> the message row of each started child */
    public array $messageIdsByToolCallId = [];

    /** @var array<string, int> the tool timeout each started child runs under */
    public array $timeoutSecondsByToolCallId = [];

    /**
     * True once every started child exited: the loop joins the whole fork before it adopts and persists the first
     * forked outcome — a parent persist re-encodes the later rows of the step, and a child still writing one of them
     * made the parent's write fail with 1020 "Record has changed since last read".
     */
    public bool $allChildrenExited = false;

    /** @var array<string, string> the failure text of a child whose wait timed out or failed, kept for its adoption */
    public array $waitFailureTextByToolCallId = [];

    /** The id of this fork's inspector row ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AIConversationToolCallBatch}); null until written and once finished. */
    public ?int $toolCallBatchId = null;

    /**
     * The fork of `$forkableToolCalls`, calls of the response `$emittedBatchId` names.
     *
     * @param ToolCall[] $forkableToolCalls
     */
    public static function forToolCallsOfEmittedBatch(array $forkableToolCalls, string $emittedBatchId): static
    {
        $agentToolCallFork = new static();
        $agentToolCallFork->toolCalls = $forkableToolCalls;
        $agentToolCallFork->emittedBatchId = $emittedBatchId;
        foreach ($forkableToolCalls as $forkableToolCall) {
            $agentToolCallFork->forkedToolCallIds[(string)$forkableToolCall->toolCallId] = true;
        }
        return $agentToolCallFork;
    }

    /** Whether `$toolCall` is one of the forked calls. */
    public function isForkedToolCall(ToolCall $toolCall): bool
    {
        return isset($this->forkedToolCallIds[(string)($toolCall->toolCallId ?? '')]);
    }

    /** Whether the child of `$toolCallId` was started and is not yet joined or stopped. */
    public function hasUnjoinedChildFor(string $toolCallId): bool
    {
        return isset($this->processesByToolCallId[$toolCallId]);
    }

    /**
     * The calls of the response in the order the loop visits them: the inline calls first (they overlap with the
     * children), the forked calls after; the order of the calls of one response carries no meaning (the model emitted
     * them together).
     *
     * @param ToolCall[] $emittedToolCalls
     * @return ToolCall[]
     */
    public function orderInlineCallsBeforeForkedCalls(array $emittedToolCalls): array
    {
        $inlineToolCalls = [];
        $forkedToolCalls = [];
        foreach ($emittedToolCalls as $orderedToolCall) {
            if (isset($this->forkedToolCallIds[(string)($orderedToolCall->toolCallId ?? '')])) {
                $forkedToolCalls[] = $orderedToolCall;
            } else {
                $inlineToolCalls[] = $orderedToolCall;
            }
        }
        return array_merge($inlineToolCalls, $forkedToolCalls);
    }
}
