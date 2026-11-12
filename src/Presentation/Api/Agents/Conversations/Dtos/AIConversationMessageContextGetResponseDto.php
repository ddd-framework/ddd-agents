<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Recall\MessageContextWindow;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The surrounding-dialogue window for one recalled message — the anchor + the USER/ASSISTANT turns before/after it,
 * each line carrying its `messageId` (re-centre handle). {@see MessageContextWindow}.
 */
class AIConversationMessageContextGetResponseDto extends RestResponseDto
{
    /** @var MessageContextWindow The assembled context window (anchor + before/after entries). */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public MessageContextWindow $window;
}
