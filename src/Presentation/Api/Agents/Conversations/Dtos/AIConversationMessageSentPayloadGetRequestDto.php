<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Reconstruct, ON DEMAND, the FULL outgoing request payload of ONE assistant message for the in-chat forensics view
 * (admin impersonation only). The stored snapshot keeps only system message + tool schemas; the wire message history
 * is re-inflated here from the conversation's own rows (kept the table O(N), not O(N²)).
 */
class AIConversationMessageSentPayloadGetRequestDto extends RequestDto
{
    /** @var int The id of the AIConversation the message belongs to. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;

    /** @var int The id of the assistant message whose sent payload to reconstruct. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $messageId;
}
