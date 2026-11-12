<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Metrics;

use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The timing and call figures of ONE root conversation, derived from its persisted message rows only — the same
 * numbers for an eval run and for a real owner.
 *
 * Time partition: every gap between two consecutive rows (`created`, index order) lands in exactly one bucket:
 * - AWAY: the later row comes from the owner (text, widget click, channel connection, action confirm, a navigation
 *   back into the chat) after more than {@see OWNER_ABSENCE_GAP_SECONDS};
 * - OWNER: the later row comes from the owner within that limit AND the earlier row left the conversation waiting
 *   for the owner (an assistant text, a widget, an error). An owner row that arrives while the agent is still working
 *   (after a tool call or a loop signal) does not turn that work into owner time;
 * - IDLE: the later row is a machine work initiator (a purpose kickoff, an application's work order), or the gap is
 *   longer than {@see OWNER_ABSENCE_GAP_SECONDS} and the later row neither comes from the owner nor continues the
 *   agent's work (no tool call — its row is written when the execution ends — no settled delegation, no finished
 *   background work) — a posting long after the turn ended;
 * - AGENT: everything else — the platform was working.
 * The buckets add up to {@see $totalWallSeconds} (first row → last row); none can go negative.
 *
 * The measured work inside the agent gaps — root model calls (`[row created − egress, row created]`), root tool
 * executions and the delegation waits — is merged into one union and intersected with the agent gaps; the
 * {@see $platformOverheadSeconds} are the agent seconds that remain. Overlaps never count twice, and work outside
 * the agent gaps never counts. Model calls without the egress stamp are counted in
 * {@see $modelCallsWithoutEgressStamp}; their time stays in the overhead.
 *
 * Execution turns start at every owner-origin row and every machine work initiator; a turn's agent seconds are the
 * agent gaps up to the next turn start.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMetrics extends ValueObject
{
    /** The version of these definitions; stored with every scorecard so BI can tell rows of different rules apart. */
    public const int METRICS_VERSION = 4; // 2: tool failures read the tool call's own status (FAILED); 3: runFailures, failure kinds; 4: toolStats

    /** A gap longer than this before an owner row means the owner left and came back. */
    public const int OWNER_ABSENCE_GAP_SECONDS = 600;

    /** Tools that return at once and let the children run in the background — no blocking tool time. */
    public const array NON_BLOCKING_TOOL_NAMES = ['delegate', 'check_delegations'];

    /** The tool call's own status (`toolCall.status`) of a failed execution. */
    public const string TOOL_STATUS_FAILED = 'FAILED';

    /** The loop's repeat-abort result (the call was stopped as a repeat without progress). */
    public const string LOOP_ABORT_RESULT_PREFIX = 'Aborted: this exact tool call was repeated without progress';

    public const string FAILURE_KIND_LOOP_ABORT = 'loop_abort';
    public const string FAILURE_KIND_REPEAT_CORRECTIVE = 'repeat_corrective';
    public const string FAILURE_KIND_GATE_REFUSAL = 'gate_refusal';
    public const string FAILURE_KIND_TIMEOUT = 'timeout';
    public const string FAILURE_KIND_EXCEPTION = 'exception';
    public const string FAILURE_KIND_EXTERNAL = 'external';
    public const string FAILURE_KIND_NO_EFFECT = 'no_effect';
    public const string FAILURE_KIND_VALIDATION = 'validation';
    public const string FAILURE_KIND_UNKNOWN = 'unknown';

    /** The failure kinds that are technical problems; the others are the agent's or by design. */
    public const array TECHNICAL_FAILURE_KINDS = [self::FAILURE_KIND_TIMEOUT, self::FAILURE_KIND_EXCEPTION, self::FAILURE_KIND_EXTERNAL];

    /**
     * @var string[] Names that mark a failed tool result as the failure of an external provider (a third-party API
     * failed, not the application's code). The module knows none; an application lists the providers its tools call
     * in a class of the same path.
     */
    public const array EXTERNAL_PROVIDER_MARKERS = [];

    /** Result heads of a tool call that did not do its work. */
    public const array FAILED_TOOL_RESULT_PREFIXES = [
        'Execution failed', 'NOT executed', 'You have already called', 'You already called', 'No item updated', 'Aborted',
    ];

    public const string ENTRY_POINT_OWNER_MESSAGE = 'ownerMessage';
    public const string ENTRY_POINT_DASHBOARD_WIDGET = 'dashboardWidget';
    public const string ENTRY_POINT_PURPOSE_KICKOFF = 'purposeKickoff';
    public const string ENTRY_POINT_OTHER = 'other';

    /**
     * @var string|null How the conversation began ({@see resolveEntryPoint()}): the owner's message, a dashboard task,
     * a kickoff, or what the first signal's source names ({@see \DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource::getMetricsEntryPoint()}), …
     */
    public ?string $entryPoint = null;

    /** @var int|null Index of the first owner-origin row (the owner's first action: a message, a dashboard task, a click). */
    public ?int $firstOwnerActionIndex = null;

    /** @var float First row → last row, seconds. */
    public float $totalWallSeconds = 0.0;

    /** @var float Agent + owner seconds: the time somebody was active. */
    public float $activeWallSeconds = 0.0;

    /** @var float Gaps in which the platform was working. */
    public float $agentSeconds = 0.0;

    /** @var float Gaps ≤ the absence limit in which the conversation waited for the owner: reading and typing. */
    public float $ownerSeconds = 0.0;

    /** @var float Gaps > the absence limit in which the conversation waited for the owner: the owner left and came back. */
    public float $awaySeconds = 0.0;

    /** @var float Gaps in which the conversation waited for new work (a long pause without the owner). */
    public float $idleSeconds = 0.0;

    /** @var float|null First row → first assistant text (the first answer or the greeting). */
    public ?float $greetingLatencySeconds = null;

    /** @var float Σ egress of the root's model calls. */
    public float $modelSeconds = 0.0;

    /** @var float Σ egress of the delegation children's model calls — cumulative work, may exceed the wall. */
    public float $childModelSeconds = 0.0;

    /** @var int Root model calls (rows carrying a request snapshot). */
    public int $modelCalls = 0;

    /** @var int Root model calls without an egress stamp (their time stays in the overhead). */
    public int $modelCallsWithoutEgressStamp = 0;

    /** @var int Root tool calls, the non-blocking ones included. */
    public int $toolCalls = 0;

    /** @var int `delegate` / `check_delegations` calls. */
    public int $nonBlockingToolCalls = 0;

    /** @var int Root tool calls the platform refused or that failed ({@see isFailedToolCallRow()}). */
    public int $toolFailures = 0;

    /** @var int Run-level failures the owner saw as an error row (a stopped run, an abort, a failed model call). */
    public int $runFailures = 0;

    /** @var int Pause reports to the owner (the step budget ran out mid-work). */
    public int $pauseReports = 0;

    /** @var int Checked-stop bounces (the model stopped with open plan items and was sent back). */
    public int $checkedStopBounces = 0;

    /**
     * @var array Per root tool (name → figures): `calls`, `failures`, `failureKinds` (kind → count,
     *      {@see classifyToolCallFailure()}), `seconds` (Σ duration) and `maxSeconds` — which tool is broken or slow,
     *      aggregated over the scorecards without reading the message rows again.
     */
    public array $toolStats = [];

    /** @var float Σ duration of the blocking root tool calls. */
    public float $toolSeconds = 0.0;

    /** @var float Merged execution intervals of the blocking root tool calls (parallel calls count once). */
    public float $toolWallSeconds = 0.0;

    /** @var float Merged [created, settledAt] intervals of the delegation batches the root forked. */
    public float $delegationWaitSeconds = 0.0;

    /** @var float Agent seconds not covered by measured work (model, tools, delegation waits); never negative. */
    public float $platformOverheadSeconds = 0.0;

    /** @var int Owner-authored inbound rows: the owner's texts and owner signals (clicks, connections, confirms). */
    public int $ownerTurns = 0;

    /** @var int The owner's typed messages alone. */
    public int $ownerTextTurns = 0;

    /** @var int Turns started by an owner-origin row or a machine work initiator. */
    public int $executionTurns = 0;

    /** @var float|null Median agent seconds per execution turn. */
    public ?float $p50TurnAgentSeconds = null;

    /** @var float|null 90th percentile of the agent seconds per execution turn. */
    public ?float $p90TurnAgentSeconds = null;

    /** @var int|null The newest message index the figures cover. */
    public ?int $upToMessageIndex = null;

    /**
     * Pure over the root's message rows (index order). Row keys: `i` (index), `role`, `t` (message type), `author`
     * (author type), `signal` (signal source), `c` (created), `snap` (has a request snapshot), `egress` (ms), `tool`,
     * `dur` (seconds), `startedAt` (tool execution start), `toolStatus`, `refusedFlag`, `resultHead`.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param AIConversationMetricsTimeIntervals|null $delegationBatchIntervals Each delegation batch from its
     *        `created` to its `settledAt`; a batch that is still open counts up to the last row
     * @param float $childModelSeconds
     * @return static|null null without rows
     */
    public static function buildFromRows(array $rows, ?AIConversationMetricsTimeIntervals $delegationBatchIntervals = null, float $childModelSeconds = 0.0): ?static
    {
        if ($rows === []) {
            return null;
        }
        $metrics = static::newInstance();
        $metrics->childModelSeconds = $childModelSeconds;
        $firstAt = null;
        $previousAt = null;
        $previousRow = null;
        $turnAgentSeconds = [];
        $currentTurn = null;
        $agentIntervals = new AIConversationMetricsTimeIntervals();
        $workIntervals = new AIConversationMetricsTimeIntervals();
        $toolIntervals = new AIConversationMetricsTimeIntervals();
        foreach ($rows as $row) {
            $createdAt = static::toUnixSeconds($row['c'] ?? null) ?? $previousAt;
            if ($createdAt === null) {
                continue;
            }
            if ($firstAt === null) {
                $metrics->entryPoint = static::resolveEntryPoint($row);
            }
            $firstAt ??= $createdAt;
            $isOwnerOrigin = static::isOwnerOriginRow($row);
            if ($isOwnerOrigin && $metrics->firstOwnerActionIndex === null && isset($row['i'])) {
                $metrics->firstOwnerActionIndex = (int)$row['i'];
            }
            $isTurnStart = $isOwnerOrigin || static::isWorkInitiatorRow($row);
            if ($previousAt !== null) {
                $gap = max(0.0, $createdAt - $previousAt);
                $isLongGap = $gap > self::OWNER_ABSENCE_GAP_SECONDS;
                if ($isOwnerOrigin && $isLongGap) {
                    $metrics->awaySeconds += $gap;
                } elseif ($isOwnerOrigin && $previousRow !== null && static::isWaitingRow($previousRow)) {
                    $metrics->ownerSeconds += $gap;
                } elseif (static::isWorkInitiatorRow($row) || (!$isOwnerOrigin && $isLongGap && !static::continuesAgentWork($row))) {
                    $metrics->idleSeconds += $gap;
                } else {
                    $metrics->agentSeconds += $gap;
                    if ($gap > 0) {
                        $agentIntervals->addStartToEnd($previousAt, $createdAt);
                    }
                    if ($currentTurn !== null) {
                        $turnAgentSeconds[$currentTurn] += $gap;
                    }
                }
            }
            if ($isTurnStart) {
                $currentTurn = count($turnAgentSeconds);
                $turnAgentSeconds[$currentTurn] = 0.0;
            }
            if (static::isOwnerAuthoredRow($row)) {
                $metrics->ownerTurns++;
                if (($row['t'] ?? null) === AIConversationMessage::MESSAGE_TEXT) {
                    $metrics->ownerTextTurns++;
                }
            }
            if ($metrics->greetingLatencySeconds === null
                && ($row['role'] ?? null) === AIConversationMessage::ROLE_ASSISTANT
                && ($row['t'] ?? null) === AIConversationMessage::MESSAGE_TEXT
            ) {
                $metrics->greetingLatencySeconds = $createdAt - $firstAt;
            }
            if (in_array($row['snap'] ?? null, [1, '1', true], true)) {
                $metrics->modelCalls++;
                $egressSeconds = ((int)($row['egress'] ?? 0)) / 1000.0;
                if ($egressSeconds <= 0) {
                    $metrics->modelCallsWithoutEgressStamp++;
                } else {
                    $workIntervals->addStartToEnd($createdAt - $egressSeconds, $createdAt);
                }
                $metrics->modelSeconds += $egressSeconds;
            }
            if (ConversationSignalSources::tryFrom((string)($row['signal'] ?? ''))?->marksAnOwnerReport() === true
                && ($row['role'] ?? null) === AIConversationMessage::ROLE_ASSISTANT
            ) {
                $metrics->pauseReports++;
            }
            // a scope stop (the agent space or account no longer permits automated work) is no failure of the agent
            if (($row['t'] ?? null) === AIConversationMessage::MESSAGE_ERROR && ($row['signal'] ?? null) !== AIScopeNotEligibleException::ERROR_CODE) {
                $metrics->runFailures++;
            }
            if (($row['t'] ?? null) === AIConversationMessage::MESSAGE_SIGNAL && ($row['signal'] ?? null) === AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE) {
                $metrics->checkedStopBounces++;
            }
            if (($row['t'] ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL) {
                $metrics->toolCalls++;
                $isFailed = static::isFailedToolCallRow($row);
                if ($isFailed) {
                    $metrics->toolFailures++;
                }
                $duration = (float)($row['dur'] ?? 0);
                $metrics->addToolCallToStats((string)($row['tool'] ?? ''), $duration, $isFailed ? (static::classifyToolCallFailure($row) ?? self::FAILURE_KIND_UNKNOWN) : null);
                if (in_array((string)($row['tool'] ?? ''), self::NON_BLOCKING_TOOL_NAMES, true)) {
                    $metrics->nonBlockingToolCalls++;
                } else {
                    $metrics->toolSeconds += $duration;
                    if ($duration > 0) {
                        // A call without its start stamp still counts, anchored at its row.
                        $startedAt = static::toUnixSeconds($row['startedAt'] ?? null) ?? ($createdAt - $duration);
                        $toolIntervals->addStartToEnd($startedAt, $startedAt + $duration);
                    }
                }
            }
            if (isset($row['i'])) {
                $metrics->upToMessageIndex = max($metrics->upToMessageIndex ?? 0, (int)$row['i']);
            }
            $previousAt = $createdAt;
            $previousRow = $row;
        }
        if ($firstAt === null) {
            return null;
        }
        $delegationIntervals = ($delegationBatchIntervals ?? new AIConversationMetricsTimeIntervals())->buildWithEndsCappedAt($previousAt);
        $metrics->totalWallSeconds = $previousAt - $firstAt;
        $metrics->activeWallSeconds = $metrics->agentSeconds + $metrics->ownerSeconds;
        $metrics->toolWallSeconds = $toolIntervals->getMergedSeconds();
        $metrics->delegationWaitSeconds = $delegationIntervals->getMergedSeconds();
        $measuredWorkSeconds = $workIntervals->buildJoinedWith($toolIntervals, $delegationIntervals)->getSecondsOverlappingWith($agentIntervals);
        $metrics->platformOverheadSeconds = max(0.0, $metrics->agentSeconds - $measuredWorkSeconds);
        $metrics->executionTurns = count($turnAgentSeconds);
        $metrics->p50TurnAgentSeconds = static::percentile($turnAgentSeconds, 0.5);
        $metrics->p90TurnAgentSeconds = static::percentile($turnAgentSeconds, 0.9);
        return $metrics;
    }

    /** Counts one root tool call into {@see $toolStats}; `$failureKind` null for a call that did its work. */
    public function addToolCallToStats(string $toolName, float $durationSeconds, ?string $failureKind): void
    {
        $toolName = $toolName !== '' ? $toolName : 'unknown';
        $toolStat = $this->toolStats[$toolName] ?? ['calls' => 0, 'failures' => 0, 'failureKinds' => [], 'seconds' => 0.0, 'maxSeconds' => 0.0];
        $toolStat['calls']++;
        $toolStat['seconds'] = round($toolStat['seconds'] + $durationSeconds, 3);
        $toolStat['maxSeconds'] = max($toolStat['maxSeconds'], round($durationSeconds, 3));
        if ($failureKind !== null) {
            $toolStat['failures']++;
            $toolStat['failureKinds'][$failureKind] = ($toolStat['failureKinds'][$failureKind] ?? 0) + 1;
        }
        $this->toolStats[$toolName] = $toolStat;
    }

    /** The agent seconds per execution turn, Ø (0 without a turn). */
    public function getAverageTurnAgentSeconds(): float
    {
        return $this->executionTurns > 0 ? $this->agentSeconds / $this->executionTurns : 0.0;
    }

    /**
     * How a conversation began, from its first row: a first SIGNAL row names it through its source's
     * {@see \DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource::getMetricsEntryPoint()}
     * (`other` when it names none); any other first row is the owner's own message or `other`.
     *
     * @param array<string, mixed> $firstRow
     */
    public static function resolveEntryPoint(array $firstRow): string
    {
        $signalSource = $firstRow['signal'] ?? null;
        if (($firstRow['t'] ?? null) === AIConversationMessage::MESSAGE_SIGNAL && is_string($signalSource)) {
            return ConversationSignalSources::tryFrom($signalSource)?->getMetricsEntryPoint() ?? self::ENTRY_POINT_OTHER;
        }
        return static::isOwnerAuthoredRow($firstRow) ? self::ENTRY_POINT_OWNER_MESSAGE : self::ENTRY_POINT_OTHER;
    }

    /**
     * A row after which the conversation waits for the owner: an assistant text, a widget (tool message) or an error.
     *
     * @param array<string, mixed> $row
     */
    public static function isWaitingRow(array $row): bool
    {
        $messageType = $row['t'] ?? null;
        return ($messageType === AIConversationMessage::MESSAGE_TEXT && ($row['role'] ?? null) === AIConversationMessage::ROLE_ASSISTANT)
            || $messageType === AIConversationMessage::MESSAGE_TOOL_MESSAGE
            || $messageType === AIConversationMessage::MESSAGE_ERROR;
    }

    /**
     * A row that continues the agent's work after a pause in it: a tool call (its row is written when the execution
     * ends) or a settled delegation / finished background work / turn continuation signal.
     *
     * @param array<string, mixed> $row
     */
    public static function continuesAgentWork(array $row): bool
    {
        return ($row['t'] ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL
            || (($row['t'] ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && ConversationSignalSources::tryFrom((string)($row['signal'] ?? ''))?->continuesAgentWork() === true);
    }

    /**
     * A tool call the platform refused (a gate, a duplicate) or whose execution failed — read from the tool call's status,
     * the refusal flags and the head of the result (`toolStatus`, `refusedFlag`, `resultHead`).
     *
     * @param array<string, mixed> $row
     */
    public static function isFailedToolCallRow(array $row): bool
    {
        if (($row['toolStatus'] ?? null) === self::TOOL_STATUS_FAILED || in_array($row['refusedFlag'] ?? null, [1, '1', true], true)) {
            return true;
        }
        $head = (string)($row['resultHead'] ?? '');
        foreach (self::FAILED_TOOL_RESULT_PREFIXES as $prefix) {
            if (str_starts_with($head, $prefix)) {
                return true;
            }
        }
        return str_contains($head, 'REJECTED');
    }

    /**
     * What kind of failure a tool-call row is, or null when it is no failure: the refusal flags decide
     * first, then the stored literals of the loop and the executor (`dupRefused`, `gateRefused`, `toolStatus`,
     * `resultHead` — the head must reach past the executor's prefix, 300 characters). Rows written before the flags
     * existed fall back to the literals; what nothing matches is `unknown`, never hidden.
     *
     * @param array<string, mixed> $row
     */
    public static function classifyToolCallFailure(array $row): ?string
    {
        if (in_array($row['dupRefused'] ?? null, [1, '1', true], true)) {
            return self::FAILURE_KIND_REPEAT_CORRECTIVE;
        }
        if (in_array($row['gateRefused'] ?? null, [1, '1', true], true)) {
            return self::FAILURE_KIND_GATE_REFUSAL;
        }
        if (!static::isFailedToolCallRow($row)) {
            return null;
        }
        $head = (string)($row['resultHead'] ?? '');
        return match (true) {
            str_starts_with($head, self::LOOP_ABORT_RESULT_PREFIX) => self::FAILURE_KIND_LOOP_ABORT,
            str_starts_with($head, 'You have already called'), str_starts_with($head, 'You already called') => self::FAILURE_KIND_REPEAT_CORRECTIVE,
            (bool)preg_match('/exceeded the \d+s execution timeout/', $head) => self::FAILURE_KIND_TIMEOUT,
            str_starts_with($head, 'No item updated'), str_contains($head, 'REJECTED') => self::FAILURE_KIND_NO_EFFECT,
            str_contains($head, 'NOT executed') && str_contains($head, 'MANDATE') => self::FAILURE_KIND_GATE_REFUSAL,
            static::containsExternalProviderMarker($head) => self::FAILURE_KIND_EXTERNAL,
            (bool)preg_match('/\b(is missing|is required|invalid argument|must be (a|an|one of)|not a valid)\b/i', $head) => self::FAILURE_KIND_VALIDATION,
            str_starts_with($head, 'Execution failed'), str_contains($head, 'Tool error'), ($row['toolStatus'] ?? null) === self::TOOL_STATUS_FAILED => self::FAILURE_KIND_EXCEPTION,
            default => self::FAILURE_KIND_UNKNOWN,
        };
    }

    protected static function containsExternalProviderMarker(string $text): bool
    {
        foreach (static::newInstance()::EXTERNAL_PROVIDER_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The owner's own inbound — mirrors {@see AIConversationMessage::isOwnerAuthoredInbound()} over a row.
     *
     * @param array<string, mixed> $row
     */
    public static function isOwnerAuthoredRow(array $row): bool
    {
        $messageType = $row['t'] ?? null;
        if ($messageType === AIConversationMessage::MESSAGE_TEXT
            && (($row['role'] ?? null) === AIConversationMessage::ROLE_USER || ($row['author'] ?? null) === AIConversationMessage::AUTHOR_USER)
        ) {
            return true;
        }
        return $messageType === AIConversationMessage::MESSAGE_SIGNAL
            && ConversationSignalSources::tryFrom((string)($row['signal'] ?? ''))?->isOwnerAuthored() === true;
    }

    /**
     * A row that came from the owner's side: their own inbound, or one of {@see \DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource::marksOwnerPresence()} (a
     * dashboard task, a call, a navigation out of or back into the chat).
     *
     * @param array<string, mixed> $row
     */
    public static function isOwnerOriginRow(array $row): bool
    {
        return static::isOwnerAuthoredRow($row)
            || (($row['t'] ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && ConversationSignalSources::tryFrom((string)($row['signal'] ?? ''))?->marksOwnerPresence() === true);
    }

    /**
     * A machine work signal that starts a turn — the set {@see AIConversationMessages::isTurnInitiatorMessage()}
     * recognises beside the owner's messages.
     *
     * @param array<string, mixed> $row
     */
    public static function isWorkInitiatorRow(array $row): bool
    {
        return ($row['t'] ?? null) === AIConversationMessage::MESSAGE_SIGNAL
            && ConversationSignalSources::tryFrom((string)($row['signal'] ?? ''))?->isWorkOrder() === true;
    }

    /**
     * Nearest-rank percentile; null without values.
     *
     * @param float[] $values
     */
    public static function percentile(array $values, float $fraction): ?float
    {
        if ($values === []) {
            return null;
        }
        $values = array_values($values);
        sort($values);
        $rank = (int)ceil($fraction * count($values));
        return $values[max(0, min(count($values) - 1, $rank - 1))];
    }

    /** A `Y-m-d H:i:s[.uuuuuu]` timestamp as unix seconds with the fraction; null when unreadable. */
    public static function toUnixSeconds(mixed $value): ?float
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        $time = strtotime(substr($text, 0, 19));
        if ($time === false) {
            return null;
        }
        $fraction = preg_match('/\.(\d{1,6})/', $text, $fractionMatch) ? (float)('0.' . $fractionMatch[1]) : 0.0;
        return $time + $fraction;
    }
}
