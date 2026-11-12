<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationSearchGroup;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * OVERALL search result: the matched conversations, each with its title + date + best
 * matched messages, best-group first.
 */
class AIConversationSearchGetResponseDto extends RestResponseDto
{
    /** @var AIConversationSearchGroup[] Matched conversations (conversationId, title, dateTime, relevance, hits[]). */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $conversations = [];
}
