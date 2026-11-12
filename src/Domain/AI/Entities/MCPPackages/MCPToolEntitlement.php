<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\Base\Entities\ValueObject;
use ReflectionClass;
use ReflectionMethod;

/**
 * What ONE MCP tool requires before it may be offered or executed — the entitlement attributes of the method UNIONED
 * with those of its package class, plus the predicate that decides whether an agent space satisfies them.
 *
 * It exists because that union and that predicate have to hold in two places at once. The OFFER side filters the tool
 * schemas out of the turn payload ({@see ToolDefinitions::filterByAvailableFeatureFlags()}); the EXECUTION side
 * ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService::dispatchTool()}) has to refuse the call independently,
 * because three callers reach it — the agent loop, the HTTP MCP server and an application's console command — and only
 * the first sees the filtered offer at all. Two copies of an authorization rule drift; this is the one copy.
 *
 * Package ∪ method is deliberately the ONLY level represented here. The exposing SKILL is not: the executor receives
 * package classes and no skill, and a tool is multi-anchored across skills
 * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities}), so "the skill that exposed this call"
 * has no single answer at execution time. Skill-level declarations stay an offer-side filter.
 *
 * @phpstan-consistent-constructor
 */
class MCPToolEntitlement extends ValueObject
{
    /**
     * @var string[] `#[MCPRequiresFeatureFlags]`, method ∪ package class. ALL must be available (AND).
     *               Empty = ungated on this axis.
     */
    public array $requiredFeatureFlagNames = [];

    /**
     * @var string[] `#[MCPRequiresAnyFeatureFlag]`, method ∪ package class pooled into ONE set. AT LEAST ONE must be
     *               available (OR). Empty = no any-of gate. Composes with {@see $requiredFeatureFlagNames} as
     *               ALL(required) AND ANY(anyOf).
     */
    public array $anyOfRequiredFeatureFlagNames = [];

    /**
     * @var string[] `#[MCPRequiresAccountRights]`, method ∪ package class. ALL must be true on the acting account
     *               (AND). Empty = ungated on this axis. A SEPARATE axis from the flags, not a stricter one: a flag
     *               says the plan of the space includes the capability, a right says this user may use it — and a
     *               missing right is never an upgrade offer.
     */
    public array $requiredAccountRightNames = [];

    /**
     * The union of all three entitlement attributes over one tool method and its package class.
     */
    public static function createFromReflectionMethod(
        ReflectionClass $mcpPackageReflectionClass,
        ReflectionMethod $reflectionMethod
    ): static {
        $mcpToolEntitlement = new static();
        $mcpToolEntitlement->requiredFeatureFlagNames = self::collectAttributeNames(
            MCPRequiresFeatureFlags::class,
            'featureFlagNames',
            $mcpPackageReflectionClass,
            $reflectionMethod
        );
        $mcpToolEntitlement->anyOfRequiredFeatureFlagNames = self::collectAttributeNames(
            MCPRequiresAnyFeatureFlag::class,
            'featureFlagNames',
            $mcpPackageReflectionClass,
            $reflectionMethod
        );
        $mcpToolEntitlement->requiredAccountRightNames = self::collectAttributeNames(
            MCPRequiresAccountRights::class,
            'accountRightNames',
            $mcpPackageReflectionClass,
            $reflectionMethod
        );
        return $mcpToolEntitlement;
    }

    /**
     * The named list property of every instance of one entitlement attribute on the method AND on its package class,
     * deduplicated. All three attributes are repeatable and carry a single string-list property, so one collector
     * serves them; only the property name differs.
     *
     * @param class-string $entitlementAttributeClassName
     * @return string[]
     */
    protected static function collectAttributeNames(
        string $entitlementAttributeClassName,
        string $attributeListPropertyName,
        ReflectionClass $mcpPackageReflectionClass,
        ReflectionMethod $reflectionMethod
    ): array {
        $collectedNames = [];
        foreach ($reflectionMethod->getAttributes($entitlementAttributeClassName) as $entitlementAttribute) {
            $collectedNames = array_merge($collectedNames, $entitlementAttribute->newInstance()->$attributeListPropertyName);
        }
        foreach ($mcpPackageReflectionClass->getAttributes($entitlementAttributeClassName) as $entitlementAttribute) {
            $collectedNames = array_merge($collectedNames, $entitlementAttribute->newInstance()->$attributeListPropertyName);
        }
        return array_values(array_unique($collectedNames));
    }

