<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Controller;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostResponseDto;

/**
 * The start of a conversation with a first message, for an application's conversations controller. It declares NO
 * route: how a start names the space it works for is the application's own (a field of the request, the id of its
 * own entity in the path, one fixed space per account, or none at all), so the application declares the route and
 * hands over the space:
 *
 *     return $this->buildConversationStartedResponse($requestDto, $this->findAgentSpaceOfStartRequest($requestDto), $aiConversationAgentService);
 *
 * Drives ONE agentic turn asynchronously; with an entry-point key a recent conversation of that entry point in the
 * same space (or at account level) is reopened instead (nothing appended, no turn).
 */
trait AgentConversationStartTrait
{
    use AgentConversationControllerHooksTrait;

    /**
     * Starts a conversation of the caller with the request's message and an optional invisible start signal, or
     * reopens the recent conversation of the request's entry point.
     *
     * WITH A SPACE the conversation works for `$agentSpace`; WITHOUT one (null) it starts at account level, which is
     * a regular case. This method refuses neither: before anything is stored the application's access gate runs
     * ({@see self::authorizeConversationAccess()}), and the start itself is checked where the conversation is created
     * ({@see AIConversationsService::findConversationToDrive()}) — a space the account may not use is refused there
     * ({@see AgentSpacesService::requireAgentSpaceUsableByAccount()}, HTTP 403), and whether a start without a space
     * is open is the application's decision
     * ({@see AgentSpacesService::requireConversationStartWithoutAgentSpaceAllowed()}; the default allows it).
     *
     * @param AIConversationStartWithMessagePostRequestDto $requestDto
     * @param AgentSpace|null $agentSpace The space the conversation works for ({@see self::findAgentSpaceOfStartRequest()}); null = account level
     * @param AIConversationAgentService $aiConversationAgentService
     * @return AIConversationStartWithMessagePostResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\ForbiddenException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildConversationStartedResponse(
        AIConversationStartWithMessagePostRequestDto $requestDto,
        ?AgentSpace $agentSpace,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationStartWithMessagePostResponseDto {
        $this->authorizeConversationAccess();
        $this->hideAgentForensicsUnlessImpersonating();
        if (($requestDto->signalSource ?? null) !== null) {
            $this->assertSignalSourceIsPostedByTheClient($requestDto->signalSource);
        }
        $runSettingsOverride = $this->buildRunSettingsOverride($requestDto); // honoured only in an admin session
        $responseDto = new AIConversationStartWithMessagePostResponseDto();
        if (($requestDto->entryPointKey ?? null) !== null) {
            $aIConversationEntryPointStart = $aiConversationAgentService->startOrReopenConversationAtEntryPoint(
                $requestDto->entryPointKey,
                $agentSpace,
                $requestDto->aiConversationMessage->message ?? '',
                $requestDto->aiModelName ?? null,
                $requestDto->budgetBypass ?? null,
                $requestDto->signalText ?? null,
                $requestDto->signalSource ?? null,
                $runSettingsOverride,
                $this->readOwnerDeviceFromRequest()
            );
            $responseDto->aiConversation = $aIConversationEntryPointStart->conversation;
            $responseDto->reopenedExistingConversation = $aIConversationEntryPointStart->reopenedExisting;
            if ($aIConversationEntryPointStart->reopenedExisting) {
                // the lean conversation: the client loads the messages through the keyset messages read
                $responseDto->aiConversation->addPropertiesToHide('messages', 'compactionNodes');
            }
        } else {
            $responseDto->aiConversation = $aiConversationAgentService->driveConversation(
                AIConversationDriveScope::CUSTOMER,
                null,
                $agentSpace,
                $requestDto->aiConversationMessage->message ?? '',
                $requestDto->aiModelName ?? null,
                $requestDto->budgetBypass ?? null,
                startSignalText: $requestDto->signalText ?? null,
                startSignalSource: $requestDto->signalSource ?? null,
                runSettingsOverride: $runSettingsOverride,
                ownerDevice: $this->readOwnerDeviceFromRequest()
            );
        }
        $this->hideParentConversationAndLoadedCapabilitiesOnEmbeddedMessages($responseDto->aiConversation);
        return $responseDto;
    }
}
