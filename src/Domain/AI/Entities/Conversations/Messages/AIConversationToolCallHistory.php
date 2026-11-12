<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\CheckDelegationsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\FindWidgetsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The tool calls of a row set: the repeat, duplicate, identical-write and failed-read scans,
 * the newest resolved call by name, the owner-action confirmations and the eval counts. Built over ALL rows, not the
 * tool-call rows only — several questions read signals and initiators beside the calls (the confirm signals, the
 * turn start of the identical-write scan). Built by {@see AIConversationMessages::buildToolCallHistory()} over the
 * set order, and by {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildToolCallHistory()} over the
 * index-sorted rows (the delegation scans), and by {@see AIConversationTurnMessages::buildToolCallHistory()} over the
 * current turn — the signature counts and the read reuse are asked there ("the CURRENT turn" in their docblocks is that
 * builder's scope). Reads nothing; never outlives a row change.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationToolCallHistory extends ValueObject
{
    /** @var AIConversationMessage[] The rows in set order. */
    protected array $rows = [];

    /** @param AIConversationMessage[] $rows */
    public static function fromRows(array $rows): static
    {
        $toolCallHistory = new static();
        $toolCallHistory->rows = $rows;
        return $toolCallHistory;
    }

    /**
     * The line prefix under which a page read names the social profile URLs the platform verified on the page; the
     * application's page renderer writes the line with it, {@see getPlatformVerifiedSocialProfileUrls()} reads it.
     */
    public const string SOCIAL_PROFILE_LINE_PREFIX = 'Platform-verified social profile URLs on this page: ';

    /** Identical FAILED reads tolerated before the next identical call is refused (one retry for a transient failure). */
    public const int MAX_IDENTICAL_FAILED_READS = 2;

    /**
     * Tool-call messages in conversation order. The trace's structured actions: each carries `toolName`,
     * `toolArguments`, `toolResult`, `status`, and (for `load_skills`) the `loadedSkills` it pulled in.
     *
     * @return AIConversationMessage[]
     */
    public function getToolCallRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (AIConversationMessage $m): bool => $m->messageType === AIConversationMessage::MESSAGE_TOOL_CALL
        ));
    }

    /**
     * Names of every tool the agent called, in order (incl. `load_skills`).
     *
     * @return string[]
     */
    public function getCalledToolNames(): array
    {
        return array_map(
            static fn (AIConversationMessage $m): string => (string)(isset($m->toolCall) ? $m->toolCall : null)?->name,
            $this->getToolCallRows()
        );
    }

    /** Total tool calls (incl. `load_skills` + failed). */
    public function countToolCalls(): int
    {
        return count($this->getToolCallRows());
    }

    /** "Real work" tool calls — everything except the `load_skills` and `findWidgets` meta-tools. Fewer = more zielgerichtet. */
    public function countWorkToolCalls(): int
    {
        return count(array_filter(
            $this->getCalledToolNames(),
            static fn (string $name): bool => $name !== LoadSkillsTool::NAME && $name !== FindWidgetsTool::NAME
        ));
    }

    /**
     * Whether a `delegate` call of the set is still unresolved — its results are not on the wire yet. With a needle
     * only the calls whose task labels name it (the platform's page read: `getWebPageAsMarkdown`) — the model's own
     * delegations (a research task) never hold another verdict open.
     */
    public function hasUnresolvedDelegateCall(?string $taskNeedle = null): bool
    {
        foreach ($this->rows as $message) {
            if (!$message->isDelegationCall() || !isset($message->toolCall) || $message->toolCall->isResolved()) {
                continue;
            }
            if ($taskNeedle === null) {
                return true;
            }
            foreach ((array)($message->toolCall->arguments['tasks'] ?? []) as $task) {
                if (str_contains((string)(is_array($task) ? ($task['task'] ?? '') : $task), $taskNeedle)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The PLATFORM-VERIFIED social profile URLs the page reads of this set found (the authored line of
     * {@see SOCIAL_PROFILE_LINE_PREFIX} in a resolved tool result —
     * a delegation verdict included), merged per network, with the newest carrying row's index. Null when no read
     * carried the line.
     *
     * @return PlatformVerifiedSocialProfileUrls|null
     */
    public function getPlatformVerifiedSocialProfileUrls(): ?PlatformVerifiedSocialProfileUrls
    {
        $urlsByNetwork = [];
        $newestIndex = null;
        $prefix = self::SOCIAL_PROFILE_LINE_PREFIX;
        foreach ($this->rows as $message) {
            if (!isset($message->toolCall) || !$message->toolCall->isResolved()) {
                continue;
            }
            $resultText = AIConversationMessages::decodeToolResultTexts($message->getToolResultText()) . "\n"
                . AIConversationMessages::decodeToolResultTexts((string)($message->toolCall->resultAppendix ?? ''));
            if (!str_contains($resultText, $prefix)) {
                continue;
            }
            foreach (preg_split('/\R/', $resultText) ?: [] as $line) {
                $position = strpos($line, $prefix);
                if ($position === false) {
                    continue;
                }
                foreach (explode(' · ', trim(substr($line, $position + strlen($prefix)))) as $networkPart) {
                    $tokens = preg_split('/\s+/', trim($networkPart)) ?: [];
                    $network = strtolower(trim((string)array_shift($tokens)));
                    foreach ($tokens as $url) {
                        // the URL up to the first character no profile URL carries (a closing quote, bracket or brace
                        // of the JSON blob around it)
                        $url = preg_match('/^https?:\/\/[^\s"\'<>\\\\\]}]+/', trim($url, " \t\"'"), $urlMatch) === 1 ? $urlMatch[0] : '';
                        if ($network !== '' && $url !== '' && !in_array($url, $urlsByNetwork[$network] ?? [], true)) {
                            $urlsByNetwork[$network][] = $url;
                        }
                    }
                }
            }
            $index = (int)($message->indexInConversation ?? -1);
            if ($newestIndex === null || $index > $newestIndex) {
                $newestIndex = $index;
            }
        }
        return $urlsByNetwork !== [] && $newestIndex !== null
            ? PlatformVerifiedSocialProfileUrls::forUrlsByNetworkAndMessageIndex($urlsByNetwork, $newestIndex)
            : null;
    }

    /** How many COMPLETED tool-call rows carry one of `$toolNames` — the memo key of a value those tools change (the memory directives). */
    public function countCompletedToolCallsNamed(array $toolNames): int
    {
        $count = 0;
        foreach ($this->rows as $message) {
            $toolCall = $message->toolCall ?? null;
            if ($toolCall !== null && in_array($toolCall->name ?? '', $toolNames, true) && $toolCall->status === AIConversationMessage::STATUS_COMPLETED) {
                $count++;
            }
        }
        return $count;
    }


    /**
     * Whether a call of the SAME tool with IDENTICAL normalized arguments COMPLETED anywhere earlier in this
     * conversation — the delete-retry disambiguation: a DELETE tool's call (`#[MCPToolEffect(DELETE)]`) that FAILS
     * ("not found") while an identical call already COMPLETED here means the target is gone BECAUSE WE DELETED IT — success, not an
     * access problem (successful deletions were retried after a stale list read,
     * every retry failed "not found" and the agent reported broken account access). Unlike
     * {@see getEarlierCompletedIdenticalWriteIndex} this fold never stops at a different-arguments call in
     * between — deleting record B does not make a retry of record A's delete meaningful again — and it scans the
     * WHOLE loaded conversation, not only back to the newest state change.
     */
    public function hasEarlierCompletedCallWithIdenticalArguments(ToolCall $toolCall): bool
    {
        $toolName = (string)($toolCall->name ?? '');
        $identityKey = $toolCall->getNormalizedIdentityKey();
        foreach ($this->rows as $message) {
            $priorCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($priorCall === null
                || $priorCall === $toolCall
                || $priorCall->status !== AIConversationMessage::STATUS_COMPLETED
                || (string)($priorCall->name ?? '') !== $toolName
                || $priorCall->wasRefusedAsDuplicate
                // a prior call that COMPLETED but declared it did not finish is no proof the target is gone
                || $priorCall->resultDeclaresIncompleteOutcome()
            ) {
                continue;
            }
            if ($priorCall->getNormalizedIdentityKey() === $identityKey) {
                return true;
            }
        }
        return false;
    }

    /**
     * The message INDEX of an earlier COMPLETED write tool call with the byte-identical normalized identity
     * (same tool + same arguments, {@see ToolCall::getNormalizedIdentityKey()}) in this conversation, or null when none
     * exists. The WRITE counterpart of {@see AIConversationToolCallHistory::hasReusablePriorReadCall()}: a write repeated with EXACTLY the same
     * arguments never expresses new intent — it is either a double-fire or content perseveration (a model locked
     * onto its own earlier tool-result echo re-persists the same payload even after the owner asked for a change;
     * every re-execution both creates a duplicate persist AND echoes the full old content back into context,
     * re-anchoring the lock). The loop skips the re-execution and answers with a corrective notice instead of
     * the result echo. Only COMPLETED priors match — retrying a FAILED write with the same arguments stays legal.
     */
    public function getEarlierCompletedIdenticalWriteIndex(ToolCall $toolCall): ?int
    {
        $toolName = (string)($toolCall->name ?? '');
        if (!$toolCall->refusesIdenticalRepeat()) {
            return null;
        }
        // Compare against the NEWEST executed COMPLETED call of the SAME tool — the current persisted state.
        // Matching ANY earlier call would falsely refuse a legitimate re-write after an intermediate change
        // (true → false → true: the third call re-establishes a state the second overwrote). Refused repeats are
        // skipped — they persisted nothing and must not become the comparison base. HARDENING:
        // a legitimate re-establishment needs an OWNER turn in between — WITHIN the current turn a different-args
        // call does NOT re-open the door, the scan keeps walking this turn's older calls for an identical match
        // (a perseverating model interposed a one-item variant and then re-fired the byte-identical full payload).
        $identityKey = $toolCall->getNormalizedIdentityKey();
        $elements = $this->rows;
        // Newest initiator via BACKWARD scan with early exit — O(current turn length) typical instead of a full
        // forward pass.
        $currentTurnStartPosition = null;
        for ($position = count($elements) - 1; $position >= 0; $position--) {
            if (AIConversationMessages::isTurnInitiatorMessage($elements[$position])) {
                $currentTurnStartPosition = $position;
                break;
            }
        }
        for ($i = count($elements) - 1; $i >= 0; $i--) {
            $priorCall = isset($elements[$i]->toolCall) ? $elements[$i]->toolCall : null;
            if ($priorCall === null
                || $priorCall->status !== AIConversationMessage::STATUS_COMPLETED
                || (string)($priorCall->name ?? '') !== $toolName
                || $priorCall->wasRefusedAsDuplicate
                || $priorCall->resultDeclaresIncompleteOutcome() // ran, saved nothing — a retry is no duplicate
                // landed as the model's PROPOSAL / note only (the write downgraded, the statement not accepted): the
                // same call in a later owner-opened turn is the owner's confirmation, never a repeat (refused as
                // identical, the re-sent call made the run loop until it gave up)
                || $priorCall->wasDowngradedToProposal()
            ) {
                continue;
            }
            if ($priorCall->getNormalizedIdentityKey() === $identityKey) {
                return $elements[$i]->indexInConversation ?? -1;
            }
            // Different arguments = the persisted state changed in between. Outside the current turn (or with no
            // initiator to scope a turn at all) that is the legal re-write case — stop. INSIDE the current turn
            // keep scanning: an in-turn variant must not re-open the door for the perseverated identical payload.
            if ($currentTurnStartPosition === null || $i < $currentTurnStartPosition) {
                return null;
            }
        }
        return null;
    }

    /**
     * The failure streak of an identical READ that already FAILED {@see MAX_IDENTICAL_FAILED_READS} times in this
     * conversation ({@see ToolCallFailedReadStreak}) — null when the call may run: a write, fewer failures, or a
     * streak that was RESET by a later COMPLETED identical call (the transient failure healed) or by a completed
     * write after it (the data changed — the same invalidation rule as {@see AIConversationToolCallHistory::hasReusablePriorReadCall()}). A read is a tool
     * declared a reusable READ ({@see ToolCall::isReusableRead()}). Same identity
     * key as the read dedup ({@see ToolCall::getNormalizedIdentityKey()}). Only calls the executor actually RAN count
     * (`executionStartedAt` stamped): the loop's own refusals (unloaded skill, repeat guard, gate, this refusal)
     * never executed and are not tool failures. Scoped to the loaded conversation window (not the current turn): the
     * per-turn repeat guard already stops same-turn loops, this stops the re-try of an unreadable page on every
     * new owner turn.
     *
     * @param ToolCall $toolCall
     * @return ToolCallFailedReadStreak|null
     */
    public function getRepeatedFailedReadResult(ToolCall $toolCall): ?ToolCallFailedReadStreak
    {
        if (!$toolCall->isReusableRead()) {
            return null;
        }
        $identityKey = $toolCall->getNormalizedIdentityKey();
        $failedCount = 0;
        $lastFailureResult = '';
        foreach ($this->rows as $message) {
            $priorCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($priorCall === null || $priorCall === $toolCall) {
                continue;
            }
            $priorName = (string)($priorCall->name ?? '');
            $isIdenticalRead = $priorName === (string)($toolCall->name ?? '')
                && $priorCall->getNormalizedIdentityKey() === $identityKey;
            if ($isIdenticalRead && $priorCall->status === AIConversationMessage::STATUS_COMPLETED && !$priorCall->wasRefusedAsDuplicate) {
                $failedCount = 0; // the read works again — the streak is over
                $lastFailureResult = '';
                continue;
            }
            if ($isIdenticalRead
                && $priorCall->status === AIConversationMessage::STATUS_FAILED
                && ($priorCall->executionStartedAt ?? null) !== null
                && !$priorCall->wasRefusedAsDuplicate
                && !$priorCall->wasRefusedByGate
            ) {
                $failedCount++;
                $lastFailureResult = (string)($priorCall->result ?? '');
                continue;
            }
            // a completed write (any non-read work tool) after the failures changed the data — the read may succeed now
            if ($failedCount > 0
                && $priorCall->status === AIConversationMessage::STATUS_COMPLETED
                && !$priorCall->isReusableRead()
                && !$priorCall->wasRefusedAsDuplicate
                && $priorName !== LoadSkillsTool::NAME
                && $priorName !== DelegateTool::NAME
                && $priorName !== CheckDelegationsTool::NAME
                && $priorName !== SendOwnerReportTool::NAME
                && !in_array($priorName, TaskPlan::PLAN_TOOL_NAMES, true)
            ) {
                $failedCount = 0;
                $lastFailureResult = '';
            }
        }
        return $failedCount >= self::MAX_IDENTICAL_FAILED_READS
            ? ToolCallFailedReadStreak::forFailedCountAndLastFailureResult($failedCount, $lastFailureResult)
            : null;
    }

    /**
     * The NEWEST RESOLVED, non-errored tool call of the given tool: the email composer reads the
     * `send_owner_report` call's persisted arguments (subject) from here — persisted args stay FULL for
     * forensics even when the wire stubs them. Null when the tool never completed in this conversation. Pure
     * fold over the loaded set.
     *
     * `$disqualifyingArgumentValues` (the argument-aware done-evidence gate,
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem::$doneRequiresToolCallArgumentsAbsent}):
     * a map of argument name → values that DISQUALIFY a call — a name-matched call whose decoded arguments
     * carry one of them is skipped and the scan keeps walking for an older qualifying call (e.g. a write
     * with `statedByOwner: false` — an agent proposal — never counts as the owner-stated value the gate demands).
     *
     * `$callThatDidNoWorkByDesignCounts`: a call that completed with {@see ToolCall::RESULT_MARKER_NO_WORK_DONE} counts —
     * the not_applicable gate reads the tool's own "nothing to do" as its evidence ({@see ToolCall::didNoWorkByDesign()}).
     *
     * @param array<string, array<int, mixed>>|null $disqualifyingArgumentValues
     */
    public function getNewestResolvedToolCallByToolName(
        string $toolName,
        ?array $disqualifyingArgumentValues = null,
        ?array $requiredArgumentValues = null,
        bool $callThatDidNoWorkByDesignCounts = false,
    ): ?ToolCall {
        foreach (array_reverse($this->rows) as $message) {
            $toolCall = $message->toolCall ?? null;
            if ($toolCall !== null
                && ($toolCall->name ?? null) === $toolName
                && $toolCall->isResolved()
                && !$toolCall->isError()
                && !($callThatDidNoWorkByDesignCounts ? $toolCall->carriesProposalOrUnacceptedClaimMarker() : $toolCall->wasDowngradedToProposal())
                && !$toolCall->resultDeclaresIncompleteOutcome()
                && !$toolCall->carriesDisqualifyingArgument($disqualifyingArgumentValues)
                && $toolCall->carriesAllRequiredArguments($requiredArgumentValues)
            ) {
                return $toolCall;
            }
        }
        return null;
    }

    /**
     * The `indexInConversation` of the row carrying the newest resolved call of the tool
     * ({@see getNewestResolvedToolCallByToolName()} without argument filters) — read off the call's parent row. Null
     * when no such call exists or its row carries no index.
     */
    public function getNewestResolvedToolCallIndexByToolName(string $toolName): ?int
    {
        $newestToolCallMessage = $this->getNewestResolvedToolCallByToolName($toolName)?->getParent();
        return $newestToolCallMessage instanceof AIConversationMessage ? ($newestToolCallMessage->indexInConversation ?? null) : null;
    }

    /**
     * The newest RESOLVED, non-errored call of ANY of the given tools (a gate that one
     * tool or another may satisfy, e.g. an update of an existing list or an add to a new one —
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem::getDoneRequiresToolCallAlternatives()}),
     * with the same argument filters as {@see getNewestResolvedToolCallByToolName()}. Null when none completed.
     *
     * @param string[] $toolNames
     * @param array<string, array<int, mixed>>|null $disqualifyingArgumentValues
     * @param array<string, array<int, mixed>|mixed>|null $requiredArgumentValues
     */
    public function getNewestResolvedToolCallByAnyToolName(
        array $toolNames,
        ?array $disqualifyingArgumentValues = null,
        ?array $requiredArgumentValues = null,
    ): ?ToolCall {
        $newestCall = null;
        $newestIndex = -1;
        foreach ($toolNames as $toolName) {
            $toolCall = $this->getNewestResolvedToolCallByToolName($toolName, $disqualifyingArgumentValues, $requiredArgumentValues);
            if ($toolCall === null) {
                continue;
            }
            $callIndex = $this->getMessageIndexOfToolCall($toolCall) ?? -1;
            if ($newestCall === null || $callIndex > $newestIndex) {
                $newestCall = $toolCall;
                $newestIndex = $callIndex;
            }
        }
        return $newestCall;
    }

    /**
     * The OLDEST resolved, non-errored call of ANY of the given tools (the text-evidence floor of a draft gate,
     * read by the application's draft state):
     * a window that opens with the first such write must not close again when a later write of the same tools lands
     * in the approval turn. Null when none completed.
     *
     * @param string[] $toolNames
     */
    public function getOldestResolvedToolCallByAnyToolName(array $toolNames): ?ToolCall
    {
        foreach ($this->rows as $message) {
            $toolCall = $message->toolCall ?? null;
            if ($toolCall !== null
                && in_array($toolCall->name ?? '', $toolNames, true)
                && $toolCall->isResolved()
                && !$toolCall->isError()
                && !$toolCall->wasDowngradedToProposal()
            ) {
                return $toolCall;
            }
        }
        return null;
    }

    /** The `indexInConversation` of the message carrying the given tool call, or null. Pure fold. */
    public function getMessageIndexOfToolCall(ToolCall $toolCall): ?int
    {
        foreach ($this->rows as $message) {
            if (($message->toolCall ?? null) === $toolCall) {
                return $message->indexInConversation ?? null;
            }
        }
        return null;
    }

    /**
     * All `delegate` tool-call messages in this conversation, index-sorted (the sub-agents it spawned). Backs the
     * `check_delegations` tool's status poll and the "offer it only when delegations exist" gate. Pure read.
     *
     * @return AIConversationMessage[]
     */
    public function getDelegateCallRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (AIConversationMessage $message): bool =>
                $message->messageType === AIConversationMessage::MESSAGE_TOOL_CALL
                && ((isset($message->toolCall) ? $message->toolCall : null)?->name) === DelegateTool::NAME
        ));
    }

    /**
     * True if any message at or before $anchorIndex is an UNSETTLED delegation — a `delegate` tool call whose child is
     * still running. Such a message must NOT be summarized away: its verdict lands LATER
     * (when the child settles), and would then be lost from both the summary and the wire context. This keeps the
     * anchor strictly below an in-flight delegate until it resolves.
     */
    public function hasUnfinishedDelegationUpToIndex(int $anchorIndex): bool
    {
        foreach ($this->rows as $message) {
            if (($message->indexInConversation ?? -1) > $anchorIndex) {
                continue;
            }
            // the message-side FK is gone — a delegate is identified by its tool-call NAME (the single
            // `delegate` call resolves only once ALL its children settle, so "unsettled delegate" is preserved).
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null
                && ($toolCall->name ?? null) === DelegateTool::NAME
                && !$toolCall->isResolved()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The LOWEST message index that is an unsettled delegation — a `delegate` tool call whose child is still running.
     * The single-pass equivalent of {@see hasUnfinishedDelegationUpToIndex()}: the round computes this
     * ONCE and holds the whole settled region strictly below it, instead of re-scanning the message stream per leaf.
     * Null = no in-flight delegate. Pure.
     */
    public function getLowestUnfinishedDelegationIndex(): ?int
    {
        $lowestUnsettledDelegateIndex = null;
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null
                && ($toolCall->name ?? null) === DelegateTool::NAME
                && !$toolCall->isResolved()) {
                $messageIndex = $message->indexInConversation ?? null;
                if ($messageIndex !== null && ($lowestUnsettledDelegateIndex === null || $messageIndex < $lowestUnsettledDelegateIndex)) {
                    $lowestUnsettledDelegateIndex = $messageIndex;
                }
            }
        }
        return $lowestUnsettledDelegateIndex;
    }

    /**
     * The newest earlier COMPLETED call of the same tool with the same NORMALIZED arguments ({@see ToolCall::getNormalizedIdentityKey()}:
     * a `noCache` flip is no new question) when that call saved NOTHING ({@see ToolCall::wasDowngradedToProposal()}: the
     * goal not saved as the owner's, the statement not recorded, no work done); null when the newest such call saved,
     * or there is none. `$toolCall` itself and the refused repeats are skipped. Asked over the CURRENT turn: the same
     * evidence gives the same answer within it (one write refused over and over, every repeat flipping `noCache`
     * past the byte-exact guard); a new owner turn may bring the words the tool asked for.
     */
    public function getNewestEarlierUnsavedIdenticalCall(ToolCall $toolCall): ?ToolCall
    {
        $toolName = (string)($toolCall->name ?? '');
        $identityKey = $toolCall->getNormalizedIdentityKey();
        for ($position = count($this->rows) - 1; $position >= 0; $position--) {
            $priorCall = isset($this->rows[$position]->toolCall) ? $this->rows[$position]->toolCall : null;
            if ($priorCall === null || $priorCall === $toolCall
                || (string)($priorCall->name ?? '') !== $toolName
                || $priorCall->status !== AIConversationMessage::STATUS_COMPLETED
                || $priorCall->wasRefusedAsDuplicate
                || $priorCall->wasRefusedByGate
                || $priorCall->getNormalizedIdentityKey() !== $identityKey
            ) {
                continue;
            }
            return $priorCall->wasDowngradedToProposal() ? $priorCall : null;
        }
        return null;
    }

    /** The rows' calls with `$toolCall`'s normalized arguments that the loop refused as repeats (`$toolCall` itself skipped). */
    public function countRefusedRepeatsWithNormalizedIdentityKey(ToolCall $toolCall): int
    {
        $identityKey = $toolCall->getNormalizedIdentityKey();
        $count = 0;
        foreach ($this->rows as $message) {
            $priorCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($priorCall !== null && $priorCall !== $toolCall && $priorCall->wasRefusedAsDuplicate && $priorCall->getNormalizedIdentityKey() === $identityKey) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Identical calls of this turn that FAILED (tool error, not a gate refusal): a state-refreshing tool is exempt from the
     * repeat guard because identical arguments legitimately return new state — an ERROR refreshes nothing, so after
     * MAX identical failures the exemption ends (otherwise one failing call repeats with the same error without end).
     */
    public function countFailedCallsWithSignature(string $signature): int
    {
        $count = 0;
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null && !$toolCall->wasRefusedByGate && $toolCall->signature() === $signature && $toolCall->status === AIConversationMessage::STATUS_FAILED) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * How many TOOL_CALL messages in the CURRENT turn carry the given call signature (same tool + same
     * arguments) — the derived replacement for the `$toolCallSignatures` repeat-guard map of the earlier per-thread
     * run object (not today's {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRun}) (per-turn
     * scope: a user legitimately re-asking the same thing next turn must not trip the
     * repeat guard). The fold includes the call currently being dispatched (appended before the guard runs).
     */
    public function countCallsWithSignature(string $signature): int
    {
        $count = 0;
        foreach ($this->rows as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            // A GATE refusal (mandate / owner confirmation) is the sanctioned "call again when the gate opens" —
            // it never counts as a repeat, or the authorized third call is swallowed.
            if ($toolCall !== null && !$toolCall->wasRefusedByGate && $toolCall->signature() === $signature) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Whether this READ tool call is a fresh duplicate: an earlier COMPLETED call with the exact same signature
     * (tool + arguments) already ran in the CURRENT turn, and NO write/action tool has run since to change the
     * underlying data — so its result still holds and re-executing it would only burn an Argus round-trip. Scoped
     * to the current turn: a new owner message reads fresh, because the data behind a read moves
     * outside the conversation too (new records, an edit in the web app, a deploy) and a conversation stays
     * open for days. Only a READ declared reusable is cacheable ({@see ToolCall::isReusableRead()},
     * the `#[MCPToolEffect]` of the tool); any other completed tool running after the prior read invalidates the reuse — a
     * write, or a read declared not reusable — except the meta tools, the NON_MUTATING_WORK_TOOL_NAMES and a tool
     * declaring `keepsEarlierReadsReusable` (the connect offer). The current PENDING call is skipped
     * (only COMPLETED prior calls match).
     *
     * `$maxToolCallImagesOnWire` + `$transientImageToolCallIds` make it PICTURE-AWARE: a prior result whose
     * pictures no longer ride (cap-evicted by newer pictures, or inline bytes an earlier worker invocation captured) is
     * not reusable — the model is entitled to look again.
     *
     * `$keepToolResultsFromIndex` makes the dedup CLEARING-AWARE: a prior result whose
     * message index lies BELOW the tool-result retention boundary is emitted as a STUB on the wire — refusing the
     * re-read while asserting "its result is still in the conversation above" would be false, trapping the model
     * between the stub's "re-call the tool" and this refusal. A cleared prior match does NOT count as reusable.
     */
    public function hasReusablePriorReadCall(
        ToolCall $toolCall,
        int $keepToolResultsFromIndex = PHP_INT_MIN,
        int $maxToolCallImagesOnWire = PHP_INT_MAX,
        array $transientImageToolCallIds = [],
        array $toolResultIndexesStubbedByBudget = [] // budget by fit: results above the boundary the wire still stubs
    ): bool {
        return $this->getReusablePriorReadCall($toolCall, $keepToolResultsFromIndex, $maxToolCallImagesOnWire, $transientImageToolCallIds, $toolResultIndexesStubbedByBudget) !== null;
    }

    /**
     * The earlier COMPLETED identical read whose result still answers this call — the call the dedup reuses (its
     * `result` is what the repeat gets back again, prefixed with the notice: the model gets the
     * answer a second time instead of a pointer to it, and keeps every tool). Null when the read must run: no
     * identical completed read in the current turn, a write or a change signal since, the result cleared off the
     * wire, pictures gone.
     *
     * @param string[] $transientImageToolCallIds
     * @param array<int, true> $toolResultIndexesStubbedByBudget
     */
    public function getReusablePriorReadCall(
        ToolCall $toolCall,
        int $keepToolResultsFromIndex = PHP_INT_MIN,
        int $maxToolCallImagesOnWire = PHP_INT_MAX,
        array $transientImageToolCallIds = [],
        array $toolResultIndexesStubbedByBudget = []
    ): ?ToolCall {
        $toolName = (string)($toolCall->name ?? '');
        if (!$toolCall->isReusableRead()) {
            return null;
        }
        $dedupKey = $toolCall->getNormalizedIdentityKey();
        $reusablePriorResultIndex = null;
        $reusablePriorCall = null;
        $picturesRidingAfterMatch = 0; // pictures of NEWER results that still ride — they push the match off the wire
        // Turn-scoped: only the current turn's reads are reused — the next owner message reads fresh.
        foreach ($this->rows as $message) {
            // A change the owner or a helper made WITHOUT a tool call of this conversation: an owner edit in a widget
            // (a widget click, an action confirm), a channel connection or a settled delegation (its children's
            // writes) after the earlier read — the data behind that read moved, so the re-read runs.
            if ($reusablePriorResultIndex !== null && $message->isChangeSignalInvalidatingEarlierReads()) {
                $reusablePriorResultIndex = null;
                continue;
            }
            $priorCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($priorCall === null || $priorCall->status !== AIConversationMessage::STATUS_COMPLETED) {
                continue;
            }
            $priorName = (string)($priorCall->name ?? '');
            if ($priorName === $toolName && $priorCall->getNormalizedIdentityKey() === $dedupKey) {
                // a matching earlier read result exists and is (so far) still valid; unset index = never wire-cleared
                $reusablePriorResultIndex = $message->indexInConversation ?? PHP_INT_MAX;
                $reusablePriorCall = $priorCall;
                $picturesRidingAfterMatch = 0;
                continue;
            }
            if ($reusablePriorResultIndex !== null && $priorCall->countPicturesStillRiding($transientImageToolCallIds) > 0) {
                $picturesRidingAfterMatch += $priorCall->countPicturesStillRiding($transientImageToolCallIds);
            }
            // a completed write (any non-read work tool) after the match changed the account data → re-fetch needed.
            // The plan tools are META: a plan tick mutates NO account data and must not blind the dedup.
            // A write REFUSED as a duplicate persisted nothing — it must not blind the dedup either (otherwise two
            // refused repeats of a write would allow a re-fetch of a read that is still current).
            if ($reusablePriorResultIndex !== null
                && !$priorCall->isReusableRead()
                && !$priorCall->wasRefusedAsDuplicate
                && $priorName !== LoadSkillsTool::NAME
                && $priorName !== DelegateTool::NAME
                && !in_array($priorName, TaskPlan::PLAN_TOOL_NAMES, true)
                // sending the owner report mutates NO account data — it must not blind the read dedup.
                && $priorName !== SendOwnerReportTool::NAME
                // a tool that only writes its widget (the connect offer) mutates NO account data either.
                && !in_array($priorName, AIConversationMessages::NON_MUTATING_WORK_TOOL_NAMES, true)
                && !MCPToolEffectCatalog::keepsEarlierReadsReusableForToolName($priorName)
            ) {
                $reusablePriorResultIndex = null;
            }
        }
        if ($reusablePriorResultIndex === null || $reusablePriorResultIndex < $keepToolResultsFromIndex
            || isset($toolResultIndexesStubbedByBudget[$reusablePriorResultIndex])
        ) {
            return null;
        }
        // Picture-aware: a prior read whose PICTURES no longer ride on the wire is
        // not reusable — refusing it with "the result is still above" while the picture is gone traps the model between
        // the dropped marker ("look again") and this refusal. Its pictures ride only when they are still resolvable
        // (captured this invocation, or re-resolvable from a durable store) AND the newer pictures leave room under the cap.
        if ($reusablePriorCall !== null && $reusablePriorCall->imageReferences !== []) {
            $matchPictures = $reusablePriorCall->countPicturesStillRiding($transientImageToolCallIds);
            if ($matchPictures === 0 || $picturesRidingAfterMatch + $matchPictures > $maxToolCallImagesOnWire) {
                return null;
            }
        }
        return $reusablePriorCall;
    }
}
