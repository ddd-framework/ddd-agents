<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;
use ReflectionMethod;

/** @phpstan-consistent-constructor */
class MCPBundleDocumentation extends ValueObject
{
    public string $name;

    public string $version;

    public string $description;

    public array $tools;

    public ?array $properties;

    public ?array $required = [];

    public static function createFromClassName(string $bundleClassName, bool $includeOutputSchema = false, bool $addObjectsToDef = false, int $maxDepth = 6): static
    {
        /** @var MCPBundle $bundleClassName */
        $mcpBundleDocumentation = new static();
        $mcpBundleDocumentation->name = $bundleClassName::$name;
        $mcpBundleDocumentation->description = $bundleClassName::$description;

        $mcpBundleDocumentation->tools = [];
        foreach ($bundleClassName::INCLUDED_PACKAGES as $includedPackageClassName) {
            /** @var MCPPackage $includedPackageClassName */
            $packageReflectionClass = $includedPackageClassName::getReflectionClass();
            $methods = $packageReflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                if (!$method->getAttributes(MCPTool::class)) {
                    continue;
                }
                $mcpFunction = MCPTool::createFromReflectionMethod(
                    $mcpBundleDocumentation,
                    $packageReflectionClass,
                    $method,
                    $includeOutputSchema,
                    $addObjectsToDef,
                    $maxDepth
                );
                // an external MCP client reads the screen default — the variant markers never leave the platform
                $mcpFunction->description = ToolDefinition::renderVariantSections((string)($mcpFunction->description ?? ''));
                $mcpBundleDocumentation->tools[] = $mcpFunction;
            }
        }
        return $mcpBundleDocumentation;
    }
}