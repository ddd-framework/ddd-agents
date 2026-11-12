<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Controller;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\SystemPrompts\AgentBaseSystemPromptCacheService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationPostMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;

/**
 * The override points of the agent conversation controller traits ({@see AgentConversationReadsTrait},
 * {@see AgentConversationWritesTrait}, {@see AgentConversationStartTrait}, {@see AgentSelectableModelsTrait}) and the
 * helpers they share. Every hook is a protected method with a working default; an application's controller overrides
 * the ones its policy differs in (its access gate, its admin session, its device header).
 *
 * The routed methods of the traits are a complete chat surface for an application that only `use`s them; an
 * application that needs extra attributes on a route (a quota, its own envelope scope) or its own route order redeclares
 * the routed method and calls the trait's protected body (`build…Response()`).
 */
trait AgentConversationControllerHooksTrait
{
    /** @var string The usage-envelope scope of the conversation searches (a routed trait method opens it when none is open). */
    public const string AGENT_CONVERSATION_SEARCH_ENVELOPE_SCOPE = 'ENVELOPE.AI.CONVERSATION.SEARCH';

    /**
     * The application's access gate of the chat surface (a product flag, a user right), called first by every routed
     * trait method. Throws ForbiddenException (HTTP 403, the web client's `forbidden`) to refuse. The default: open to
     * every authenticated caller — the read rights still confine every conversation to its account (an unknown or
     * foreign id is HTTP 404, the web client's `notFound`).
     */
    protected function authorizeConversationAccess(): void
    {
    }

