<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewShownNumbers;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The per-block eval record of a `ComposedView` carries the `$from` bindings whose snapshot
 * is `failed` (the chart renders an empty dataset — the report counts the block as malformed) and the numbers of the
 * tree the prose outside every block writes again, in either locale (the report's PROSE_REPEATS_WIDGET_VALUES).
 */
class WidgetBlockSyntaxRecordsTest extends UnitTestCase
{
    public function testAFailedFromBindingIsCountedAResolvedOneIsNot(): void
    {
        $failedChart = ['type' => 'ComposedView', 'view' => ['type' => 'Chart', 'kind' => 'line', 'x' => 'month', 'series' => [['field' => 'averageRating']],
            'data' => ['$from' => ['toolCall' => 'call_1', 'path' => '$.ratingsByMonth[*]', 'state' => 'failed', 'value' => [], 'reason' => 'the result binds nothing']]]];
        $resolvedChart = $failedChart;
        $resolvedChart['view']['data']['$from'] = ['toolCall' => 'call_1', 'path' => '$.ratingsByMonth[*]', 'state' => 'resolved', 'value' => [['month' => '2026-09', 'averageRating' => 4.5]]];

        $blockSyntaxRecords = WidgetBlocks::fromMarkdown(self::buildFence($failedChart) . "\n" . self::buildFence($resolvedChart) . "\n" . self::buildFence(['type' => 'PostsList']))->buildBlockSyntaxRecords();

        self::assertSame(1, $blockSyntaxRecords[0]['failedBindingCount']);
        self::assertSame(0, $blockSyntaxRecords[1]['failedBindingCount']);
        self::assertNull($blockSyntaxRecords[2]['failedBindingCount'], 'only a ComposedView record carries the count');
        self::assertNull($blockSyntaxRecords[2]['numbersRepeatedInProse']);
    }

    public function testTheProseRepeatsTheTreeNumbersInEitherLocaleOutsideTheBlocksOnly(): void
    {
        $kpiGrid = ['type' => 'ComposedView', 'shopId' => 500553, 'view' => ['type' => 'Grid', 'children' => [
            ['type' => 'KpiTile', 'label' => 'Rating', 'value' => 4.7],
            ['type' => 'KpiTile', 'label' => 'Reviews', 'value' => 1234],
            ['type' => 'KpiTile', 'label' => 'Visibility', 'value' => '62,5 %'],
            ['type' => 'KpiTile', 'label' => 'Keywords', 'value' => 18],
            ['type' => 'KpiTile', 'label' => 'Stars', 'value' => 5],
        ]]];
        $answer = "Your rating is 4,7 with 1.234 reviews, visibility 62.5 % and 5 stars.\n" . self::buildFence($kpiGrid);

        $blockSyntaxRecords = WidgetBlocks::fromMarkdown($answer)->buildBlockSyntaxRecords();

        self::assertSame(['4.7', '1234', '62.5'], $blockSyntaxRecords[0]['numbersRepeatedInProse'], '18 is only in the tree; 5 and the shop id are no shown figure');
    }

    public function testTheBoundRowsOfAResolvedSnapshotAreShownNumbers(): void
    {
        $shownNumbers = ComposedViewShownNumbers::fromWidgetFields(['view' => ['type' => 'Chart', 'data' => ['$from' => [
            'toolCall' => 'call_1', 'path' => '$.ratingsByMonth[*]', 'state' => 'resolved', 'rowCount' => 12,
            'value' => [['month' => '2026-08', 'reviewCount' => 14, 'averageRating' => 4.25], ['month' => '2026-09', 'reviewCount' => 3, 'averageRating' => 3.8]],
        ]], 'min' => 1, 'max' => 5]]);

        self::assertSame(['14', '4.25', '3.8'], $shownNumbers->shownNumbers);
        self::assertSame(['4.25', '3.8'], $shownNumbers->findNumbersRepeatedInProse('In August 4,25, in September 3.8 stars.'));
    }

    public function testAWrittenFigureIsReadInBothLocales(): void
    {
        self::assertSame(['4.7'], ComposedViewShownNumbers::readCanonicalNumbers('4,7'));
        self::assertSame(['1234', '1.234'], ComposedViewShownNumbers::readCanonicalNumbers('1.234'));
        self::assertSame(['1234.5'], ComposedViewShownNumbers::readCanonicalNumbers('1.234,5'));
        self::assertSame(['1234567'], ComposedViewShownNumbers::readCanonicalNumbers('1,234,567'));
        self::assertSame(['-3'], ComposedViewShownNumbers::readCanonicalNumbers('-3'));
    }

    /** @param array<string, mixed> $widgetFields */
    protected static function buildFence(array $widgetFields): string
    {
        return "```widget\n" . json_encode($widgetFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n```";
    }
}
