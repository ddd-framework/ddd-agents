<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One read of the live state of the supervised conversation's space, as a supervisor checkpoint renders it: the read
 * tool and the MCP packages that serve it ({@see SupervisorPolicy::getAgentSpaceStateReads()}).
 *
 * @phpstan-consistent-constructor
 */
class SupervisorAgentSpaceStateRead extends ValueObject
{
    /** The read tool's name (also the heading of its block in the rendered state). */
    public string $toolName = '';

    /** @var string[] The class names of the MCP packages the tool is executed through. */
    public array $packageClassNames = [];

    /** @param string[] $packageClassNames */
    public static function forTool(string $toolName, array $packageClassNames): static
    {
        $supervisorAgentSpaceStateRead = new static();
        $supervisorAgentSpaceStateRead->toolName = $toolName;
        $supervisorAgentSpaceStateRead->packageClassNames = $packageClassNames;
        return $supervisorAgentSpaceStateRead;
    }
}
