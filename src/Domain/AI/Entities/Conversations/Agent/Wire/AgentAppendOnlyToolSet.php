<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;

/**
 * Which tools an append-only conversation's prefix epoch carries as the top-level `tools`.
 * A second `additional_tools` item in the history voids the provider's whole prompt cache (measured by a probe), while appended
 * skill TEXT keeps it. So a purpose whose whole REACHABLE domain tool set — the tools of every skill it may
 * load (rail, sub-skills, entitlement-gated, its blocked tools removed) — is small enough gets that set top-level from
 * the epoch start, and a skill load appends its instructions only; a call of a tool whose skill is not loaded yet is
 * refused with the next move "load the skill first". A purpose above the ceiling keeps the per-load `additional_tools`
 * item. The decision is recorded on every call's request snapshot. Pure.
 *
 * @phpstan-consistent-constructor
 */
class AgentAppendOnlyToolSet
{
    /** The epoch carries the purpose's whole reachable domain tool set top-level; a skill load appends text only. */
    public const string TOP_LEVEL_REACHABLE_TOOLS = 'reachableTools';

    /** The epoch carries the base set's tools top-level; a skill loaded inside the epoch appends an `additional_tools` item. */
    public const string TOP_LEVEL_LOADED_SKILL_TOOLS = 'loadedSkillTools';

    /** @var string {@see TOP_LEVEL_REACHABLE_TOOLS} or {@see TOP_LEVEL_LOADED_SKILL_TOOLS} */
    public string $topLevelToolSet = self::TOP_LEVEL_LOADED_SKILL_TOOLS;

    /** @var int The estimated tokens of the reachable domain tool set: chars/4 of its JSON schemas (the snapshots' estimate). */
    public int $reachableToolSetEstimatedTokens = 0;

    /** @var int The ceiling the estimate was compared with ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings::resolveAppendOnlyReachableToolSetCeilingTokens()}). */
    public int $ceilingTokens = 0;

    /** @var ToolDefinitions|null The reachable domain tools, in catalog package order; null when they stay off the top level. */
    public ?ToolDefinitions $reachableToolDefinitions = null;

    /**
     * The decision for `$reachableToolDefinitions`: top-level when their schemas (as the offer renders them on a screen
     * surface) estimate at or below `$ceilingTokens`.
     *
     * @param string[] $descriptionPrefixesOfToolArgumentsFilledFromAgentSpace by argument name, as the offer passes them
     */
    public static function decideFor(ToolDefinitions $reachableToolDefinitions, array $descriptionPrefixesOfToolArgumentsFilledFromAgentSpace, int $ceilingTokens): static
    {
        $agentAppendOnlyToolSet = new static();
        $reachableToolSchemas = [];
        foreach ($reachableToolDefinitions->getElements() as $reachableToolDefinition) {
            $reachableToolSchemas[] = $reachableToolDefinition->toOpenAiToolSchema($descriptionPrefixesOfToolArgumentsFilledFromAgentSpace, false);
        }
        $agentAppendOnlyToolSet->reachableToolSetEstimatedTokens = self::estimateToolSchemaTokens($reachableToolSchemas);
        $agentAppendOnlyToolSet->ceilingTokens = $ceilingTokens;
        if ($reachableToolSchemas !== [] && $agentAppendOnlyToolSet->reachableToolSetEstimatedTokens <= $ceilingTokens) {
            $agentAppendOnlyToolSet->topLevelToolSet = self::TOP_LEVEL_REACHABLE_TOOLS;
            $agentAppendOnlyToolSet->reachableToolDefinitions = $reachableToolDefinitions;
        }
        return $agentAppendOnlyToolSet;
    }

    /**
     * The chars/4 estimate of tool schemas — the one {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput::estimateFixedOverheadTokens()}
     * applies to the offered tools.
     *
     * @param array<int, array<string, mixed>> $toolSchemas
     */
    public static function estimateToolSchemaTokens(array $toolSchemas): int
    {
        return (int)ceil(mb_strlen((string)json_encode($toolSchemas, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) / 4);
    }

    /** Whether the epoch carries the whole reachable domain tool set top-level. */
    public function offersReachableToolsTopLevel(): bool
    {
        return $this->topLevelToolSet === self::TOP_LEVEL_REACHABLE_TOOLS;
    }
}
