<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The full documentation of one MCP tool: the exact wire schema the model receives every turn (verbatim
 * {@see \DDD\Domain\AI\Entities\MCPPackages\ToolDefinition::toOpenAiToolSchema()}), its owning skill/package, and the
 * token-component breakdown. The typed object returned by
 * {@see \DDD\Domain\AI\Services\Skills\AISkillCatalogDocService::buildToolDocumentation()}.
 *
 * @phpstan-consistent-constructor
 */
class AISkillToolDocumentation extends ValueObject
{
    /** @var string The tool/function name. */
    public string $name = '';

    /** @var string The dotted name of the skill that exposes this tool (e.g. `Reputation.Overview`). */
    public string $skill = '';

    /** @var string The short class name of the MCP package providing this tool. */
    public string $package = '';

    /** @var int The total per-turn token cost of this tool's wire schema (GPT-3 BPE tokens). */
    public int $tokens = 0;

    /** @var AISkillCatalogToolComponents Where the tokens live (description, parameters, $defs, envelope). */
    public AISkillCatalogToolComponents $components;

    /** @var array The exact OpenAI tool-schema envelope sent to the model — open-ended JSON-Schema. */
    public array $openAiToolSchema = [];

    /** @param array<string, mixed> $openAiToolSchema */
    public static function make(
        string $name,
        string $skill,
        string $package,
        int $tokens,
        AISkillCatalogToolComponents $components,
        array $openAiToolSchema,
    ): static {
        $documentation = new static();
        $documentation->name = $name;
        $documentation->skill = $skill;
        $documentation->package = $package;
        $documentation->tokens = $tokens;
        $documentation->components = $components;
        $documentation->openAiToolSchema = $openAiToolSchema;
        return $documentation;
    }
}
