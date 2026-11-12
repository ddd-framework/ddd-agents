<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Media;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchema;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Media\ImageComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Media\ImageGroupComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Media\VideoComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The media node types `Image`, `ImageGroup` and `Video` as the reader sees them: a valid node is
 * kept unchanged, a missing required prop (`alt` on an image, `images` on a group, `title` on a video) drops the node,
 * an unknown prop or a value outside an enum is dropped with its code, and the rest of the tree survives.
 */
class MediaComposedViewNodesTest extends UnitTestCase
{
    public function testAValidImageIsKeptWithEveryProp(): void
    {
        $imageWireNode = [
            'type' => 'Image',
            'src' => 'https://upload.wikimedia.org/wikipedia/commons/a/a0/Rathaus_Muenster.jpg',
            'alt' => 'The gothic town hall on the Prinzipalmarkt',
            'title' => 'Historisches Rathaus',
            'caption' => 'Gotische Architektur am Prinzipalmarkt',
            'source' => ['name' => 'commons.wikimedia.org', 'url' => 'https://commons.wikimedia.org'],
            'aspectRatio' => '16:9',
            'fit' => 'cover',
        ];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => $imageWireNode]);

        self::assertInstanceOf(ImageComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($imageWireNode, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnImageWithoutAltTextIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'view' => ['type' => 'Image', 'src' => 'https://example.org/a.jpg'],
        ]);

        self::assertNull($composedViewTreeReading->view);
        self::assertNotSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnUnknownAspectRatioAndAnUnknownPropAreDroppedTheImageStays(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'view' => ['type' => 'Image', 'src' => 'https://example.org/a.jpg', 'alt' => 'A photo', 'aspectRatio' => '21:9', 'width' => 400],
        ]);

        self::assertInstanceOf(ImageComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame(['src' => 'https://example.org/a.jpg', 'alt' => 'A photo'], $composedViewTreeReading->view->props);
        self::assertSame(['INVALID_PROP'], array_values(array_unique($composedViewTreeReading->findings->getCodes())));
    }

    public function testAnImageGroupKeepsItsListAndItsLayout(): void
    {
        $imageGroupWireNode = [
            'type' => 'ImageGroup',
            'images' => [
                ['src' => 'https://example.org/1.jpg', 'alt' => 'The west front'],
                ['src' => 'https://example.org/2.jpg', 'alt' => 'The choir', 'title' => 'Hochchor'],
            ],
            'layout' => 'magazine',
            'maxVisible' => 4,
        ];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => $imageGroupWireNode]);

        self::assertInstanceOf(ImageGroupComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($imageGroupWireNode, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnImageGroupMayBindItsImagesToADataset(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'data' => ['photos' => [['src' => 'https://example.org/1.jpg', 'alt' => 'The west front']]],
            'view' => ['type' => 'ImageGroup', 'images' => ['$data' => 'photos'], 'layout' => 'carousel'],
        ]);

        self::assertInstanceOf(ImageGroupComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame(['$data' => 'photos'], $composedViewTreeReading->view->props['images']);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnImageGroupWithAnUnknownLayoutKeepsItsImages(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'view' => ['type' => 'ImageGroup', 'images' => [['src' => 'https://example.org/1.jpg', 'alt' => 'A']], 'layout' => 'masonry'],
        ]);

        self::assertInstanceOf(ImageGroupComposedViewNode::class, $composedViewTreeReading->view);
        self::assertArrayNotHasKey('layout', $composedViewTreeReading->view->props);
        self::assertSame(['INVALID_PROP'], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnImageGroupWhoseImagesAreNoListIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'view' => ['type' => 'ImageGroup', 'images' => 'https://example.org/1.jpg'],
        ]);

        self::assertNull($composedViewTreeReading->view);
        self::assertNotSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAValidVideoIsKept(): void
    {
        $videoWireNode = [
            'type' => 'Video',
            'title' => 'Münster, Germany 4k Walking Tour',
            'videoId' => 'x5-yoDUoz5k',
            'durationSeconds' => 1418,
            'channel' => 'Duslin Travels',
            'source' => ['name' => 'YouTube', 'url' => 'https://www.youtube.com/watch?v=x5-yoDUoz5k'],
        ];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => $videoWireNode]);

        self::assertInstanceOf(VideoComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($videoWireNode, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAVideoWithoutTitleIsDroppedAndAWrongDurationTypeIsDroppedAlone(): void
    {
        $withoutTitle = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Video', 'videoId' => 'x5-yoDUoz5k']]);
        self::assertNull($withoutTitle->view);

        $withTextDuration = ComposedViewTreeReading::fromWidgetFields([
            'view' => ['type' => 'Video', 'title' => 'A tour', 'durationSeconds' => '23:38'],
        ]);
        self::assertInstanceOf(VideoComposedViewNode::class, $withTextDuration->view);
        self::assertSame(['title' => 'A tour'], $withTextDuration->view->props);
        self::assertSame(['INVALID_PROP'], $withTextDuration->findings->getCodes());
    }

    public function testTheSchemaExportListsTheThreeMediaTypes(): void
    {
        $schemaArray = ComposedViewNodeSchema::build();
        foreach (['Image', 'ImageGroup', 'Video'] as $mediaNodeType) {
            self::assertArrayHasKey($mediaNodeType, $schemaArray['nodeTypes']);
            self::assertSame(['media'], $schemaArray['nodeTypes'][$mediaNodeType]['categories']);
        }
    }
}
