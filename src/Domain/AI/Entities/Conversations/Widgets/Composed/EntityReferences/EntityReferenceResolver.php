<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences;

/**
 * The domain side of one entity type: what the type is called, what the detail panel shows, and
 * whether a resolved row is still readable for the conversation's agent space under the reader's rights. One class
 * `<Type>EntityReferenceResolver` per type in its domain's `Entities/AI/Conversations/EntityReferences/` folder, found
 * by folder ({@see EntityReferenceResolverClasses}); the AI core names no type. The frontend twin is the detail body of
 * the same `entityType` in the `entityDetail` canvas (`*.entity-detail-body.tsx`).
 *
 * @phpstan-consistent-constructor
 */
abstract class EntityReferenceResolver
{
    /** @var string The entity type the references carry (`competitor`, `review`, …): lower camel, letters and digits. */
    public const string ENTITY_TYPE = '';

    /** @var string One line for the model spec: what an entity of this type is. */
    public const string SUMMARY = '';

    /**
     * Whether the entity `$entityReferenceRow` names exists and may be shown in a conversation whose widgets carry
     * `$widgetSelfHydrationKey` (null = the conversation has no agent space) — read through the owning
     * domain's service, under the reader's entity rights (never a bypass). The persist-time validation drops a reference
     * whose row is not readable.
     */
    abstract public function isReadableForWidgetSelfHydrationKey(EntityReferenceRow $entityReferenceRow, ?int $widgetSelfHydrationKey): bool;
}
