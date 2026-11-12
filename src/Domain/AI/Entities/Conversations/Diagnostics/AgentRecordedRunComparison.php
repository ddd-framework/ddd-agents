<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecordingPlayedRequests;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The comparison of a replayed run with its recording — the identity proof of every
 * extraction step: the persisted rows of the conversation TREE (the root and its delegation children, their messages,
 * the delegation batches, the compaction nodes as a section of their own), the Messenger messages the run dispatched
 * and the requests the loop sent to Argus (the model and decision payloads), each normalized by its run's
 * {@see AgentRecordedRunIdNormalization} (minted ids above the run-start high-water marks → ordinals, declared scope
 * ids → placeholders, timestamps and tokens → placeholders, timing keys dropped), must be equal. The volatile columns
 * (change stamps, heartbeat, run token, embeddings) are left out. Everything else is compared verbatim. Pure.
 *
 * The tree is the root and the conversations whose `rootConversationId` is the root: a supervisor conversation
 * (`supervisedConversationId`) lies outside it — record a supervised run is not supported yet (`--no-supervisor`).
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunComparison extends ValueObject
{
    /** Columns of a tree row that differ between two runs of one build: change stamps, liveness, ownership, vectors. */
    public const array VOLATILE_ROW_COLUMNS = ['created', 'updated', 'runHeartbeatAt', 'runToken', 'summaryEmbedding', 'messageEmbedding'];

    /** Body keys of a dispatched message that only say where it was dispatched from. */
    public const array IGNORED_DISPATCH_BODY_KEYS = ['dispatchedFromWorkspaceDir', 'dispatchedFromEnvironment'];

    public const string SECTION_CONVERSATIONS = 'conversations';
    public const string SECTION_MESSAGES = 'messages';
    public const string SECTION_DELEGATION_BATCHES = 'delegationBatches';
    public const string SECTION_COMPACTION_NODES = 'compactionNodes';
    public const string SECTION_DISPATCHES = 'dispatches';

    /** The tree sections in snapshot order (the compaction nodes — the conversation memory — are their own section) and the id family of each section's rows. */
    public const array TREE_SECTION_FAMILIES = [
        self::SECTION_CONVERSATIONS => AgentRecordedRunIdNormalization::FAMILY_CONVERSATION,
        self::SECTION_MESSAGES => AgentRecordedRunIdNormalization::FAMILY_MESSAGE,
        self::SECTION_DELEGATION_BATCHES => AgentRecordedRunIdNormalization::FAMILY_DELEGATION_BATCH,
        self::SECTION_COMPACTION_NODES => AgentRecordedRunIdNormalization::FAMILY_COMPACTION_NODE,
    ];

    /** How many differences a comparison reports at most. */
    /** Stored tool-call keys newer than the goldens; compared only when the recording holds them. */
    protected const array TOOL_CALL_KEYS_ADDED_AFTER_RECORDINGS = ['effect', 'executionCosts'];

    public const int MAX_REPORTED_DIFFERENCES = 40;

    /** @var string[] the differences found, empty when the replay is identical */
    public array $differences = [];

    /** @var string[] the ids translated inside played answers (a live-tools replay), reported beside the differences */
    public array $playedAnswerIdTranslations = [];

    /** @var string[] what the comparison could only read approximately (tables read unscoped), reported beside the differences */
    public array $notes = [];

    public function isIdentical(): bool
    {
        return $this->differences === [];
    }

    /**
     * The normalized snapshot of a conversation tree, comparable across runs: one list of rows per section, each row
     * carrying a `_label` that names it in a difference. Rows are normalized in section order, so the ordinals follow
     * the first appearance in the tree (the root conversation is `<conversation#1>`).
     *
     * @param array<string, array<int, array<string, mixed>>> $treeRows raw rows per {@see TREE_SECTION_FAMILIES} section, root conversation first
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function buildTreeSnapshot(array $treeRows, AgentRecordedRunIdNormalization $idNormalization): array
    {
        $snapshot = [];
        foreach (self::TREE_SECTION_FAMILIES as $section => $sectionFamily) {
            $snapshot[$section] = [];
            foreach (array_values($treeRows[$section] ?? []) as $row) {
                $snapshot[$section][] = $section === self::SECTION_MESSAGES
                    ? self::buildMessageRowSnapshot($row, $idNormalization)
                    : self::buildGenericRowSnapshot($row, $sectionFamily, $idNormalization);
            }
        }
        return $snapshot;
    }

    /**
     * The dispatched Messenger messages as a comparable section: class, normalized body (the dispatch-origin keys
     * dropped) and what running it in the harness did.
     *
     * @param AgentRecordedRunDispatchedMessage[] $dispatchedMessages in dispatch order
     * @param AgentRecordedRunIdNormalization $idNormalization
     * @return array<int, array<string, mixed>>
     */
    public static function buildDispatchSnapshot(array $dispatchedMessages, AgentRecordedRunIdNormalization $idNormalization): array
    {
        $dispatchSnapshot = [];
        foreach (array_values($dispatchedMessages) as $dispatchedMessage) {
            $decodedBody = json_decode($dispatchedMessage->body, true);
            $comparableBody = is_array($decodedBody)
                ? (string)json_encode($idNormalization->normalizeStructure(self::withoutKeys($decodedBody, self::IGNORED_DISPATCH_BODY_KEYS)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $idNormalization->normalizeText($dispatchedMessage->body);
            $dispatchSnapshot[] = [
                '_label' => $dispatchedMessage->getShortClassName(),
                'messageClass' => $dispatchedMessage->messageClass,
                'body' => $comparableBody,
                'outcome' => $idNormalization->normalizeText((string)($dispatchedMessage->outcome ?? '')),
            ];
        }
        return $dispatchSnapshot;
    }

    /**
     * The requests a replay sent, as {@see self::buildComparison()} compares them, each with the recorded call that
     * answered it.
     *
     * @param ArgusTransportRecordingPlayedRequests $playedRequests
     * @return AgentRecordedRunArgusRequest[]
     */
    public static function buildPlayedArgusRequests(ArgusTransportRecordingPlayedRequests $playedRequests): array
    {
        $playedArgusRequests = [];
        foreach (array_values($playedRequests->getElements()) as $playedRequest) {
            $playedArgusRequests[] = AgentRecordedRunArgusRequest::fromPlayedRequest($playedRequest);
        }
        return $playedArgusRequests;
    }

    /**
     * Compare a replay with its recording: the snapshot sections first (row count, every column of every row, the rows
     * only one run has), then the requests sent to Argus, each against the recorded call that answered it — each body
     * normalized by its own run's id normalization, pair by pair, so both runs number their ids in the same order.
     *
     * @param array<string, array<int, array<string, mixed>>> $expectedSnapshot the recording's sections
     * @param array<string, array<int, array<string, mixed>>> $actualSnapshot the replay's sections
     * @param AgentRecordedRunArgusRequest[] $expectedRequests the recording's requests, keyed by their recorded position
     * @param AgentRecordedRunArgusRequest[] $actualRequests the replay's requests and the recorded call that answered each
     * @param AgentRecordedRunIdNormalization $expectedIdNormalization
     * @param AgentRecordedRunIdNormalization $actualIdNormalization
     * @param array<int|string, string> $unusedRecordedCalls recorded Argus calls (by position) and tool results (by label) a replay never asked for
     * @return static
     */
    public static function buildComparison(
        array $expectedSnapshot,
        array $actualSnapshot,
        array $expectedRequests,
        array $actualRequests,
        AgentRecordedRunIdNormalization $expectedIdNormalization,
        AgentRecordedRunIdNormalization $actualIdNormalization,
        array $unusedRecordedCalls
    ): static {
        $comparison = new static();
        $sections = array_values(array_unique(array_merge(array_keys($expectedSnapshot), array_keys($actualSnapshot))));
        foreach ($sections as $section) {
            $comparison->compareSection((string)$section, array_values($expectedSnapshot[$section] ?? []), array_values($actualSnapshot[$section] ?? []));
        }
        if (count($expectedRequests) !== count($actualRequests)) {
            $comparison->addDifference('Argus calls: ' . count($expectedRequests) . ' recorded, ' . count($actualRequests) . ' replayed');
        }
        // each replayed request against the recorded call that answered it (its own kind, not its position)
        foreach ($actualRequests as $position => $actualRequest) {
            $answeredByPosition = $actualRequest->getComparedRecordedPosition((int)$position);
            $expectedRequest = $answeredByPosition !== null ? ($expectedRequests[$answeredByPosition] ?? null) : null;
            if ($expectedRequest === null) {
                $comparison->addDifference("call #{$position} {$actualRequest->endpoint}: no recorded call answered it");
                continue;
            }
            $expectedBody = $expectedIdNormalization->normalizeJsonOrText($expectedRequest->requestBody);
            $actualBody = $actualIdNormalization->normalizeJsonOrText($actualRequest->requestBody);
            if ($expectedBody !== $actualBody) {
                $comparison->addDifference("call #{$position} (recorded #{$answeredByPosition}) {$actualRequest->endpoint}: " . self::firstDivergence($expectedBody, $actualBody));
            }
        }
        foreach ($unusedRecordedCalls as $position => $endpoint) {
            $comparison->addDifference((is_int($position) ? "recorded call #{$position}" : "recorded {$position}") . " {$endpoint} was never asked for");
        }
        return $comparison;
    }

    /**
     * The report of the played-answer id translation: every translation is listed, every reference without a replay
     * counterpart is a difference.
     *
     * @param string[] $translations
     * @param string[] $missingCounterparts
     */
    public function addPlayedAnswerIdTranslationReport(array $translations, array $missingCounterparts): void
    {
        $this->playedAnswerIdTranslations = $translations;
        foreach ($missingCounterparts as $missingCounterpart) {
            $this->addDifference($missingCounterpart);
        }
    }

    /**
     * The report of one run's created-id read: a table whose read hit the limit is a difference (its created ids beyond
     * the limit are not normalized), a table read without a scope column is a note (its ids may include other runs' rows).
     *
     * @param AgentRecordedRunCreatedIdReadReport $createdIdReadReport
     * @param string $runName
     * @return void
     */
    public function addCreatedIdReadReport(AgentRecordedRunCreatedIdReadReport $createdIdReadReport, string $runName): void
    {
        foreach ($createdIdReadReport->cappedTables as $cappedTable) {
            $this->addDifference("{$runName}: created-id read cap reached for {$cappedTable} — the ids beyond it are not normalized");
        }
        foreach ($createdIdReadReport->unscopedTableReads as $unscopedTableRead) {
            $this->notes[] = "{$runName}: {$unscopedTableRead->table} read unscoped ({$unscopedTableRead->readIdCount} ids read, {$unscopedTableRead->allocatedIdCount} allocated since the mark) — rows of other runs may count as created";
        }
    }

    /**
     * One message row: the compared message columns; the stored tool call and capability set as normalized JSON.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function buildMessageRowSnapshot(array $row, AgentRecordedRunIdNormalization $idNormalization): array
    {
        // the row's own id takes its ordinal here (later references read the same placeholder); the label and the
        // index name the row, so the id itself is not compared — one shifted ordinal would otherwise repeat on every row
        if (isset($row['id'])) {
            $idNormalization->normalizeIdOfFamily(AgentRecordedRunIdNormalization::FAMILY_MESSAGE, $row['id']);
        }
        $conversationPlaceholder = $idNormalization->normalizeIdOfFamily(AgentRecordedRunIdNormalization::FAMILY_CONVERSATION, (string)($row['aiConversationId'] ?? ''));
        return [
            '_label' => $conversationPlaceholder . '@' . (int)($row['indexInConversation'] ?? 0),
            'index' => (int)($row['indexInConversation'] ?? 0),
            'role' => $row['role'] ?? null,
            'authorType' => $row['authorType'] ?? null,
            'messageType' => $row['messageType'] ?? null,
            'signalSource' => $row['signalSource'] ?? null,
            'status' => $row['status'] ?? null,
            'message' => $idNormalization->normalizeText((string)($row['message'] ?? '')),
            'toolCall' => is_string($row['toolCall'] ?? null) ? $idNormalization->normalizeJsonOrText($row['toolCall']) : null,
            'loadedCapabilities' => $idNormalization->normalizeJsonOrText((string)($row['loadedCapabilities'] ?? '')),
        ];
    }

    /**
     * Any other tree row: the row's own id first (its section's family), then every column but the volatile ones —
     * declared id columns by their family, JSON columns as normalized structures, every other value as text.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function buildGenericRowSnapshot(array $row, string $sectionFamily, AgentRecordedRunIdNormalization $idNormalization): array
    {
        $rowPlaceholder = isset($row['id']) ? (string)$idNormalization->normalizeIdOfFamily($sectionFamily, $row['id']) : '';
        $rowSnapshot = ['_label' => $rowPlaceholder];
        foreach ($row as $column => $value) {
            if ($column === 'id' || in_array($column, self::VOLATILE_ROW_COLUMNS, true)) {
                continue;
            }
            if ($value === null) {
                $rowSnapshot[$column] = null;
                continue;
            }
            $stringValue = is_scalar($value) ? (string)$value : (string)json_encode($value);
            $rowSnapshot[$column] = isset(AgentRecordedRunIdNormalization::TABLE_FAMILY_BY_ID_KEY[$column]) && ctype_digit($stringValue)
                ? (string)$idNormalization->normalizeIdOfFamily(AgentRecordedRunIdNormalization::TABLE_FAMILY_BY_ID_KEY[$column], $stringValue)
                : $idNormalization->normalizeJsonOrText($stringValue);
        }
        return $rowSnapshot;
    }

    /**
     * @param array<int, array<string, mixed>> $expectedRows
     * @param array<int, array<string, mixed>> $actualRows
     */
    protected function compareSection(string $section, array $expectedRows, array $actualRows): void
    {
        if (count($expectedRows) !== count($actualRows)) {
            $this->addDifference("{$section}: " . count($expectedRows) . ' recorded, ' . count($actualRows) . ' replayed');
        }
        $rowCount = max(count($expectedRows), count($actualRows));
        for ($position = 0; $position < $rowCount; $position++) {
            $expectedRow = $expectedRows[$position] ?? null;
            $actualRow = $actualRows[$position] ?? null;
            if ($expectedRow === null || $actualRow === null) {
                $onlyRow = $expectedRow ?? $actualRow ?? [];
                $this->addDifference("{$section} #{$position} " . (string)($onlyRow['_label'] ?? '') . ': only ' . ($expectedRow === null ? 'replayed' : 'recorded'));
                continue;
            }
            $label = (string)($expectedRow['_label'] ?? '');
            foreach (array_unique(array_merge(array_keys($expectedRow), array_keys($actualRow))) as $column) {
                $expectedValue = $expectedRow[$column] ?? null;
                $actualValue = $actualRow[$column] ?? null;
                if ($column === 'toolCall') {
                    $expectedValue = self::withoutAsyncVerdictKeys($expectedValue);
                    $actualValue = self::withoutToolCallKeysTheRecordingPredates($expectedValue, self::withoutAsyncVerdictKeys($actualValue));
                }
                if ($actualValue !== $expectedValue) {
                    $this->addDifference("{$section} #{$position} {$label} {$column}: " . self::firstDivergence((string)json_encode($expectedValue, JSON_UNESCAPED_UNICODE), (string)json_encode($actualValue, JSON_UNESCAPED_UNICODE)));
                }
            }
        }
    }

    /**
     * A stored tool call without the keys a worker job writes after the turn
     * ({@see AgentRecordedRunIdNormalization::ASYNC_VERDICT_KEYS}) — on both sides, because a recording made before
     * those keys were dropped at normalization still holds them.
     */
    protected static function withoutAsyncVerdictKeys(mixed $toolCall): mixed
    {
        if (!is_string($toolCall)) {
            return $toolCall;
        }
        $structure = json_decode($toolCall, true);
        if (!is_array($structure)) {
            return $toolCall;
        }
        $removedAnyKey = false;
        foreach (AgentRecordedRunIdNormalization::ASYNC_VERDICT_KEYS as $asyncVerdictKey) {
            if (array_key_exists($asyncVerdictKey, $structure)) {
                unset($structure[$asyncVerdictKey]);
                $removedAnyKey = true;
            }
        }
        return $removedAnyKey ? (string)json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $toolCall;
    }

    /**
     * The replayed tool call without the keys a stored tool call gained after the recording was made
     * (`effect`, stamped at append time): an older recording does not hold them, and every row would differ on them.
     */
    protected static function withoutToolCallKeysTheRecordingPredates(mixed $expectedToolCall, mixed $actualToolCall): mixed
    {
        if (!is_string($expectedToolCall) || !is_string($actualToolCall)) {
            return $actualToolCall;
        }
        $expectedStructure = json_decode($expectedToolCall, true);
        $actualStructure = json_decode($actualToolCall, true);
        if (!is_array($expectedStructure) || !is_array($actualStructure)) {
            return $actualToolCall;
        }
        $removedKeys = [];
        foreach (self::TOOL_CALL_KEYS_ADDED_AFTER_RECORDINGS as $addedKey) {
            if (!array_key_exists($addedKey, $expectedStructure) && array_key_exists($addedKey, $actualStructure)) {
                unset($actualStructure[$addedKey]);
                $removedKeys[] = $addedKey;
            }
        }
        return $removedKeys === [] ? $actualToolCall : (string)json_encode($actualStructure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<mixed> $structure
     * @param string[] $keys
     * @return array<mixed>
     */
    protected static function withoutKeys(array $structure, array $keys): array
    {
        foreach ($structure as $key => $value) {
            if (is_string($key) && in_array($key, $keys, true)) {
                unset($structure[$key]);
            } elseif (is_array($value)) {
                $structure[$key] = self::withoutKeys($value, $keys);
            }
        }
        return $structure;
    }

    protected function addDifference(string $difference): void
    {
        if (count($this->differences) < self::MAX_REPORTED_DIFFERENCES) {
            $this->differences[] = $difference;
        }
    }

    /** Where two texts start to differ, with a little context from both. */
    protected static function firstDivergence(string $expected, string $actual): string
    {
        $length = min(strlen($expected), strlen($actual));
        $offset = 0;
        while ($offset < $length && $expected[$offset] === $actual[$offset]) {
            $offset++;
        }
        $from = max(0, $offset - 60);
        return 'recorded «…' . mb_strcut($expected, $from, 160) . '…» replayed «…' . mb_strcut($actual, $from, 160) . '…»';
    }
}
