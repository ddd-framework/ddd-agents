<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadToolTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPSchema;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinition;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `#[MCPToolSummary]` routing line must survive the conversion MCPTool → ToolDefinition: the `load_tool` catalog
 * prints it per available tool, and a missing copy made the catalog fall back to the full docblock of every tool.
 */
class ToolDefinitionSummaryBridgeTest extends UnitTestCase
{
    protected function mcpTool(string $name, string $description, ?string $summary): MCPTool
    {
        $mcpTool = self::instantiateWithoutConstructor(MCPTool::class);
        $mcpTool->name = $name;
        $mcpTool->description = $description;
        $mcpTool->summary = $summary;
        $mcpTool->inputSchema = new MCPSchema();
        $mcpTool->isAlwaysLoad = false;
        $mcpTool->isNonEvictable = false;
        $mcpTool->refreshesStateOnEveryCall = false;
        $mcpTool->executionTimeoutSeconds = null;
        $mcpTool->imageBudget = null;
        $mcpTool->requiredFeatureFlagNames = [];
        $mcpTool->anyOfRequiredFeatureFlagNames = [];
        $mcpTool->requiredAccountRightNames = [];
        return $mcpTool;
    }

    public function testTheSummarySurvivesTheConversionAndIsWhatTheLoadToolCatalogPrints(): void
    {
        $longDocblock = str_repeat('Replaces the WHOLE weekly schedule with every shape rule spelled out. ', 20);
        $withSummary = ToolDefinition::fromMcpTool($this->mcpTool('updateOpeningHoursProbe', $longDocblock, 'Replaces the weekly opening hours. Use when the owner changes them.'));
        $withoutSummary = ToolDefinition::fromMcpTool($this->mcpTool('legacyProbeTool', 'The docblock is the only text this tool has.', null));

        self::assertSame('Replaces the weekly opening hours. Use when the owner changes them.', $withSummary->summary);
        self::assertSame($longDocblock, $withSummary->description, 'the full description stays the loaded schema text');
        self::assertNull($withoutSummary->summary);

        $availableTools = new ToolDefinitions();
        $availableTools->add($withSummary);
        $availableTools->add($withoutSummary);
        $catalog = (string)LoadToolTool::forAvailableTools($availableTools, [])->toOpenAiToolSchema()['function']['description'];

        self::assertStringContainsString('- updateOpeningHoursProbe — Replaces the weekly opening hours. Use when the owner changes them.', $catalog);
        self::assertStringNotContainsString('every shape rule spelled out', $catalog, 'a tool WITH a summary never prints its docblock');
        self::assertStringContainsString('- legacyProbeTool — The docblock is the only text this tool has.', $catalog, 'a tool without a summary keeps the docblock fallback');
    }
}
