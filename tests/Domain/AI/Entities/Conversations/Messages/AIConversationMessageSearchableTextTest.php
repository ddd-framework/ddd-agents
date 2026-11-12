<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * AIConversationMessage::toSearchableText() cleans a message down to prose for the SEARCH excerpt + rerank candidate:
 * it removes ```widget fenced blocks (payload JSON), the {preCallMessage} marker, and Markdown formatting. Guards the
 * "review" search regression where the widget JSON + the preCall marker leaked into the hit excerpts. Pure domain.
 */
class AIConversationMessageSearchableTextTest extends UnitTestCase
{
    public function testStripsWidgetFencedBlocksEntirely(): void
    {
        // The exact stored shape (verified in the DB): prose + two ```widget blocks.
        $raw = "Here are your two latest reviews:\n\n"
            . "```widget\n{ \"type\": \"SingleReview\", \"directoryAlias\": \"facebook\", \"externalId\": \"2190270225040891\" }\n```\n"
            . "```widget\n{ \"type\": \"SingleReview\", \"directoryAlias\": \"facebook\", \"externalId\": \"27875091508764443\" }\n```";
        self::assertSame('Here are your two latest reviews:', AIConversationMessage::toSearchableText($raw));
    }

    public function testStripsPreCallMessageMarker(): void
    {
        self::assertSame(
            'One moment — fetching your latest reviews ...',
            AIConversationMessage::toSearchableText('{preCallMessage} One moment — fetching your latest reviews ...')
        );
    }

    public function testStripsMarkdownFormattingKeepsWords(): void
    {
        self::assertSame(
            'Nummer acht is a heading with a link and code and italic',
            AIConversationMessage::toSearchableText(
                "**Nummer acht** is a\n## heading\nwith a [link](https://example.com) and `code` and *italic*"
            )
        );
    }

    public function testStripsListAndQuoteMarkers(): void
    {
        self::assertSame(
            'first second quoted line',
            AIConversationMessage::toSearchableText("- first\n2. second\n> quoted line")
        );
    }

    public function testPlainTextIsUnchangedApartFromWhitespaceCollapse(): void
    {
        self::assertSame('show 2 reviews in inline widget', AIConversationMessage::toSearchableText('show 2 reviews in inline widget'));
    }

    public function testKeepsInnerTextOfNonWidgetCodeFence(): void
    {
        self::assertSame('before echo hello after', AIConversationMessage::toSearchableText("before\n```php\necho hello\n```\nafter"));
    }

    public function testEmptyStaysEmpty(): void
    {
        self::assertSame('', AIConversationMessage::toSearchableText(''));
    }
}
