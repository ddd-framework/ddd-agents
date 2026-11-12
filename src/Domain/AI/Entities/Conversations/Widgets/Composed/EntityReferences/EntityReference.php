<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A platform-minted reference to one row of a tool result: `<toolCallId>:<entityType>:<index>`
 * — which call of this conversation returned it, what kind of entity it is, and its position in that call's rows. The
 * model copies references into `Map` points, `Table` rows, `EntityLink`s and `openEntity` actions; it never builds one
 * from a name (a reference the platform did not mint does not resolve). The tool call id may itself contain `:` — the
 * entity type and the index are the last two segments. Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class EntityReference extends ValueObject
{
    /** @var string The provider's tool call id of the call that returned the row. */
    public string $toolCallId = '';

    /** @var string The entity type (`competitor`, `review`, `keyword`, … — an {@see EntityReferenceResolver::ENTITY_TYPE}). */
    public string $entityType = '';

    /** @var int The row's zero-based position in the call's result rows of that type. */
    public int $rowIndex = 0;

    public static function create(string $toolCallId, string $entityType, int $rowIndex): static
    {
        $entityReference = new static();
        $entityReference->toolCallId = $toolCallId;
        $entityReference->entityType = $entityType;
        $entityReference->rowIndex = $rowIndex;
        return $entityReference;
    }

    /** The reference a wire string names, or null when it is not `<toolCallId>:<entityType>:<index>`. */
    public static function fromWireValue(string $wireValue): ?self
    {
        if (preg_match('/^(.+):([A-Za-z][A-Za-z0-9]*):(0|[1-9][0-9]{0,5})$/', $wireValue, $referenceParts) !== 1) {
            return null;
        }
        return self::create($referenceParts[1], $referenceParts[2], (int)$referenceParts[3]);
    }

    /** `<toolCallId>:<entityType>:<index>`. */
    public function toWireValue(): string
    {
        return $this->toolCallId . ':' . $this->entityType . ':' . $this->rowIndex;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->toWireValue());
    }
}
