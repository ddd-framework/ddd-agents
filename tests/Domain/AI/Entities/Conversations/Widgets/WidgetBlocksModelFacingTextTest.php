<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The model reads its own ComposedView trees back as it wrote them: the history fold
 * re-encodes the block's JSON, and an empty object (`"fields":{}` of an embedded widget) must stay `{}` — decoded to an
 * array it came back as `[]`, the model's own tree changed under it.
 */
class WidgetBlocksModelFacingTextTest extends UnitTestCase
{
    public function testAnEmptyObjectOfAnEmbeddedWidgetStaysAnEmptyObject(): void
    {
        $treeJson = '{"type":"ComposedView","title":"Online-Auftritt","view":{"type":"Grid","columns":2,"children":['
            . '{"type":"Widget","widget":"BusinessProfileStatus","fields":{"shopId":500553}},'
            . '{"type":"Widget","widget":"SeoRankingsSnapshot","fields":{}},'
            . '{"type":"Text","text":"Website: Noch keine Website vorhanden."}]}}';
        $text = "Hier ist dein Dashboard.\n\n```widget\n" . $treeJson . "\n```";

        $modelFacingText = WidgetBlocks::buildModelFacingText($text);

        self::assertStringContainsString('"widget":"SeoRankingsSnapshot","fields":{}', $modelFacingText);
        self::assertStringNotContainsString('"fields":[]', $modelFacingText);
        self::assertSame($text, $modelFacingText, 'a tree without snapshots reads back byte for byte');
    }

    public function testTheDecoderKeepsListsListsAndObjectsArrays(): void
    {
        $decodedValue = WidgetBlocks::decodeJsonKeepingEmptyObjects('{"a":[],"b":{},"c":{"d":[1,{}]}}');
        self::assertIsArray($decodedValue);
        self::assertSame([], $decodedValue['a']);
        self::assertInstanceOf(\stdClass::class, $decodedValue['b']);
        self::assertSame('{"a":[],"b":{},"c":{"d":[1,{}]}}', json_encode($decodedValue));
        self::assertNull(WidgetBlocks::decodeJsonKeepingEmptyObjects('{not json'));
    }
}
