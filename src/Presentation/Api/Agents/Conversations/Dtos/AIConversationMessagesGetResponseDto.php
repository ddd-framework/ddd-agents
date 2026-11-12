<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One keyset page of the customer's conversation messages (ordered per the request's `$orderBy`; soft-deleted/truncated
 * rows excluded by the repo). `hasMoreOlder` tells the frontend whether to keep the scroll-up lazy-load armed.
 */
class AIConversationMessagesGetResponseDto extends RestResponseDto
{
    /** @var AIConversationMessages The requested page of messages (with the client's $expand applied). */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AIConversationMessages $messages;

    /** @var bool True when this page filled the requested `$top` → an older page may exist (drives lazy-load). */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $hasMoreOlder;
}
