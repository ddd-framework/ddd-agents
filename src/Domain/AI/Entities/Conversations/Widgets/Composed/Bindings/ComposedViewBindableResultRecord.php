<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceRow;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a bindable tool result keeps for `ComposedView`: the structured shape a `$from` path addresses,
 * and the entity rows the references `<toolCallId>:<entityType>:<index>` are minted over. Stored as a key of its own
 * in the call's `toolCall` column ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::$bindableResult}),
 * NEVER inside the result text: the result string, its `toolResultChars` column, the token estimates, the stub budget,
 * the compaction transcript and the wire read exactly what they read before. Built by the executor
 * ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService}), carried by
 * {@see \DDD\Domain\AI\Entities\MCPPackages\ToolResult::$bindableResult} through every path that copies a result (the
 * forked child, the recorded-run comparison), dropped by the injection screening's removal; read by the persist-time
 * resolution. Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewBindableResultRecord extends ValueObject
{
    /** The key of the record in the call's stored `toolCall` JSON. */
    public const string STORED_RESULT_KEY = 'bindableResult';

    /** Bytes of the stored structured shape at most; a larger result keeps its entity rows only and binds nothing. */
    public const int MAX_DATA_BYTES = 65536;

    /** Entity rows kept per result at most. */
    public const int MAX_ENTITY_REFERENCE_ROWS = 500;

    /** @var mixed The structured shape (`toObject()` of the result DTO, as arrays); null when not stored. */
    public mixed $data = null;

    /** @var bool The structured shape is the result's own `structuredContent` (a structured result) — not stored twice. */
    public bool $dataIsStructuredContent = false;

    /** @var int|null The byte size of a structured shape too large to store ({@see MAX_DATA_BYTES}); null when stored. */
    public ?int $dataOmittedBytes = null;

    /** @var EntityReferenceRow[] The listed entities, in the order the result lists them. */
    public array $entityReferenceRows = [];

    /**
     * The record of a result: its serialized shape (null for a structured result, whose `structuredContent` is the
     * shape) and its listed entity rows.
     *
     * @param EntityReferenceRow[] $entityReferenceRows
     */
    public static function forResult(mixed $serializedResult, bool $dataIsStructuredContent, array $entityReferenceRows): static
    {
        $composedViewBindableResultRecord = new static();
        $composedViewBindableResultRecord->dataIsStructuredContent = $dataIsStructuredContent;
        if (!$dataIsStructuredContent && $serializedResult !== null) {
            $dataAsArrays = json_decode((string)json_encode($serializedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), true);
            $dataBytes = strlen((string)json_encode($dataAsArrays, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($dataBytes > self::MAX_DATA_BYTES) {
                $composedViewBindableResultRecord->dataOmittedBytes = $dataBytes;
            } else {
                $composedViewBindableResultRecord->data = $dataAsArrays;
            }
        }
        $composedViewBindableResultRecord->entityReferenceRows = array_slice(array_values(array_filter(
            $entityReferenceRows,
            static fn (EntityReferenceRow $entityReferenceRow): bool => $entityReferenceRow->entityType !== '' && $entityReferenceRow->entityId !== ''
        )), 0, self::MAX_ENTITY_REFERENCE_ROWS);
        return $composedViewBindableResultRecord;
    }

    /** The record as read back from a persisted result JSON, or null when `$storedRecord` is no record. */
    public static function fromStoredArray(mixed $storedRecord): ?static
    {
        if (!is_array($storedRecord)) {
            return null;
        }
        $composedViewBindableResultRecord = new static();
        $composedViewBindableResultRecord->data = $storedRecord['data'] ?? null;
        $composedViewBindableResultRecord->dataIsStructuredContent = ($storedRecord['dataIsStructuredContent'] ?? false) === true;
        $composedViewBindableResultRecord->dataOmittedBytes = is_int($storedRecord['dataOmittedBytes'] ?? null) ? $storedRecord['dataOmittedBytes'] : null;
        $widgetSelfHydrationKeyFieldName = WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName();
        foreach (is_array($storedRecord['entityReferenceRows'] ?? null) ? $storedRecord['entityReferenceRows'] : [] as $storedRow) {
            if (is_array($storedRow) && is_string($storedRow['entityType'] ?? null) && (is_string($storedRow['entityId'] ?? null) || is_int($storedRow['entityId'] ?? null))) {
                $composedViewBindableResultRecord->entityReferenceRows[] = EntityReferenceRow::create(
                    $storedRow['entityType'],
                    (string)$storedRow['entityId'],
                    is_int($storedRow[$widgetSelfHydrationKeyFieldName] ?? null) ? $storedRow[$widgetSelfHydrationKeyFieldName] : null,
                );
            }
        }
        return $composedViewBindableResultRecord;
    }

    /** @return array<string, mixed> the stored form under {@see STORED_RESULT_KEY} */
    public function toStoredArray(): array
    {
        $storedRecord = [];
        if ($this->dataIsStructuredContent) {
            $storedRecord['dataIsStructuredContent'] = true;
        } elseif ($this->dataOmittedBytes !== null) {
            $storedRecord['dataOmittedBytes'] = $this->dataOmittedBytes;
        } else {
            $storedRecord['data'] = $this->data;
        }
        $storedRecord['entityReferenceRows'] = array_map(
            static fn (EntityReferenceRow $entityReferenceRow): array => $entityReferenceRow->toWireArray(),
            $this->entityReferenceRows
        );
        return $storedRecord;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
