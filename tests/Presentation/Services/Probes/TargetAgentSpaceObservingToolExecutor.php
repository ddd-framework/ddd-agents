<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\MCPPackages\MCPServerProvidedInput;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use ReflectionClass;
use ReflectionMethod;

/**
 * The executor with the target space of a call observable: it records each question for the target and what every
 * gate was handed. A test may have the question refused, or answered with a space of its own, instead of asking the
 * space service; the gates themselves run unchanged.
 */
class TargetAgentSpaceObservingToolExecutor extends MCPToolExecutorService
{
    /** @var array<int, array<array-key, mixed>> the arguments of each question for the target space, in order */
    public array $argumentsOfTargetAgentSpaceLookups = [];

    /** @var array<string, AgentSpace|null> the target space each gate was handed, by the gate's method name */
    public array $targetAgentSpaceHandedToGate = [];

    /** Set: the question for the target space is refused with it, the way the space service refuses a space the account may not use */
    public ?ForbiddenException $refusalOfTargetAgentSpace = null;

    /** true = the question is answered with {@see $stubbedTargetAgentSpace} and the space service is not asked */
    public bool $answersWithStubbedTargetAgentSpace = false;

    /** The answer while {@see $answersWithStubbedTargetAgentSpace} is set; null = the call works at account level */
    public ?AgentSpace $stubbedTargetAgentSpace = null;

    /**
     * Runs one tool call the way {@see callTool()} does behind its usage envelope.
     *
     * @param array<array-key, mixed> $arguments
     * @param array<int, class-string> $candidatePackageClasses
     */
    public function callDispatchTool(string $toolName, array $arguments, array $candidatePackageClasses, ?MCPServerProvidedInput $serverProvidedInput): MCPToolResponse
    {
        return $this->dispatchTool($toolName, $arguments, $candidatePackageClasses, null, false, $serverProvidedInput);
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @return array<array-key, mixed>
     */
    public function callApplyAgentSpaceDefaultsToToolArguments(ReflectionMethod $toolMethod, array $arguments, ?MCPServerProvidedInput $serverProvidedInput): array
    {
        return $this->applyAgentSpaceDefaultsToToolArguments($toolMethod, $arguments, $serverProvidedInput);
    }

    protected function findAgentSpaceOfToolCall(array $arguments, ?MCPServerProvidedInput $serverProvidedInput): ?AgentSpace
    {
        $this->argumentsOfTargetAgentSpaceLookups[] = $arguments;
        if ($this->refusalOfTargetAgentSpace !== null) {
            throw $this->refusalOfTargetAgentSpace;
        }
        if ($this->answersWithStubbedTargetAgentSpace) {
            return $this->stubbedTargetAgentSpace;
        }
        return parent::findAgentSpaceOfToolCall($arguments, $serverProvidedInput);
    }

    protected function refuseUnentitledToolCall(
        string $toolName,
        ReflectionClass $mcpPackageReflectionClass,
        ReflectionMethod $reflectionMethod,
        ?AgentSpace $targetAgentSpace,
        ?MCPServerProvidedInput $serverProvidedInput = null
    ): ?MCPToolResponse {
        $this->targetAgentSpaceHandedToGate[__FUNCTION__] = $targetAgentSpace;
        return parent::refuseUnentitledToolCall($toolName, $mcpPackageReflectionClass, $reflectionMethod, $targetAgentSpace, $serverProvidedInput);
    }

    protected function refuseToolCallForIneligibleScope(
        string $toolName,
        ?AgentSpace $targetAgentSpace,
        ?MCPServerProvidedInput $serverProvidedInput
    ): ?MCPToolResponse {
        $this->targetAgentSpaceHandedToGate[__FUNCTION__] = $targetAgentSpace;
        return parent::refuseToolCallForIneligibleScope($toolName, $targetAgentSpace, $serverProvidedInput);
    }

    protected function refuseActionLackingMandate(
        string $toolName,
        ReflectionMethod $reflectionMethod,
        ?AgentSpace $targetAgentSpace,
        ?MCPServerProvidedInput $serverProvidedInput
    ): ?MCPToolResponse {
        $this->targetAgentSpaceHandedToGate[__FUNCTION__] = $targetAgentSpace;
        return parent::refuseActionLackingMandate($toolName, $reflectionMethod, $targetAgentSpace, $serverProvidedInput);
    }
}
