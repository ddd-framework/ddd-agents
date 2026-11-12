<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Text` node: a paragraph with its roles, bound text, inline children on the same line; a block
 * child, a non-scalar text and an out-of-list tone are dropped with their code.
 */
class TextComposedViewNodeTest extends UnitTestCase
{
    public function testTheReaderKeepsAParagraphWithItsRolesAndInlineChildren(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => [
            'type' => 'Text',
            'text' => 'Quellen: WDR und Antenne Münster.',
            'weight' => 'medium',
            'tone' => 'muted',
            'maxLines' => 3,
            'children' => [
                ['type' => 'Badge', 'text' => 'Neu', 'tone' => 'info'],
                ['type' => 'Text', 'text' => 'und mehr'],
                ['type' => 'SourceRef', 'sources' => [['name' => 'WDR', 'url' => 'https://www1.wdr.de']]],
            ],
        ]]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
        self::assertSame(['Badge', 'Text', 'SourceRef'], array_map(static fn ($childNode): string => $childNode::NODE_TYPE, $composedViewTreeReading->view->getNodesOfSlot('children')));
        self::assertSame(3, $composedViewTreeReading->view->props['maxLines']);
    }

    public function testABlockChildIsDroppedAndTheRunStays(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => [
            'type' => 'Text',
            'text' => 'Intro',
            'children' => [['type' => 'Title', 'text' => 'Not inline'], ['type' => 'Link', 'text' => 'Mehr', 'url' => 'https://example.org']],
        ]]);
        self::assertSame(['Link'], array_map(static fn ($childNode): string => $childNode::NODE_TYPE, $composedViewTreeReading->view->getNodesOfSlot('children')));
        self::assertSame(['INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    public function testBoundTextIsKeptAndAnObjectTextOrAnUnlistedToneIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['period' => '7d'],
            'view' => ['type' => 'Text', 'text' => ['$state' => 'period'], 'tone' => 'purple', 'weight' => 'black'],
        ]);
        self::assertSame(['type' => 'Text', 'text' => ['$state' => 'period']], $composedViewTreeReading->view?->toWireArray());
        self::assertSame(['INVALID_PROP', 'INVALID_PROP'], $composedViewTreeReading->findings->getCodes(), 'the tone and the weight');

        $objectTextReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Text', 'text' => ['a' => 1]]]);
        self::assertSame(['type' => 'Text'], $objectTextReading->view?->toWireArray());
        self::assertSame(['INVALID_PROP'], $objectTextReading->findings->getCodes());
    }

    public function testABoundToneIsKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['tone' => 'success'],
            'view' => ['type' => 'Text', 'text' => '94', 'tone' => ['$state' => 'tone']],
        ]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
        self::assertSame(['$state' => 'tone'], $composedViewTreeReading->view->props['tone']);
    }
}
