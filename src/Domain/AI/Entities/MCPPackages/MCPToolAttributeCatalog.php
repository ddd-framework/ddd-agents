<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Skills\AISkill;
use ReflectionClass;
use ReflectionMethod;

/**
 * The attributes every agent tool declares, by tool name — for the readers that hold only a STORED tool call (its name)
 * and ask what kind of tool it is: the run report's owner-facing subject ({@see MCPToolOwnerFacingSubject}), the
 * reasoning-effort precondition ({@see MCPToolProposesIdentityOrClass}), the wire's never-stubbed newest result
 * ({@see MCPToolNewestResultIsNeverStubbed}) — for the loop itself, which asks around a call what the called tool
 * declares ({@see MCPToolReceivesCurrentConversationId}, {@see MCPToolWritesAgentMemoryOnly},
 * {@see MCPToolResultIsNeverStubbedByTheBudget}, {@see MCPToolShowsNoActivityLine}) — and for a purpose class that
 * blocks a kind of tool when the purpose is built ({@see MCPToolSendsMessageToOwner}).
 * The core asks the declaration; it never lists tool names of a feature domain. When two bound packages declare a tool
 * of the same name, the first in catalog order is the one whose attributes are read.
 *
 * Built once per process from the `#[MCPTool]` methods of the packages every skill carries
 * ({@see AISkill::getPackageClassesBySkillName()}) — code metadata, the same in every process. This walk is the only one:
 * {@see MCPToolEffectCatalog} builds its effects from {@see self::getToolMethodsByToolName()}. A name the catalog does not
 * know (a tool removed since the row was written) carries no attribute.
 */
class MCPToolAttributeCatalog
{
    /** @var ReflectionMethod[]|null tool name → its `#[MCPTool]` method, built on first use */
    protected static ?array $toolMethodsByToolName = null;

    /** @var array<string, array<string, object|null>> tool name → attribute class → the declared instance, memoized */
    protected static array $attributesByToolName = [];

    /** @var array<string, string[]> attribute class → the names of the tools declaring it, memoized */
    protected static array $toolNamesByAttributeClass = [];

    /**
     * The attribute `$attributeClass` the named tool declares, or null when it declares none or the name is unknown.
     *
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    public static function getAttributeOfToolName(string $toolName, string $attributeClass): ?object
    {
        if (array_key_exists($attributeClass, self::$attributesByToolName[$toolName] ?? [])) {
            return self::$attributesByToolName[$toolName][$attributeClass];
        }
        $toolMethod = self::getToolMethodsByToolName()[$toolName] ?? null;
        $attributes = $toolMethod?->getAttributes($attributeClass) ?? [];
        $attribute = $attributes !== [] ? $attributes[0]->newInstance() : null;
        self::$attributesByToolName[$toolName][$attributeClass] = $attribute;

        return $attribute;
    }

    /** True when the named tool declares the attribute `$attributeClass`. */
    public static function hasAttributeOnToolName(string $toolName, string $attributeClass): bool
    {
        return self::getAttributeOfToolName($toolName, $attributeClass) !== null;
    }

    /**
     * The names of every tool that declares `$attributeClass`, in catalog order (skill, package, method). Computed once
     * per process and attribute class: readers on the path of a model call ask it at every call.
     *
     * @return string[]
     */
    public static function getToolNamesDeclaringAttribute(string $attributeClass): array
    {
        if (isset(self::$toolNamesByAttributeClass[$attributeClass])) {
            return self::$toolNamesByAttributeClass[$attributeClass];
        }
        $toolNames = [];
        foreach (array_keys(self::getToolMethodsByToolName()) as $toolName) {
            if (self::hasAttributeOnToolName($toolName, $attributeClass)) {
                $toolNames[] = $toolName;
            }
        }
        self::$toolNamesByAttributeClass[$attributeClass] = $toolNames;

        return $toolNames;
    }

    /**
     * Every `#[MCPTool]` method of the catalog, by tool name — the one reflection walk over the skills' packages per
     * process; {@see MCPToolEffectCatalog} reads its effects from it too.
     *
     * @return ReflectionMethod[] tool name → its `#[MCPTool]` method
     */
    public static function getToolMethodsByToolName(): array
    {
        if (self::$toolMethodsByToolName !== null) {
            return self::$toolMethodsByToolName;
        }
        $toolMethodsByToolName = [];
        foreach (AISkill::getPackageClassesBySkillName() as $packageClasses) {
            foreach ($packageClasses as $packageClass) {
                foreach ((new ReflectionClass($packageClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
                    if ($reflectionMethod->getAttributes(MCPTool::class) !== []) {
                        $toolMethodsByToolName[$reflectionMethod->getName()] ??= $reflectionMethod;
                    }
                }
            }
        }
        self::$toolMethodsByToolName = $toolMethodsByToolName;

        return $toolMethodsByToolName;
    }
}
