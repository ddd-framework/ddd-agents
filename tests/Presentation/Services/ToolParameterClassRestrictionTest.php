<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use ArrayObject;
use DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/**
 * The model's arguments are handed to a request DTO or an object of the framework only: a tool parameter of any
 * other class must come from the container, it is never constructed from the arguments.
 */
class ToolParameterClassRestrictionTest extends UnitTestCase
{
    /** Fixture: a tool-shaped method with a parameter class that is no DTO, no framework object and no service. */
    protected function toolWithForeignClassParameter(ArrayObject $foreignObject): void
    {
    }

    /** Fixture: a tool-shaped method with a framework object as its parameter. */
    protected function toolWithFrameworkObjectParameter(AgentApplicationSqlFilter $frameworkObject): void
    {
    }

    public function testAForeignClassIsNeverConstructedFromTheArguments(): void
    {
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('typed ArrayObject');
        (new MCPToolExecutorService())->buildMethodArguments(new ReflectionMethod($this, 'toolWithForeignClassParameter'), ['flags' => 2]);
    }

    public function testAFrameworkObjectIsStillBuilt(): void
    {
        $params = (new MCPToolExecutorService())->buildMethodArguments(new ReflectionMethod($this, 'toolWithFrameworkObjectParameter'), ['joinSql' => 'x']);
        self::assertInstanceOf(AgentApplicationSqlFilter::class, $params[0]);
    }
}
