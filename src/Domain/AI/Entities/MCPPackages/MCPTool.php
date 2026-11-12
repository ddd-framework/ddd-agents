<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;


use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ReflectionDocComment;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use ReflectionClass;
use ReflectionMethod;

/** @phpstan-consistent-constructor */
#[Attribute(Attribute::TARGET_METHOD)]
class MCPTool extends ValueObject
{
    use BaseAttributeTrait;

    /** @var string|null The Tools's name */
    public ?string $name;

    /** @var string|null The Tools's title */
    public ?string $title;

    /** @var string|null The Tools's description */
    public ?string $description;

    public MCPSchema $inputSchema;

    public MCPSchema $outputSchema;

    /** True if this tool carries #[MCPToolAlwaysLoad] (on the method OR its package class) — auto-provisioned on skill-load even in lazy mode (no load_tool hop to first reach it). Eviction still applies identically; it is NOT a pin. */
    public bool $isAlwaysLoad = false;

    /** True if this tool carries #[MCPToolNoEviction] (on the method OR its package class) — a PIN: once loaded the per-turn sweep never evicts it (SOFT/CASCADE/HARD all skip it). Orthogonal to {@see $isAlwaysLoad} (residency vs eviction). */
    public bool $isNonEvictable = false;

    /** True if this tool carries #[MCPToolRefreshesState] (on the method OR its package class) — exempt from the identical-arguments repeat guard, because it reports CHANGED state each call. */
    public bool $refreshesStateOnEveryCall = false;

    /** @var int|null The tool's own execution ceiling in seconds (#[MCPToolExecutionTimeout], method before package class); null = the loop's default. */
    public ?int $executionTimeoutSeconds = null;

    /** True if this tool carries #[MCPToolParallelSafe] (on the method OR its package class) — the loop may run it concurrently with the other calls of the same model response. */
    public bool $isParallelSafe = false;

    /** The method's #[MCPToolEffect]; null when it declares none. Platform-internal — never on the documentation wire. */
    #[HideProperty]
    public ?MCPToolEffect $effect = null;

    /** True if this tool carries #[MCPToolReturnsUntrustedContent] (on the method OR its package class) — its results are screened for injections. */
    public bool $returnsUntrustedContent = false;

    /** The MCP tool annotations derived from {@see $effect} and {@see $returnsUntrustedContent} — on the `tools/list` wire for external clients. */
    public ?MCPToolAnnotations $annotations = null;

    /** @var int|null How many image parts the tool's result may carry (#[MCPToolImageBudget], method before package class); null = the executor's default. */
    public ?int $imageBudget = null;

    /** @var string[] Product FeatureFlags this tool requires (#[MCPRequiresFeatureFlags], UNION of method ∪ package class). ALL must be available on the agent space for the tool to be offered. Empty = ungated. */
    public array $requiredFeatureFlagNames = [];

    /** @var string[] Alternative Product FeatureFlags (#[MCPRequiresAnyFeatureFlag], method ∪ package class): AT LEAST ONE must be available (OR). Composes with {@see $requiredFeatureFlagNames} as ALL(required) AND ANY(anyOf). Empty = no any-of gate. */
    public array $anyOfRequiredFeatureFlagNames = [];
    /** @var string[] The product capability keys this tool delivers (#[MCPDeliversProductCapability], method ∪ package class) — the AI side's declaration the Capability Coverage Review reads against `ProductCapability::$agentCoverage`; never a gate. */
    public array $productCapabilityKeys = [];

    /** @var string[] AccountRights this tool requires (#[MCPRequiresAccountRights], method ∪ package class). ALL must be true on the acting account (AND). A SECOND axis, not a stricter flag: a missing right is granted by the account administrator, never bought, so it is never an upgrade offer. Empty = ungated. */
    public array $requiredAccountRightNames = [];

    /** The #[MCPToolSummary] routing line (what + when) — the level-1 disclosure shown in the `load_tool` catalog while the tool is "available"; null if unset. */
    public ?string $summary = null;

