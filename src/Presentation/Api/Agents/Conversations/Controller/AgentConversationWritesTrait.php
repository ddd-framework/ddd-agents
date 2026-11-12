<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Controller;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Exceptions\Conversations\ConversationClosedException;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationDeleteResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationInjectSignalPostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationPostMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationRetryPostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationTitleUpdateRequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Delete;
use DDD\Presentation\Base\Router\Routes\Patch;
use DDD\Presentation\Base\Router\Routes\Post;

/**
 * The write routes of the agent chat for an application's conversations controller (routes relative to the
 * controller's `#[Route]`): post a message, inject a widget signal, retry an unanswered turn, rename, delete. Turns run
 * asynchronously: a write answers with the conversation and its appended row; the reply arrives through the change
 * signal or the polling read. Every body is a protected `build…Response()` an application calls from its own
 * redeclared route (to add a quota or its own envelope attribute). A closed conversation refuses further input
 * (ConversationClosedException).
 */
trait AgentConversationWritesTrait
{
    use AgentConversationControllerHooksTrait;

    /**
     * Post a message within an existing conversation and drive ONE agentic turn (asynchronously).
     *
     * @param AIConversationPostMessagePostRequestDto $requestDto
     * @param AIConversationAgentService $aiConversationAgentService
     * @return AIConversationGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Post('/{aiConversationId}/postMessage', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation Post Message')]
    public function postMessage(
        AIConversationPostMessagePostRequestDto &$requestDto,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationGetResponseDto {
        $this->authorizeConversationAccess();
        $aIUsageEnvelopeContext = $this->openConversationEnvelopeIfNoneIsOpen(AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN);
        try {
            return $this->buildMessagePostedResponse($requestDto, $aiConversationAgentService);
        } finally {
            $this->closeConversationEnvelope($aIUsageEnvelopeContext);
        }
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildMessagePostedResponse(
        AIConversationPostMessagePostRequestDto &$requestDto,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationGetResponseDto {
        $this->hideAgentForensicsUnlessImpersonating();
        $responseDto = new AIConversationGetResponseDto();
        $responseDto->aiConversation = $aiConversationAgentService->driveConversation(
            AIConversationDriveScope::CUSTOMER,
            $requestDto->aiConversationId,
            null,
            $requestDto->aiConversationMessage->message ?? '',
            $requestDto->aiModelName ?? null,
            $requestDto->budgetBypass ?? null,
            runSettingsOverride: $this->buildRunSettingsOverride($requestDto), // honoured only in an admin session
            ownerDevice: $this->readOwnerDeviceFromRequest()
        );
        $this->afterMessagePosted($responseDto->aiConversation);
        $this->hideParentConversationAndLoadedCapabilitiesOnEmbeddedMessages($responseDto->aiConversation);
        return $responseDto;
    }

    /**
     * Inject a frontend-widget SIGNAL (a human-readable description of a widget interaction) into an existing
     * conversation and drive ONE agentic turn (asynchronously). The conversation is resolved under read rights.
     *
     * @param AIConversationInjectSignalPostRequestDto $requestDto
     * @param AIConversationTurnSignalsService $aIConversationTurnSignalsService
     * @param AIConversationsService $aiConversationsService
     * @return AIConversationGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Post('/{aiConversationId}/injectSignal', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation Inject Signal')]
    public function injectSignal(
        AIConversationInjectSignalPostRequestDto $requestDto,
        AIConversationTurnSignalsService $aIConversationTurnSignalsService,
        AIConversationsService $aiConversationsService
    ): AIConversationGetResponseDto {
        $this->authorizeConversationAccess();
        $aIUsageEnvelopeContext = $this->openConversationEnvelopeIfNoneIsOpen(AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN);
        try {
            return $this->buildSignalInjectedResponse($requestDto, $aIConversationTurnSignalsService, $aiConversationsService);
        } finally {
            $this->closeConversationEnvelope($aIUsageEnvelopeContext);
        }
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildSignalInjectedResponse(
        AIConversationInjectSignalPostRequestDto $requestDto,
        AIConversationTurnSignalsService $aIConversationTurnSignalsService,
        AIConversationsService $aiConversationsService
    ): AIConversationGetResponseDto {
        $this->hideAgentForensicsUnlessImpersonating();
        // read rights APPLIED → resolves only the caller's OWN conversation; with throwErrors a foreign / missing id
        // throws NotFoundException → 404.
        $aiConversationsService->throwErrors = true;
        $conversation = $this->findVisibleConversation($aiConversationsService, (int)$requestDto->aiConversationId);
        // Closed conversation: no further customer input — widget signals included.
        if ($conversation instanceof AIConversation && $conversation->isClosed()) {
            throw new ConversationClosedException();
        }
        $signalSource = $requestDto->signalSource ?? AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK;
        $this->assertSignalSourceIsPostedByTheClient($signalSource);
        $aIConversationTurnSignalsService->injectSignal(
            $conversation,
            $requestDto->signalText,
            $signalSource,
            ownerDevice: $this->readOwnerDeviceFromRequest()
        );
        $this->hideParentConversationAndLoadedCapabilitiesOnEmbeddedMessages($conversation);
        $responseDto = new AIConversationGetResponseDto();
        $responseDto->aiConversation = $conversation;
        return $responseDto;
    }

    /**
     * Retry a run that owes an answer: re-drives the persisted, still-unanswered customer message (a FAILED run, or an
     * IDLE run whose turn errored under the retry cap). A run with nothing left to retry is rejected with 400.
     *
     * @param AIConversationRetryPostRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @return AIConversationGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Post('/{aiConversationId}/retry', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation Retry Run')]
    public function retryFailedRun(
        AIConversationRetryPostRequestDto $requestDto,
        AIConversationsService $aiConversationsService
    ): AIConversationGetResponseDto {
        $this->authorizeConversationAccess();
        return $this->buildRunRetriedResponse($requestDto, $aiConversationsService);
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildRunRetriedResponse(
        AIConversationRetryPostRequestDto $requestDto,
        AIConversationsService $aiConversationsService
    ): AIConversationGetResponseDto {
        $this->hideAgentForensicsUnlessImpersonating();
        $aiConversationsService->throwErrors = true;
        $conversation = $this->findVisibleConversation($aiConversationsService, (int)$requestDto->aiConversationId);
        if ($conversation instanceof AIConversation && $conversation->isClosed()) {
            throw new ConversationClosedException();
        }
        if (!$aiConversationsService->retryUnansweredTurn($conversation)) {
            throw new BadRequestException('Nothing to retry: the conversation has no unanswered customer message, or its run cannot be re-armed right now.');
        }
        // The retry loaded the derive tail onto the entity — the response is the lean conversation (the client reads
        // messages through the keyset read), never that window.
        $conversation->addPropertiesToHide('messages', 'compactionNodes');
        $responseDto = new AIConversationGetResponseDto();
        $responseDto->aiConversation = $conversation;
        return $responseDto;
    }

    /**
     * Rename a conversation's display title; latches `manuallyEdited` so a generated title never overwrites it again.
     *
     * @param AIConversationTitleUpdateRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @return AIConversationGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \ReflectionException
     */
    #[Patch('/{aiConversationId}/title', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation Title Update')]
    public function patchTitle(
        AIConversationTitleUpdateRequestDto $requestDto,
        AIConversationsService $aiConversationsService
    ): AIConversationGetResponseDto {
        $this->authorizeConversationAccess();
        return $this->buildTitleUpdatedResponse($requestDto, $aiConversationsService);
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \ReflectionException
     */
    protected function buildTitleUpdatedResponse(
        AIConversationTitleUpdateRequestDto $requestDto,
        AIConversationsService $aiConversationsService
    ): AIConversationGetResponseDto {
        $aiConversationsService->throwErrors = true;
        $this->hideAgentForensicsUnlessImpersonating();
        $aiConversation = $this->findVisibleConversation($aiConversationsService, (int)$requestDto->aiConversationId);
        $responseDto = new AIConversationGetResponseDto();
        $responseDto->aiConversation = $aiConversationsService->editTitle($aiConversation, $requestDto->title);
        return $responseDto;
    }

    /**
     * Delete a conversation of the caller.
     *
     * @param AIConversationGetRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @return AIConversationDeleteResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Doctrine\ORM\Exception\ORMException
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Delete('/{aiConversationId}', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation Deletion')]
    public function delete(
        AIConversationGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService
    ): AIConversationDeleteResponseDto {
        $this->authorizeConversationAccess();
        $this->deleteVisibleConversation($requestDto, $aiConversationsService);
        return new AIConversationDeleteResponseDto();
    }

    /**
     * Deletes the caller's conversation `aiConversationId` (an unknown or foreign id 404s).
     *
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Doctrine\ORM\Exception\ORMException
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function deleteVisibleConversation(
        AIConversationGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService
    ): void {
        $aiConversationsService->throwErrors = true;
        $aiConversation = $this->findVisibleConversation($aiConversationsService, (int)$requestDto->aiConversationId);
        $aiConversationsService->delete($aiConversation);
    }
}
