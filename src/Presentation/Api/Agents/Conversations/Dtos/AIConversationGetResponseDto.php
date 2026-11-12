<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionLayerStatus;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AIConversationGetResponseDto extends RestResponseDto
{
    /** @var AIConversation The requested AIConversation */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AIConversation $aiConversation;

    /** @var AgentDecisionLayerStatus|null present only while an ADMIN impersonates the customer (the impersonation panel's resolved line). */
    public ?AgentDecisionLayerStatus $decisionLayer = null;
}