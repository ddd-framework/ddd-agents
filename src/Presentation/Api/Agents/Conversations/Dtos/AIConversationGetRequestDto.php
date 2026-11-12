<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Presentation\Api\Agents\QueryOptions\DtoQueryOptionsOfApplicationClassTrait;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;

#[DtoQueryOptions(baseEntity: AIConversation::class)]
class AIConversationGetRequestDto extends RequestDto
{
    use DtoQueryOptionsOfApplicationClassTrait;

    /** @var int The id of the AIConversation. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;
}