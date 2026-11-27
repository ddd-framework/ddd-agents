<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Connections;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * The provider answered with an error (network, 5xx, an unexpected answer). Counted against the connection; repeated errors pause it.
 */
class AgentConnectionProviderException extends InternalErrorException
{
}
