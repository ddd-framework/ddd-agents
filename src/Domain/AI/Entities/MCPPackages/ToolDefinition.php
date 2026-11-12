<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Skills\AISkillSections;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;

/**
 * One tool offered to the model: its name, description, and JSON-Schema parameters. `toOpenAiToolSchema()` produces the single
 * OpenAI tool-schema envelope (`{type: function, function: {...}}`) the proxy forwards to every vendor. Built from an
 * {@see MCPTool} via {@see fromMcpTool}; collected into {@see ToolDefinitions}.
 *
 * @phpstan-consistent-constructor
 */
class ToolDefinition extends ValueObject
{
    public string $name = '';

    public string $description = '';

    public MCPSchema $inputSchema;

    /**
     * The cached STATIC resident cost of this tool's wire schema — the per-turn context cost when the tool is loaded,
     * the input to the eviction cost-benefit ({@see \DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability::$residentTokens}).
     * Estimated as `chars/4` of `json_encode(toOpenAiToolSchema())` — NEVER
     * the tokenizer (consistent with the tool-result budget's `toolResultChars`/4). The eviction is COMPARATIVE (ratios
     * `residentTokens/ref`, `loadedCap/budget`), so a consistent estimate is enough and the thresholds are calibrated in
     * the same unit — it is NOT a BPE-exact count (`AISkillCatalogDocService` builds the exact token map). Wire
     * schemas are deploy-invariant → computed ONCE per tool name per worker ({@see self::$residentSchemaTokensByName}).
     */
    public int $residentSchemaTokens = 0;

    /** @var array<string, int> Per-tool-name memo of {@see $residentSchemaTokens} — the wire schema is deploy-invariant. */
    protected static array $residentSchemaTokensByName = [];

    /** True if the tool carries #[MCPToolAlwaysLoad] — auto-provisioned on skill-load even in lazy mode (no load_tool hop). A display marker only; eviction still applies identically (no pin). */
    public bool $isAlwaysLoad = false;

    /** True if the tool carries #[MCPToolNoEviction] — a PIN: the per-turn sweep never evicts it. Orthogonal to {@see $isAlwaysLoad}. */
    public bool $isNonEvictable = false;

    /** True if the tool carries #[MCPToolRefreshesState] — exempt from the identical-arguments repeat guard. */
    public bool $refreshesStateOnEveryCall = false;

    /** The tool's own execution ceiling in seconds (#[MCPToolExecutionTimeout]); null = the loop's default. */
    public ?int $executionTimeoutSeconds = null;

    /** True if the tool carries #[MCPToolParallelSafe] — the loop may run it concurrently with the other calls of one model response. */
    public bool $isParallelSafe = false;

    /** The tool's declared {@see MCPToolEffect}, read once with the definition; null when it declares none. Never on the wire. */
    #[HideProperty]
    public ?MCPToolEffect $effect = null;

    /** True if the tool carries #[MCPToolReturnsUntrustedContent] — the loop screens its results for injections. */
    public bool $returnsUntrustedContent = false;

    /** How many image parts the tool's result may carry (#[MCPToolImageBudget]); null = the executor's default. */
    public ?int $imageBudget = null;

    /** @var string[] Product FeatureFlags this tool requires (union of #[MCPRequiresFeatureFlags] on the method + its package). ALL must be available for the tool to be offered; empty = ungated. */
    public array $requiredFeatureFlagNames = [];

    /** @var string[] Alternative flags (#[MCPRequiresAnyFeatureFlag]): AT LEAST ONE must be available (OR); composes as ALL(required) AND ANY(anyOf). Empty = no any-of gate. */
    public array $anyOfRequiredFeatureFlagNames = [];

    /** @var string[] AccountRights this tool requires (union of #[MCPRequiresAccountRights] on the method + its package). ALL must be true on the acting account; empty = ungated. A missing right is granted by the account administrator, never bought — so it never becomes an upgrade offer. */
    public array $requiredAccountRightNames = [];

    /** The #[MCPToolSummary] routing line (what + when) — shown in the `load_tool` catalog while the tool is "available"; null if unset. */
    public ?string $summary = null;

    public static function fromMcpTool(MCPTool $function): static
    {
        $definition = new static();
        $definition->name = (string)$function->name;
        $definition->description = $function->description ?? '';
        // The #[MCPToolSummary] routing line: without it the `load_tool` catalog falls back to the FULL docblock per
        // available tool (hundreds of tokens each) and the awareness entry of a locked function has no words to show.
        $definition->summary = $function->summary;
        $definition->inputSchema = $function->inputSchema;
        $definition->isAlwaysLoad = $function->isAlwaysLoad;
        $definition->isNonEvictable = $function->isNonEvictable;
        $definition->refreshesStateOnEveryCall = $function->refreshesStateOnEveryCall;
        $definition->executionTimeoutSeconds = $function->executionTimeoutSeconds;
        $definition->isParallelSafe = $function->isParallelSafe;
        $definition->effect = $function->effect;
        $definition->returnsUntrustedContent = $function->returnsUntrustedContent;
        $definition->imageBudget = $function->imageBudget;
        $definition->requiredFeatureFlagNames = $function->requiredFeatureFlagNames;
        $definition->anyOfRequiredFeatureFlagNames = $function->anyOfRequiredFeatureFlagNames;
        $definition->requiredAccountRightNames = $function->requiredAccountRightNames;
        $definition->residentSchemaTokens = self::resolveResidentSchemaTokens($definition);
        return $definition;
    }

