<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationReadsTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationStartTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationWritesTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentSelectableModelsTrait;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostResponseDto;

/** A consuming application's controller that only mounts the traits. */
class AgentConversationTraitsFixtureController
{
    use AgentConversationReadsTrait, AgentConversationWritesTrait, AgentConversationStartTrait, AgentSelectableModelsTrait;

    public function callHideAgentForensicsUnlessImpersonating(): void
    {
        $this->hideAgentForensicsUnlessImpersonating();
    }

    public function callHidePropertiesOnEveryObjectOfClass(string $moduleClassName, string ...$propertyNames): void
    {
        $this->hidePropertiesOnEveryObjectOfClass($moduleClassName, ...$propertyNames);
    }

    public function callAuthorizeConversationAccess(): void
    {
        $this->authorizeConversationAccess();
    }

    public function callFindAgentSpaceOfStartRequest(AIConversationStartWithMessagePostRequestDto $requestDto): ?AgentSpace
    {
        return $this->findAgentSpaceOfStartRequest($requestDto);
    }

    public function callBuildConversationStartedResponse(
        AIConversationStartWithMessagePostRequestDto $requestDto,
        ?AgentSpace $agentSpace,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationStartWithMessagePostResponseDto {
        return $this->buildConversationStartedResponse($requestDto, $agentSpace, $aiConversationAgentService);
    }
}
