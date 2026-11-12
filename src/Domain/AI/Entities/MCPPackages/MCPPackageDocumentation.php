<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;
use ReflectionMethod;

/** @phpstan-consistent-constructor */
class MCPPackageDocumentation extends ValueObject
{
    public string $name;

    public string $version;

    public string $description;

    public array $functions;

    public ?array $properties;

    public ?array $required = [];

    /**
     * True if ANY of this package's tools exposes a QueryOptions parameter (filters/orderBy/expand/select). Derived
     * from the tools' input schemas during {@see self::createFromClassName()}. The agent loop reads this (via
     * {@see self::packageUsesQueryOptions()}) to inject the shared QueryOptions grammar ONCE into the system context
     * instead of inlining it into every tool parameter. Deterministic per package class.
     */
    public bool $usesQueryOptions = false;

    /**
     * True if ANY of this package's tools exposes a #[SharedRequestParameter] (outputFormat/noCache/skip/top/skiptoken).
     * Same role as {@see $usesQueryOptions} for the shared-request-parameters doc block. Deterministic per package class.
     */
    public bool $usesSharedParams = false;

    /** @var array<string, bool> Per-package-class cache of {@see $usesQueryOptions} (deterministic per class). */
    protected static array $usesQueryOptionsByClass = [];

    /** @var array<string, bool> Per-package-class cache of {@see $usesSharedParams} (deterministic per class). */
    protected static array $usesSharedParamsByClass = [];

    /**
     * @var array<string, self> Per-(class|flags) in-process cache of the FULL built documentation — so the per-turn
     * agent path ({@see \DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions::forPackages()}) reflects each package ONCE
     * per worker instead of every step. Mirrors the Redis layer ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPPackage::buildDocumentation()})
     * for the in-process agent loop, which bypasses Redis. The doc is deploy-invariant (a deploy = a new process = a
     * cold cache), so no invalidation is needed — same justification as the flag + token memos.
     */
    protected static array $documentationByClass = [];

    /**
     * Cheap, cached "does this package expose any QueryOptions parameter?" — for the agent's system-context build
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput}), which must NOT rebuild the documentation per
     * turn. The cache is warmed for free whenever {@see self::createFromClassName()} runs (e.g. the per-turn tool-schema
     * build), so by the time the context is composed the lookup is a hit.
     */
    public static function packageUsesQueryOptions(string $className): bool
    {
        if (!array_key_exists($className, self::$usesQueryOptionsByClass)) {
            self::$usesQueryOptionsByClass[$className] = self::createFromClassName($className)->usesQueryOptions;
        }
        return self::$usesQueryOptionsByClass[$className];
    }

    /** Cheap, cached "does this package expose any #[SharedRequestParameter]?" — mirror of {@see self::packageUsesQueryOptions()}. */
    public static function packageUsesSharedParams(string $className): bool
    {
        if (!array_key_exists($className, self::$usesSharedParamsByClass)) {
            self::$usesSharedParamsByClass[$className] = self::createFromClassName($className)->usesSharedParams;
        }
        return self::$usesSharedParamsByClass[$className];
    }

    public static function createFromClassName(
        string $className,
        bool $includeOutputSchema = false,
        bool $addObjectsToDef = false
    ): self
    {
        if (!is_a($className, MCPPackage::class, true)) {
            throw new BadRequestException('Class name for MCP Package documentation has to inherit MCPPackage');
        }
        $memoKey = $className . '|' . (int)$includeOutputSchema . '|' . (int)$addObjectsToDef;
        if (isset(self::$documentationByClass[$memoKey])) {
            return self::$documentationByClass[$memoKey];
        }
        /** @var class-string<MCPPackage> $className */
        $reflectionClass = $className::getReflectionClass();

        $mcpPackageDocumentation = new static();
        $mcpPackageDocumentation->name = $className::$name;
        $mcpPackageDocumentation->description = $className::$description;

        $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);
        $mcpPackageDocumentation->functions = [];
        foreach ($methods as $method) {
            if (!$method->getAttributes(MCPTool::class)) {
                continue;
            }
            $mcpFunction = MCPTool::createFromReflectionMethod(
                $mcpPackageDocumentation,
                $reflectionClass,
                $method,
                $includeOutputSchema,
                $addObjectsToDef
            );
            $mcpPackageDocumentation->functions[] = $mcpFunction;
            if (isset($mcpFunction->inputSchema) && $mcpFunction->inputSchema->usesQueryOptions) {
                $mcpPackageDocumentation->usesQueryOptions = true;
            }
            if (isset($mcpFunction->inputSchema) && $mcpFunction->inputSchema->usesSharedParams) {
                $mcpPackageDocumentation->usesSharedParams = true;
            }
        }
        // Warm the per-class flag caches (independent of $includeOutputSchema/$addObjectsToDef — both flags are derived
        // only from input schemas), so a later packageUsesQueryOptions()/packageUsesSharedParams() lookup is a hit.
        self::$usesQueryOptionsByClass[$className] = $mcpPackageDocumentation->usesQueryOptions;
        self::$usesSharedParamsByClass[$className] = $mcpPackageDocumentation->usesSharedParams;
        self::$documentationByClass[$memoKey] = $mcpPackageDocumentation;
        return $mcpPackageDocumentation;
    }
}