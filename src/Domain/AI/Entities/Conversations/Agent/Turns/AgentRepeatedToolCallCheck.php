<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallFailedReadStreak;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Whether one tool call repeats earlier work of the conversation (formerly asked separately
 * by the dispatch and by the fork admission, each building the same history reads): the identical call past the
 * per-turn guard, a read that already failed twice, a read the current turn already answered, a write already
 * persisted. Two callers, two questions, each in today's order:
 * - the dispatch asks {@see verdictForRepeatGuard()} first and the three findings later in its branch chain;
 * - the fork admission asks {@see wouldInterceptBeforeAppend()} before the call's row exists.
 * Every answer is read from the history when asked, never cached: the dispatch's branch order decides which reads run.
 *
 * @phpstan-consistent-constructor
 */
class AgentRepeatedToolCallCheck extends ValueObject
{
    /** Identical calls of one signature per turn before the corrective (the third) and the abort (the fourth). */
    public const int MAX_REPEATED_TOOL_CALLS = 3;

    public const string VERDICT_NONE = 'none';
    /** The identical call ran past the corrective: the turn aborts. */
    public const string VERDICT_ABORT = 'abort';
    /** The identical call reached the cap: it does not run, the model gets the corrective. */
    public const string VERDICT_CORRECTIVE = 'corrective';

    protected ToolCall $toolCall;

    protected AIConversation $conversation;

    public static function forToolCall(ToolCall $toolCall, AIConversation $conversation): static
    {
        $check = new static();
        $check->toolCall = $toolCall;
        $check->conversation = $conversation;
        return $check;
    }

    protected function getToolName(): string
    {
        return (string)($this->toolCall->name ?? '');
    }

    /** Identical calls (same name, same arguments) in the current turn; gate refusals never count. */
    public function countIdenticalCallsInTurn(): int
    {
        return $this->conversation->messages->buildCurrentTurn()->buildToolCallHistory()->countCallsWithSignature($this->toolCall->signature());
    }

    /** Identical calls of the current turn that FAILED. */
    public function countIdenticalFailedCallsInTurn(): int
    {
        return $this->conversation->messages->buildCurrentTurn()->buildToolCallHistory()->countFailedCallsWithSignature($this->toolCall->signature());
    }

    /** The tool declares #[MCPToolRefreshesState]: an identical call may report changed state. */
    public function toolRefreshesStateOnEveryCall(): bool
    {
        $toolName = $this->getToolName();
        if ($toolName === '') {
            return false;
        }
        $toolDefinition = $this->conversation->buildLoadedToolDefinitions()->getByName($toolName);
        return $toolDefinition !== null && $toolDefinition->refreshesStateOnEveryCall;
    }

    /**
     * Exempt from both repeat-guard branches: the status poll and the plan tools always, a state-refreshing tool and
     * the sub-agent wait while their identical calls succeed (MAX identical failures end the exemption).
     */
    public function isRepeatGuardExempt(): bool
    {
        return in_array($this->getToolName(), AgentMetaTools::REPEAT_GUARD_EXEMPT_NAMES, true)
            || (($this->toolRefreshesStateOnEveryCall() || in_array($this->getToolName(), AgentMetaTools::REPEAT_GUARD_EXEMPT_WHILE_SUCCEEDING_NAMES, true))
                && $this->countIdenticalFailedCallsInTurn() < self::MAX_REPEATED_TOOL_CALLS);
    }

    /**
     * The per-turn repeat guard as the dispatch sees it (the call's own row already appended, so it counts itself):
     * abort past the cap, the corrective at the cap, otherwise none.
     */
    public function verdictForRepeatGuard(): string
    {
        $repeatCount = $this->countIdenticalCallsInTurn();
        $isRepeatGuardExempt = $this->isRepeatGuardExempt();
        if ($repeatCount > self::MAX_REPEATED_TOOL_CALLS && !$isRepeatGuardExempt
            && !in_array($this->getToolName(), AgentMetaTools::REPEAT_ABORT_EXEMPT_NAMES, true)
        ) {
            return self::VERDICT_ABORT;
        }
        if ($repeatCount >= self::MAX_REPEATED_TOOL_CALLS && !$isRepeatGuardExempt) {
            return self::VERDICT_CORRECTIVE;
        }
        return self::VERDICT_NONE;
    }

    /**
     * The earlier call of the current turn with the same normalized arguments that saved NOTHING
     * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationToolCallHistory::getNewestEarlierUnsavedIdenticalCall()}):
     * the repeat gets the same answer, so it is not executed again. Null for an exempt or state-refreshing tool.
     */
    public function findEarlierUnsavedIdenticalCallInTurn(): ?ToolCall
    {
        if (!isset($this->conversation->messages) || $this->isRepeatGuardExempt() || $this->toolRefreshesStateOnEveryCall()) {
            return null;
        }
        return $this->conversation->messages->buildCurrentTurn()->buildToolCallHistory()->getNewestEarlierUnsavedIdenticalCall($this->toolCall);
    }

