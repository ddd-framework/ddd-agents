<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/**
 * Locks the server-provided-input mechanism: a tool param typed as a {@see AgentToolCallProvenance}
 * ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPServerProvidedInput}) is INJECTED by the executor from the call
 * context threaded into `callTool()` — never hydrated from the model's arguments — and is an EMPTY instance (null
 * fields) when nothing is provided (the HTTP-MCP path). Its exclusion from the model-facing schema is verified
 * empirically via `app:mcp:documentation` (no `provenance` property on remember/updateMemory).
 */
class AgentToolCallProvenanceInjectionTest extends UnitTestCase
{
    /** Fixture: a tool-shaped method whose sole parameter is the server-provided provenance. */
    protected function toolWithProvenance(AgentToolCallProvenance $provenance): void
    {
    }

    public function testProvidedProvenanceInstanceIsInjectedVerbatim(): void
    {
        $executor = new MCPToolExecutorService();
        $provenance = new AgentToolCallProvenance();
        $method = new ReflectionMethod($this, 'toolWithProvenance');

        $params = $executor->buildMethodArguments($method, [], null, $provenance);

        $this->assertCount(1, $params);
        $this->assertSame($provenance, $params[0], 'the passed provenance must be injected verbatim, not rebuilt');
    }

    public function testEmptyProvenanceInjectedWhenNoneProvided(): void
    {
        $executor = new MCPToolExecutorService();
        $method = new ReflectionMethod($this, 'toolWithProvenance');

        // The HTTP-MCP path passes no server input; the param must still be a non-null empty instance.
        $params = $executor->buildMethodArguments($method, [], null, null);

        $this->assertInstanceOf(AgentToolCallProvenance::class, $params[0]);
        $this->assertNull($params[0]->getSourceUserMessageId());
        $this->assertNull($params[0]->getToolCallOriginMessageId());
    }

    public function testModelArgumentsNeverHydrateTheProvenance(): void
    {
        $executor = new MCPToolExecutorService();
        $method = new ReflectionMethod($this, 'toolWithProvenance');

        // Even if the model were to send a forged `sourceUserMessage`, it must NOT reach the provenance.
        $params = $executor->buildMethodArguments($method, ['sourceUserMessage' => 999, 'provenance' => 999], null, null);

        $this->assertInstanceOf(AgentToolCallProvenance::class, $params[0]);
        $this->assertNull($params[0]->getSourceUserMessageId(), 'model-supplied data must never populate a server-provided input');
    }

    public function testSourceUserMessageIdReadFromHeldMessage(): void
    {
        $userMessage = self::instantiateWithoutConstructor(AIConversationMessage::class);
        self::setPropertyValue($userMessage, 'id', 4242);
        $provenance = new AgentToolCallProvenance($userMessage, null);

        $this->assertSame(4242, $provenance->getSourceUserMessageId());
        $this->assertNull($provenance->getToolCallOriginMessageId());
    }
}
