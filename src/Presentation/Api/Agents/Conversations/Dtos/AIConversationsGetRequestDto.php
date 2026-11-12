<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Presentation\Api\Agents\QueryOptions\DtoQueryOptionsOfApplicationClassTrait;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;

#[DtoQueryOptions(baseEntity: AIConversations::class)]
class AIConversationsGetRequestDto extends RequestDto
{
    use DtoQueryOptionsOfApplicationClassTrait;
}