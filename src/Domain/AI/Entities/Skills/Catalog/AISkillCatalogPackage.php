<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One MCP package in the catalog tree: its short class name, total token cost, tool count, and its tools (heaviest
 * first).
 *
 * @phpstan-consistent-constructor
 */
class AISkillCatalogPackage extends ValueObject
{
    /** @var string The package's short class name (e.g. `ReputationSummaryMCPPackage`). */
    public string $name = '';

    /** @var int Σ of the package's tool token costs. */
    public int $total = 0;

    /** @var int Number of tools in the package. */
    public int $toolCount = 0;

    /** @var AISkillCatalogTool[] The package's tools, heaviest first. */
    public array $tools = [];

    /** @param AISkillCatalogTool[] $tools */
    public static function make(string $name, int $total, int $toolCount, array $tools): static
    {
        $package = new static();
        $package->name = $name;
        $package->total = $total;
        $package->toolCount = $toolCount;
        $package->tools = $tools;
        return $package;
    }
}
