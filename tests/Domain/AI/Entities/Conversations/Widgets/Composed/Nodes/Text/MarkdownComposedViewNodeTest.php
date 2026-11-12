<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Markdown` node: formatted text is kept as written (the safe subset is the frontend's renderer);
 * a fenced code block is refused by the reader (three backticks would end the widget fence); it is a block, so a
 * `Text` does not take it inline.
 */
class MarkdownComposedViewNodeTest extends UnitTestCase
{
    public function testTheReaderKeepsMarkdown(): void
    {
        $markdown = "**Koalitionen** mit Mehrheit:\n\n| Koalition | Sitze |\n|---|---:|\n| CSU + Grüne | 104 |";
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Markdown', 'markdown' => $markdown]]);
        self::assertSame(['type' => 'Markdown', 'markdown' => $markdown], $composedViewTreeReading->view?->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAFencedCodeBlockIsRefused(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Markdown', 'markdown' => "Code:\n```php\necho 1;\n```"]]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    public function testATextDoesNotTakeMarkdownInline(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Text', 'text' => 'x', 'children' => [['type' => 'Markdown', 'markdown' => '*y*']]]]);
        self::assertSame(['type' => 'Text', 'text' => 'x'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame(['INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }
}
