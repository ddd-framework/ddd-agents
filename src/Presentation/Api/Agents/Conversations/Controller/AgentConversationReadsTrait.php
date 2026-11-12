<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Controller;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionLayerStatus;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentReasoningEffortDecisionsService;
use DDD\Domain\AI\Services\Conversations\SemanticSearchService;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationActivityGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationActivityGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationMessageContextGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationMessageContextGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationMessageSearchGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationMessageSearchGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationMessagesGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationMessagesGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationRunStateGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationRunStateGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationSearchGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationSearchGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsGetResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;

/**
 * The read routes of the agent chat for an application's conversations controller (routes relative to the
 * controller's `#[Route]`): the conversation, its run state (the polling read), its messages page, the activity lines
 * of a turn, the in-conversation search, the context around one message, the search across conversations and the list
 * of the caller's conversations. Every body is a protected `build…Response()` an application calls from its own
 * redeclared route. Error outcomes: an unknown, foreign or hidden id is NotFoundException (HTTP 404, the web client's
 * `notFound`); a refusal of the application's gate ({@see self::authorizeConversationAccess()}) is ForbiddenException
 * (HTTP 403, `forbidden`).
 */
trait AgentConversationReadsTrait
{
    use AgentConversationControllerHooksTrait;

