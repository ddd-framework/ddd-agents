<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The tools offered to the model for one turn. `forPackages()` builds the catalog from the loaded MCP packages'
 * documented `#[MCPTool]` methods; `toOpenAiToolSchemas()` emits the OpenAI tools array. Replaces the agent service's inline
 * `packageToolSchemas()` — schema building lives on the Set, not the loop.
 *
 * @property ToolDefinition[] $elements
 * @method ToolDefinition|null first()
 * @method ToolDefinition[] getElements()
 * @phpstan-consistent-constructor
 */
class ToolDefinitions extends ObjectSet
{
    /**
     * True if ANY tool in this catalog exposes a QueryOptions parameter (filters/orderBy/expand/select). The agent loop
     * reads this to inject the shared QueryOptions grammar ONCE into the system context instead of inlining it per tool.
     * Accumulated from each tool's input schema during {@see self::forPackages()}; not part of {@see self::toOpenAiToolSchemas()}.
     */
    public bool $usesQueryOptions = false;

    /**
     * True if any tool exposes a #[SharedRequestParameter] (outputFormat/noCache/skip/top/skiptoken). Mirror of
     * {@see self::$usesQueryOptions} — used by the external MCP server to emit the shared-params doc once in `instructions`.
     */
    public bool $usesSharedParams = false;

    /**
     * Build the tool catalog from the given loaded MCP package classes (in order).
     *
     * @param array<int, class-string> $packageClasses
     */
    /** @var array<string, self> Per-process memo of the assembled definitions per package-class list (pure code, like the documentation memo it builds on). */
    protected static array $definitionsByPackageList = [];

    public static function forPackages(array $packageClasses): self
    {
        $packageListKey = implode('|', array_unique(array_map('strval', $packageClasses)));
        if (isset(self::$definitionsByPackageList[$packageListKey])) {
            return clone self::$definitionsByPackageList[$packageListKey];
        }
        $definitions = self::assembleForPackages($packageClasses);
        self::$definitionsByPackageList[$packageListKey] = $definitions;
        return clone $definitions;
    }

    /** The assembly itself — reflection-backed documentation per package, one {@see ToolDefinition} per tool. */
    protected static function assembleForPackages(array $packageClasses): static
    {
        $definitions = new static();
        foreach ($packageClasses as $packageClass) {
            $documentation = MCPPackageDocumentation::createFromClassName($packageClass);
            if ($documentation->usesQueryOptions) {
                $definitions->usesQueryOptions = true;
            }
            if ($documentation->usesSharedParams) {
                $definitions->usesSharedParams = true;
            }
            foreach ($documentation->functions as $function) {
                // ObjectSet::add() takes its elements by reference (variadic &...$elements) — pass a variable, not a
                // method-return value, or PHP raises "Only variables should be passed by reference".
                $definition = ToolDefinition::fromMcpTool($function);
                $definitions->add($definition);
            }
        }
        return $definitions;
    }

    /**
     * A NEW catalog of only the tools the agent space is entitled to: a tool survives iff the agent space has ALL of
     * its {@see ToolDefinition::$requiredFeatureFlagNames} (its own #[MCPRequiresFeatureFlags] ∪ its package's) available
     * AND — when an any-of gate is declared ({@see ToolDefinition::$anyOfRequiredFeatureFlagNames}) — AT LEAST ONE of
     * those alternative flags. A gated tool the agent space lacks is thus offered NEITHER as a resident schema NOR in the
     * load_tool catalog — it cannot be called even if its skill loaded (a tool may be gated tighter than its skill).
     * The grammar flags are carried over conservatively (never drop the QueryOptions/shared-params doc for a surviving tool).
     */
    public function filterByAvailableFeatureFlags(AgentFeatureFlagsInterface $featureFlags): ToolDefinitions
    {
        $entitledToolDefinitions = new static();
        $entitledToolDefinitions->usesQueryOptions = $this->usesQueryOptions;
        $entitledToolDefinitions->usesSharedParams = $this->usesSharedParams;
        foreach ($this->getElements() as $toolDefinition) {
            if ($featureFlags->hasAllAvailableFeatureFlags($toolDefinition->requiredFeatureFlagNames)
                && ($toolDefinition->anyOfRequiredFeatureFlagNames === []
                    || $featureFlags->hasAnyAvailableFeatureFlag($toolDefinition->anyOfRequiredFeatureFlagNames))) {
                $entitledToolDefinitions->add($toolDefinition);
            }
        }
        return $entitledToolDefinitions;
    }

    /**
     * The definitions the ACTING USER is permitted to call — the account-rights axis, applied beside
     * {@see filterByAvailableFeatureFlags()} and never instead of it. The two are orthogonal: the flags say what the
     * plan of the space includes, the rights say what this user may do with it, so a tool must clear both.
     *
     * The schema of a right-locked tool is dropped exactly like a flag-locked one — a model that sees a callable tool
     * calls it, and the schema is re-billed every turn for something that can never run. What differs is the
     * KNOWLEDGE half: a flag-locked capability becomes an upgrade offer, a right-locked one becomes a line naming the
     * right the administrator has to grant. Neither belongs in this method; both read the same
     * definitions afterwards.
     */
    public function filterByAccountRights(AgentAccountRightsInterface $accountRightsSetting): ToolDefinitions
    {
        $permittedToolDefinitions = new static();
        $permittedToolDefinitions->usesQueryOptions = $this->usesQueryOptions;
        $permittedToolDefinitions->usesSharedParams = $this->usesSharedParams;
        foreach ($this->getElements() as $toolDefinition) {
            if ($accountRightsSetting->getMissingRightNames($toolDefinition->requiredAccountRightNames) === []) {
                $permittedToolDefinitions->add($toolDefinition);
            }
        }
        return $permittedToolDefinitions;
    }

    /**
     * A NEW catalog without the named tools — the offer's hold-back of a later plan act's tools. The grammar flags are
     * carried over like the other filters (never drop the QueryOptions/shared-params doc for a surviving tool).
     *
     * @param string[] $toolNames
     */
    public function withoutNames(array $toolNames): ToolDefinitions
    {
        $remainingToolDefinitions = new static();
        $remainingToolDefinitions->usesQueryOptions = $this->usesQueryOptions;
        $remainingToolDefinitions->usesSharedParams = $this->usesSharedParams;
        foreach ($this->getElements() as $toolDefinition) {
            if (!in_array($toolDefinition->name, $toolNames, true)) {
                $remainingToolDefinitions->add($toolDefinition);
            }
        }
        return $remainingToolDefinitions;
    }

    /** @return array<int, array<string, mixed>> The OpenAI tools array — one tool schema per definition. */
    public function toOpenAiToolSchemas(): array
    {
        $openAiToolSchemas = [];
        foreach ($this->getElements() as $definition) {
            $openAiToolSchemas[] = $definition->toOpenAiToolSchema();
        }
        return $openAiToolSchemas;
    }

    /** The ToolDefinition offered under $name, or null if this catalog has none. Pure linear scan over the elements. */
    public function getByName(string $name): ?ToolDefinition
    {
        foreach ($this->getElements() as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }
        return null;
    }
}