    /** Calls of the current turn with this call's normalized arguments that the loop already refused as repeats. */
    public function countRefusedRepeatsOfTheSameCallInTurn(): int
    {
        if (!isset($this->conversation->messages)) {
            return 0;
        }
        return $this->conversation->messages->buildCurrentTurn()->buildToolCallHistory()->countRefusedRepeatsWithNormalizedIdentityKey($this->toolCall);
    }

    /**
     * Whether the repeat guard already answered a `load_skills` call in an earlier model step of the current turn
     * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationTurnMessages::hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep()});
     * the checked call's own row is part of the newest step.
     */
    public function hasRepeatGuardAnsweredASkillLoadInAnEarlierStepOfTheTurn(): bool
    {
        if (!isset($this->conversation->messages)) {
            return false;
        }
        return $this->conversation->messages->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep();
    }

    /** The head of the earlier result quoted in {@see buildUnsavedRepeatCorrective()}. */
    public const int MAX_QUOTED_UNSAVED_RESULT_CHARS = 600;

    /**
     * The answer to a repeat of a call that saved nothing: NOT executed, nothing saved, the earlier result's own words
     * (they name the next move), and the two moves left — follow that result, or tell the owner plainly and end the
     * turn. Never "the work IS done" (after that sentence the model told the owner the goal was recorded).
     */
    public function buildUnsavedRepeatCorrective(ToolCall $earlierUnsavedCall): string
    {
        $earlierResult = trim($earlierUnsavedCall->getResultTextHead(self::MAX_QUOTED_UNSAVED_RESULT_CHARS));
        return sprintf(
            'NOT executed: `%s` with these arguments already ran in this turn and saved NOTHING — the same call gets the same '
            . 'answer. Its result: «%s» Nothing is saved, so never tell the owner it is. Your next move is the one that result '
            . 'names (different arguments, another step, or the question it names — then END YOUR TURN); if you cannot do it, '
            . 'tell the owner plainly what is missing and end your turn.',
            $this->getToolName(),
            $earlierResult
        );
    }

    /**
     * An identical read that already failed twice in the conversation (and nothing changed since): its failure count
     * and last failure text. Null for a state-refreshing tool.
     *
     * @return ToolCallFailedReadStreak|null
     */
    public function findRepeatedFailedRead(): ?ToolCallFailedReadStreak
    {
        if ($this->toolRefreshesStateOnEveryCall()) {
            return null;
        }
        return $this->conversation->messages->buildToolCallHistory()->getRepeatedFailedReadResult($this->toolCall);
    }

    /**
     * The identical read the current turn already ran whose result the wire still carries (clearing- and
     * picture-aware). Null for a state-refreshing tool.
     */
    public function findReusablePriorRead(): ?ToolCall
    {
        if ($this->toolRefreshesStateOnEveryCall()) {
            return null;
        }
        // clearing-aware: a prior result the wire already stubbed must not be refused as "still above".
        $toolResultRetention = $this->conversation->resolveToolResultRetention(array_values($this->conversation->messages->getElements()));
        return $this->conversation->messages->buildCurrentTurn()->buildToolCallHistory()->getReusablePriorReadCall(
            $this->toolCall,
            $toolResultRetention->fromIndex,
            // picture-aware: a prior result whose pictures no longer ride (cap-evicted, or inline bytes this
            // invocation never captured) is not "still above" either — the re-look must run.
            isset($this->conversation->contextRetentionSettings)
                ? $this->conversation->contextRetentionSettings->getMaxToolCallImagesOnWire()
                : ContextRetentionSettings::DEFAULT_MAX_TOOL_CALL_IMAGES_ON_WIRE,
            array_keys($this->conversation->getTransientToolCallImages()),
            $toolResultRetention->stubbedIndexes
        );
    }

    /** The index of the newest earlier COMPLETED write with byte-identical normalized arguments. */
    public function findEarlierIdenticalWriteIndex(): ?int
    {
        return $this->conversation->messages->buildToolCallHistory()->getEarlierCompletedIdenticalWriteIndex($this->toolCall);
    }

    /**
     * Whether the dispatch would answer this call WITHOUT executing it — asked by the fork admission BEFORE the
     * call's row is appended (the repeat count therefore counts one more). Today's order: no name, the repeat cap,
     * the identical write, a tool outside $loadedToolNames, the failed read, the reused read.
     *
     * @param string[] $loadedToolNames the newest loaded capabilities' tool names
     */
    public function wouldInterceptBeforeAppend(array $loadedToolNames): bool
    {
        $toolName = $this->getToolName();
        if ($toolName === '' || !isset($this->conversation->messages)) {
            return true;
        }
        if ($this->countIdenticalCallsInTurn() + 1 >= self::MAX_REPEATED_TOOL_CALLS) {
            return true;
        }
        if ($this->findEarlierIdenticalWriteIndex() !== null || $this->findEarlierUnsavedIdenticalCallInTurn() !== null) {
            return true;
        }
        if (!in_array($toolName, $loadedToolNames, true)) {
            return true;
        }
        return $this->findRepeatedFailedRead() !== null || $this->findReusablePriorRead() !== null;
    }
}
