<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\MCPPackages\MCPObjectProperty;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsSyntax;
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;

/**
 * Locks the "QueryOptions grammar once, type-as-source" optimization:
 *  - the shared grammar block carries all four families + examples under the trigger heading,
 *  - every per-parameter summary carries the same trigger phrase so the agent links param → block,
 *  - the expand-block orderBy/filter dedup collapses ONLY identical sets (custom orderBy keys survive).
 */
class QueryOptionsDocumentationTest extends UnitTestCase
{
    public function testAggregatedSyntaxCarriesAllFamiliesHeadingAndExamples(): void
    {
        $syntax = QueryOptionsSyntax::getSyntaxDocumentation();

        // the trigger heading the per-parameter summaries point at
        self::assertStringContainsString('## ' . QueryOptionsSyntax::HEADING, $syntax);
        // all four families present, once each
        self::assertStringContainsString('### filters', $syntax);
        self::assertStringContainsString('### orderBy', $syntax);
        self::assertStringContainsString('### expand', $syntax);
        self::assertStringContainsString('### select', $syntax);
        // examples migrated out of the trait PHPDoc into the block (not lost)
        self::assertStringContainsString("someInt lt '10'", $syntax);
        self::assertStringContainsString('nameScore desc', $syntax);
    }

    public function testEveryParameterSummaryCarriesTheTriggerPhrase(): void
    {
        foreach ([
            FiltersOptions::getParameterSummary(),
            OrderByOptions::getParameterSummary(),
            ExpandOptions::getParameterSummary(),
            SelectOptions::getParameterSummary(),
        ] as $summary) {
            self::assertStringContainsString('OData-inspired', $summary);
            self::assertStringContainsString('QueryOptions syntax', $summary);
            // the summary is a one-liner, not the full grammar
            self::assertStringNotContainsString('Operators:', $summary);
        }
    }

    /**
     * @dataProvider propertySetCases
     * @param string[] $a
     * @param string[] $b
     */
    public function testIsSamePropertySet(array $a, array $b, bool $expected): void
    {
        $mcpObjectProperty = $this->instantiateWithoutConstructor(MCPObjectProperty::class);
        self::assertSame($expected, $this->invokeMethod($mcpObjectProperty, 'isSamePropertySet', [$a, $b]));
    }

    /** @return array<string, array{0: string[], 1: string[], 2: bool}> */
    public static function propertySetCases(): array
    {
        return [
            'identical, different order' => [['id', 'name', 'created'], ['created', 'name', 'id'], true],
            'identical with duplicates'  => [['id', 'id', 'name'], ['name', 'id'], true],
            // custom orderBy keys (ShopCompetitors): filters=[source] vs orderBy=[REPUTATION,DISTANCE] → MUST NOT collapse
            'divergent custom orderBy'   => [['source'], ['REPUTATION', 'DISTANCE'], false],
            // filter superset (virtual/fulltext column) → MUST NOT collapse
            'filter superset'            => [['name', 'virtualNameSearch'], ['name'], false],
            'both empty'                 => [[], [], true],
        ];
    }
}
