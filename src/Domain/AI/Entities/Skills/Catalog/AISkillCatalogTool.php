<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One tool (a `#[MCPTool]` method) in the catalog tree: its name, per-turn wire-schema token cost, and the
 * token-component breakdown. `components` is null only for an error row (a package whose reflection failed).
 *
 * @phpstan-consistent-constructor
 */
class AISkillCatalogTool extends ValueObject
{
    /** @var string The tool/function name. */
    public string $name = '';

    /** @var int The tool's wire-schema token cost (GPT-3 BPE tokens). */
    public int $tokens = 0;

    /** @var AISkillCatalogToolComponents|null The token-component breakdown (null on an error row). */
    public ?AISkillCatalogToolComponents $components = null;

    public static function make(string $name, int $tokens, ?AISkillCatalogToolComponents $components): static
    {
        $tool = new static();
        $tool->name = $name;
        $tool->tokens = $tokens;
        $tool->components = $components;
        return $tool;
    }
}
