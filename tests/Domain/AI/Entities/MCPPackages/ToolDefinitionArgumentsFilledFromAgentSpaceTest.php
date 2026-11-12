<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\MCPPackages\ToolDefinition;
use DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\Probes\ProbeMCPSchemaWithFixedWireArray;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The tool schema the model reads in a conversation with a space: an argument the executor fills from the space is
 * no longer required and says so in front of its description; every other argument, and the schema of a conversation
 * without a space, stay as declared.
 */
class ToolDefinitionArgumentsFilledFromAgentSpaceTest extends UnitTestCase
{
    protected const array DECLARED_PARAMETERS = [
        'type' => 'object',
        'properties' => [
            'agentSpaceId' => ['type' => 'integer', 'description' => 'The space to read.'],
            'note' => ['type' => 'string', 'description' => 'A note.'],
        ],
        'required' => ['agentSpaceId', 'note'],
    ];

    public function testAFilledArgumentIsOptionalAndSaysSo(): void
    {
        $parameters = $this->buildToolDefinition(self::DECLARED_PARAMETERS)->toOpenAiToolSchema(['agentSpaceId' => 'OPTIONAL — omit it.'])['function']['parameters'];

        self::assertSame('OPTIONAL — omit it. The space to read.', $parameters['properties']['agentSpaceId']['description']);
        self::assertSame('integer', $parameters['properties']['agentSpaceId']['type']);
        self::assertSame(['note'], $parameters['required']);
        self::assertSame(self::DECLARED_PARAMETERS['properties']['note'], $parameters['properties']['note']);
    }

    public function testWithoutASpaceTheSchemaStaysAsDeclared(): void
    {
        $parameters = $this->buildToolDefinition(self::DECLARED_PARAMETERS)->toOpenAiToolSchema()['function']['parameters'];

        self::assertSame(self::DECLARED_PARAMETERS, $parameters);
    }

    public function testAnArgumentTheToolDoesNotDeclareChangesNothing(): void
    {
        $parameters = $this->buildToolDefinition(self::DECLARED_PARAMETERS)->toOpenAiToolSchema(['someOtherId' => 'OPTIONAL — omit it.'])['function']['parameters'];

        self::assertSame(self::DECLARED_PARAMETERS, $parameters);
    }

    public function testSeveralFilledArgumentsAreEachTakenOffTheRequiredList(): void
    {
        $declaredParameters = self::DECLARED_PARAMETERS;
        $declaredParameters['properties']['someOtherId'] = ['type' => 'integer'];
        $declaredParameters['required'][] = 'someOtherId';

        $parameters = $this->buildToolDefinition($declaredParameters)->toOpenAiToolSchema(['agentSpaceId' => 'First.', 'someOtherId' => 'Second.'])['function']['parameters'];

        self::assertSame(['note'], $parameters['required']);
        self::assertSame('First. The space to read.', $parameters['properties']['agentSpaceId']['description']);
        self::assertSame('Second.', $parameters['properties']['someOtherId']['description']);
    }

    /** @param array<string, mixed> $declaredParameters */
    protected function buildToolDefinition(array $declaredParameters): ToolDefinition
    {
        $inputSchema = new ProbeMCPSchemaWithFixedWireArray();
        $inputSchema->fixedWireArray = $declaredParameters;
        $toolDefinition = new ToolDefinition();
        $toolDefinition->name = 'probeTool';
        $toolDefinition->description = 'Reads a probe.';
        $toolDefinition->inputSchema = $inputSchema;
        return $toolDefinition;
    }
}
