<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Conversations;

use RuntimeException;

/**
 * Thrown by the agent loop's alarm when a tool runs longer than its execution timeout. It arrives INSIDE whatever
 * the tool is doing at that moment, so code that catches every error of a remote call must let this one pass:
 * caught there, the tool would carry on without any limit.
 */
class AgentToolExecutionTimeoutException extends RuntimeException
{
    public function __construct(string $toolName, int $timeoutSeconds)
    {
        parent::__construct(sprintf(
            "tool '%s' exceeded the %ds execution timeout and was aborted — the underlying call (likely a stuck "
            . 'external API) did not return.',
            $toolName,
            $timeoutSeconds
        ));
    }
}
