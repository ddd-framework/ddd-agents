<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Conversations;

use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * The conversation is completed and no longer accepts customer input.
 *
 * Carries the STANDARDIZED wire code {@see self::ERROR_CODE}: the public $errorCode property serializes into the
 * JSON error body, so every consumer keys on the stable CODE instead of matching the human-readable message string.
 */
class ConversationClosedException extends BadRequestException
{
    /** @var string The stable wire code for a completed conversation that no longer accepts customer input. */
    public const string ERROR_CODE = 'CONVERSATION_CLOSED';

    /** @var string Machine-readable error code, serialized into the JSON error response alongside `error`. */
    public string $errorCode = self::ERROR_CODE;

    public function __construct(
        string $message = 'This conversation is completed and closed. Please start a new conversation.'
    ) {
        parent::__construct($message);
    }
}
