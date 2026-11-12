<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Recall\SemanticSearchHit;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * IN-conversation search result: the matched messages of one conversation, best-first.
 */
class AIConversationMessageSearchGetResponseDto extends RestResponseDto
{
    /** @var SemanticSearchHit[] The matched messages (messageId, indexInConversation, role, dateTime, excerpt, relevance). */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $hits = [];
}
