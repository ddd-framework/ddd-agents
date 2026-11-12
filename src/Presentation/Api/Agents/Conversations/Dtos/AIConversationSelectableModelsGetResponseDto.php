<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Models\CustomerSelection\AgentCustomerSelectableModels;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The AI models a customer may pick in the agent chat's model picker — customer vocabulary only (display name,
 * one-line description, strength/speed 1–5 levels, credit-consumption multiplier). `name` is what
 * startWithMessage/postMessage accept as `aiModelName`; any other value is rejected fail-closed.
 */
class AIConversationSelectableModelsGetResponseDto extends RestResponseDto
{
    /** @var AgentCustomerSelectableModels The selectable models, in display order. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentCustomerSelectableModels $models;
}
