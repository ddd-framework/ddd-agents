<?php

declare(strict_types=1);

namespace AgentsReference\Presentation\Api\Conversations\Controller;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationReadsTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationStartTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationWritesTrait;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostResponseDto;
use DDD\Presentation\Base\Controller\HttpController;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\Router\Routes\Post;
use DDD\Presentation\Base\Router\Routes\Route;

/**
 * The reference application's chat surface: the package's read and write routes as they are, plus the one route the
 * package leaves to an application — the start, whose path would name what the application's agent works for. This
 * host has no such thing (no agent space): every conversation works for the demo owner's account as a whole, which
 * the package allows by default (`AgentSpacesService::requireConversationStartWithoutAgentSpaceAllowed()`). Every hook
 * keeps the package default: no access gate beyond the read rights, no admin session, the device from the user agent.
 */
#[Route('/conversations')]
#[Tag(group: 'Agents', name: 'AIConversations', description: 'The agent conversations of the demo owner')]
class ReferenceConversationsController extends HttpController
{
    use AgentConversationReadsTrait, AgentConversationWritesTrait, AgentConversationStartTrait;

    /**
     * Starts a conversation of the demo owner (without an agent space, at account level) with its first message and
     * drives the first agent turn. A space the request names is ignored: this host has none.
     *
     * @param AIConversationStartWithMessagePostRequestDto $requestDto
     * @param AIConversationAgentService $aiConversationAgentService
     * @return AIConversationStartWithMessagePostResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Post('/start')]
    #[Summary('AIConversation Start')]
    public function startConversation(
        AIConversationStartWithMessagePostRequestDto $requestDto,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationStartWithMessagePostResponseDto {
        $this->authorizeConversationAccess();
        $aIUsageEnvelopeContext = $this->openConversationEnvelopeIfNoneIsOpen(AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN);
        try {
            return $this->buildConversationStartedResponse($requestDto, null, $aiConversationAgentService);
        } finally {
            $this->closeConversationEnvelope($aIUsageEnvelopeContext);
        }
    }
}
