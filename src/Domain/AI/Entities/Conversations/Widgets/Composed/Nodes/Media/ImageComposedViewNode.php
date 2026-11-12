<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Media;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * One picture: the image, its required `alt` text, an optional
 * title and caption under it and the source chip over it (`{"name","url"}`, the attribution an image search or a
 * Wikimedia connector returns). The frontend loads it lazily, shows a skeleton in the image's own shape until
 * it painted and a muted fallback tile when it fails (reported `failed`); a click opens the lightbox. In a compact slot
 * it renders as a 4:3 thumbnail. Component: `components/media/Image.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'src', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'the image URL (https, or a data:image URI)')]
#[ComposedViewNodeProp(name: 'alt', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'what the image shows, in one short sentence (read aloud by screen readers, shown when the image fails)')]
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the bold line under the image')]
#[ComposedViewNodeProp(name: 'caption', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the muted line under the title')]
#[ComposedViewNodeProp(name: 'source', types: [ComposedViewNodeProp::TYPE_OBJECT], description: 'where the image was found ({"name","url"}), shown as a chip over the image')]
#[ComposedViewNodeProp(name: 'aspectRatio', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: self::ASPECT_RATIOS, description: 'the frame the image is cropped to (default 4:3)')]
#[ComposedViewNodeProp(name: 'fit', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: self::FITS, description: 'cover crops a photo to the frame (default), contain shows a logo whole')]
class ImageComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Image';

    public const array CATEGORIES = [ComposedViewNodeCategory::MEDIA];

    public const string SUMMARY = 'one picture with alt text, an optional title, caption and source chip; opens in a lightbox';

    /** @var string[] the frames an image (or an image group) may be cropped to */
    public const array ASPECT_RATIOS = ['1:1', '4:3', '3:2', '5:4', '16:9', '3:4'];

    /** @var string[] how the picture fills its frame */
    public const array FITS = ['cover', 'contain'];
}
