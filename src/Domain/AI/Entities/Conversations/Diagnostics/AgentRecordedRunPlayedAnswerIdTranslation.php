<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;

/**
 * The ids inside a PLAYED model answer of a replay whose tools run live: the recorded answer names
 * the rows the RECORDING run created (a booking, a note, a page), the replay created the same rows under other
 * ids. Before a played answer is handed to the platform, every id the recording knows as run-created (above its
 * high-water mark) is replaced by the replay's id of the same table and the same ordinal — the ordinal being the
 * creation order among the run's new rows of that table (ids ascending).
 *
 * Only id-bearing positions are touched: the values inside a tool call's `arguments` that are a JSON number or a
 * numeric string EQUAL to a recorded created id — under an id key (`id`, `…Id`, `…Ids`) at any length, under any
 * other key only from {@see MIN_UNKEYED_ID_DIGITS} digits; never a number inside free text. An id two tables created is
 * left alone (ambiguous), and an ordinal the replay has not created yet is left unchanged and reported
 * ({@see $missingCounterparts}). An id key whose table is declared ({@see findDeclaredTableOfIdKey()}) translates only ids its
 * own table created. Every replacement is recorded ({@see $translations}). Pure — the replay's created ids come from
 * a callable the harness reads them through, called only when the answer holds a candidate.
 *
 * Not translated, by design: a created id the played answer ECHOES in free text (its prose, a widget fence) — the
 * replay then sends a request or persists a text that names the recording's id, which the comparison reports as a
 * difference (fails safe, never silently equal). Known coincidence: under a key that does not name an id, a value
 * of at least six digits that equals a created id (a token count, a price in cents) is translated too; the
 * translation report lists it.
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunPlayedAnswerIdTranslation
{
    /** A value under a key that does not name an id is read as one only from this many digits. */
    public const int MIN_UNKEYED_ID_DIGITS = 6;

    /**
     * Id keys whose table is known: such a key translates only the ids its own table created. The package declares
     * none — an application lists the id keys of its own tools in a class it derives from this one
     * (`['noteId' => 'notes', 'noteIds' => 'notes']`) and builds that class. The keys of a conversation are not
     * listed here ({@see CONVERSATION_ID_KEYS}): their table is read from the conversation's model.
     *
     * @var array<string, string>
     */
    public const array TABLE_BY_ID_KEY = [];

    /** Id keys that name a conversation: such a key translates only the ids the conversation table created. */
    public const array CONVERSATION_ID_KEYS = ['conversationId', 'aiConversationId'];

    /** @var array<string, int[]> per table: the ids the RECORDING run created, ascending (= creation order) */
    public array $recordedCreatedIdsByTable = [];

    /** @var string[] every replacement made, in order: "call #n: <table> #k 123 → 456" */
    public array $translations = [];

    /** @var string[] every reference that could not be translated, in order — each one is a comparison difference */
    public array $missingCounterparts = [];

    /** @var array<string, AgentRecordedRunCreatedIdOrdinal|null> per recorded created id: its table and 1-based ordinal; null = ambiguous */
    protected array $ordinalByRecordedId = [];

    /** @param array<string, int[]|string[]> $recordedCreatedIdsByTable */
    public static function forRecordedCreatedIds(array $recordedCreatedIdsByTable): static
    {
        $agentRecordedRunPlayedAnswerIdTranslation = new static();
        foreach ($recordedCreatedIdsByTable as $table => $createdIds) {
            $sortedCreatedIds = array_map('intval', $createdIds);
            sort($sortedCreatedIds);
            $agentRecordedRunPlayedAnswerIdTranslation->recordedCreatedIdsByTable[(string)$table] = $sortedCreatedIds;
            foreach ($sortedCreatedIds as $position => $createdId) {
                $idKey = (string)$createdId;
                $agentRecordedRunPlayedAnswerIdTranslation->ordinalByRecordedId[$idKey] = array_key_exists($idKey, $agentRecordedRunPlayedAnswerIdTranslation->ordinalByRecordedId)
                    ? null
                    : AgentRecordedRunCreatedIdOrdinal::forTableAndOrdinal((string)$table, $position + 1);
            }
        }
        return $agentRecordedRunPlayedAnswerIdTranslation;
    }

    /**
     * The played answer as the replay hands it to the platform: its tool-call arguments with every recorded created id
     * replaced by the replay's id of the same table and ordinal. Returns the body byte-identical when nothing changed.
     *
     * @param callable(): array<string, int[]|string[]> $findReplayCreatedIdsByTable the replay's created ids per table, ascending
     */
    public function translateResponseBody(string $responseBody, callable $findReplayCreatedIdsByTable, string $callLabel): string
    {
        if ($this->ordinalByRecordedId === [] || !str_contains($responseBody, 'arguments')) {
            return $responseBody;
        }
        $decodedBody = json_decode($responseBody, true);
        if (!is_array($decodedBody)) {
            return $responseBody;
        }
        $replayCreatedIdsByTable = null;
        $findReplayCreatedIdsOnce = function () use (&$replayCreatedIdsByTable, $findReplayCreatedIdsByTable): array {
            if ($replayCreatedIdsByTable === null) {
                $replayCreatedIdsByTable = [];
                foreach ($findReplayCreatedIdsByTable() as $table => $createdIds) {
                    $sortedCreatedIds = array_map('intval', $createdIds);
                    sort($sortedCreatedIds);
                    $replayCreatedIdsByTable[(string)$table] = $sortedCreatedIds;
                }
            }
            return $replayCreatedIdsByTable;
        };
        $hasTranslated = false;
        $translatedBody = $this->translateToolCallArgumentsIn($decodedBody, $findReplayCreatedIdsOnce, $callLabel, $hasTranslated);
        return $hasTranslated ? (string)json_encode($translatedBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $responseBody;
    }

    /**
     * @param array<mixed> $structure
     * @return array<mixed>
     */
    protected function translateToolCallArgumentsIn(array $structure, callable $findReplayCreatedIdsOnce, string $callLabel, bool &$hasTranslated): array
    {
        foreach ($structure as $key => $value) {
            if ($key === 'arguments' && is_string($value)) {
                $decodedArguments = json_decode($value, true);
                if (is_array($decodedArguments)) {
                    $argumentsChanged = false;
                    $translatedArguments = $this->translateIdValues($decodedArguments, null, $findReplayCreatedIdsOnce, $callLabel, $argumentsChanged);
                    if ($argumentsChanged) {
                        $structure[$key] = (string)json_encode($translatedArguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $hasTranslated = true;
                    }
                }
            } elseif ($key === 'arguments' && is_array($value)) {
                $argumentsChanged = false;
                $structure[$key] = $this->translateIdValues($value, null, $findReplayCreatedIdsOnce, $callLabel, $argumentsChanged);
                $hasTranslated = $hasTranslated || $argumentsChanged;
            } elseif (is_array($value)) {
                $structure[$key] = $this->translateToolCallArgumentsIn($value, $findReplayCreatedIdsOnce, $callLabel, $hasTranslated);
            }
        }
        return $structure;
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    protected function translateIdValues(array $arguments, ?string $parentKey, callable $findReplayCreatedIdsOnce, string $callLabel, bool &$argumentsChanged): array
    {
        foreach ($arguments as $key => $value) {
            // a list element inherits its list's key: `bookingIds: [1, 2]` names ids
            $valueKey = is_string($key) ? $key : $parentKey;
            if (is_array($value)) {
                $arguments[$key] = $this->translateIdValues($value, $valueKey, $findReplayCreatedIdsOnce, $callLabel, $argumentsChanged);
                continue;
            }
            $idText = is_int($value) ? (string)$value : (is_string($value) && ctype_digit($value) ? $value : null);
            if ($idText === null || !array_key_exists($idText, $this->ordinalByRecordedId)) {
                continue;
            }
            if (!self::isIdKey($valueKey) && strlen($idText) < self::MIN_UNKEYED_ID_DIGITS) {
                continue;
            }
            $createdIdOrdinal = $this->ordinalByRecordedId[$idText];
            $declaredTable = $valueKey !== null ? $this->findDeclaredTableOfIdKey($valueKey) : null;
            if ($declaredTable !== null) {
                // the key names its table: only an id that table created is translated, at that table's ordinal
                $positionInDeclaredTable = array_search((int)$idText, $this->recordedCreatedIdsByTable[$declaredTable] ?? [], true);
                if ($positionInDeclaredTable === false) {
                    continue;
                }
                $createdIdOrdinal = AgentRecordedRunCreatedIdOrdinal::forTableAndOrdinal($declaredTable, (int)$positionInDeclaredTable + 1);
            }
            if ($createdIdOrdinal === null) {
                $this->missingCounterparts[] = "{$callLabel}: played answer refers to created id {$idText}, which several tables created — left unchanged";
                continue;
            }
            $replayId = $createdIdOrdinal->getCounterpartIdIn($findReplayCreatedIdsOnce());
            if ($replayId === null) {
                $this->missingCounterparts[] = "{$callLabel}: played answer refers to created id {$idText} ({$createdIdOrdinal->table} #{$createdIdOrdinal->ordinal}) with no replay counterpart — left unchanged";
                continue;
            }
            $arguments[$key] = is_int($value) ? $replayId : (string)$replayId;
            $argumentsChanged = true;
            $this->translations[] = "{$callLabel}: {$createdIdOrdinal->table} #{$createdIdOrdinal->ordinal} {$idText} → {$replayId}";
        }
        return $arguments;
    }

    /**
     * The table an id key declares, or null when the key names none. A conversation key names the table of the
     * conversation's generated model, so it follows the table name this application gives the conversation; every
     * other declared key names its table in {@see TABLE_BY_ID_KEY}.
     */
    protected function findDeclaredTableOfIdKey(string $idKey): ?string
    {
        if (in_array($idKey, static::CONVERSATION_ID_KEYS, true)) {
            return DBAIConversationModel::getTableName();
        }
        return static::TABLE_BY_ID_KEY[$idKey] ?? null;
    }

    protected static function isIdKey(?string $key): bool
    {
        return $key !== null && ($key === 'id' || $key === 'ids' || str_ends_with($key, 'Id') || str_ends_with($key, 'Ids'));
    }
}
