<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Connections;

use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * The provider refused the refresh of the grant (revoked, password changed): the connection is EXPIRED and its account must connect again.
 */
class AgentConnectionExpiredException extends BadRequestException
{
}
