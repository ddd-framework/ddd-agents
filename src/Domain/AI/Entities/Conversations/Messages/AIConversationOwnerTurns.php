<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentTurn;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The owner's turns over a given row order: the owner texts, the window lists and the turn
 * counts. Built by {@see AIConversationMessages::buildOwnerTurns()} over the set order and by
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildOwnerTurns()} over the index-sorted rows — each
 * question keeps the order and the predicate it had. The predicates still differ (recorded in
 * a test of the application): the windows open at an owner or legacy user
 * row, the turn count between initiators uses the loop's initiator predicate, three "work tool" lists coexist, one
 * window list drops the rows before the first owner row while the other keeps them as a turn without initiator, and the
 * two builders read two orders (set order vs index-sorted — a set loaded through the plain lazy relation can yield
 * different windows). Reads nothing; never outlives a row change.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationOwnerTurns extends ValueObject
{
    /** @var AIConversationMessage[] The rows in the order the questions read them; the keys are kept. */
    protected array $rows = [];

    /** @param AIConversationMessage[] $rows */
    public static function fromRows(array $rows): static
    {
        $ownerTurns = new static();
        $ownerTurns->rows = $rows;
        return $ownerTurns;
    }

    /**
     * The message TEXTS the USER genuinely wrote, in row order — the owner or legacy user rows (ROLE_USER authored by
     * AUTHOR_USER or legacy null; the loop's nudges/signals are AUTHOR_AGENT and never counted). Empty texts are
     * skipped. A purpose's interface-language confirmation reads count + concatenation from this (its trigger +
     * signal floor).
     *
     * @return string[]
     */
    public function getOwnerTexts(): array
    {
        $userAuthoredMessageTexts = [];
        foreach ($this->rows as $message) {
            $isUserAuthored = $message->isOwnerOrLegacyUserRoleRow();
            if (!$isUserAuthored) {
                continue;
            }
            $messageText = trim((string)($message->message ?? ''));
            if ($messageText !== '') {
                $userAuthoredMessageTexts[] = $messageText;
            }
        }
        return $userAuthoredMessageTexts;
    }

    /**
     * The conversational context the turn-start decision sites read (the language, plan-dynamics and preload
     * questions): what the assistant said last before `$beforeMessageIndex` and the last `$maxTurns` owner /
     * assistant texts before it, each cut to `$maxChars`. Pre-call lines and signals are not turns.
     * `$collapseWhitespace` folds every whitespace run of a turn into one space (the skill preload sends each turn as
     * one line).
     *
     * @param int $beforeMessageIndex
     * @param int $maxTurns
     * @param int $maxChars
     * @param bool $collapseWhitespace
     * @return AIConversationRecentTurnTexts
     */
    public function buildRecentTurnTextsBefore(int $beforeMessageIndex, int $maxTurns = 6, int $maxChars = 300, bool $collapseWhitespace = false): AIConversationRecentTurnTexts
    {
        $assistantTextBefore = '';
        $recentTurns = [];
        foreach ($this->rows as $message) {
            $messageIndex = $message->indexInConversation ?? PHP_INT_MAX;
            if ($messageIndex >= $beforeMessageIndex || ($message->messageType ?? null) !== AIConversationMessage::MESSAGE_TEXT) {
                continue;
            }
            $text = trim((string)($message->message ?? ''));
            if ($text === '' || str_starts_with($text, AIConversationMessage::PRE_CALL_MESSAGE_MARKER)) {
                continue;
            }
            $turnText = $collapseWhitespace ? (preg_replace('/\s+/', ' ', $text) ?? $text) : $text;
            if ($message->isAssistantAuthoredText()) {
                $assistantTextBefore = $text;
                $recentTurns[] = 'assistant: ' . mb_substr($turnText, 0, $maxChars);
            } elseif ($message->isOwnerOrLegacyUserRoleRow()) {
                $recentTurns[] = 'owner: ' . mb_substr($turnText, 0, $maxChars);
            }
        }
        return AIConversationRecentTurnTexts::forAssistantTextAndRecentTurns($assistantTextBefore, array_slice($recentTurns, -$maxTurns));
    }

    /**
     * The owner's most recent OWN words: the LAST `$maxWords` words across at most the last `$maxMessages`
     * owner-authored texts (default: the NEWEST message only — a language read from an earlier turn must never pass
     * as what the owner writes NOW; continuity across turns is the decision's sticky anchor), newest
     * last, joined by newlines. Nothing else — no tool output, no signals, no agent text — so the decision model judges
     * only what the owner typed. Same predicate as {@see getOwnerTexts()}. '' when the owner has not written anything
     * yet (turn 1, a signal-started run), which the caller reads as "no decision call at all".
     */
    public function buildRecentOwnerWords(int $maxWords = 40, int $maxMessages = 1): string
    {
        $userAuthoredMessageTexts = $this->getOwnerTexts();
        if ($userAuthoredMessageTexts === []) {
            return '';
        }
        $recentTexts = array_slice($userAuthoredMessageTexts, -max(1, $maxMessages));
        $words = preg_split('/\s+/u', trim(implode("\n", $recentTexts))) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
        if ($words === []) {
            return '';
        }
        return implode(' ', array_slice($words, -max(1, $maxWords)));
    }

    /**
     * COMPLETED turns since `$sinceInitiatorIndex`: the turn initiators strictly between that index and
     * `$currentInitiatorIndex` — the current turn does not count until it is over. A null stamp, or a stamp below the
     * loaded tail (a fold covered it), counts every loaded initiator before the current one: the capability is AT
     * LEAST that old (the tail is bounded, the estimate errs towards "kept"). Reads the loop's initiator predicate
     * ({@see AIConversationMessages::isTurnInitiatorMessage()}: a turn-starting signal counts), not the owner rows the
     * windows open at (one of the recorded predicate differences).
     */
    public function countTurnsBetweenInitiators(?int $sinceInitiatorIndex, ?int $currentInitiatorIndex): int
    {
        $count = 0;
        foreach ($this->rows as $message) {
            if (!AIConversationMessages::isTurnInitiatorMessage($message)) {
                continue;
            }
            $index = $message->indexInConversation ?? null;
            if ($index === null) {
                continue;
            }
            if ($currentInitiatorIndex !== null && $index >= $currentInitiatorIndex) {
                continue;
            }
            if ($sinceInitiatorIndex !== null && $index <= $sinceInitiatorIndex) {
                continue;
            }
            $count++;
        }
        return $count;
    }

    /**
     * The count of owner or legacy user rows so far — the turn-staleness unit for the eviction recency axis:
     * one per user-initiated turn; a multi-step turn's
     * load_skills / tool calls / texts all share it. Derived (no stored counter) → resume-safe. Matches how the
     * simulator calibrates `unload*AfterUnusedTurns`.
     */
    public function countOwnerTurns(): int
    {
        $count = 0;
        foreach ($this->rows as $message) {
            $isUserInitiator = $message->isOwnerOrLegacyUserRoleRow();
            if ($isUserInitiator) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * The user-bounded TURNS over the rows: one pass, slicing at every owner or legacy user row
     * (nudges are `AUTHOR_AGENT` and never boundaries); the rows before the first one form a turn without an
     * initiating message. Delegation child turns are attached by the conversation
     * ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildAgentTurns()}).
     *
     * @return AgentTurn[]
     */
    public function buildAgentTurns(): array
    {
        $turns = [];
        $currentTurn = null;
        foreach ($this->rows as $message) {
            $isInitiator = $message->isOwnerOrLegacyUserRoleRow();
            if ($isInitiator || $currentTurn === null) {
                $currentTurn = new AgentTurn();
                $currentTurn->initiatingMessage = $isInitiator ? $message : null;
                $turns[] = $currentTurn;
            }
            $currentTurn->messages[] = $message;
        }
        return $turns;
    }

    /**
     * The positions (row keys) of the owner rows that merely CONTINUE a paused run — an owner text whose nearest
     * preceding assistant text is the step-budget pause report ({@see AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT}).
     * Such a "continue" is no new owner turn for the retention window: counting it evicted the research results of the
     * paused turn, and the resumed run repeated the research it had already done. Reads the rows in the order given
     * (the AgentModelInput's index-sorted messages, or a set's order).
     *
     * @return array<int|string, true> position → true
     */
    public function getOwnerContinuePositionsAfterPauseReports(): array
    {
        $continuePositions = [];
        $newestAssistantTextIsPauseReport = false;
        foreach ($this->rows as $position => $message) {
            if ($message->isAssistantText()) {
                $newestAssistantTextIsPauseReport = $message->getSignalSource()?->marksAnOwnerReport() === true;
                continue;
            }
            if ($message->isOwnerOrLegacyUserRoleRow()
                && $newestAssistantTextIsPauseReport
            ) {
                $continuePositions[$position] = true;
                $newestAssistantTextIsPauseReport = false;
            }
        }
        return $continuePositions;
    }
}
