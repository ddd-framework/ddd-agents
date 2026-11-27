<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Connections;

use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * An outward step waits for a person: an approval request was created (and posted where the host names the approval channel). The message tells the model to stop and wait; the run continues when a person answers.
 */
class AgentConnectionApprovalPendingException extends BadRequestException
{
}
