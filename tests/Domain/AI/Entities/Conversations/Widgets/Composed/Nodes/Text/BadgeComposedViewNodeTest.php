<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTone;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The `Badge` node: a status with a tone is kept, an unlisted tone dropped, the badge stays. */
class BadgeComposedViewNodeTest extends UnitTestCase
{
    public function testTheReaderKeepsABadgeWithItsTone(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Badge', 'text' => 'Mehrheit', 'tone' => 'success']]);
        self::assertSame(['type' => 'Badge', 'text' => 'Mehrheit', 'tone' => 'success'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnUnlistedToneIsDroppedAndTheBadgeStays(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Badge', 'text' => 'Live', 'tone' => 'purple']]);
        self::assertSame(['type' => 'Badge', 'text' => 'Live'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame(['INVALID_PROP'], $composedViewTreeReading->findings->getCodes());
    }

    public function testEveryToneOfTheKitAndEveryOlderWordIsKept(): void
    {
        foreach (ComposedViewTone::ACCEPTED_WORDS as $toneWord) {
            $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Badge', 'text' => 'Live', 'tone' => $toneWord]]);
            self::assertSame(['type' => 'Badge', 'text' => 'Live', 'tone' => $toneWord], $composedViewTreeReading->view?->toWireArray(), $toneWord);
            self::assertSame([], $composedViewTreeReading->findings->getCodes(), $toneWord);
        }
    }
}
