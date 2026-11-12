<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The filled-fields handover gate reads WidgetBlocks —
 * a VALID block of the type in any syntax, unclosed included, the frontend's fence repairs applied; invalid JSON does not count.
 */
class WidgetBlockFilledFieldsTest extends UnitTestCase
{
    protected const array PROMISE_SKELETON = ['promise' => ['date' => '<YYYY-MM-DD>', 'topic' => '<topic>']];

    protected const string FILLED_HANDOVER = '{"type":"SetupHandover","promise":{"date":"2026-11-01","topic":"Reviews"}}';

    public function testTheFilledFieldsGateReadsAValidBlockInAnySyntax(): void
    {
        $carries = static fn (string $text): bool => AIConversationMessages::textCarriesWidgetBlockOfTypeWithFilledFields($text, 'SetupHandover', self::PROMISE_SKELETON);
        self::assertTrue($carries("Done.\n```widget\n" . self::FILLED_HANDOVER . "\n```"));
        self::assertTrue($carries("~~~widget\n" . self::FILLED_HANDOVER . "\n~~~"), 'a tilde fence counts');
        self::assertTrue($carries("```widget json\n" . self::FILLED_HANDOVER . "\n```"), 'an info string after widget counts');
        self::assertTrue($carries("```widget\n" . self::FILLED_HANDOVER), 'an unclosed block counts, as the frontend renders it');
        self::assertTrue($carries("```widget\n{\"type\":\"SetupHandover\",\"promise\":{\"date\":\"2026-11-01\",\"topic\":\"a ``` b\"}}\n```"), 'a ``` inside a JSON string no longer cuts the payload');
        self::assertTrue($carries("text ```widget\n" . self::FILLED_HANDOVER . "\n```"), 'a glued opener counts, as the frontend repairs it');
        self::assertTrue($carries("```widget\n" . self::FILLED_HANDOVER . "\n``` Let me know."), 'a closer line with prose: the frontend closes after the JSON');
        self::assertFalse($carries("```widget\n{\"type\":\"SetupHandover\",\"promise\":{\"date\":\"<YYYY-MM-DD>\",\"topic\":\"x\"}}\n```"), 'a placeholder is not filled');
    }
}
