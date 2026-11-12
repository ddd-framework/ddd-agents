<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Conversations;

use RuntimeException;

/**
 * A step that needs the owner's explicit consent has none this call may use — the tool wrote nothing. The
 * message is the model-facing text of the reservation ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation::buildModelFacingText()});
 * the MCP executor answers it as a gate refusal (`REFUSAL_OWNER_CONFIRMATION_REQUIRED`), never as a failed execution.
 */
class OwnerConsentMissingException extends RuntimeException
{
}
