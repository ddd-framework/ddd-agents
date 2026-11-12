<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Evals;

use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * An eval run tried to send to a recipient that is no allow-listed test sink — nothing was sent. The message is the
 * sentence the agent reads as the tool result ("test run: sending to ab***@example.com blocked"), built by
 * {@see \DDD\Domain\AI\Entities\Evals\OutboundRecipients\EvalOutboundRecipientCheck::buildBlockedNotice()}; the same
 * exception is what the issues channel records.
 */
class EvalOutboundRecipientBlockedException extends BadRequestException
{
}