    /**
     * Lists the caller's conversations that are visible to it.
     *
     * @param AIConversationsGetRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @return AIConversationsGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Get('/listAllConversations')]
    #[Summary('AIConversations list')]
    public function listAllConversations(
        AIConversationsGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService
    ): AIConversationsGetResponseDto {
        $this->authorizeConversationAccess();
        return $this->buildAllConversationsListResponse($requestDto, $aiConversationsService);
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildAllConversationsListResponse(
        AIConversationsGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService
    ): AIConversationsGetResponseDto {
        /** @var AIConversations $aiConversationsClassName the repository reads the options of the application's class */
        $aiConversationsClassName = $this->getClassNameTheApplicationUsesFor(AIConversations::class);
        $aiConversationsClassName::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
        $aiConversationsService->throwErrors = true;
        $this->hideAgentForensicsUnlessImpersonating();
        $responseDto = new AIConversationsGetResponseDto();
        $responseDto->aiConversations = $aiConversationsService->findAllVisibleToCustomer();
        return $responseDto;
    }

    /**
     * One keyset page of the caller's own conversation messages for the chat (QueryOptions: `orderBy`, `top`, the keyset
     * filter `indexInConversation lt 'X'`, `expand`). The owning-conversation scope is AND-merged server-side; the read
     * rights confine the page to the caller's account.
     *
     * @param AIConversationMessagesGetRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @param AIConversationMessagesService $aiConversationMessagesService
     * @return AIConversationMessagesGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \JsonException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Get('/{aiConversationId}/messages', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation Messages')]
    public function getAIConversationMessages(
        AIConversationMessagesGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationMessagesService $aiConversationMessagesService
    ): AIConversationMessagesGetResponseDto {
        $this->authorizeConversationAccess();
        return $this->buildConversationMessagesPageResponse($requestDto, $aiConversationsService, $aiConversationMessagesService);
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \JsonException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildConversationMessagesPageResponse(
        AIConversationMessagesGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationMessagesService $aiConversationMessagesService
    ): AIConversationMessagesGetResponseDto {
        $aiConversationsService->throwErrors = true;
        $aiConversationMessagesService->throwErrors = true;

        // 404 on an unknown id AND a rights check in one call: the customer can only find() its OWN conversation, so a
        // foreign/unknown id 404s here.
        $aiConversation = $this->findVisibleConversation($aiConversationsService, (int)$requestDto->aiConversationId);
        // Recover-on-read, customer path: opening/scrolling the chat loads messages — use that presence signal to nudge a
        // stuck conversation back to life, exactly like the conversation read (best-effort, self-limiting).
        $aiConversationsService->recoverIfStuck($aiConversation);

        // `summary` is a lazy model-generated load — hide it (the expanded delegation children are full
        // AIConversations) so no per-row model call can be triggered.
        $this->hidePropertiesOnEveryObjectOfClass(AIConversation::class, 'summary');
        $this->hideAgentForensicsUnlessImpersonating();

        // getDefaultQueryOptions() is per-class static + worker-lifetime cached → snapshot + restore so this request's
        // filter/top/orderBy never leak into a later message load on the same worker.
        /** @var AIConversationMessages $aiConversationMessagesClassName the repository reads the options of the application's class */
        $aiConversationMessagesClassName = $this->getClassNameTheApplicationUsesFor(AIConversationMessages::class);
        $aiConversationMessagesClassName::setDefaultQueryOptionsSnapshot();
        try {
            $queryOptions = $aiConversationMessagesClassName::getDefaultQueryOptions();
            // $top / $orderBy / $expand + the client's keyset cursor ($filter=indexInConversation lt 'X') from the DTO.
            $queryOptions->setQueryOptionsFromRequestDto($requestDto);

            // AND-connect the MANDATORY owning-conversation scope on top of whatever the client filtered, so a page
            // can never span the customer's OTHER conversations.
            $scopeFilters = FiltersOptions::fromString("aiConversationId eq '{$requestDto->aiConversationId}'");
            $queryOptions->addFiltersConnectedByAnd($scopeFilters);

            $messages = $aiConversationMessagesService->findAll();
            // Apply the client's $expand (the delegation child tree, eager-loaded large).
            $messages->expand();
            $this->hideParentConversationAndLoadedCapabilitiesOnMessageRows($messages);

            $responseDto = new AIConversationMessagesGetResponseDto();
            $responseDto->messages = $messages;
            // A full page (count == the applied $top) means an older page may exist → keep the scroll-up lazy-load armed.
            $appliedTop = $queryOptions->getTop();
            $responseDto->hasMoreOlder = $appliedTop !== null && count($messages->getElements()) >= $appliedTop;
        } finally {
            $aiConversationMessagesClassName::restoreDefaultQueryOptionsSnapshot();
        }
        return $responseDto;
    }

    /**
     * What the agent is doing for the customer right now: the activity lines of one turn, one element per tool call the
     * customer is told about, with a generated sentence per element (cached by element and state).
     *
     * @param AIConversationActivityGetRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @param AIConversationMessagesService $aiConversationMessagesService
     * @param AgentActivityLinesService $agentActivityLinesService
     * @return AIConversationActivityGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \ReflectionException
     */
    #[Get('/{aiConversationId}/activity', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation Activity Lines')]
    public function getAIConversationActivity(
        AIConversationActivityGetRequestDto $requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationMessagesService $aiConversationMessagesService,
        AgentActivityLinesService $agentActivityLinesService
    ): AIConversationActivityGetResponseDto {
        $this->authorizeConversationAccess();
        $aIUsageEnvelopeContext = $this->openConversationEnvelopeIfNoneIsOpen(AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_ACTIVITY);
        try {
            return $this->buildConversationActivityResponse($requestDto, $aiConversationsService, $aiConversationMessagesService, $agentActivityLinesService);
        } finally {
            $this->closeConversationEnvelope($aIUsageEnvelopeContext);
        }
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \ReflectionException
     */
    protected function buildConversationActivityResponse(
        AIConversationActivityGetRequestDto $requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationMessagesService $aiConversationMessagesService,
        AgentActivityLinesService $agentActivityLinesService
    ): AIConversationActivityGetResponseDto {
        $aiConversationsService->throwErrors = true;
        $aiConversationMessagesService->throwErrors = true;
        $conversation = $this->findVisibleConversation($aiConversationsService, (int)$requestDto->aiConversationId);
        $this->hidePropertiesOnEveryObjectOfClass(AIConversation::class, 'summary');
        // the open chat is a reader — the turn narrates its steps for it while this stamp is fresh
        $agentActivityLinesService->markReaderPresent($conversation->getTreeRootConversationId());
        // the rows of the followed turn only — never the whole history
        $conversation->messages = $aiConversationMessagesService->findTurnMessages($conversation, $requestDto->turnMessageId);

        $responseDto = new AIConversationActivityGetResponseDto();
        $responseDto->turnMessageId = $agentActivityLinesService->resolveTurnMessageId($conversation, $requestDto->turnMessageId);
        $responseDto->elements = $agentActivityLinesService->findOrNarrateLinesForTurn($conversation, $responseDto->turnMessageId);
        $responseDto->turnRunning = $agentActivityLinesService->isTurnRunning($conversation, $responseDto->elements);
        return $responseDto;
    }

    /**
     * IN-conversation semantic search: this conversation's messages matching a free-text query (hybrid message +
     * compaction-node recall, reranked).
     *
     * @param AIConversationMessageSearchGetRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @param SemanticSearchService $semanticSearchService
     * @return AIConversationMessageSearchGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \ReflectionException
     */
    #[Get('/{aiConversationId}/messages/search', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation In-Conversation Search')]
    public function searchAIConversationMessages(
        AIConversationMessageSearchGetRequestDto $requestDto,
        AIConversationsService $aiConversationsService,
        SemanticSearchService $semanticSearchService
    ): AIConversationMessageSearchGetResponseDto {
        $this->authorizeConversationAccess();
        $aIUsageEnvelopeContext = $this->openConversationEnvelopeIfNoneIsOpen(self::AGENT_CONVERSATION_SEARCH_ENVELOPE_SCOPE);
        try {
            return $this->buildConversationMessageSearchResponse($requestDto, $aiConversationsService, $semanticSearchService);
        } finally {
            $this->closeConversationEnvelope($aIUsageEnvelopeContext);
        }
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \ReflectionException
     */
    protected function buildConversationMessageSearchResponse(
        AIConversationMessageSearchGetRequestDto $requestDto,
        AIConversationsService $aiConversationsService,
        SemanticSearchService $semanticSearchService
    ): AIConversationMessageSearchGetResponseDto {
        $aiConversationsService->throwErrors = true;
        $conversation = $this->findVisibleConversation($aiConversationsService, (int)$requestDto->aiConversationId);
        $this->hidePropertiesOnEveryObjectOfClass(AIConversation::class, 'summary');
        $responseDto = new AIConversationMessageSearchGetResponseDto();
        $responseDto->hits = $semanticSearchService->searchWithinConversation(
            $conversation,
            $requestDto->query,
            $requestDto->limit ?? 10,
            $requestDto->excerptChars ?? SemanticSearchService::DEFAULT_EXCERPT_CHARS
        );
        return $responseDto;
    }

    /**
     * The conversational context AROUND one message: the anchor + the dialogue before/after it, within three token
     * budgets. Rights-gated (own messages only); no model call.
     *
     * @param AIConversationMessageContextGetRequestDto $requestDto
     * @param AIConversationMessagesService $aiConversationMessagesService
     * @return AIConversationMessageContextGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     */
    #[Get('/messages/{messageId}/context', requirements: ['messageId' => '\d+'])]
    #[Summary('AIConversation Message Context Window')]
    public function getAIConversationMessageContext(
        AIConversationMessageContextGetRequestDto $requestDto,
        AIConversationMessagesService $aiConversationMessagesService
    ): AIConversationMessageContextGetResponseDto {
        $this->authorizeConversationAccess();
        return $this->buildConversationMessageContextResponse($requestDto, $aiConversationMessagesService);
    }

    /** @throws \DDD\Infrastructure\Exceptions\NotFoundException */
    protected function buildConversationMessageContextResponse(
        AIConversationMessageContextGetRequestDto $requestDto,
        AIConversationMessagesService $aiConversationMessagesService
    ): AIConversationMessageContextGetResponseDto {
        $window = $aiConversationMessagesService->loadMessageContext(
            $requestDto->messageId,
            $requestDto->capContentTokens ?? AIConversationMessagesService::MESSAGE_CONTEXT_DEFAULT_CAP_TOKENS,
            $requestDto->capLeftTokens,
            $requestDto->capRightTokens
        );
        if ($window === null) {
            throw new NotFoundException("Message {$requestDto->messageId} not found.");
        }
        $responseDto = new AIConversationMessageContextGetResponseDto();
        $responseDto->window = $window;
        return $responseDto;
    }

    /**
     * Search across the conversations of the authenticated account, results grouped by conversation.
     *
     * @param AIConversationSearchGetRequestDto $requestDto
     * @param SemanticSearchService $semanticSearchService
     * @return AIConversationSearchGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\ForbiddenException when no account is authenticated
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \ReflectionException
     */
    #[Get('/search')]
    #[Summary('AIConversations Overall Search')]
    public function searchAIConversations(
        AIConversationSearchGetRequestDto $requestDto,
        SemanticSearchService $semanticSearchService
    ): AIConversationSearchGetResponseDto {
        $this->authorizeConversationAccess();
        $aIUsageEnvelopeContext = $this->openConversationEnvelopeIfNoneIsOpen(self::AGENT_CONVERSATION_SEARCH_ENVELOPE_SCOPE);
        try {
            return $this->buildConversationsSearchResponse($requestDto, $semanticSearchService);
        } finally {
            $this->closeConversationEnvelope($aIUsageEnvelopeContext);
        }
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\ForbiddenException when no account is authenticated
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \ReflectionException
     */
    protected function buildConversationsSearchResponse(
        AIConversationSearchGetRequestDto $requestDto,
        SemanticSearchService $semanticSearchService
    ): AIConversationSearchGetResponseDto {
        $authAccount = AuthService::instance()->getAccount();
        $responseDto = new AIConversationSearchGetResponseDto();
        if (!$authAccount instanceof Account || !isset($authAccount->id)) {
            throw new ForbiddenException('No authenticated account');
        }
        $responseDto->conversations = $semanticSearchService->searchAcrossConversationsGrouped(
            $authAccount,
            $requestDto->query,
            $requestDto->limit ?? 10,
            $requestDto->excerptChars ?? SemanticSearchService::DEFAULT_EXCERPT_CHARS
        );
        return $responseDto;
    }

    /**
     * The conversation without its messages page (the metadata read): recover-on-read, the progress steps of a purposed
     * conversation, and the resolved decision-layer line in an admin session.
     *
     * @param AIConversationGetRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @param AgentReasoningEffortDecisionsService $agentReasoningEffortDecisionsService
     * @return AIConversationGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Get('/{aiConversationId}', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation Details')]
    public function getAIConversation(
        AIConversationGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService,
        AgentReasoningEffortDecisionsService $agentReasoningEffortDecisionsService
    ): AIConversationGetResponseDto {
        $this->authorizeConversationAccess();
        return $this->buildConversationResponse($requestDto, $aiConversationsService, $agentReasoningEffortDecisionsService);
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildConversationResponse(
        AIConversationGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService,
        AgentReasoningEffortDecisionsService $agentReasoningEffortDecisionsService
    ): AIConversationGetResponseDto {
        $aiConversationsService->throwErrors = true;
        $this->hideAgentForensicsUnlessImpersonating();
        $responseDto = new AIConversationGetResponseDto();
        /** @var AIConversation $aiConversationClassName the loaded conversation reads the options of the application's class */
        $aiConversationClassName = $this->getClassNameTheApplicationUsesFor(AIConversation::class);
        $aiConversationClassName::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
        $aiConversation = $this->findVisibleConversation($aiConversationsService, (int)$requestDto->aiConversationId);
        // Recover-on-read, customer path: a client polls this read for runState, so the poll IS the presence signal —
        // a conversation that looks stuck is nudged back to life by the read itself (best-effort, self-limiting).
        $aiConversationsService->recoverIfStuck($aiConversation);
        $aiConversation->expand();
        // The customer-facing stepper: the derived progress view of a purposed conversation's task plan — the
        // customer's ONLY view of the plan. Empty set = the frontend renders no stepper.
        $aiConversation->customerProgressSteps = $aiConversation->buildCustomerProgressSteps();
        $this->hideParentConversationAndLoadedCapabilitiesOnEmbeddedMessages($aiConversation);
        $responseDto->aiConversation = $aiConversation;
        if ($this->isAdminSession()) {
            // the admin session shows the same resolved line as the admin console — admins only.
            $responseDto->decisionLayer = AgentDecisionLayerStatus::forConversation($aiConversation, null, $agentReasoningEffortDecisionsService->buildReasoningEffortLimitsForConversation($aiConversation));
        }
        return $responseDto;
    }

    /**
     * The run state of one conversation — the read a polling client repeats (2 s while a turn runs): run state, owed-turn
     * mark, newest stored message id, title and summary; no messages, no expand, no lazy generation. Recover-on-read like
     * the conversation read (the poll is the presence signal).
     *
     * @param AIConversationRunStateGetRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @param AIConversationMessagesService $aiConversationMessagesService
     * @return AIConversationRunStateGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Get('/{aiConversationId}/runState', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation Run State')]
    public function getAIConversationRunState(
        AIConversationRunStateGetRequestDto $requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationMessagesService $aiConversationMessagesService
    ): AIConversationRunStateGetResponseDto {
        $this->authorizeConversationAccess();
        return $this->buildConversationRunStateResponse($requestDto, $aiConversationsService, $aiConversationMessagesService);
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildConversationRunStateResponse(
        AIConversationRunStateGetRequestDto $requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationMessagesService $aiConversationMessagesService
    ): AIConversationRunStateGetResponseDto {
        $aiConversationsService->throwErrors = true;
        $aiConversation = $this->findVisibleConversation($aiConversationsService, (int)$requestDto->aiConversationId);
        $aiConversationsService->recoverIfStuck($aiConversation);
        $aiConversation->lastMessageId = $aiConversationMessagesService->findHighestMessageId($aiConversation);
        return AIConversationRunStateGetResponseDto::fromConversation($aiConversation);
    }
}