    /** Whether this tool is gated on the feature-flag axis at all. An ungated tool never needs an agent space resolved. */
    public function hasFeatureFlagGate(): bool
    {
        return $this->requiredFeatureFlagNames !== [] || $this->anyOfRequiredFeatureFlagNames !== [];
    }

    /**
     * Whether the agent space's applied flags satisfy both gates — the exact predicate the offer-side filter applies, so
     * "offered" and "executable" can never disagree.
     */
    public function isSatisfiedByFeatureFlags(AgentFeatureFlagsInterface $featureFlags): bool
    {
        if (!$featureFlags->hasAllAvailableFeatureFlags($this->requiredFeatureFlagNames)) {
            return false;
        }
        return $this->anyOfRequiredFeatureFlagNames === []
            || $featureFlags->hasAnyAvailableFeatureFlag($this->anyOfRequiredFeatureFlagNames);
    }

    /**
     * The flag names a refusal should name — the required ones plus the any-of set. For the deterministic corrective
     * the model reads; never for the decision itself.
     *
     * @return string[]
     */
    public function getGatingFeatureFlagNames(): array
    {
        return array_values(array_unique(
            array_merge($this->requiredFeatureFlagNames, $this->anyOfRequiredFeatureFlagNames)
        ));
    }

    /**
     * The gate in words for a refusal — "AI.AGENT and one of AI.AGENT.CHANNEL.APPVOICE, AI.AGENT.CHANNEL.PHONE" — so a reader never
     * takes an alternative for a requirement ({@see describeFeatureFlagRequirementOf()}).
     */
    public function describeFeatureFlagRequirement(): string
    {
        return self::describeFeatureFlagRequirementOf($this->requiredFeatureFlagNames, $this->anyOfRequiredFeatureFlagNames);
    }

    /**
     * A flag gate in words, ALL(required) AND ANY(anyOf): one required flag is named alone, several as "all of A, B";
     * the alternatives follow as "one of C, D" ("and one of" after a required part). An any-of set of ONE flag is a
     * plain requirement. The one wording of the tool refusal and the skill refusal (`load_skills`), so the two never
     * disagree about what a plan lacks.
     *
     * @param string[] $requiredFeatureFlagNames
     * @param string[] $anyOfRequiredFeatureFlagNames
     */
    public static function describeFeatureFlagRequirementOf(array $requiredFeatureFlagNames, array $anyOfRequiredFeatureFlagNames): string
    {
        $requiredFeatureFlagNames = array_values(array_unique($requiredFeatureFlagNames));
        $anyOfRequiredFeatureFlagNames = array_values(array_unique(array_diff($anyOfRequiredFeatureFlagNames, $requiredFeatureFlagNames)));
        if (count($anyOfRequiredFeatureFlagNames) === 1) {
            $requiredFeatureFlagNames[] = $anyOfRequiredFeatureFlagNames[0];
            $anyOfRequiredFeatureFlagNames = [];
        }
        $requirementParts = [];
        if (count($requiredFeatureFlagNames) === 1) {
            $requirementParts[] = $requiredFeatureFlagNames[0];
        } elseif ($requiredFeatureFlagNames !== []) {
            $requirementParts[] = 'all of ' . implode(', ', $requiredFeatureFlagNames);
        }
        if ($anyOfRequiredFeatureFlagNames !== []) {
            $requirementParts[] = 'one of ' . implode(', ', $anyOfRequiredFeatureFlagNames);
        }
        return implode(' and ', $requirementParts);
    }

    /** Whether this tool is gated on the account-rights axis at all. */
    public function hasAccountRightsGate(): bool
    {
        return $this->requiredAccountRightNames !== [];
    }

    /**
     * Whether the acting account's rights satisfy the gate — every named right must be true (AND).
     *
     * A name that is not a property of {@see AgentAccountRightsInterface} is treated as NOT granted. That cannot happen with
     * a declaration that uses the class constants and passes the application's right-name test; failing closed is
     * the right answer for the case where it somehow does, because the alternative is a gate that silently opens.
     */
    public function isSatisfiedByAccountRights(AgentAccountRightsInterface $accountRightsSetting): bool
    {
        return $accountRightsSetting->getMissingRightNames($this->requiredAccountRightNames) === [];
    }
}
