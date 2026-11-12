<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The deterministic TOOL TELEMETRY a compaction node preserves for the supervisor: once a
 * range is summarised its raw tool calls are gone from the loaded tail, and the supervisor's cumulative tally —
 * derived from the tail alone — claimed that writes which had succeeded "never happened" and steered a false
 * customer-facing correction. The slot rides {@see AIConversationCompactionPreservedFacts} (node data, never rendered
 * into the summariser input or the agent-side summary text, so the agent's cacheable prefix stays byte-stable) and the
 * supervisor reads it off the cover nodes that are already loaded for the turn — zero extra queries.
 *
 * Four parts with DIFFERENT truncation rules (a capped excerpt alone can erase the very write being challenged, and
 * "an excerpt was capped" must never be confused with "the counts are unknown"):
 * - {@see $toolCompletionCounts}: COMPLETED calls per tool name, ALL tools — merged by SUM, NEVER capped (bounded by
 *   the tool catalogue). Complete negative evidence — while {@see $countsIncomplete} is false — a count of 0 is the
 *   only ground for "tool X never ran".
 * - {@see $countsIncomplete}: true when a merged child was written BEFORE this slot existed (its calls are unknown
 *   and NOT in the counts) — propagated by OR; the supervisor then may not read an absent tool as "never ran".
 * - {@see $persistedWrites}: excerpt lines for successful WRITE calls — illustrative, capped per tool and per node.
 * - {@see $writesOmitted}: true when any excerpt was dropped here or in any merged child (a cap) — the counts stay
 *   authoritative; propagated by OR so a mixed roll-up can never claim excerpt completeness.
 *
 * SUCCESS = status COMPLETED (never merely "not FAILED"), excluding refused duplicates ({@see ToolCall::$wasRefusedAsDuplicate})
 * and the meta tools (load_skills, load_tool, delegate, check_delegations, the plan tools). Pure value logic — the same predicates the
 * supervisor applies to the raw tail live here so cover and tail can never disagree.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCompactionToolTelemetry extends ValueObject
{
    /** Excerpt lines kept per tool name per node / per merge (the newest survive). */
    public const int MAX_EXCERPTS_PER_TOOL = 3;

    /** Total excerpt characters per node — beyond it the OLDEST lines are dropped (and `writesOmitted` set). */
    public const int MAX_EXCERPT_CHARS_PER_NODE = 4000;

    /** Argument JSON characters kept in an excerpt line. */
    public const int EXCERPT_ARGUMENTS_CHARS = 220;

    /** Result characters kept in an excerpt line. */
    public const int EXCERPT_RESULT_CHARS = 150;

    /** @var AIConversationCompactionToolCompletionCount[] COMPLETED calls per tool name — complete, never capped. */
    public array $toolCompletionCounts = [];

    /** @var string[] `[<index>] <toolName> <arguments JSON> => <result>` lines of successful WRITE calls, oldest first. */
    public array $persistedWrites = [];

    /** @var bool Whether any write excerpt is missing (a cap here or in a merged child) — the counts stay complete. */
    public bool $writesOmitted = false;

    /** @var bool Whether a merged child predates this slot — its calls are unknown and NOT in the counts. */
    public bool $countsIncomplete = false;

    /** The meta tools that never count as work ({@see AgentMetaTools::isExcludedFromCompactionWorkCounts()}). */
    public static function isMetaToolName(string $toolName): bool
    {
        return AgentMetaTools::isExcludedFromCompactionWorkCounts($toolName);
    }

    /** Whether $toolCall counts as a COMPLETED work call: status COMPLETED, not a refused duplicate, not a meta tool. */
    public static function isCountedCompletion(ToolCall $toolCall): bool
    {
        $toolName = (string)($toolCall->name ?? '');
        return $toolName !== ''
            && ($toolCall->status ?? null) === AIConversationMessage::STATUS_COMPLETED
            && !$toolCall->wasRefusedAsDuplicate
            && !AgentMetaTools::isExcludedFromCompactionWorkCounts($toolName);
    }

    /** Whether $toolCall is a COMPLETED WRITE (the counted predicate + the tool's declared effect, {@see MCPToolEffectCatalog}). */
    public static function isCountedWrite(ToolCall $toolCall): bool
    {
        return self::isCountedCompletion($toolCall) && $toolCall->changesState();
    }

    /**
     * The ONE excerpt line format cover and tail share: `[<index>] <toolName> <arguments JSON capped> => <result capped>`
     * (result truncated BEFORE the whitespace collapse — the regex must never run over a multi-KB result).
     */
    public static function buildPersistedWriteExcerptLine(int $messageIndex, ToolCall $toolCall): string
    {
        $argumentsJson = mb_substr(
            (string)json_encode($toolCall->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0,
            self::EXCERPT_ARGUMENTS_CHARS
        );
        $resultExcerpt = mb_substr((string)($toolCall->result ?? ''), 0, 600);
        $resultExcerpt = mb_substr(preg_replace('/\s+/', ' ', $resultExcerpt) ?? '', 0, self::EXCERPT_RESULT_CHARS);
        return "[$messageIndex] {$toolCall->name} {$argumentsJson} => {$resultExcerpt}";
    }

    /** The tool name an excerpt line carries (the token after the `[index] ` prefix) — for the per-tool cap. */
    public static function toolNameOfExcerptLine(string $excerptLine): string
    {
        if (preg_match('/^\[\d+\] (\S+)/', $excerptLine, $lineParts) === 1) {
            return $lineParts[1];
        }
        return '';
    }

    /**
     * Build the telemetry of a RAW range (a leaf): counts over every counted completion, excerpts over every counted
     * write, de-duplicated on tool name + arguments (a byte-identical re-send is one fact), then capped.
     *
     * @param iterable<int, AIConversationMessage> $messages The raw messages of the range, in conversation order.
     */
    public static function fromMessages(iterable $messages): static
    {
        $telemetry = new static();
        $countsByToolName = [];
        $excerptLines = [];
        $seenExcerptKeys = [];
        foreach ($messages as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if (!$toolCall instanceof ToolCall || !self::isCountedCompletion($toolCall)) {
                continue;
            }
            $toolName = (string)$toolCall->name;
            $countsByToolName[$toolName] = ($countsByToolName[$toolName] ?? 0) + 1;
            if (!self::isCountedWrite($toolCall)) {
                continue;
            }
            $excerptLine = self::buildPersistedWriteExcerptLine((int)($message->indexInConversation ?? 0), $toolCall);
            // De-duplicate on the RENDERED line minus its index (what the supervisor would read twice), not the raw
            // arguments — two calls differing only past the argument cap would otherwise render as identical lines.
            $excerptKey = preg_replace('/^\[\d+\] /', '', $excerptLine) ?? $excerptLine;
            if (isset($seenExcerptKeys[$excerptKey])) {
                continue;
            }
            $seenExcerptKeys[$excerptKey] = true;
            $excerptLines[] = $excerptLine;
        }
        foreach ($countsByToolName as $toolName => $completedCount) {
            $telemetry->addCompletedCount((string)$toolName, $completedCount);
        }
        $telemetry->replaceExcerptsWithCaps($excerptLines, false);
        return $telemetry;
    }

    /**
     * Merge the telemetry of K children (oldest → newest) for a roll-up: counts SUM, excerpts concatenated in order
     * and re-capped, `writesOmitted` ORed; a child WITHOUT the slot (a node written before it existed) sets
     * `countsIncomplete` — its calls are unknown, so a mixed old/new roll-up never claims complete counts.
     *
     * @param array<int, AIConversationCompactionToolTelemetry|null> $childTelemetries Null = the child has no slot.
     */
    public static function merge(array $childTelemetries): static
    {
        $merged = new static();
        $countsByToolName = [];
        $excerptLines = [];
        $anyChildOmitted = false;
        $anyChildCountsIncomplete = false;
        foreach ($childTelemetries as $childTelemetry) {
            if ($childTelemetry === null) {
                $anyChildCountsIncomplete = true;
                continue;
            }
            $anyChildCountsIncomplete = $anyChildCountsIncomplete || $childTelemetry->countsIncomplete;
            foreach ($childTelemetry->getCompletedCountsByToolName() as $toolName => $completedCount) {
                $countsByToolName[$toolName] = ($countsByToolName[$toolName] ?? 0) + $completedCount;
            }
            foreach ($childTelemetry->persistedWrites as $excerptLine) {
                $excerptLines[] = $excerptLine;
            }
            $anyChildOmitted = $anyChildOmitted || $childTelemetry->writesOmitted;
        }
        foreach ($countsByToolName as $toolName => $completedCount) {
            $merged->addCompletedCount((string)$toolName, $completedCount);
        }
        $merged->replaceExcerptsWithCaps($excerptLines, $anyChildOmitted);
        $merged->countsIncomplete = $anyChildCountsIncomplete;
        return $merged;
    }

    /** @return array<string, int> Completed counts keyed by tool name (a read view over the typed list). */
    public function getCompletedCountsByToolName(): array
    {
        $countsByToolName = [];
        foreach ($this->toolCompletionCounts as $toolCompletionCount) {
            $countsByToolName[$toolCompletionCount->toolName] = ($countsByToolName[$toolCompletionCount->toolName] ?? 0)
                + $toolCompletionCount->completedCount;
        }
        return $countsByToolName;
    }

    /** COMPLETED calls of $toolName in the covered range (0 when unknown — check {@see $countsIncomplete}). */
    public function getCompletedCountForTool(string $toolName): int
    {
        return $this->getCompletedCountsByToolName()[$toolName] ?? 0;
    }

    /** Add $count completions of $toolName (summing into an existing entry). */
    public function addCompletedCount(string $toolName, int $count): void
    {
        foreach ($this->toolCompletionCounts as $toolCompletionCount) {
            if ($toolCompletionCount->toolName === $toolName) {
                $toolCompletionCount->completedCount += $count;
                return;
            }
        }
        $toolCompletionCount = new AIConversationCompactionToolCompletionCount();
        $toolCompletionCount->toolName = $toolName;
        $toolCompletionCount->completedCount = $count;
        $this->toolCompletionCounts[] = $toolCompletionCount;
    }

    /**
     * Replace the excerpts with $excerptLines (oldest first) under the two caps — the newest
     * {@see MAX_EXCERPTS_PER_TOOL} lines per tool survive, then the OLDEST lines are dropped until the node stays
     * under {@see MAX_EXCERPT_CHARS_PER_NODE}. Sets `writesOmitted` when anything was dropped, ORed with $alreadyOmitted.
     *
     * @param string[] $excerptLines
     */
    protected function replaceExcerptsWithCaps(array $excerptLines, bool $alreadyOmitted): void
    {
        $omitted = $alreadyOmitted;
        // Per-tool cap: walk newest → oldest, keep the first MAX per tool.
        $keptPerTool = [];
        $keptLines = [];
        foreach (array_reverse($excerptLines) as $excerptLine) {
            $toolName = self::toolNameOfExcerptLine($excerptLine);
            $keptPerTool[$toolName] = ($keptPerTool[$toolName] ?? 0) + 1;
            if ($keptPerTool[$toolName] > self::MAX_EXCERPTS_PER_TOOL) {
                $omitted = true;
                continue;
            }
            $keptLines[] = $excerptLine;
        }
        $keptLines = array_reverse($keptLines);
        // Byte cap: drop the oldest until the node fits.
        $totalChars = array_sum(array_map(static fn (string $line): int => mb_strlen($line) + 1, $keptLines));
        while ($keptLines !== [] && $totalChars > self::MAX_EXCERPT_CHARS_PER_NODE) {
            $droppedLine = array_shift($keptLines);
            $totalChars -= mb_strlen((string)$droppedLine) + 1;
            $omitted = true;
        }
        $this->persistedWrites = array_values($keptLines);
        $this->writesOmitted = $omitted;
    }
}