    /**
     * The space a start request asks to work for, for the application's routed start method to hand to
     * {@see AgentConversationStartTrait::buildConversationStartedResponse()}. Null = the request names none: the
     * conversation starts at account level.
     *
     * The default reads the request's `agentSpaceId` under the caller's read rights
     * ({@see AgentSpacesService::find()}). An id the caller cannot read is refused here (ForbiddenException, HTTP
     * 403) and never becomes an account-level start. The id comes from the request, so reading it proves only that
     * the caller may see the space: whether the account may START a conversation with it is decided where the
     * conversation is created ({@see AIConversationsService::findConversationToDrive()}).
     *
     * An application that names the space by a field of its own request (the id of its own entity in the path), or
     * that has one fixed space per account, overrides this method.
     *
     * @param AIConversationStartWithMessagePostRequestDto $requestDto
     * @return AgentSpace|null null = the request names no space
     * @throws ForbiddenException when the request names a space the caller cannot read
     */
    protected function findAgentSpaceOfStartRequest(AIConversationStartWithMessagePostRequestDto $requestDto): ?AgentSpace
    {
        $requestedAgentSpaceId = $requestDto->agentSpaceId ?? null;
        if ($requestedAgentSpaceId === null) {
            return null;
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        try {
            $agentSpace = $agentSpacesService->find($requestedAgentSpaceId);
        } catch (NotFoundException) {
            // the shared service throws instead of answering null when a caller switched its `throwErrors` on: the
            // refusal is the same either way (an unknown id and one of another account are not told apart)
            $agentSpace = null;
        }
        if (!$agentSpace instanceof AgentSpace) {
            // the text of the resolved service: an application words the refusal its own way in its subclass
            throw new ForbiddenException($agentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        }
        return $agentSpace;
    }

    /** Whether a validated admin acts in this session (impersonation): the forensic fields stay on the wire then. */
    protected function isAdminSession(): bool
    {
        return AuthService::instance()->getAdminAccountOrNull() !== null;
    }

    /**
     * The conversation `$aiConversationId` under the caller's read rights and customer visibility; throws
     * NotFoundException for an unknown, foreign or hidden id when the service throws errors.
     *
     * @throws NotFoundException
     */
    protected function findVisibleConversation(AIConversationsService $aIConversationsService, int $aiConversationId): AIConversation
    {
        return $aIConversationsService->findVisibleToCustomer($aiConversationId);
    }

    /**
     * Hide the agent-forensic fields (the resolved prompt, model, tokens, cost, tool-call detail, delegation tree) from
     * the customer wire UNLESS a validated admin acts in this session ({@see self::isAdminSession()}, server-side —
     * NEVER a client flag). The raw task plan stays hidden too; customers get only the derived progress steps.
     */
    protected function hideAgentForensicsUnlessImpersonating(): void
    {
        if ($this->isAdminSession()) {
            return; // admin session → expose the full agentic trace (like the admin console)
        }
        $this->hidePropertiesOnEveryObjectOfClass(
            AIConversationMessage::class,
            'requestSnapshot',
            'toolCall',
            'aiModelName',
            'inputTokens',
            'outputTokens',
            'webSearchCount',
            'costInUSD',
            'delegatedConversations',
        );
        // agentWorkingState carries the raw task plan — English agent instructions; customers get only the
        // derived customerProgressSteps stepper view.
        $this->hidePropertiesOnEveryObjectOfClass(AIConversation::class, 'budgetBypass', 'runToken', 'agentWorkingState');
    }

    /**
     * The class the application uses in the place of a class of the module: its class of the same path under its own
     * root namespace (or the one its container maps), else the module's class itself.
     *
     * What the framework keeps per class — the default query options, the class-wide property hides — is read for
     * the class of the object at hand, and that is the application's class. Options set on the module's class would
     * never reach the query (the list would come back unfiltered and uncut); a hide registered there would leave the
     * property on the wire.
     *
     * @param class-string $moduleClassName
     * @return class-string
     */
    protected function getClassNameTheApplicationUsesFor(string $moduleClassName): string
    {
        return DDDService::instance()->getContainerServiceClassNameForClass($moduleClassName) ?? $moduleClassName;
    }

    /**
     * Hides `$propertyNames` on every serialized object of the module class `$moduleClassName` for the rest of the
     * request, and on every object of the class the application uses in its place
     * ({@see self::getClassNameTheApplicationUsesFor()}).
     *
     * A class-wide hide is checked against the class of the serialized object only, never against its parents: one
     * registered on the module's class alone leaves the property visible on the application's objects.
     *
     * @param class-string $moduleClassName
     */
    protected function hidePropertiesOnEveryObjectOfClass(string $moduleClassName, string ...$propertyNames): void
    {
        $moduleClassName::addStaticPropertiesToHide(true, ...$propertyNames);
        $applicationClassName = $this->getClassNameTheApplicationUsesFor($moduleClassName);
        if ($applicationClassName !== $moduleClassName) {
            $applicationClassName::addStaticPropertiesToHide(true, ...$propertyNames);
        }
    }

    /**
     * For every caller, admins included. A row that carries its conversation (bound by the read that loaded it, or
     * expanded) would serialize it once per row (~300 KB each). loadedCapabilities (~17 KB per row) is rendered only by
     * the admin inspector.
     */
    protected function hideParentConversationAndLoadedCapabilitiesOnMessageRows(AIConversationMessages $messages): void
    {
        $messages->hidePropertiesOnEveryMessage('aiConversation', 'loadedCapabilities');
    }

    /**
     * The conversation responses carry every message the action loaded (the whole history after a drive), so the same
     * per-row weight applies. isset() leaves an unloaded lazy `messages` unloaded.
     */
    protected function hideParentConversationAndLoadedCapabilitiesOnEmbeddedMessages(AIConversation $conversation): void
    {
        if (isset($conversation->messages)) {
            $this->hideParentConversationAndLoadedCapabilitiesOnMessageRows($conversation->messages);
        }
    }

    /**
     * The device the owner sends this request from: the client header ({@see OwnerDevice::CLIENT_HEADER_NAME}) first,
     * the user agent as the fallback. Read from the main request (a DDD request DTO cannot bind a dashed header name).
     * Never throws; a request without either yields the UNKNOWN device, which renders no tag.
     */
    protected function readOwnerDeviceFromRequest(): OwnerDevice
    {
        $request = DDDService::instance()->getRequestService()?->getRequestStack()?->getMainRequest();
        return OwnerDevice::fromRequestHints(
            $request?->headers->get(OwnerDevice::CLIENT_HEADER_NAME),
            $request?->headers->get('User-Agent')
        );
    }

    /** The per-conversation admin overrides a post or a start carries (null = none) — honoured only in an admin session (the drive decides). */
    protected function buildRunSettingsOverride(AIConversationPostMessagePostRequestDto|AIConversationStartWithMessagePostRequestDto $requestDto): ?RunSettingsOverride
    {
        return RunSettingsOverride::fromValues($requestDto->decisionModelSkillPreload ?? null, $requestDto->decisionModelTurnClosing ?? null, $requestDto->decisionModelOff ?? null, $requestDto->decisionModelReasoningEffort ?? null, $requestDto->decisionModelStallRecovery ?? null, $requestDto->decisionModelReplyLanguage ?? null, $requestDto->planDynamicsAskDecisionModel ?? null, $requestDto->planDynamicsModelReceivesVerdict ?? null);
    }

    /**
     * After a posted message started its turn. The default: the turn worker reads the base system prompt from the
     * cache; this request builds it after the response was sent when the current key has none (fire-and-forget).
     */
    protected function afterMessagePosted(AIConversation $conversation): void
    {
        /** @var AgentBaseSystemPromptCacheService $agentBaseSystemPromptCacheService */
        $agentBaseSystemPromptCacheService = DDDService::instance()->getService(AgentBaseSystemPromptCacheService::class);
        $agentBaseSystemPromptCacheService->queueBaseSystemPromptWarmAfterResponse($conversation);
    }

    /**
     * A client posts only the sources the frontend sends: a work order, a supervisor turn or a loop corrective posted by
     * a client would get their turn-start and work semantics.
     *
     * @throws BadRequestException
     */
    protected function assertSignalSourceIsPostedByTheClient(string $signalSource): void
    {
        if (ConversationSignalSources::tryFrom($signalSource)?->isPostedByTheClient() !== true) {
            throw new BadRequestException('Unknown or server-only signal source: ' . $signalSource);
        }
    }

    /**
     * Opens a usage envelope of `$scope` for the authenticated account when none is open — a routed trait method's
     * model calls are then attributed and budget-checked in an application without a request subscriber that opens
     * envelopes from an attribute. Null when one is open already (the subscriber's), nothing to close then.
     *
     * @throws InternalErrorException
     */
    protected function openConversationEnvelopeIfNoneIsOpen(string $scope): ?AIUsageEnvelopeContext
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        if ($aIUsageEnvelopesService->hasActiveEnvelope()) {
            return null;
        }
        $authAccount = AuthService::instance()->getAccount();
        return $aIUsageEnvelopesService->openEnvelope(isset($authAccount->id) ? (int)$authAccount->id : null, $scope);
    }

    /** Closes an envelope {@see self::openConversationEnvelopeIfNoneIsOpen()} opened; null-safe (one line in a `finally`). */
    protected function closeConversationEnvelope(?AIUsageEnvelopeContext $aIUsageEnvelopeContext): void
    {
        if ($aIUsageEnvelopeContext === null) {
            return;
        }
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $aIUsageEnvelopesService->closeEnvelope($aIUsageEnvelopeContext);
    }
}
