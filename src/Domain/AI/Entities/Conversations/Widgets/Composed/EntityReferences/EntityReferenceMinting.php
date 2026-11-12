<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences;

/**
 * Minting the references of a tool result: one reference per listed row, `<toolCallId>:<entityType>:<index>`
 * with the index counted per entity type in result order — the form the persist-time validation reads back (the
 * reference must name a call row of THIS conversation, unambiguously, and a row that call returned).
 * Reads nothing.
 */
class EntityReferenceMinting
{
    /**
     * The references of `$entityReferenceRows`, wire value → row, in result order.
     *
     * @return EntityReferenceRow[] Keyed by wire value
     */
    public static function mintForToolCall(string $toolCallId, EntityReferenceRow ...$entityReferenceRows): array
    {
        $mintedReferences = [];
        $nextIndexByEntityType = [];
        foreach ($entityReferenceRows as $entityReferenceRow) {
            $rowIndex = $nextIndexByEntityType[$entityReferenceRow->entityType] ?? 0;
            $nextIndexByEntityType[$entityReferenceRow->entityType] = $rowIndex + 1;
            $mintedReferences[EntityReference::create($toolCallId, $entityReferenceRow->entityType, $rowIndex)->toWireValue()] = $entityReferenceRow;
        }
        return $mintedReferences;
    }

    /**
     * The row `$entityReference` names among the rows its call returned, or null when the call returned no such row.
     *
     * @param EntityReferenceRow[] $entityReferenceRowsOfTheCall
     */
    public static function findRowNamedByReference(EntityReference $entityReference, array $entityReferenceRowsOfTheCall): ?EntityReferenceRow
    {
        $rowIndexOfType = 0;
        foreach ($entityReferenceRowsOfTheCall as $entityReferenceRow) {
            if ($entityReferenceRow->entityType !== $entityReference->entityType) {
                continue;
            }
            if ($rowIndexOfType === $entityReference->rowIndex) {
                return $entityReferenceRow;
            }
            $rowIndexOfType++;
        }
        return null;
    }
}
