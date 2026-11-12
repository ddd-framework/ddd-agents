<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;

/**
 * Request for the agent chat's customer model picker list. No parameters — the list is account-independent
 * (model availability is regulated by budget consumption, not per-plan gating; see
 * `config/app/AI/models.php` → `agentCustomerSelection`).
 */
class AIConversationSelectableModelsGetRequestDto extends RequestDto
{
}
