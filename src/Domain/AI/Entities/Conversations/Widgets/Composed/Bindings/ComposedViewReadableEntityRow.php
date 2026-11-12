<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReference;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceRow;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The row an `openEntity` reference of a `ComposedView` block names, found readable for the conversation: the
 * reference as the block writes it and the entity row of the tool result it points at. Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewReadableEntityRow extends ValueObject
{
    /** @var EntityReference The reference the block writes. */
    public EntityReference $entityReference;

    /** @var EntityReferenceRow The row of the tool result the reference names. */
    public EntityReferenceRow $entityReferenceRow;

    public static function forReferenceAndRow(EntityReference $entityReference, EntityReferenceRow $entityReferenceRow): static
    {
        $composedViewReadableEntityRow = new static();
        $composedViewReadableEntityRow->entityReference = $entityReference;
        $composedViewReadableEntityRow->entityReferenceRow = $entityReferenceRow;
        return $composedViewReadableEntityRow;
    }

    /** The key of the row a reference names: the reference as it is written on the wire. */
    public static function uniqueKeyOfReference(EntityReference $entityReference): string
    {
        return self::class . '_' . $entityReference->toWireValue();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyOfReference($this->entityReference);
    }
}
