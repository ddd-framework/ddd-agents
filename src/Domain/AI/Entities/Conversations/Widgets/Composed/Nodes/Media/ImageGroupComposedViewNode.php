<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Media;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A gallery of pictures: `images` is a
 * list of `{"src","alt","title","caption","source"}` — literal, or bound to a dataset (`{"$data":…}`, `{"$from":…}`)
 * whose rows carry those keys. `layout` picks the arrangement: `grid` (tiles, the last visible one counts the hidden
 * rest as "+N"), `carousel` (one large picture at a time with previous / next) or `magazine` (one hero over smaller
 * tiles). Every picture opens the lightbox at its place in the gallery. The frontend drops an entry without `src` or
 * `alt` and reports it; the rows are capped at the schema's `maxRepeatRows`. Component:
 * `components/media/ImageGroup.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'images', types: [ComposedViewNodeProp::TYPE_ARRAY], isRequired: true, description: 'the pictures: [{"src","alt","title"?,"caption"?,"source"?:{"name","url"}}], src and alt required per picture')]
#[ComposedViewNodeProp(name: 'layout', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: self::LAYOUTS, description: 'grid (default), carousel or magazine (one hero over tiles)')]
#[ComposedViewNodeProp(name: 'aspectRatio', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ImageComposedViewNode::ASPECT_RATIOS, description: 'the frame of each tile (default 4:3; the carousel and the magazine hero use 16:9 when unset)')]
#[ComposedViewNodeProp(name: 'maxVisible', types: [ComposedViewNodeProp::TYPE_NUMBER], isBindable: false, description: 'grid only: how many tiles show before the last one counts the rest (default 4)')]
class ImageGroupComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'ImageGroup';

    public const array CATEGORIES = [ComposedViewNodeCategory::MEDIA];

    public const string SUMMARY = 'a gallery of pictures as a grid, a carousel or a magazine layout; each opens in a lightbox';

    /** @var string[] the arrangements of a gallery */
    public const array LAYOUTS = ['grid', 'carousel', 'magazine'];
}
