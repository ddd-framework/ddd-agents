<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Controller;

use DDD\Domain\AI\Entities\Models\CustomerSelection\AgentCustomerSelectableModels;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationSelectableModelsGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationSelectableModelsGetResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;

/**
 * The model picker list of the agent chat: the customer-selectable models of the model catalog (the
 * `agentCustomerSelection` blocks), the whitelist a start or a post validates a sent `aiModelName` against.
 */
trait AgentSelectableModelsTrait
{
    use AgentConversationControllerHooksTrait;

    /**
     * The AI models a customer may pick in the agent chat's model picker.
     *
     * @param AIConversationSelectableModelsGetRequestDto $requestDto
     * @param AIConversationModelCallsService $aIConversationModelCallsService
     * @return AIConversationSelectableModelsGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     */
    #[Get('/selectable-models')]
    #[Summary('AIConversation Selectable Agent Models')]
    public function getSelectableAgentModels(
        AIConversationSelectableModelsGetRequestDto $requestDto,
        AIConversationModelCallsService $aIConversationModelCallsService
    ): AIConversationSelectableModelsGetResponseDto {
        $this->authorizeConversationAccess();
        $responseDto = new AIConversationSelectableModelsGetResponseDto();
        $responseDto->models = $this->findSelectableAgentModels($aIConversationModelCallsService);
        return $responseDto;
    }

    /**
     * The selectable models (an application's same-path subclass of the set when it has one).
     *
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     */
    protected function findSelectableAgentModels(AIConversationModelCallsService $aIConversationModelCallsService): AgentCustomerSelectableModels
    {
        return $aIConversationModelCallsService->getCustomerSelectableAgentModels();
    }
}
