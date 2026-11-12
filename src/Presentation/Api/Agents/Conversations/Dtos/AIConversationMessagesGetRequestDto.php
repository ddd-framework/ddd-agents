<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Presentation\Api\Agents\QueryOptions\DtoQueryOptionsOfApplicationClassTrait;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;

/**
 * One KEYSET page of the customer's OWN conversation messages for the chat UI. The client
 * paginates purely via QueryOptions: `$orderBy=indexInConversation desc` + `$top=50` for the newest page, then
 * `$filter=indexInConversation lt '<lowestLoadedIndex>'` to load older (keyset cursor — stable under new messages
 * appended at the newest end). Long delegation children load eagerly large via
 * `$expand=delegatedConversation(expand=messages(top=200))`.
 *
 * The mandatory conversation scope is added SERVER-SIDE (a guaranteed top-level AND) on top of the client's filter;
 * the entity rights layer additionally confines the customer to its own account — no rights-bypass here.
 */
#[DtoQueryOptions(baseEntity: AIConversationMessages::class)]
class AIConversationMessagesGetRequestDto extends RequestDto
{
    use DtoQueryOptionsOfApplicationClassTrait;

    /** @var int The id of the AIConversation whose messages page is requested. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;
}
