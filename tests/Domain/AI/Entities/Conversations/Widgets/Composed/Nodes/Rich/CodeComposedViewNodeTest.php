<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewBudgets;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich\CodeComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Code` node: code with its language, title and wrap flag is kept; code holding three
 * backticks would end the widget fence and drops the node; code over the text budget and a non-boolean wrap are
 * dropped with their codes.
 */
class CodeComposedViewNodeTest extends UnitTestCase
{
    public function testCodeIsKeptWithItsLanguageTitleAndWrap(): void
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\necho conversionRate(750, 25000); // 3\n";
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => [
            'type' => 'Code',
            'code' => $code,
            'language' => 'php',
            'title' => 'conversion-rate.php',
            'wrap' => true,
        ]]);
        self::assertInstanceOf(CodeComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($code, $composedViewTreeReading->view->props['code']);
        self::assertTrue($composedViewTreeReading->view->props['wrap']);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testCodeWithThreeBackticksDropsTheNode(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Code', 'code' => "```\necho 1;\n```"]]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    public function testCodeOverTheTextBudgetDropsTheNode(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => [
            'type' => 'Code',
            'code' => str_repeat('x', ComposedViewBudgets::MAX_TEXT_LENGTH + 1),
        ]]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['BUDGET_EXCEEDED', 'INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    public function testANonBooleanWrapIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Code', 'code' => 'SELECT 1;', 'wrap' => 'yes']]);
        self::assertSame(['code' => 'SELECT 1;'], $composedViewTreeReading->view->props);
        self::assertSame(['INVALID_PROP'], $composedViewTreeReading->findings->getCodes());
    }
}
