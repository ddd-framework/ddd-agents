<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Entity;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * The name of an entity a tool returned, clickable (ChatGPT's `Entity`): a click runs the
 * tree's `openEntity` action named by `action`, which opens that entity's detail panel (the `entityDetail` canvas).
 * The action carries the platform-minted reference `<toolCallId>:<entityType>:<index>` of a tool result row; the
 * backend resolves it when the row is stored, so the link opens nothing for a reference the platform did not mint.
 * `action` is optional on purpose: a link whose reference does not resolve (the persist pass drops the action, or it
 * carries no resolved `entity`) still shows its text, without the click, and reports `UNRESOLVED_REF` (the node
 * still renders its text).
 * The prop is a plain string, not an event, so it can be bound: `{"$item":"action"}` inside a `Repeat` lets every row
 * open its own entity (the `Map` point's `action` works the same way). A name that names no `openEntity` action of
 * the tree opens nothing. Component: `components/entity/EntityLink.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'text', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], isRequired: true, description: 'the entity\'s name as the owner reads it ("Pizzeria Roma")')]
#[ComposedViewNodeProp(name: 'action', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the name of this tree\'s openEntity action whose ref is the entity\'s reference from a tool result')]
#[ComposedViewNodeProp(name: 'variant', types: [ComposedViewNodeProp::TYPE_STRING], allowedValues: ['link', 'title', 'chip'], description: 'link = the name inline in a sentence or cell (default), title = the name as the bold headline of a Surface, chip = a small pill with a detail glyph')]
class EntityLinkComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'EntityLink';

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE];

    public const string SUMMARY = 'an entity\'s name (a competitor, a review, a keyword) that opens its detail panel through an openEntity action';
}
