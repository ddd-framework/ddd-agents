<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences;

use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One entity a tool result lists, as the result knows it: its type, its durable id and the key of the agent space it
 * belongs to. A result DTO that lists entities hands these over ({@see ListsEntityReferenceRows}); the platform mints a
 * reference per row. What a stored `ComposedView` block keeps once a reference is resolved, so a replay long after the
 * turn still opens the detail panel. Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class EntityReferenceRow extends ValueObject
{
    /** @var string The entity type ({@see EntityReferenceResolver::ENTITY_TYPE}). */
    public string $entityType = '';

    /** @var string The entity's durable id (`competitorId`, `reviewId` — a string so external ids fit). */
    public string $entityId = '';

    /**
     * @var int|null The key of the agent space the entity belongs to — the key the widgets of that space carry
     * ({@see \DDD\Domain\AI\Entities\AgentSpaces\AgentSpace::getWidgetSelfHydrationKey()}); null for an entity of no
     * space (a web hit).
     */
    public ?int $widgetSelfHydrationKey = null;

    public static function create(string $entityType, string $entityId, ?int $widgetSelfHydrationKey): static
    {
        $entityReferenceRow = new static();
        $entityReferenceRow->entityType = $entityType;
        $entityReferenceRow->entityId = $entityId;
        $entityReferenceRow->widgetSelfHydrationKey = $widgetSelfHydrationKey;
        return $entityReferenceRow;
    }

    /**
     * The resolved form a stored block carries: the type, the id and the key under the field name the application chose
     * ({@see WidgetBlockFieldNames}).
     *
     * @return array<string, string|int|null>
     */
    public function toWireArray(): array
    {
        return [
            'entityType' => $this->entityType,
            'entityId' => $this->entityId,
            WidgetBlockFieldNames::getWidgetSelfHydrationKeyFieldName() => $this->widgetSelfHydrationKey,
        ];
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->entityType . ':' . $this->entityId);
    }
}