    public static function createFromReflectionMethod(
        MCPPackageDocumentation|MCPBundleDocumentation $bundleOrPackageDocumentation,
        ReflectionClass $mcpPackageReflectionClass,
        ReflectionMethod $reflectionMethod,
        bool $includeOutputSchema = false,
        bool $addObjectsToDef = false,
        int $maxDepth = 6,
    ): static
    {
        $mcpFunction = new static();
        $mcpFunction->name = $reflectionMethod->getName();
        $mcpFunction->title = $reflectionMethod->getName();
        // #[MCPToolAlwaysLoad] — method-level OR class-level (a whole package marked cheap+central). Read once here (the
        // documentation is memoized), carried onto ToolDefinition to drive the INITIAL auto-load (even in lazy mode). It
        // is a display marker downstream, not a pin — eviction treats an always-loaded tool like any other.
        $mcpFunction->isAlwaysLoad = $reflectionMethod->getAttributes(MCPToolAlwaysLoad::class) !== []
            || $mcpPackageReflectionClass->getAttributes(MCPToolAlwaysLoad::class) !== [];
        // #[MCPToolNoEviction] — same method-OR-class read; the PIN (never evicted). Orthogonal to always-load above.
        $mcpFunction->isNonEvictable = $reflectionMethod->getAttributes(MCPToolNoEviction::class) !== []
            || $mcpPackageReflectionClass->getAttributes(MCPToolNoEviction::class) !== [];
        // #[MCPToolRefreshesState] — same method-OR-class read; exempts the tool from the identical-arguments repeat
        // guard because it reports changed state each call (the browser capture case).
        $mcpFunction->refreshesStateOnEveryCall = $reflectionMethod->getAttributes(MCPToolRefreshesState::class) !== []
            || $mcpPackageReflectionClass->getAttributes(MCPToolRefreshesState::class) !== [];
        // #[MCPToolParallelSafe] — same method-OR-class read; admits the tool to the concurrent execution of a
        // multi-call model response (the fork-join). Declared by the author, never inferred from the name.
        $mcpFunction->isParallelSafe = $reflectionMethod->getAttributes(MCPToolParallelSafe::class) !== []
            || $mcpPackageReflectionClass->getAttributes(MCPToolParallelSafe::class) !== [];
        // #[MCPToolEffect] — the METHOD's own declaration only (the same read as MCPToolEffectCatalog)
        $effectAttributes = $reflectionMethod->getAttributes(MCPToolEffect::class);
        $mcpFunction->effect = $effectAttributes !== [] ? $effectAttributes[0]->newInstance() : null;
        // #[MCPToolReturnsUntrustedContent] — same method-OR-class read; the loop screens its results.
        $mcpFunction->returnsUntrustedContent = $reflectionMethod->getAttributes(MCPToolReturnsUntrustedContent::class) !== []
            || $mcpPackageReflectionClass->getAttributes(MCPToolReturnsUntrustedContent::class) !== [];
        $mcpFunction->annotations = MCPToolAnnotations::fromToolDeclarations($mcpFunction->effect, $mcpFunction->returnsUntrustedContent);
        // #[MCPToolExecutionTimeout] — the method's own ceiling wins over the package class's; null = loop default.
        $executionTimeoutAttributes = $reflectionMethod->getAttributes(MCPToolExecutionTimeout::class);
        if ($executionTimeoutAttributes === []) {
            $executionTimeoutAttributes = $mcpPackageReflectionClass->getAttributes(MCPToolExecutionTimeout::class);
        }
        if ($executionTimeoutAttributes !== []) {
            /** @var MCPToolExecutionTimeout $executionTimeoutAttribute */
            $executionTimeoutAttribute = $executionTimeoutAttributes[0]->newInstance();
            $mcpFunction->executionTimeoutSeconds = max(1, $executionTimeoutAttribute->seconds);
        }
        // #[MCPToolRequiresMandate]: the executor may check the customer's consent before the tool runs;
        // a tool with a long ceiling of its own already has the room (and must stay below the reaper's hung threshold)
        $ownCeilingSeconds = $mcpFunction->executionTimeoutSeconds ?? MCPToolExecutionTimeout::DEFAULT_SECONDS;
        if ($reflectionMethod->getAttributes(MCPToolRequiresMandate::class) !== [] && $ownCeilingSeconds < MCPToolRequiresMandate::LONG_CEILING_SECONDS) {
            $mcpFunction->executionTimeoutSeconds = $ownCeilingSeconds + MCPToolRequiresMandate::CONSENT_CHECK_SECONDS;
        }
        // #[MCPToolImageBudget] — the method's own image budget wins over the package class's; null = executor default.
        $imageBudgetAttributes = $reflectionMethod->getAttributes(MCPToolImageBudget::class);
        if ($imageBudgetAttributes === []) {
            $imageBudgetAttributes = $mcpPackageReflectionClass->getAttributes(MCPToolImageBudget::class);
        }
        if ($imageBudgetAttributes !== []) {
            /** @var MCPToolImageBudget $imageBudgetAttribute */
            $imageBudgetAttribute = $imageBudgetAttributes[0]->newInstance();
            $mcpFunction->imageBudget = max(1, $imageBudgetAttribute->images);
        }
        // #[MCPRequiresFeatureFlags] (AND) ∪ #[MCPRequiresAnyFeatureFlag] (OR), each UNIONED over method ∪ package
        // class. The union AND the satisfaction predicate live on MCPToolEntitlement, because the EXECUTION
        // guard (MCPToolExecutorService::dispatchTool) has to apply exactly the same rule as this offer side — two
        // copies of an authorization rule drift apart.
        $mcpToolEntitlement = MCPToolEntitlement::createFromReflectionMethod($mcpPackageReflectionClass, $reflectionMethod);
        $mcpFunction->requiredFeatureFlagNames = $mcpToolEntitlement->requiredFeatureFlagNames;
        $productCapabilityKeys = [];
        foreach ([...$reflectionMethod->getAttributes(MCPDeliversProductCapability::class), ...$mcpPackageReflectionClass->getAttributes(MCPDeliversProductCapability::class)] as $deliversAttribute) {
            array_push($productCapabilityKeys, ...$deliversAttribute->newInstance()->productCapabilityKeys);
        }
        $mcpFunction->productCapabilityKeys = array_values(array_unique($productCapabilityKeys));
        $mcpFunction->anyOfRequiredFeatureFlagNames = $mcpToolEntitlement->anyOfRequiredFeatureFlagNames;
        $mcpFunction->requiredAccountRightNames = $mcpToolEntitlement->requiredAccountRightNames;
        $summaryAttributes = $reflectionMethod->getAttributes(MCPToolSummary::class);
        $mcpFunction->summary = $summaryAttributes !== [] ? $summaryAttributes[0]->newInstance()->summary : null;
        $docCommentText = $reflectionMethod->getDocComment();
        if ($docCommentText) {
            $docComment = new ReflectionDocComment($docCommentText, $mcpPackageReflectionClass, $reflectionMethod);
            // we exclude examples from description as the examples will be included in the MCPSchema of the input
            $mcpFunction->description = $docComment->getDescription(includeExamples: false);
        }
        $mcpFunction->inputSchema = MCPSchema::createFromReflectionMethod(
            $bundleOrPackageDocumentation,
            $mcpFunction,
            MCPSchema::FUNCTION_TYPE_INPUT,
            $mcpPackageReflectionClass,
            $reflectionMethod,
            $addObjectsToDef,
            $maxDepth,
        );
        if ($includeOutputSchema) {
            $mcpFunction->outputSchema = MCPSchema::createFromReflectionMethod(
                $bundleOrPackageDocumentation,
                $mcpFunction,
                MCPSchema::FUNCTION_TYPE_OUTPUT,
                $mcpPackageReflectionClass,
                $reflectionMethod,
                $addObjectsToDef,
                $maxDepth,
            );
        }
        return $mcpFunction;
    }
}