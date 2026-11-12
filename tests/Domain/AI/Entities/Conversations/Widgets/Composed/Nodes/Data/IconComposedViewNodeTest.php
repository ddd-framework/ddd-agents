<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/** Icon names are a closed list. */
class IconComposedViewNodeTest extends UnitTestCase
{
    public function testAKnownIconIsKeptAndAnUnknownNameDropsIt(): void
    {
        $known = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Icon', 'name' => 'check', 'tone' => 'success', 'size' => 'small', 'label' => 'Majority']]);
        self::assertSame('Icon', $known->view::NODE_TYPE);
        self::assertSame([], $known->findings->getCodes());

        $unknown = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Icon', 'name' => 'rocket']]);
        self::assertNull($unknown->view);
        self::assertSame(['INVALID_PROP', 'INVALID_NODE'], $unknown->findings->getCodes());
    }
}
