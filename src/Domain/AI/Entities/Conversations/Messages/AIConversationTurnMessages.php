<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\GetTaskPlansTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadToolTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\WaitForSubAgentsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\FindWidgetsTool;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSpeaker;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One turn of a message set, its rows in set order, the initiator first — and the ~25 questions
 * the loop asks about it (model steps, cost, work calls, the plan-tick streaks, the bounce counts per chunk, the
 * obligations, the signals). Mostly the CURRENT turn, {@see AIConversationMessages::buildCurrentTurn()}: the rows from
 * the newest turn initiator on, the whole set when no initiator exists; a bounded turn comes from
 * {@see AIConversationMessages::buildTurnEndingAtMessageIndex()} or {@see AIConversationMessages::buildTurnStartingAtMessageIndex()}.
 * Built at each question; the loop appends rows between two questions, so a
 * view never outlives a row change. The index-scoped readers of the turn (the tool-written widgets, the owner report of
 * the run, the plan-bookkeeping texts) keep their own scoping on the set. The turn's tool-call
 * questions are asked on {@see buildToolCallHistory()}.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationTurnMessages extends ValueObject
{
    /** @var AIConversationMessage[] The turn's rows in set order, the initiator first when there is one. */
    protected array $rows = [];

    /** @param AIConversationMessage[] $rows */
    public static function fromRows(array $rows): static
    {
        $turnMessages = new static();
        $turnMessages->rows = $rows;
        return $turnMessages;
    }

    /**
     * The turn's rows (the initiator first) — for the platform's record of a turn ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentPausedTurnRecord}).
     *
     * @return AIConversationMessage[]
     */
    public function getRows(): array
    {
        return array_values($this->rows);
    }

    /** The current turn's initiator when it is a form widget's submit ({@see AIConversationMessages::isOwnerFormSubmitInitiator()}), else null. */
    public function getInitiatorWhenOwnerFormSubmit(): ?AIConversationMessage
    {
        $initiator = $this->getInitiatorMessage();
        return $initiator !== null && AIConversationMessages::isOwnerFormSubmitInitiator($initiator) ? $initiator : null;
    }

    /** The tool calls of this turn — the signature counts and the read reuse are turn-scoped. */
    public function buildToolCallHistory(): AIConversationToolCallHistory
    {
        return AIConversationToolCallHistory::fromRows($this->rows);
    }

    /**
     * The CURRENT turn's initiator message (the loop's own predicate), or null when no initiator exists in the loaded
     * set — the turn's first row when it is one (the slice starts at the newest initiator, so no later row is one).
     * Returns an index-less initiator too (AIConversationMessages::getNewestInitiatorMessage() skips one).
     */
    public function getInitiatorMessage(): ?AIConversationMessage
    {
        $initiator = null;
        foreach ($this->rows as $message) {
            if (AIConversationMessages::isTurnInitiatorMessage($message)) {
                $initiator = $message;
            }
        }
        return $initiator;
    }

    /** Whether the turn holds its initiator — a page read from the tail without one does not reach the start of its turn. */
    public function hasInitiatorMessage(): bool
    {
        return $this->getInitiatorMessage() !== null;
    }

    /** How many calls of the named tool the CURRENT turn carries (the `turnEnd` counts); COMPLETED-only when asked. */
    /** How many `findWidgets` calls of the turn loaded the skill of the widget they found. */
    public function countFindWidgetsCallsThatLoadedASkill(): int
    {
        $count = 0;
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if (($toolCall?->name) === FindWidgetsTool::NAME && $toolCall->skillNamesLoadedByTheCall !== []) {
                $count++;
            }
        }
        return $count;
    }

    public function countToolCallsNamed(string $toolName, bool $completedOnly = false): int
    {
        $count = 0;
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if (($toolCall?->name) !== $toolName) {
                continue;
            }
            if ($completedOnly && $toolCall->status !== AIConversationMessage::STATUS_COMPLETED) {
                continue;
            }
            $count++;
        }
        return $count;
    }

    /**
     * The newest COMPLETED call of the named tool in the CURRENT turn (the recorded askOwner question), null without
     * one. An OWNER inbound row inside the turn window (a widget click or edit, an action confirm — owner-authored
     * signals that drive a new turn without being turn initiators) ends the window the call is looked up in: a question
     * recorded BEFORE the owner's click was answered by that click. Without this cut the question widget of the
     * previous turn was rendered again under the turn that handled the owner's edit of another widget, and the owner
     * answered the same question twice.
     */
    public function getNewestCompletedToolCallNamedSinceOwnerInbound(string $toolName): ?ToolCall
    {
        $newest = null;
        $elements = $this->rows;
        foreach ($elements as $position => $message) {
            if ($position > 0 && $message->isOwnerAuthoredInbound()) {
                $newest = null;
                continue;
            }
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null && $toolCall->name === $toolName && $toolCall->status === AIConversationMessage::STATUS_COMPLETED) {
                $newest = $toolCall;
            }
        }
        return $newest;
    }

    /**
     * The written widget types of the TOOL and PLATFORM rows since the newest owner-authored inbound of this turn
     * (a `MESSAGE_TOOL_MESSAGE` row authored `AUTHOR_TOOL`) — the same cut as
     * {@see getNewestCompletedToolCallNamedSinceOwnerInbound()}. A widget click or an action confirm is owner-authored
     * but starts no turn: the widget the owner just answered sits before it and is no widget of the reply. The origin is the host row, never the block's `emittedBy`.
     *
     * @return string[]
     */
    public function findToolWrittenWidgetTypesSinceOwnerInbound(): array
    {
        $widgetTypes = [];
        foreach ($this->rows as $position => $message) {
            if ($position > 0 && $message->isOwnerAuthoredInbound()) {
                $widgetTypes = [];
                continue;
            }
            if ($message->messageType !== AIConversationMessage::MESSAGE_TOOL_MESSAGE
                || ($message->authorType ?? null) !== AIConversationMessage::AUTHOR_TOOL
            ) {
                continue;
            }
            foreach ($message->buildWidgetBlocks()->getWrittenWidgetTypes() as $widgetType) {
                $widgetTypes[] = $widgetType;
            }
        }
        return array_values(array_unique($widgetTypes));
    }

    /**
     * Whether a tool or platform row since the newest owner-authored inbound holds a widget that awaits the owner by
     * its content — a `ComposedView` with a `Form`
     * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks::hasClosedBlockWhoseContentAwaitsOwnerInput()}).
     * The same window and cut as {@see findToolWrittenWidgetTypesSinceOwnerInbound()}.
     */
    public function hasToolWrittenWidgetWhoseContentAwaitsOwnerInputSinceOwnerInbound(): bool
    {
        $awaitsOwnerInput = false;
        foreach ($this->rows as $position => $message) {
            if ($position > 0 && $message->isOwnerAuthoredInbound()) {
                $awaitsOwnerInput = false;
                continue;
            }
            if ($message->messageType === AIConversationMessage::MESSAGE_TOOL_MESSAGE
                && ($message->authorType ?? null) === AIConversationMessage::AUTHOR_TOOL
                && $message->buildWidgetBlocks()->hasClosedBlockWhoseContentAwaitsOwnerInput()
            ) {
                $awaitsOwnerInput = true;
            }
        }
        return $awaitsOwnerInput;
    }

    /**
     * The ROW of the newest COMPLETED call of the named tool in the CURRENT turn — the same window and the same cut at an
     * owner inbound as {@see getNewestCompletedToolCallNamedSinceOwnerInbound()}, but the message row itself: its id is a stable
     * identity of the call (the `ToolCall` value object carries none). A purpose that relays a question keys its claim
     * on it: the widget rendered from the call is presentation only — not
     * written under an empty final text, re-rendered under a later final text of the same window.
     */
    public function getNewestCompletedToolCallRowNamedSinceOwnerInbound(string $toolName): ?AIConversationMessage
    {
        $newestRow = null;
        foreach ($this->rows as $position => $message) {
            if ($position > 0 && $message->isOwnerAuthoredInbound()) {
                $newestRow = null;
                continue;
            }
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null && $toolCall->name === $toolName && $toolCall->status === AIConversationMessage::STATUS_COMPLETED) {
                $newestRow = $message;
            }
        }
        return $newestRow;
    }

    /** Whether the CURRENT turn already carries a signal row of the given source (an idempotence guard for turn-start signals). */
    public function hasSignalOfSource(string $signalSource): bool
    {
        foreach ($this->rows as $message) {
            if (($message->signalSource ?? null) === $signalSource) {
                return true;
            }
        }
        return false;
    }

    /** How many LOOP_NUDGE signals the CURRENT turn carries. */
    public function countLoopNudges(): int
    {
        $count = 0;
        foreach ($this->rows as $message) {
            if (($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Model-call STEPS the CURRENT turn has consumed so far, ACROSS all of its chunked worker invocations — derived, no stored counter: every step's model call stamps its usage onto a persisted message (the
     * assistant turn via appendTurn, a stalled/yielded step via its usage-stamped SIGNAL), so "messages of the
     * current turn with measured input tokens" counts the turn's model calls crash/park/chunk-safe. (A forced
     * tool_choice re-drive overwrites its stalled first response — that inner call is deliberately not counted, the
     * step is one unit of budget.)
     */
    public function countModelCallSteps(): int
    {
        $stepCount = 0;
        foreach ($this->rows as $message) {
            if (($message->inputTokens ?? 0) > 0) {
                $stepCount++;
            }
        }
        return $stepCount;
    }

    /**
     * The CURRENT turn's total model spend in USD across all of its chunked invocations — the Σ the
     * `maxTurnCostUsd` cap is enforced against: the turn's OWN model calls (per-message {@see AIConversationMessage::$costInUSD})
     * PLUS what its tool calls booked while they ran ({@see ToolCall::$executionCosts}) PLUS the delegation children's spend carried in the turn's `delegate` calls' settle slices
     * ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildDelegationResultSlice()} — enforceable at FOLD time only; the children already
     * ran). A model call with measured tokens but NO reported cost is estimated at
     * {@see AIConversationMessages::ESTIMATED_USD_PER_MILLION_TOKENS_FALLBACK}. Pure fold, crash/park/chunk-safe by derivation.
     */
    public function getCostInUSD(): float
    {
        $turnCostInUSD = 0.0;
        foreach ($this->rows as $message) {
            $messageCostInUSD = $message->costInUSD;
            $inputTokens = $message->inputTokens ?? 0;
            $outputTokens = $message->outputTokens ?? 0;
            if ($messageCostInUSD <= 0.0 && ($inputTokens > 0 || $outputTokens > 0)) {
                $messageCostInUSD = ($inputTokens + $outputTokens) / 1_000_000 * AIConversationMessages::ESTIMATED_USD_PER_MILLION_TOKENS_FALLBACK;
            }
            $turnCostInUSD += $messageCostInUSD;
            // What the row's tool call booked outside the model call (a search fee, a page reading) — never part of the
            // token estimate above, which stands in for the MODEL cost the provider did not report
            $turnCostInUSD += isset($message->toolCall) ? $message->toolCall->getExecutionCostInUSD() : 0.0;
            // Child spend: each settled child's slice carries its costInUSD (delegate calls live in the same turn as
            // their fold — the initiator is unchanged across the park/resume). Older slices carry none.
            $delegationResultSlices = isset($message->delegationResults) ? $message->delegationResults->results : [];
            foreach ($delegationResultSlices as $delegationResultSlice) {
                $turnCostInUSD += $delegationResultSlice->costInUSD ?? 0.0;
            }
            // a sub-agent row's runs carry their child's spend once they ended
            $turnCostInUSD += isset($message->delegationResults) ? $message->delegationResults->getSubAgentRunsCostInUsd() : 0.0;
        }
        return round($turnCostInUSD, 6);
    }

    /**
     * Whether a REAL work tool (anything except the load_skills / delegate meta-tools) has COMPLETED in the
     * CURRENT turn — the derived replacement for `$workToolRan` of the earlier per-thread run object (not today's
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRun}) (the per-turn
     * scope mirrors the old fresh-run-per-turn semantics). Once true, a no-tool prose turn is a final answer.
     * FAILED calls deliberately do NOT count: a model whose only work calls errored has produced nothing —
     * the forced-tool-choice stall breaker must stay armed so an "I will retry in a moment" prose turn gets
     * forced back into an actual call instead of ending the turn (observed live after a tool TypeError). The stall
     * classification itself reads {@see hasWorkToolCallThatRanOrFailed()} (a refused call is
     * the platform's answer, not a model that did nothing); this fold stays for the turn-closing decision.
     */
    public function hasCompletedWorkToolCall(): bool
    {
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if (self::isWorkToolName($toolCall?->name)
                && $toolCall->status === AIConversationMessage::STATUS_COMPLETED
                && !$toolCall->wasRefusedAsDuplicate
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the turn CALLED a work tool (the names of {@see hasCompletedWorkToolCall()}) that completed, or that the
     * platform refused or that failed (status FAILED — a read-only run, a missing mandate, an error of the tool): the
     * model used the skill it loaded. The stall guard reads it, so a prose answer about a refused call is no
     * prose-after-load-skills stall. A call refused as a duplicate of an earlier one ran nothing new and does not count.
     */
    public function hasWorkToolCallThatRanOrFailed(): bool
    {
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if (self::isWorkToolName($toolCall?->name)
                && in_array($toolCall->status, [AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_FAILED], true)
                && !$toolCall->wasRefusedAsDuplicate
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The turn's work-tool call when it is the ONLY one since the owner's message and its result dictates a widget block
     * without a condition ({@see ToolCall::$dictatedWidgetType}) — null otherwise. The same window and cut at an owner
     * inbound as {@see getNewestCompletedToolCallNamedSinceOwnerInbound()}; a failed or refused call dictates nothing.
     * With two or more work calls (parallel reads of an overview) which one is newest is arbitrary, and the answer may
     * rightly show none of their blocks. The recorded `askOwner` answer names its block ({@see AskOwnerTool::buildRecordedResult()}).
     */
    public function findOnlyWorkToolCallDictatingAWidgetSinceOwnerInbound(): ?ToolCall
    {
        $newestWorkToolCall = null;
        $workToolCallCount = 0;
        foreach ($this->rows as $position => $message) {
            if ($position > 0 && $message->isOwnerAuthoredInbound()) {
                $newestWorkToolCall = null;
                $workToolCallCount = 0;
                continue;
            }
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if (self::isWorkToolName($toolCall?->name) && !$toolCall->wasRefusedAsDuplicate) {
                $newestWorkToolCall = $toolCall;
                $workToolCallCount++;
            }
        }
        if ($newestWorkToolCall === null || $workToolCallCount !== 1
            || $newestWorkToolCall->status !== AIConversationMessage::STATUS_COMPLETED
            || ($newestWorkToolCall->dictatedWidgetType ?? null) === null
        ) {
            return null;
        }
        return $newestWorkToolCall;
    }

    /**
     * {@see findOnlyWorkToolCallDictatingAWidgetSinceOwnerInbound()} when no LOOP_NUDGE followed that call — null
     * otherwise. The DICTATED-BLOCK-MISSING stall reads it
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStallClassification}): a nudge after the call
     * means the shape already had its one recovery for this result.
     */
    public function findNewestWorkToolCallDictatingAWidgetWithoutNudgeSince(): ?ToolCall
    {
        $dictatingToolCall = $this->findOnlyWorkToolCallDictatingAWidgetSinceOwnerInbound();
        if ($dictatingToolCall === null) {
            return null;
        }
        $afterDictatingToolCall = false;
        foreach ($this->rows as $message) {
            if (isset($message->toolCall) && $message->toolCall === $dictatingToolCall) {
                $afterDictatingToolCall = true;
                continue;
            }
            if ($afterDictatingToolCall && ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE) {
                return null;
            }
        }
        return $dictatingToolCall;
    }

    /**
     * The turn's NEWEST tool call when it is a `delegate` call that started nothing (FAILED — refused by the spawn,
     * a malformed task shape — or refused by a gate or as a duplicate) and no LOOP_NUDGE followed it — null otherwise.
     * The same window and cut at an owner inbound as {@see findNewestWorkToolCallDictatingAWidgetWithoutNudgeSince()};
     * any tool call after the delegate ends it. The DICTATED-BLOCK-MISSING stall reads it
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStallClassification}): a prose answer right after a
     * delegation that did not start is the intro line without the block the model meant to write itself.
     */
    public function findNewestToolCallWhenADelegateThatStartedNothingWithoutNudgeSince(): ?ToolCall
    {
        $newestToolCall = null;
        $nudgedSinceNewestToolCall = false;
        foreach ($this->rows as $position => $message) {
            if ($position > 0 && $message->isOwnerAuthoredInbound()) {
                $newestToolCall = null;
                $nudgedSinceNewestToolCall = false;
                continue;
            }
            if (($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE) {
                $nudgedSinceNewestToolCall = true;
                continue;
            }
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null) {
                $newestToolCall = $toolCall;
                $nudgedSinceNewestToolCall = false;
            }
        }
        if ($newestToolCall === null || $nudgedSinceNewestToolCall || $newestToolCall->name !== DelegateTool::NAME) {
            return null;
        }
        $startedNothing = $newestToolCall->status === AIConversationMessage::STATUS_FAILED
            || $newestToolCall->wasRefusedByGate
            || $newestToolCall->wasRefusedAsDuplicate;
        return $startedNothing ? $newestToolCall : null;
    }

    /** Whether a call of this tool is WORK: not a skill load, a delegation, a plan tool, a question or a widget search. */
    protected static function isWorkToolName(?string $toolName): bool
    {
        return $toolName !== null && $toolName !== LoadSkillsTool::NAME && $toolName !== DelegateTool::NAME
            && $toolName !== GetTaskPlansTool::NAME // a META read of the loop's own state
            && $toolName !== AskOwnerTool::NAME // a recorded question is no work
            && $toolName !== WaitForSubAgentsTool::NAME // the end of a turn without a text is no work
            && $toolName !== FindWidgetsTool::NAME // a widget catalog search is no work
            && !in_array($toolName, TaskPlan::PLAN_TOOL_NAMES, true);
    }

    /**
     * Whether an EARLIER `set_task_status` call of the CURRENT turn (completed or failed) already named this item — the
     * second tick of one item in a turn is a loop, the first an echo of what the model saw. `$taskAddress` is the item's
     * KEY (the tick tool passes the key its strict address check resolved); an earlier update names the item
     * when its address reads as that key. A call the address check REJECTED as a whole ({@see ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED})
     * named nothing: nothing of it applied, so its corrected retry is still the first tick of the item.
     */
    public function hasTaskPlanTickNamingTask(string $taskAddress): bool
    {
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall?->name !== SetTaskStatusTool::NAME
                || !in_array($toolCall->status, [AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_FAILED], true)
                || $toolCall->taskPlanCallOutcome === ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED
            ) {
                continue;
            }
            if (SetTaskStatusArguments::fromToolArguments($toolCall->arguments)->hasUpdateNamingTaskAddress($taskAddress)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an EARLIER `set_task_status` call already FAILED in the CURRENT turn — the escalation signal for
     * the all-no-op tick breaker: the FIRST redundant/rejected tick in a turn gets corrective feedback and the
     * turn continues (there may be perfectly workable open plan items left — ending the turn there is the
     * announce-instead-of-do defect), but a SECOND failed tick in the same turn means
     * the model is fighting the plan instead of working it, and the loop forces the tool-less closing step.
     * A call the address check REJECTED as a whole ({@see ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED}) is no failed tick:
     * it ticked nothing, and its corrected retry must not meet the brake. Pure fold over persisted tool-call statuses
     * and outcomes — no free-text matching.
     */
    public function hasFailedTaskPlanTick(): bool
    {
        return $this->countFailedTaskPlanTicks() > 0;
    }

    /** How many `set_task_status` calls of this turn failed (a refused call excluded) — the re-tick loop's length. */
    public function countFailedTaskPlanTicks(): int
    {
        $failedTickCount = 0;
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall?->name === SetTaskStatusTool::NAME
                && $toolCall->status === AIConversationMessage::STATUS_FAILED
                && $toolCall->taskPlanCallOutcome !== ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED
            ) {
                $failedTickCount++;
            }
        }
        return $failedTickCount;
    }

    /**
     * Whether the model ran `load_skills` in the CURRENT turn — i.e. it signalled, THIS turn, the intent to do data
     * work. This is the discriminator that arms the forced-tool-choice stall breaker (see
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService} step loop): "loaded a skill then replied
     * in prose without using a tool" is a genuine stall; a prose-only reply on a turn where NO skill was loaded (the
     * skills were carried forward from earlier) is a legitimate CONVERSATIONAL answer ("here's a joke", "my opinion is
     * …") and must NOT be forced into a spurious work-tool call. Per-turn scope mirrors {@see hasCompletedWorkToolCall()}.
     * A `load_skills` call that loaded nothing ({@see ToolCall::$loadSkillsCallLoadedNothing}, the "Already loaded"
     * answer) is no load: the skills were carried forward, so the prose after it is a reply (both strategies).
     * A load call the repeat guard answered instead of running ({@see ToolCall::$wasRefusedAsDuplicate}) is no load
     * either: the guard forces a tool-less answer step after it, and counted as a load that very answer was nudged as
     * a stall — the model loaded again, was answered by the guard again, and the turn ran to its step budget.
     */
    public function hasSkillOrToolLoadCall(): bool
    {
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if (($toolCall?->name) === LoadSkillsTool::NAME && $toolCall->loadSkillsCallLoadedNothing) {
                continue;
            }
            if ($toolCall !== null && $toolCall->wasRefusedAsDuplicate) {
                continue;
            }
            // a `load_tool` call is the same signal: it may bring a sub-skill with the tool; so is a `findWidgets` call
            // that loaded the skill of the widget it found
            if (in_array($toolCall?->name, [LoadSkillsTool::NAME, LoadToolTool::NAME], true)
                || (($toolCall?->name) === FindWidgetsTool::NAME && $toolCall->skillNamesLoadedByTheCall !== [])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the CURRENT turn contains a COMPLETED call of the named tool — the mechanical "did this turn
     * actually run X successfully" check (a purpose's final-answer guard reads it: it appends the link to a
     * result only on a turn that really produced it). Per-turn scope mirrors
     * {@see hasSkillOrToolLoadCall()}.
     */
    public function hasCompletedToolCallNamed(string $toolName): bool
    {
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null && $toolCall->name === $toolName
                && $toolCall->status === AIConversationMessage::STATUS_COMPLETED) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an `update_task_plan` call of the CURRENT turn changed the plan — its recorded outcome
     * ({@see ToolCall::readTaskPlanCallOutcome()}) is an applied one. A refused call and a no-op resend changed nothing.
     */
    public function hasAppliedTaskPlanUpdateCall(): bool
    {
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall?->name === UpdateTaskPlanTool::NAME
                && in_array($toolCall->readTaskPlanCallOutcome(), [ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_AS_FULL_REPLACE], true)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * How many plan-tool calls (`set_task_status` / `update_task_plan`) the model fired IN A ROW at the end of the
     * current turn that were all REJECTED (tool status FAILED — a gate refused every update). Any other tool call, a
     * completed plan call or an assistant text ends the streak. In one run, fourteen consecutive
     * rejected ticks burned the whole step budget while the stage work stood still — the loop drops the plan tools
     * from the offered schemas once the streak reaches its cap, so the only way forward is the work itself. A call the
     * address check rejected as a whole (`refused`) counts: a model that keeps sending addresses that are not keys
     * spends steps exactly like one fighting a gate; one rejection and a corrected retry never reach the cap. A call the
     * step held back on the wire ({@see ToolCall::$wasWithheldOnStep}) neither counts nor ends the streak: it is the
     * brake's own refusal, and counting it re-armed the brake on every step.
     */
    public function countTrailingRejectedPlanToolCalls(): int
    {
        $streak = 0;
        foreach (array_reverse($this->rows) as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall === null) {
                if ($message->isAssistantText()) {
                    break;
                }
                continue; // signals / nudges between the ticks do not end the streak
            }
            if ($toolCall->wasWithheldOnStep) {
                continue;
            }
            if (!in_array($toolCall->name ?? null, TaskPlan::PLAN_TOOL_NAMES, true)
                || ($toolCall->status ?? null) !== AIConversationMessage::STATUS_FAILED
            ) {
                break;
            }
            $streak++;
        }
        return $streak;
    }

    /**
     * ALL rejected plan-tool calls of the current turn, streak or not — the model interleaves a status
     * read between its rejected ticks (many rejections in one turn, never three in a row), so the
     * trailing streak alone never reached its cap. The loop suppresses the plan tools for the rest of the turn once
     * this count reaches {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::MAX_REJECTED_PLAN_TICKS_PER_TURN}.
     * A call the step held back on the wire ({@see ToolCall::$wasWithheldOnStep}) is the brake's own refusal and does not count.
     */
    public function countRejectedPlanToolCalls(): int
    {
        $count = 0;
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null
                && !$toolCall->wasWithheldOnStep
                && in_array($toolCall->name ?? null, TaskPlan::PLAN_TOOL_NAMES, true)
                && ($toolCall->status ?? null) === AIConversationMessage::STATUS_FAILED
            ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Whether a legacy DELEGATIONS_SETTLED signal sits in this turn — the turn that reads the answers of a delegation
     * stored before sub-agent records existed (legacy readers only; goes once no such rows remain).
     */
    public function hasDelegationsSettledSignal(): bool
    {
        foreach ($this->rows as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_DELEGATIONS_SETTLED
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether this turn reads its sub-agents' results — the stall recovery, the checked stop and the turn-closing
     * decision treat it alike: opened by sub-agent notifications only
     * ({@see isOpenedBySubAgentNotificationsOnly()}), or carrying a legacy DELEGATIONS_SETTLED signal
     * ({@see hasDelegationsSettledSignal()}, the delegations stored before sub-agent records existed).
     */
    public function readsSubAgentResults(): bool
    {
        return $this->isOpenedBySubAgentNotificationsOnly() || $this->hasDelegationsSettledSignal();
    }

    /**
     * Whether the newest stretch of this turn was opened by sub-agent notifications alone: the
     * inbound rows that arrived after the last turn end (a final assistant text, or a completed `wait_for_sub_agents`
     * call) and before the next model call are run-end notifications only — at least one that resumes the conversation
     * (`SUB_AGENT_RUN_ENDED`), the rest of that source or `SUB_AGENT_RUN_ENDED_WITHOUT_RESUME` — with no owner row, no
     * click, no other signal among them. The loop's replay of a run end counts as the run end it replays
     * (`SUB_AGENT_RUN_ENDED_REPLAY`, `…_WITHOUT_RESUME_REPLAY`); the generic replay does not. A notification is no turn initiator, so the rows run from the owner's turn on;
     * the inbound block that opened the newest stretch is what is asked. The loop's own rows (nudges, bounces,
     * bookkeeping, the purpose's turn-start notice) and the markers the owed-work walks see through neither open nor
     * spoil it. A notification handed over in the middle of a turn (below model output) opens nothing. Pure.
     */
    public function isOpenedBySubAgentNotificationsOnly(): bool
    {
        $openingInboundRows = $this->findInboundRowsOpeningTheNewestStretch();
        if ($openingInboundRows === []) {
            return false;
        }
        $hasRunEndThatResumes = false;
        foreach ($openingInboundRows as $openingInboundRow) {
            $signalSource = ($openingInboundRow->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL ? ($openingInboundRow->signalSource ?? null) : null;
            if ($signalSource === AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED
                || $signalSource === AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_REPLAY
            ) {
                $hasRunEndThatResumes = true;
                continue;
            }
            if ($signalSource !== AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME
                && $signalSource !== AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME_REPLAY
            ) {
                return false;
            }
        }
        return $hasRunEndThatResumes;
    }

    /**
     * Whether a model call ran since the newest turn end (a TOOL_CALL row, or a row carrying a model call's usage) — false
     * at the head of a stretch, before its first model call: the short wait of a notification-started turn runs only
     * there, never in a chunk continuation of the same turn. Pure.
     */
    public function hasModelCallSinceTheNewestTurnEnd(): bool
    {
        $modelCallSinceTurnEnd = false;
        foreach ($this->rows as $message) {
            if ($message->isAssistantText() || $message->isTurnEndWithoutTextCall()) {
                $modelCallSinceTurnEnd = false;
                continue;
            }
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL || ($message->inputTokens ?? 0) > 0) {
                $modelCallSinceTurnEnd = true;
            }
        }
        return $modelCallSinceTurnEnd;
    }

    /**
     * The inbound rows that opened the newest stretch of this turn, in order: the rows after the newest turn end (the
     * start of the rows counts as one) up to the first row of model work after it; empty when no inbound row followed
     * the newest turn end. A row of model work is a TOOL_CALL row or a row that carries a model call's usage (a nudge,
     * a bounce); a turn end is an assistant TEXT or a completed `wait_for_sub_agents` call.
     *
     * @return AIConversationMessage[]
     */
    protected function findInboundRowsOpeningTheNewestStretch(): array
    {
        $openingInboundRows = [];
        $inboundRowsAfterTurnEnd = [];
        $afterTurnEnd = true;
        foreach ($this->rows as $message) {
            if ($message->isAssistantText() || $message->isTurnEndWithoutTextCall()) {
                $afterTurnEnd = true;
                $inboundRowsAfterTurnEnd = [];
                continue;
            }
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL || ($message->inputTokens ?? 0) > 0) {
                if ($afterTurnEnd && $inboundRowsAfterTurnEnd !== []) {
                    $openingInboundRows = $inboundRowsAfterTurnEnd;
                }
                $afterTurnEnd = false;
                $inboundRowsAfterTurnEnd = [];
                continue;
            }
            if ($afterTurnEnd && self::opensAStretch($message)) {
                $inboundRowsAfterTurnEnd[] = $message;
            }
        }
        if ($afterTurnEnd && $inboundRowsAfterTurnEnd !== []) {
            $openingInboundRows = $inboundRowsAfterTurnEnd;
        }
        return $openingInboundRows;
    }

    /**
     * Whether a row that is neither model output nor a turn end is inbound that opens a stretch of work: a user-role row,
     * or a signal that owes work and is not the loop's own note — except the loop notes that quote the owner's rows or
     * replay a signal (they carry inbound). The rows the owed-work walks see through (call markers, tool messages, the
     * platform's widget notice, a resolved supervisor note, bookkeeping) and the purpose's turn-start notice
     * (PURPOSE_CONSEQUENCE) open nothing.
     */
    protected static function opensAStretch(AIConversationMessage $message): bool
    {
        $messageType = $message->messageType ?? null;
        if ($messageType === AIConversationMessage::MESSAGE_ERROR || $messageType === AIConversationMessage::MESSAGE_CHECKPOINT
            || $message->isPhoneCallLifecycleSignal() || $message->isToolMessage() || $message->isPlatformWidgetEmittedSignal()
            || $message->isResolvedSupervisorNote() || $message->isRepeatedTextSuppressedSignal()
        ) {
            return false;
        }
        if ($messageType !== AIConversationMessage::MESSAGE_SIGNAL) {
            return ($message->role ?? null) === AIConversationMessage::ROLE_USER;
        }
        $signalSource = $message->getSignalSource();
        if ($signalSource === null) {
            return true;
        }
        if (!$signalSource->owesWork() || ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_PURPOSE_CONSEQUENCE) {
            return false;
        }
        if ($signalSource->getSpeaker() === ConversationSignalSpeaker::LOOP) {
            return $message->isMidTurnReplay()
                || ($signalSource instanceof AIConversationSignalSource && $signalSource->quotesOwnerRows());
        }
        return true;
    }

    /**
     * The OPEN tool-declared obligations of the current turn, keyed by tool name (pure, derived from the persisted
     * tool-call messages so it survives yields and resumes): a tool result whose `structuredContent.turnObligation` is
     * a non-empty string OPENS (or replaces) the obligation of that tool; an empty string CLEARS it. A later successful
     * call of the same tool therefore clears the block an earlier blocked one declared.
     *
     * @return array<string, string> toolName → obligation text
     */
    public function getOpenToolObligationsByToolName(): array
    {
        $openObligations = [];
        foreach ($this->rows as $message) {
            if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_TOOL_CALL) {
                continue;
            }
            $toolCall = $message->toolCall ?? null;
            if ($toolCall === null || ($toolCall->name ?? '') === '') {
                continue;
            }
            $structuredContent = $toolCall->getDecodedResult()->structuredContent;
            if ($structuredContent === null || !array_key_exists('turnObligation', $structuredContent)) {
                continue;
            }
            $obligation = $structuredContent['turnObligation'];
            if (is_string($obligation) && trim($obligation) !== '') {
                $openObligations[(string)$toolCall->name] = trim($obligation);
            } else {
                unset($openObligations[(string)$toolCall->name]);
            }
        }
        return $openObligations;
    }

    /** The turn-obligation bounces in the current CHUNK — same per-chunk discipline as {@see countTaskPlanOpenBouncesInCurrentChunk()}. */
    public function countTurnObligationBouncesInCurrentChunk(): int
    {
        $bounceCount = 0;
        foreach ($this->rows as $message) {
            if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_SIGNAL) {
                continue;
            }
            $signalSource = $message->signalSource ?? null;
            if ($signalSource === AIConversationMessage::SIGNAL_SOURCE_TURN_CONTINUATION) {
                $bounceCount = 0;
            } elseif ($signalSource === AIConversationMessage::SIGNAL_SOURCE_TURN_OBLIGATION_BOUNCE) {
                $bounceCount++;
            }
        }
        return $bounceCount;
    }

    /**
     * How many CHECKED-STOP bounce signals ({@see AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE})
     * the CURRENT CHUNK already carries — the bounce-cap's derivation: bounces since the last
     * {@see AIConversationMessage::SIGNAL_SOURCE_TURN_CONTINUATION} yield within the current turn (or since the
     * turn's initiator when no yield happened). PER-CHUNK, not per-turn (a model stopped
     * prematurely a second time ~14 steps after an accepted per-TURN bounce — one bounce per turn is too weak for
     * multi-chunk runs); still hard-bounded (≤ 1 bounce per chunk, chunk count bounded by `maxStepsPerTurn`), and
     * deliberately NOT countTrailingConsecutiveNudges, whose trailing run every bounced final text would reset.
     * Chunk scope survives a crash/resume: bounce and yield signals are persisted messages, so the derivation
     * reconstructs identically.
     */
    public function countTaskPlanOpenBouncesInCurrentChunk(): int
    {
        $bounceCount = 0;
        foreach ($this->rows as $message) {
            if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_SIGNAL) {
                continue;
            }
            $signalSource = $message->signalSource ?? null;
            if ($signalSource === AIConversationMessage::SIGNAL_SOURCE_TURN_CONTINUATION) {
                $bounceCount = 0; // a new chunk begins — its bounce budget resets
            } elseif ($message->getSignalSource()?->isTaskPlanOpenBounce() === true) {
                $bounceCount++; // both triggers share ONE chunk budget
            }
        }
        return $bounceCount;
    }

    /**
     * How many checked-stop bounces the DECISION MODEL caused in the CURRENT TURN — across its chunks
     * (under `decisionModel`, the decision model judges a turn's text once; a later chunk of the same turn never re-judges it). Derived
     * over the persisted signals, so it survives a crash / resume.
     */
    public function countDecisionBounces(): int
    {
        $count = 0;
        foreach ($this->rows as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE_DECISION
            ) {
                $count++;
            }
        }
        return $count;
    }

    /** How many `INJECTION_REMOVED` re-drives the current owner turn already spent (the bound is one). */
    public function countInjectionRemovedSignals(): int
    {
        $signalCount = 0;
        foreach ($this->rows as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_INJECTION_REMOVED
            ) {
                $signalCount++;
            }
        }
        return $signalCount;
    }

    /**
     * The call messages of the current owner turn whose result screening is still `pending` in memory —
     * the only rows the loop has to re-read when a candidate response comes back.
     *
     * @return AIConversationMessage[]
     */
    public function getCallRowsWithPendingInjectionScreening(): array
    {
        $pendingCallMessages = [];
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null && $toolCall->injectionScreening === ToolCall::INJECTION_SCREENING_PENDING && isset($message->id)) {
                $pendingCallMessages[] = $message;
            }
        }
        return $pendingCallMessages;
    }

    /** MID_TURN_OWNER_INBOUND notes appended in the current chunk (reset by a TURN_CONTINUATION) — the freshness retry's cap. */
    public function countMidTurnOwnerInboundNotesInCurrentChunk(): int
    {
        $noteCount = 0;
        foreach ($this->rows as $message) {
            if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_SIGNAL) {
                continue;
            }
            $signalSource = $message->signalSource ?? null;
            if ($signalSource === AIConversationMessage::SIGNAL_SOURCE_TURN_CONTINUATION) {
                $noteCount = 0;
            } elseif ($signalSource === AIConversationMessage::SIGNAL_SOURCE_MID_TURN_OWNER_INBOUND) {
                $noteCount++;
            }
        }
        return $noteCount;
    }
}