    /**
     * The description as the model reads it: a docblock may end in `## [surface=screen] …` /
     * `## [surface=phone] …` variant sections — the screen ones render by default, the phone ones only while the owner
     * is on the phone line; the markers never reach the wire. A description without a variant section is returned as is.
     */
    public function renderDescription(bool $ownerIsOnThePhoneLine = false): string
    {
        return self::renderVariantSections($this->description, $ownerIsOnThePhoneLine);
    }

    /** The same render for any tool text (a description, a catalog line). */
    public static function renderVariantSections(string $text, bool $ownerIsOnThePhoneLine = false): string
    {
        return AISkillSections::renderSurfaceSectionsOf($text, $ownerIsOnThePhoneLine);
    }

    /** Estimate the wire schema's token cost (chars/4) once per tool name (deploy-invariant); reused on every later build. */
    protected static function resolveResidentSchemaTokens(self $definition): int
    {
        if (!array_key_exists($definition->name, self::$residentSchemaTokensByName)) {
            self::$residentSchemaTokensByName[$definition->name] = (int)(mb_strlen((string)json_encode($definition->toOpenAiToolSchema())) / 4);
        }
        return self::$residentSchemaTokensByName[$definition->name];
    }

    /**
     * A serialized schema without the serializer's class discriminators — an `objectType` key whose value is a PHP class
     * name. A tool PARAMETER named `objectType` is a sub-schema (an array), never a string, and stays.
     */
    public static function withoutClassDiscriminators(mixed $serializedSchema): mixed
    {
        if (!is_array($serializedSchema)) {
            return $serializedSchema;
        }
        foreach ($serializedSchema as $key => $value) {
            if ($key === 'objectType' && is_string($value) && (str_starts_with($value, 'App\\') || str_starts_with($value, 'DDD\\'))) {
                unset($serializedSchema[$key]);
                continue;
            }
            if (is_array($value)) {
                $serializedSchema[$key] = self::withoutClassDiscriminators($value);
            }
        }
        return $serializedSchema;
    }

    /**
     * @param string[] $descriptionPrefixesOfToolArgumentsFilledFromAgentSpace By argument name: what the model reads
     *        in front of an argument the executor fills from the conversation's space. Such an argument is genuinely
     *        optional and the schema says so. A conversation WITHOUT a space passes none: there is nothing to fall
     *        back to, so every property keeps its declared required semantics.
     * @param bool $ownerIsOnThePhoneLine renders the description's `[surface=phone]` sections instead of the screen
     *        ones
     * @return array<string, mixed> The OpenAI tool-schema envelope.
     */
    public function toOpenAiToolSchema(array $descriptionPrefixesOfToolArgumentsFilledFromAgentSpace = [], bool $ownerIsOnThePhoneLine = false): array
    {
        // The class discriminator of the schema objects (`objectType`: "App\\…\\MCPSchema") is no JSON Schema and means
        // nothing to the model — and whether it is serialized at all depends on process state: a tool that hides
        // `objectType` for its own output (Entity::addStaticPropertiesToHide) hid it from every later schema of the
        // process, so a turn's first model call sent other (longer) tool schemas than its later calls and broke the
        // prompt-cache prefix at step 2 (found by a recorded-run comparison). Stripped always: one wire shape.
        $parameters = self::withoutClassDiscriminators($this->inputSchema->toObject());
        // JSON-Schema requires `properties` to be an object map (paramName → sub-schema). A no-argument tool has an
        // EMPTY map, and PHP serialises an empty array as `[]` — which OpenAI strict mode rejects ("[] is not of type
        // object"). Force the object shape so e.g. listImageModels emits `"properties": {}`.
        if (is_array($parameters) && (($parameters['properties'] ?? null) === [])) {
            $parameters['properties'] = new \stdClass();
        }
        // ARGUMENTS FILLED FROM THE SPACE: ToolDefinition is the AGENT-LOOP wire shape only (the HTTP-MCP server
        // serves MCPBundle::buildDocumentation instead), and in the loop the executor fills an omitted argument of
        // this kind from the conversation's space. So HERE the property is truly optional — teach the model to omit
        // it (token savings + no wrong-id class) while keeping it visible and documented for work on another space.
        // This transforms the emitted ARRAY copy; the cached MCPSchema stays untouched.
        if (is_array($parameters) && is_array($parameters['properties'] ?? null)) {
            foreach ($descriptionPrefixesOfToolArgumentsFilledFromAgentSpace as $filledArgumentName => $descriptionPrefix) {
                if (!isset($parameters['properties'][$filledArgumentName])) {
                    continue;
                }
                $filledArgumentProperty = (array)$parameters['properties'][$filledArgumentName];
                $filledArgumentProperty['description'] = trim($descriptionPrefix . ' ' . (string)($filledArgumentProperty['description'] ?? ''));
                $parameters['properties'][$filledArgumentName] = $filledArgumentProperty;
                if (is_array($parameters['required'] ?? null)) {
                    $parameters['required'] = array_values(array_diff($parameters['required'], [$filledArgumentName]));
                }
            }
        }
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->renderDescription($ownerIsOnThePhoneLine),
                'parameters' => $parameters,
            ],
        ];
    }
}
