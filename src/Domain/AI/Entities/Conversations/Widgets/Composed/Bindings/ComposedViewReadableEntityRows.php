<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReference;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceRow;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The rows the `openEntity` references of one `ComposedView` block name that are readable for the conversation, one
 * per reference. A reference the set does not hold names no readable row. Reads nothing.
 *
 * @property ComposedViewReadableEntityRow[] $elements
 * @method ComposedViewReadableEntityRow[] getElements()
 * @method ComposedViewReadableEntityRow|null first()
 */
class ComposedViewReadableEntityRows extends ObjectSet
{
    /** Adds the readable row a reference names; a reference the set already holds keeps its row. */
    public function addRowOfReference(EntityReference $entityReference, EntityReferenceRow $entityReferenceRow): void
    {
        $composedViewReadableEntityRow = ComposedViewReadableEntityRow::forReferenceAndRow($entityReference, $entityReferenceRow);
        $this->add($composedViewReadableEntityRow);
    }

    public function hasRowOfReference(EntityReference $entityReference): bool
    {
        return $this->getByUniqueKey(ComposedViewReadableEntityRow::uniqueKeyOfReference($entityReference)) !== null;
    }

    /** The readable row a reference names; null when the reference names none. */
    public function getRowOfReference(EntityReference $entityReference): ?EntityReferenceRow
    {
        $composedViewReadableEntityRow = $this->getByUniqueKey(ComposedViewReadableEntityRow::uniqueKeyOfReference($entityReference));
        return $composedViewReadableEntityRow instanceof ComposedViewReadableEntityRow ? $composedViewReadableEntityRow->entityReferenceRow : null;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
