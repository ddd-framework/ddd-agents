<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The reconstructed FULL outgoing request payload of one assistant message (system message + tool schemas from the
 * stored snapshot, plus the re-inflated point-in-time wire message history) for the in-chat forensics dump.
 */
class AIConversationMessageSentPayloadGetResponseDto extends RestResponseDto
{
    /** @var array<string, mixed> The fully-reconstructed outgoing request payload (redacted secrets), as sent. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $payload = [];
}
