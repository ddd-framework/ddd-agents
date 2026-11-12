<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Media;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * One video with its preview (a video connector, e.g. a YouTube search, fills it): the
 * title, the thumbnail and the length the owner sees before playing, and what plays it — a `videoId` of the connector
 * or a plain `url`. Declared by the core so a connector adds no node change; the frontend component is
 * `components/media/Video.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'the video title, as the source names it')]
#[ComposedViewNodeProp(name: 'videoId', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the id the video connector returned (plays embedded)')]
#[ComposedViewNodeProp(name: 'url', types: [ComposedViewNodeProp::TYPE_STRING], description: 'an http(s) link that opens the video, when there is no videoId')]
#[ComposedViewNodeProp(name: 'thumbnail', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the thumbnail image URL')]
#[ComposedViewNodeProp(name: 'durationSeconds', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'the length in seconds')]
#[ComposedViewNodeProp(name: 'channel', types: [ComposedViewNodeProp::TYPE_STRING], description: 'who published it')]
#[ComposedViewNodeProp(name: 'source', types: [ComposedViewNodeProp::TYPE_OBJECT], description: 'where the video was found ({"name","url"}), shown as the source line')]
class VideoComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Video';

    public const array CATEGORIES = [ComposedViewNodeCategory::MEDIA];

    public const string SUMMARY = 'one video: title, thumbnail, length, and a videoId or url that plays it';
}
