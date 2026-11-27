<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Connections;

use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * A step through a connection that a guard refused: the level, the stage guard, a limit, a connection of another stage, a paused connection. The message is model-facing: it says what is missing and what to do; it names no secret.
 */
class AgentConnectionRefusedException extends BadRequestException
{
}
