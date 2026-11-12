<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

/**
 * How one run of a recorded-run comparison reads the ids it minted, so two runs of one
 * build compare equal while a changed id is still a difference — the rule of
 * the host's comparison id ordinals, applied to a whole run:
 *
 * - an id counts as MINTED by the run only when it lies above the high-water mark its table had when the run started
 *   (the marks of every table are read at the start and stored with the recording); an id at or below the mark
 *   existed before the run and stays RAW — a changed reference to an existing row is reported;
 * - a minted id becomes the ordinal of its CREATION among the run's new rows of its table — its position in
 *   {@see $createdIdsByTable} (ids ascending): `<conversation#1>`, `<message#3>`, `<bookings#2>`. It is the same
 *   ordinal the played-answer translation uses ({@see AgentRecordedRunPlayedAnswerIdTranslation}), so a replay that
 *   creates two rows in the other order reads as a difference rather than as equal. An id above the mark that the
 *   created-id read did not list (a capped or a scoped-out table) falls back to its FIRST appearance (`<bookings~1>`);
 * - ids are replaced only where they are declared: the row's own `id`, the declared id-bearing columns and JSON keys
 *   ({@see TABLE_FAMILY_BY_ID_KEY}), and — where an id can only stand in free text (message text, tool results,
 *   request bodies) — a number of at least {@see MIN_TEXT_WIDE_ID_DIGITS} digits that IS one of the run's minted ids
 *   ({@see $mintedIdTables}, read at the end of the run from the tables that grew). An ordinary count ("312
 *   visitors") is never an id; a 3-digit batch id in free text stays raw;
 * - the scope ids a run creates for itself before the recording starts (a throwaway agent space and the host's rows
 *   around it) are declared by the harness by name and read as `<name>` (e.g. `<agentSpace>`) — the other run's scope
 *   ids too, because a played answer carries the recording's ids.
 *
 * Besides ids: timestamps, dates, clock times, sub-agent run durations ({@see RUN_DURATION_TEXT_REPLACEMENTS}), UUIDs
 * and 32+ hex tokens become placeholders, and the declared timing keys ({@see TIMING_KEYS}) are dropped. Stateful (the
 * ordinals), serializable into the recording ({@see toArray()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunIdNormalization
{
    /** A free-text number shorter than this is never read as an id. */
    public const int MIN_TEXT_WIDE_ID_DIGITS = 6;

    /** The id families of the conversation tree; every other table is its own family (named after the table). */
    public const string FAMILY_CONVERSATION = 'conversation';
    public const string FAMILY_MESSAGE = 'message';
    public const string FAMILY_DELEGATION_BATCH = 'delegationBatch';
    public const string FAMILY_COMPACTION_NODE = 'compactionNode';
    public const string FAMILY_SCHEDULE = 'schedule';

    /**
     * The declared id-bearing columns and JSON keys → the family whose ids they carry. `supervisedConversationId`
     * points at a supervisor conversation OUTSIDE the tree (the snapshot holds the tree only; a supervised run is not
     * supported by the comparison yet — record with `--no-supervisor`).
     */
    public const array TABLE_FAMILY_BY_ID_KEY = [
        'aiConversationId' => self::FAMILY_CONVERSATION,
        'conversationId' => self::FAMILY_CONVERSATION,
        'rootConversationId' => self::FAMILY_CONVERSATION,
        'parentConversationId' => self::FAMILY_CONVERSATION,
        'supervisedConversationId' => self::FAMILY_CONVERSATION,
        'delegatedConversationId' => self::FAMILY_CONVERSATION,
        'childConversationId' => self::FAMILY_CONVERSATION,
        'delegatedFromMessageId' => self::FAMILY_MESSAGE,
        'spawnedByMessageId' => self::FAMILY_MESSAGE,
        'aiConversationMessageId' => self::FAMILY_MESSAGE,
        'messageId' => self::FAMILY_MESSAGE,
        'sourceUserMessageId' => self::FAMILY_MESSAGE,
        'toolCallOriginMessageId' => self::FAMILY_MESSAGE,
        'delegationBatchId' => self::FAMILY_DELEGATION_BATCH,
        'scheduleId' => self::FAMILY_SCHEDULE,
    ];

    /** Keys that only carry how long or when something ran: dropped before the comparison, at any depth. */
    public const array TIMING_KEYS = [
        'executionLease', 'durationSeconds', 'startedAt', 'finishedAt', 'executedAt', 'executionStartedAt',
        'durationMs', 'elapsedMs', 'loopOverheadMs', 'loopStepOverheadMs', 'argusCallsMs', 'argusEndpointTimings', 'lastPersistBreakdownMs',
        'lastPersistAfterBreakdownMs', 'lastPersistAfterMs', 'previousToolCallPersistAfterBreakdownMs',
        'previousToolCallPersistAfterMs', 'answerRequestMs', 'briefingMs', 'currentStepPreCallMs', 'currentStepSchemasMs',
        'preCallMs', 'schemasMs', 'decisionModelMs', 'averageWriteMs', 'slowestWriteMs', 'avgDurationMs',
        'totalDurationMs', 'waitMs', 'atUnixMs', 'occurredAtUnixMs', 'receivedAtUnixMs', 'turnStartMs',
        'delegationRoundTripsMs', 'openRequestMs', 'medianTurnGapMs', 'dispatchedAtWallUs',
    ];

    /**
     * Keys a worker job writes into a stored row AFTER the turn wrote it — the tool-result injection screening verdict
     * (`pending` until the screening job ran, then `clean` / `removed` with its probability). Whether the job finished
     * before the run was read is timing, not the run: dropped before the comparison, at any depth. A removal that
     * changes what the model reads still shows in the model calls.
     */
    public const array ASYNC_VERDICT_KEYS = ['injectionScreening', 'injectionProbability'];

    /** What a wall-clock duration of a sub-agent run reads as in a comparison. */
    public const string DURATION_PLACEHOLDER = '<duration>';

    /**
     * The sub-agent texts that render a wall-clock duration ({@see \DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun::formatDuration()}:
     * `13 s`, `2 min`, `1 h`), each anchored on the text around it, → the replacement that puts
     * {@see DURATION_PLACEHOLDER} in place of the duration. A duration anywhere else (a tool result, the model's prose)
     * stays raw:
     *
     * - `COMPLETED (run 1, 13 s).` — the run-end notification and the status report's ended row;
     * - `RUNNING for 13 s (run 2)` — the status report's unfinished row;
     * - `run 1: COMPLETED, ran 13 s` / `run 2: RUNNING, for 13 s` — the drill-down run rows;
     * - `COMPLETED 13 s ago`, `(COMPLETED, 20 s ago)`, `last step 20 s ago:` — run ends and live steps;
     * - `#2 «label» (13 s)` — the run-end notification's "Still running" line.
     */
    public const array RUN_DURATION_TEXT_REPLACEMENTS = [
        '/(\(run \d+, )\d+ (?:s|min|h)(?=\))/' => '$1' . self::DURATION_PLACEHOLDER,
        '/( for )\d+ (?:s|min|h)(?= \(run \d+)/' => '$1' . self::DURATION_PLACEHOLDER,
        '/(\brun \d+: [A-Z_]+(?: [A-Z_]+)*, (?:ran|for) )\d+ (?:s|min|h)\b/' => '$1' . self::DURATION_PLACEHOLDER,
        '/\b\d+ (?:s|min|h)( ago\b)/' => self::DURATION_PLACEHOLDER . '$1',
        '/(» \()\d+ (?:s|min|h)(?=\))/u' => '$1' . self::DURATION_PLACEHOLDER,
    ];

    /** @var array<string, int> per table: the highest id when the run started */
    public array $highWaterMarksByTable = [];

    /** @var array<string, string> per family: its table (the tree families; every other family is a table) */
    public array $tableByFamily = [];

    /** @var array<string, string[]> per minted id (≥ {@see MIN_TEXT_WIDE_ID_DIGITS} digits): the tables that minted it */
    public array $mintedIdTables = [];

    /** @var array<string, int[]> per table: every id the run created (above the mark), ascending — the creation order a replay translates played ids by */
    public array $createdIdsByTable = [];

    /** @var array<string, string> per scope id: its placeholder */
    public array $scopePlaceholdersById = [];

    /** @var array<string, array<string, string>> per family: minted id NOT in the created-id read → its first-appearance placeholder */
    public array $placeholdersByFamily = [];

    /** @var array<string, array<string, int>> per table: created id → 0-based position (built on demand, not stored) */
    protected array $creationOrdinalsByTable = [];

    /** @var array<string, string[]>|null per created id of any length: the tables that created it (built on demand, not stored) */
    protected ?array $createdIdTables = null;

    /**
     * @param array<string, int> $highWaterMarksByTable
     * @param array<string, string> $tableByFamily
     */
    public static function withHighWaterMarks(array $highWaterMarksByTable, array $tableByFamily): static
    {
        $agentRecordedRunIdNormalization = new static();
        $agentRecordedRunIdNormalization->highWaterMarksByTable = $highWaterMarksByTable;
        $agentRecordedRunIdNormalization->tableByFamily = $tableByFamily;
        return $agentRecordedRunIdNormalization;
    }

    /** @param array<string, mixed> $state from {@see toArray()} */
    public static function fromArray(array $state): static
    {
        $agentRecordedRunIdNormalization = new static();
        $agentRecordedRunIdNormalization->highWaterMarksByTable = array_map('intval', (array)($state['highWaterMarksByTable'] ?? []));
        $agentRecordedRunIdNormalization->tableByFamily = (array)($state['tableByFamily'] ?? []);
        $agentRecordedRunIdNormalization->mintedIdTables = (array)($state['mintedIdTables'] ?? []);
        $agentRecordedRunIdNormalization->createdIdsByTable = (array)($state['createdIdsByTable'] ?? []);
        $agentRecordedRunIdNormalization->scopePlaceholdersById = (array)($state['scopePlaceholdersById'] ?? []);
        $agentRecordedRunIdNormalization->placeholdersByFamily = (array)($state['placeholdersByFamily'] ?? []);
        return $agentRecordedRunIdNormalization;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'highWaterMarksByTable' => $this->highWaterMarksByTable,
            'tableByFamily' => $this->tableByFamily,
            'mintedIdTables' => $this->mintedIdTables,
            'createdIdsByTable' => $this->createdIdsByTable,
            'scopePlaceholdersById' => $this->scopePlaceholdersById,
            'placeholdersByFamily' => $this->placeholdersByFamily,
        ];
    }

    /** @param array<string, int|string|null> $scopeIdsByName e.g. `['agentSpace' => 4711]`; empty ids are skipped */
    public function addScopeIds(array $scopeIdsByName): void
    {
        foreach ($scopeIdsByName as $scopeName => $scopeId) {
            if ($scopeId !== null && ctype_digit((string)$scopeId) && (int)$scopeId > 0) {
                $this->scopePlaceholdersById[(string)$scopeId] ??= '<' . $scopeName . '>';
            }
        }
    }

    /**
     * Declares the ids the run minted (read at the end of the run: per table that grew, its ids above the mark); only
     * ids of at least {@see MIN_TEXT_WIDE_ID_DIGITS} digits are kept — the ones free text is searched for.
     *
     * @param array<string, int[]|string[]> $mintedIdsByTable
     */
    public function addMintedIds(array $mintedIdsByTable): void
    {
        foreach ($mintedIdsByTable as $table => $mintedIds) {
            foreach ($mintedIds as $mintedId) {
                $mintedIdText = (string)$mintedId;
                if (ctype_digit($mintedIdText) && $this->isAboveHighWaterMark((string)$table, $mintedIdText)) {
                    $this->createdIdsByTable[(string)$table][] = (int)$mintedIdText;
                    unset($this->creationOrdinalsByTable[(string)$table]);
                    $this->createdIdTables = null;
                }
                if (strlen($mintedIdText) >= self::MIN_TEXT_WIDE_ID_DIGITS && $this->isAboveHighWaterMark((string)$table, $mintedIdText)) {
                    $this->mintedIdTables[$mintedIdText][] = (string)$table;
                }
            }
        }
    }

    /** $id of $family as the comparison reads it: the scope placeholder, the creation ordinal of a minted id, or the raw id. */
    public function normalizeIdOfFamily(string $family, int|string $id): int|string
    {
        $idText = (string)$id;
        if (isset($this->scopePlaceholdersById[$idText])) {
            return $this->scopePlaceholdersById[$idText];
        }
        $table = $this->tableByFamily[$family] ?? $family;
        if (!ctype_digit($idText) || !$this->isAboveHighWaterMark($table, $idText)) {
            return $id;
        }
        return $this->ordinalPlaceholder($family, $table, $idText);
    }

    /** The 1-based creation ordinal of $idText among the run's new rows of $table, or null when the read did not list it. */
    public function findCreationOrdinal(string $table, string $idText): ?int
    {
        if (!isset($this->creationOrdinalsByTable[$table])) {
            $createdIds = array_map('intval', $this->createdIdsByTable[$table] ?? []);
            sort($createdIds);
            $this->creationOrdinalsByTable[$table] = array_flip(array_map('strval', $createdIds));
        }
        $position = $this->creationOrdinalsByTable[$table][$idText] ?? null;
        return $position !== null ? $position + 1 : null;
    }

    /**
     * A free text: timestamps (with the weekday beside them), sub-agent run durations and tokens become placeholders, then every minted id or scope id of ≥ 6 digits, then a
     * shorter number written as an id marker (`#57414`, the "Booking saved. #57414" a tool answers with) when the run
     * created that id.
     */
    public function normalizeText(string $text): string
    {
        $normalized = (string)preg_replace(
            [
                '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?/',
                '/\d{4}-\d{2}-\d{2}/',
                '/\b\d{1,2}:\d{2}(:\d{2})?\b/',
                '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
                '/\b[0-9a-f]{32,}\b/i',
            ],
            ['<timestamp>', '<date>', '<time>', '<uuid>', '<hex>'],
            $text
        );
        // the weekday the clock signal names beside its timestamp moves with the replay day like the timestamp itself
        $normalized = (string)preg_replace('/(<timestamp>|<date>) \((Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\)/', '$1 (<weekday>)', $normalized);
        $normalized = self::normalizeRunDurations($normalized);
        $normalized = (string)preg_replace_callback(
            '/\b\d{' . self::MIN_TEXT_WIDE_ID_DIGITS . ',}\b/',
            fn (array $match): string => $this->normalizeTextWideNumber($match[0]),
            $normalized
        );
        return (string)preg_replace_callback(
            '/#(\d{1,' . (self::MIN_TEXT_WIDE_ID_DIGITS - 1) . '})\b/',
            fn (array $match): string => '#' . $this->normalizeMarkedShortNumber($match[1]),
            $normalized
        );
    }

    /** A text with every sub-agent run duration of {@see RUN_DURATION_TEXT_REPLACEMENTS} read as {@see DURATION_PLACEHOLDER}. */
    public static function normalizeRunDurations(string $text): string
    {
        return (string)preg_replace(array_keys(self::RUN_DURATION_TEXT_REPLACEMENTS), array_values(self::RUN_DURATION_TEXT_REPLACEMENTS), $text);
    }

    /**
     * A stored structure (a JSON column, a tool call, a message body): timing keys dropped, declared id keys normalized
     * by their family, every other string read as free text and every other integer of ≥ 6 digits as a possible id.
     *
     * @param array<mixed> $structure
     * @return array<mixed>
     */
    public function normalizeStructure(array $structure): array
    {
        foreach ($structure as $key => $value) {
            if (is_string($key) && (in_array($key, self::TIMING_KEYS, true) || in_array($key, self::ASYNC_VERDICT_KEYS, true))) {
                unset($structure[$key]);
                continue;
            }
            if (is_array($value)) {
                $structure[$key] = $this->normalizeStructure($value);
            } elseif (is_string($key) && isset(self::TABLE_FAMILY_BY_ID_KEY[$key]) && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
                $structure[$key] = $this->normalizeIdOfFamily(self::TABLE_FAMILY_BY_ID_KEY[$key], $value);
            } elseif (is_string($value)) {
                $structure[$key] = $this->normalizeText($value);
            } elseif (is_int($value) && strlen((string)$value) >= self::MIN_TEXT_WIDE_ID_DIGITS) {
                $normalizedNumber = $this->normalizeTextWideNumber((string)$value);
                $structure[$key] = $normalizedNumber === (string)$value ? $value : $normalizedNumber;
            }
        }
        return $structure;
    }

    /** A text that may be JSON: a JSON object or list is normalized as a structure and encoded again, else as text. */
    public function normalizeJsonOrText(string $text): string
    {
        $trimmedText = ltrim($text);
        $decoded = $trimmedText !== '' && ($trimmedText[0] === '{' || $trimmedText[0] === '[') ? json_decode($text, true) : null;
        if (is_array($decoded)) {
            return (string)json_encode($this->normalizeStructure($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $this->normalizeText($text);
    }

    protected function normalizeTextWideNumber(string $number): string
    {
        if (isset($this->scopePlaceholdersById[$number])) {
            return $this->scopePlaceholdersById[$number];
        }
        $mintingTables = array_values(array_unique($this->mintedIdTables[$number] ?? []));
        if ($mintingTables === []) {
            return $number;
        }
        // an id two tables minted in one run cannot be told apart in free text: one shared family for those
        return count($mintingTables) === 1
            ? $this->ordinalPlaceholder($this->familyOfTable($mintingTables[0]), $mintingTables[0], $number)
            : $this->ordinalPlaceholder('id', '', $number);
    }

    /** A short number behind an id marker: the creation ordinal when the run created it in exactly one table, else raw. */
    protected function normalizeMarkedShortNumber(string $number): string
    {
        if ($this->createdIdTables === null) {
            $this->createdIdTables = [];
            foreach ($this->createdIdsByTable as $table => $createdIds) {
                foreach ($createdIds as $createdId) {
                    $this->createdIdTables[(string)$createdId][] = (string)$table;
                }
            }
        }
        $creatingTables = array_values(array_unique($this->createdIdTables[$number] ?? []));
        return count($creatingTables) === 1
            ? $this->ordinalPlaceholder($this->familyOfTable($creatingTables[0]), $creatingTables[0], $number)
            : $number;
    }

    protected function isAboveHighWaterMark(string $table, string $idText): bool
    {
        return isset($this->highWaterMarksByTable[$table]) && (int)$idText > $this->highWaterMarksByTable[$table];
    }

    protected function familyOfTable(string $table): string
    {
        $family = array_search($table, $this->tableByFamily, true);
        return is_string($family) ? $family : $table;
    }

    /** The creation ordinal `<family#n>` when the created-id read listed the id, else its first appearance `<family~n>`. */
    protected function ordinalPlaceholder(string $family, string $table, string $idText): string
    {
        $creationOrdinal = $table !== '' ? $this->findCreationOrdinal($table, $idText) : null;
        if ($creationOrdinal !== null) {
            return '<' . $family . '#' . $creationOrdinal . '>';
        }
        if (!isset($this->placeholdersByFamily[$family][$idText])) {
            $this->placeholdersByFamily[$family][$idText] = '<' . $family . '~' . (count($this->placeholdersByFamily[$family] ?? []) + 1) . '>';
        }
        return $this->placeholdersByFamily[$family][$idText];
    }
}
