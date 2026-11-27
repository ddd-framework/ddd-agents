<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Widgets\ShownConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Widgets\ShownConversationWidgets;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ExtendConnectionPermissionConversationWidget;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Entities\Conversations\Widgets\ContinueInPersonalSpaceConversationWidget;
use DDD\Domain\AI\Entities\Connections\AgentConnectionThreads;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Config;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddress;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddresses;
use DDD\Domain\AI\Entities\Connections\AgentConnectionProviderCall;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionContact;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequest;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionApprovalPendingException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionExpiredException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionProviderException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\CalendarAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\ChatAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\ContactsAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\FilesAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\MailAgentConnectionAdapter;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The ONE gate every step through a connection passes, in this order, before an adapter is called:
 * 1. the connection: this stage's row, CONNECTED (EXPIRED asks for a reconnect, PAUSED says so);
 * 2. the level the acting account has for the capability ({@see AgentConnectionsService::getEffectiveLevel()});
 * 3. for an outward step (send, create, update): the stage guard (outside production only allow-listed recipients,
 *    fail closed), the limits per hour, day and recipient, and the approval:
 *    - the *_WITH_APPROVAL level the operation needs (levelNeededFor), or SEND_BY_RULE towards a recipient outside the team: attended runs ask the
 *      package's consent step (the yes is bound to the exact step); unattended runs create an
 *      {@see AgentConnectionApprovalRequest} with the exact content and its hash, post it to the approval channel,
 *      and stop ({@see AgentConnectionApprovalPendingException}); a person's yes executes exactly that step;
 *    - SEND_BY_RULE towards internal recipients only (never a removal): runs.
 * 4. the adapter call; 5. the audit row ({@see AgentConnectionAction}), the refused and failed ones included.
 */
class AgentConnectionOperationsService
{
    public const array READ_OPERATIONS = [
        AgentConnectionAction::OPERATION_LIST, AgentConnectionAction::OPERATION_SEARCH, AgentConnectionAction::OPERATION_READ,
        AgentConnectionAction::OPERATION_DRAFT,
    ];

    public const string APPROVAL_PENDING = 'NOT DONE YET: this step needs a person\'s approval. An approval request #%d was created with the exact content%s. Do not repeat the call: when a person approves, exactly this step is carried out and you are told here. Tell the owner it waits for approval.';

    /**
     * Runs a read (list, search, read, draft) after the checks.
     *
     * @template T
     * @param object $providerCall an invokable object ({@see AgentConnectionProviderCall} or a named call class), never a closure
     * @return T
     * @throws AgentConnectionRefusedException
     * @throws AgentConnectionProviderException
     */
    public function runRead(AgentConnectionOperation $agentConnectionOperation, object $providerCall, string $target = ''): mixed
    {
        $this->requireUsableConnection($agentConnectionOperation);
        // private content never lands in a conversation others read (audience rule)
        $this->requireAudienceFits($agentConnectionOperation);
        $this->requireLevel($agentConnectionOperation, $agentConnectionOperation->operation === AgentConnectionAction::OPERATION_DRAFT ? AgentConnectionPermission::LEVEL_DRAFT : AgentConnectionPermission::LEVEL_READ);
        if ($providerCall instanceof AgentConnectionProviderCall && $providerCall->cacheQuery !== null) {
            $agentConnectionAccess = $this->getAgentConnectionsService()->getAccessOfAccount($agentConnectionOperation->agentConnection, (int)$agentConnectionOperation->actingAccountId, $agentConnectionOperation->getReach());
            /** @var AgentConnectionReadCacheService $agentConnectionReadCacheService */
            $agentConnectionReadCacheService = DDDService::instance()->getService(AgentConnectionReadCacheService::class);
            $providerCall = $agentConnectionReadCacheService->wrap($agentConnectionOperation->agentConnection, $agentConnectionAccess?->fingerprint($agentConnectionOperation->agentConnection) ?? 'none', $providerCall);
        }
        return $this->callProvider($agentConnectionOperation, $providerCall, $target);
    }

    /**
     * Runs an outward step after the checks, the limits and the approval. The step is described by
     * `$agentConnectionOperation->stepArguments` and executed by {@see executeStep()}, so an approved request runs
     * exactly what was approved.
     *
     * @throws AgentConnectionRefusedException
     * @throws AgentConnectionApprovalPendingException
     * @throws AgentConnectionProviderException
     */
    public function runOutwardStep(AgentConnectionOperation $agentConnectionOperation): AgentConnectionSendResult
    {
        $this->requireUsableConnection($agentConnectionOperation);
        $level = $this->requireLevel($agentConnectionOperation, AgentConnectionPermission::levelNeededFor($agentConnectionOperation->operation, $agentConnectionOperation->recipients !== []));
        $this->requireStageGuard($agentConnectionOperation);
        $this->requireLimits($agentConnectionOperation);
        $target = $this->describeTargets($agentConnectionOperation->recipients);

        if ($agentConnectionOperation->approvedRequestId !== null) {
            return $this->callProvider($agentConnectionOperation, AgentConnectionProviderCall::of($this, 'executeStep', $agentConnectionOperation), $target, $agentConnectionOperation->approvedRequestId);
        }
        // a removal (a delete, a merge that deletes its donor) always needs a yes; a rule covers sends and writes towards internal targets only
        $needsApproval = $level !== AgentConnectionPermission::LEVEL_SEND_BY_RULE
            || in_array($agentConnectionOperation->operation, AgentConnectionPermission::REMOVING_OPERATIONS, true)
            || !$this->areAllRecipientsInternal($agentConnectionOperation);
        if (!$needsApproval) {
            return $this->callProvider($agentConnectionOperation, AgentConnectionProviderCall::of($this, 'executeStep', $agentConnectionOperation), $target);
        }
        if ($this->isRunUnattended($agentConnectionOperation)) {
            $agentConnectionApprovalRequest = $this->getApprovalRequestsService()->openRequest($agentConnectionOperation);
            $this->audit($agentConnectionOperation, AgentConnectionAction::OUTCOME_AWAITING_APPROVAL, $target, null, (int)$agentConnectionApprovalRequest->id);
            $postedWhere = $agentConnectionApprovalRequest->postedExternalReference !== null ? ' and posted to the team\'s approval channel' : ' (shown in the approval list of the admin)';
            throw new AgentConnectionApprovalPendingException(sprintf(static::APPROVAL_PENDING, (int)$agentConnectionApprovalRequest->id, $postedWhere));
        }
        // attended: the owner is in the conversation; the package's consent step reads their yes to exactly this step
        /** @var OwnerConsentsService $ownerConsentsService */
        $ownerConsentsService = DDDService::instance()->getService(OwnerConsentsService::class);
        $ownerConsentRequest = OwnerConsentRequest::forAction(
            $agentConnectionOperation->toolName,
            strtolower($agentConnectionOperation->operation) . ' through ' . $agentConnectionOperation->agentConnection->getLabel(),
            $agentConnectionOperation->approvalSummary,
            // what follows from the step: a send reaches its recipients (the content itself is the target the owner saw)
            $agentConnectionOperation->recipients !== []
                ? 'It reaches ' . implode(', ', $agentConnectionOperation->recipients) . '.'
                : $agentConnectionOperation->approvalContent,
            $agentConnectionOperation->getContentHash()
        );
        // evidence for the consent step: the agent's own text that showed exactly this content (never a widget or tool row)
        $ownerConsentRequest->shownContentMessageIndex = $this->findShownContentMessageIndex($agentConnectionOperation);
        try {
            $ownerConsentReservation = $ownerConsentsService->requireOwnerConsentForStep($agentConnectionOperation->callProvenance, $ownerConsentRequest);
        } catch (Throwable $consentMissing) {
            $this->audit($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD, $target, 'waiting for the owner\'s yes');
            throw $consentMissing;
        }
        $sendResult = $this->callProvider($agentConnectionOperation, AgentConnectionProviderCall::of($this, 'executeStep', $agentConnectionOperation), $target);
        $ownerConsentsService->markConsentUsed($agentConnectionOperation->callProvenance, $ownerConsentReservation);
        return $sendResult;
    }

    /**
     * Executes an outward step from its stored arguments: the one place a send, a create or a post reaches an
     * adapter, for a direct step and for an approved request alike.
     *
     * @throws AgentConnectionRefusedException
     */
    public function executeStep(AgentConnectionOperation $agentConnectionOperation): AgentConnectionSendResult
    {
        // drive and native-document writes (stepKind) run in their own service: roots, revision lock, range compare
        if (AgentConnectionDriveStepsService::isDriveStep($agentConnectionOperation)) {
            /** @var AgentConnectionDriveStepsService $agentConnectionDriveStepsService */
            $agentConnectionDriveStepsService = DDDService::instance()->getService(AgentConnectionDriveStepsService::class);
            return $agentConnectionDriveStepsService->executeStep($agentConnectionOperation);
        }
        $adapter = $this->getAdaptersService()->getAdapterOfConnection($agentConnectionOperation->agentConnection);
        $stepArguments = $agentConnectionOperation->stepArguments;
        $stepKey = $agentConnectionOperation->capability . ':' . $agentConnectionOperation->operation;
        return match (true) {
            $stepKey === 'MAIL:SEND' && $adapter instanceof MailAgentConnectionAdapter && $stepArguments->mailDraft !== null => $adapter->sendMail($stepArguments->mailDraft),
            $stepKey === 'CALENDAR:CREATE' && $adapter instanceof CalendarAgentConnectionAdapter && $stepArguments->calendarEvent !== null => $adapter->createCalendarEvent($stepArguments->calendarEvent),
            $stepKey === 'CONTACTS:CREATE' && $adapter instanceof ContactsAgentConnectionAdapter && $stepArguments->contact !== null => $adapter->createContact($stepArguments->contact),
            $stepKey === 'CONTACTS:UPDATE' && $adapter instanceof ContactsAgentConnectionAdapter && $stepArguments->contact !== null => $adapter->updateContact($stepArguments->contact, $stepArguments->fieldNames),
            in_array($stepKey, ['CONTACTS:LINK', 'CONTACTS:UNLINK'], true) && $adapter instanceof ContactsAgentConnectionAdapter => $adapter->changeContactGroupMembers($stepArguments->groupId, $stepArguments->newGroupName, $stepArguments->addContactIds, $stepArguments->removeContactIds),
            $stepKey === 'CONTACTS:MERGE' && $adapter instanceof ContactsAgentConnectionAdapter => DDDService::instance()->getService(AgentConnectionContactMergesService::class)->executeMerge($agentConnectionOperation, $adapter),
            $stepKey === 'CONTACTS:DELETE' && $adapter instanceof ContactsAgentConnectionAdapter => $adapter->deleteContact((string)$stepArguments->contactId),
            $stepKey === 'FILES:CREATE' && $adapter instanceof FilesAgentConnectionAdapter => $adapter->createTextFile((string)$stepArguments->fileName, (string)$stepArguments->fileText, $stepArguments->parentFolderId),
            $stepKey === 'CHAT:SEND' && $adapter instanceof ChatAgentConnectionAdapter && $stepArguments->chatPost !== null => $adapter->postChatMessage($stepArguments->chatPost),
            default => throw new AgentConnectionRefusedException("The connection \"{$agentConnectionOperation->agentConnection->getLabel()}\" cannot do $stepKey."),
        };
    }

    /** @throws AgentConnectionRefusedException */
    protected function requireUsableConnection(AgentConnectionOperation $agentConnectionOperation): void
    {
        $agentConnection = $agentConnectionOperation->agentConnection;
        $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
        if (!$agentConnection->belongsToStage($agentConnectionsConfiguration->getCurrentStage())) {
            $this->refuse($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD, "The connection \"{$agentConnection->getLabel()}\" was created in another stage and is inert here.");
        }
        if ($agentConnection->status === AgentConnection::STATUS_EXPIRED) {
            $this->refuse($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD, "The connection \"{$agentConnection->getLabel()}\" has expired: its owner must connect it again in the connection settings.");
        }
        if ($agentConnection->status !== AgentConnection::STATUS_CONNECTED) {
            $this->refuse($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD, "The connection \"{$agentConnection->getLabel()}\" is {$agentConnection->status} and cannot be used now.");
        }
    }

    /**
     * The level the step needs, and the provider's grant for it (incremental consent). For the connection's owner a
     * button to the connection settings is shown when either is missing.
     *
     * @throws AgentConnectionRefusedException
     */
    protected function requireLevel(AgentConnectionOperation $agentConnectionOperation, string $neededLevel): string
    {
        $agentConnectionsService = $this->getAgentConnectionsService();
        $agentConnection = $agentConnectionOperation->agentConnection;
        $actingAccountId = (int)$agentConnectionOperation->actingAccountId;
        $this->requireAudienceFits($agentConnectionOperation);
        $level = $agentConnectionsService->getEffectiveLevel($agentConnection, $agentConnectionOperation->capability, $actingAccountId, $agentConnectionOperation->getReach());
        $connectionLabel = $agentConnection->getLabel();
        if (AgentConnectionPermission::rankOfLevel($level) < AgentConnectionPermission::rankOfLevel($neededLevel)) {
            $offerWritten = $this->offerToExtendPermission($agentConnectionOperation, $neededLevel);
            $this->refuse(
                $agentConnectionOperation,
                AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD,
                "The connection \"$connectionLabel\" allows {$agentConnectionOperation->capability} only at level $level; this step needs $neededLevel. "
                . ($offerWritten
                    ? 'A button that opens the connection settings is already shown below; tell the owner the permission is missing and point to the button below without quoting its label.'
                    : 'Its owner raises the level in the connection settings.')
            );
        }
        if ($agentConnectionsService->findMissingScopes($agentConnection, $agentConnectionOperation->capability, $neededLevel) !== []) {
            $offerWritten = $this->offerToExtendPermission($agentConnectionOperation, $neededLevel);
            $this->refuse(
                $agentConnectionOperation,
                AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD,
                "The provider has not granted \"$connectionLabel\" the permission this step needs ({$agentConnectionOperation->capability} at $neededLevel); nothing was done. "
                . ($offerWritten
                    ? 'A button that opens the connection settings to extend it is already shown below; tell the owner the permission is missing and point to the button below without quoting its label.'
                    : 'Its owner extends the permission in the connection settings (the provider asks for consent).')
            );
        }
        return $level;
    }

    /**
     * Shows the connection's owner a button to the connection settings (extend the level, consent at the provider).
     * Once per owner message and widget type, read from the stored rows; true when the button is there.
     */
    protected function offerToExtendPermission(AgentConnectionOperation $agentConnectionOperation, string $neededLevel): bool
    {
        $agentConnection = $agentConnectionOperation->agentConnection;
        if (!$this->isActingAccountTheOwner($agentConnectionOperation)) {
            return false;
        }
        $agentConnectionsService = $this->getAgentConnectionsService();
        $extendWidget = ExtendConnectionPermissionConversationWidget::forConnection(
            (int)$agentConnection->id,
            $agentConnectionOperation->capability,
            $neededLevel,
            $agentConnectionsService->getOwnerFacingText('extendPermission.reason', 'The connection "%s" is missing a permission for this step.', $agentConnection->getLabel())
        );
        return $this->appendOwnerWidgetOnce($agentConnectionOperation, $extendWidget, ['connectionId' => (int)$agentConnection->id, 'capability' => $agentConnectionOperation->capability]);
    }

    protected function isActingAccountTheOwner(AgentConnectionOperation $agentConnectionOperation): bool
    {
        $agentConnection = $agentConnectionOperation->agentConnection;
        return $agentConnectionOperation->callProvenance !== null && !$agentConnection->isAgentOwned() && $agentConnection->accountId !== null
            && (int)$agentConnection->accountId === (int)$agentConnectionOperation->actingAccountId;
    }

    /**
     * Writes a widget for the owner once per owner message: a widget of the same type and the same `$matchingFields`
     * stored after that message counts as written (read from the rows, so it holds across processes and worker
     * messages). A turn that came in through a chat connection gets no widget: a chat shows no buttons.
     *
     * @param array<string, int|string> $matchingFields fields that tell two widgets of the type apart
     */
    protected function appendOwnerWidgetOnce(AgentConnectionOperation $agentConnectionOperation, ConversationWidget $conversationWidget, array $matchingFields = []): bool
    {
        $callProvenance = $agentConnectionOperation->callProvenance;
        $conversationId = $agentConnectionOperation->getConversationId();
        if ($callProvenance === null || $conversationId === null || static::isChatConnectionTurn($agentConnectionOperation)) {
            return false;
        }
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $conversation = AIConversation::getService()->find($conversationId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $widgetRows = $conversation instanceof AIConversation ? $aIConversationMessagesService->findWidgetAndReactionRows($conversation) : null;
        if ($widgetRows !== null) {
            $ownerMessageIndex = (int)($callProvenance->sourceUserMessage->indexInConversation ?? -1);
            foreach (ShownConversationWidgets::buildFor($widgetRows)->findShownAfterIndex($ownerMessageIndex)->getElements() as $shownConversationWidget) {
                if ($shownConversationWidget->widgetType === $conversationWidget::WIDGET_TYPE && static::shownWidgetMatches($shownConversationWidget, $matchingFields)) {
                    return true;
                }
            }
        }
        return $aIConversationMessagesService->appendWidget($callProvenance, $conversationWidget)->isWritten();
    }

    /** A turn whose owner message came in through a chat connection (a chat thread shows no buttons) */
    public static function isChatConnectionTurn(AgentConnectionOperation $agentConnectionOperation): bool
    {
        return ($agentConnectionOperation->callProvenance?->sourceUserMessage?->ownerDevice?->kind ?? null) === OwnerDevice::KIND_CHAT_CONNECTION;
    }

    /** @param array<string, int|string> $matchingFields */
    public static function shownWidgetMatches(ShownConversationWidget $shownConversationWidget, array $matchingFields): bool
    {
        $shownFields = $shownConversationWidget->widgetBlock?->getFieldsAsArray() ?? [];
        foreach ($matchingFields as $fieldName => $fieldValue) {
            if ((string)($shownFields[$fieldName] ?? '') !== (string)$fieldValue) {
                return false;
            }
        }
        return true;
    }

    /**
     * The audience rule: in a conversation others read (a shared chat thread, or a conversation the host says others
     * read) a connection is used only through a release to that conversation's space; the owner's own access does
     * not count there. What it reads would be seen by the conversation's readers.
     *
     * @throws AgentConnectionRefusedException
     */
    public function requireAudienceFits(AgentConnectionOperation $agentConnectionOperation): void
    {
        $conversationId = $agentConnectionOperation->getConversationId();
        $agentConnectionsService = $this->getAgentConnectionsService();
        $agentConnection = $agentConnectionOperation->agentConnection;
        if ($conversationId === null || !$agentConnectionsService->isConversationSharedWithOthers($conversationId, $agentConnection)) {
            return;
        }
        if ($agentConnectionsService->getAccessOfAccount($agentConnection, (int)$agentConnectionOperation->actingAccountId, $agentConnectionOperation->getReach()) !== null) {
            return;
        }
        $connectionLabel = $agentConnection->getLabel();
        $whyNot = $agentConnectionsService->getVisibility($agentConnection) === AgentConnectionsService::VISIBILITY_OWNER_ONLY
            ? "the connection \"$connectionLabel\" is set to \"only for me\""
            : "the connection \"$connectionLabel\" is not released to this conversation's space";
        if ($agentConnectionsService->isSharedChatThread($conversationId)) {
            $this->refuse($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD, "This conversation is a shared chat thread that other people read, and $whyNot. Its data is not used here. Tell the owner plainly and offer to continue in a direct message.");
        }
        $audienceDescription = $agentConnectionsService->describeConversationAudience($conversationId);
        $offerWritten = $this->offerToContinueInPersonalSpace($agentConnectionOperation, $connectionLabel, $audienceDescription);
        $this->refuse($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD,
            "This conversation is in $audienceDescription, and $whyNot, so its data is not used here. "
            . ($offerWritten
                ? 'A button that continues the request in the owner\'s personal space is already shown below; tell the owner this in one or two plain sentences and point to the button below without quoting its label. Do not repeat or guess any of the private data.'
                : 'Tell the owner this in one or two plain sentences and offer to continue in a conversation of the owner\'s personal space.')
        );
    }

    /**
     * Shows the owner a button that starts the same request in a new conversation of the owner's personal space (the
     * branch: nothing of the private data enters this conversation). Only for the connection's own owner and only
     * when a tool call of a conversation asked; true when the button is there.
     */
    protected function offerToContinueInPersonalSpace(AgentConnectionOperation $agentConnectionOperation, string $connectionLabel, string $audienceDescription): bool
    {
        if (!$this->isActingAccountTheOwner($agentConnectionOperation)) {
            return false;
        }
        $ownerRequest = (string)($agentConnectionOperation->callProvenance->sourceUserMessage->message ?? '');
        if (trim($ownerRequest) === '') {
            return false;
        }
        $agentConnectionsService = $this->getAgentConnectionsService();
        $continueWidget = ContinueInPersonalSpaceConversationWidget::forRequest(
            $ownerRequest,
            $agentConnectionsService->getOwnerFacingText('continueInPersonalSpace.reason', '"%s" is only for you, and this conversation is in %s.', $connectionLabel, $audienceDescription)
        );
        return $this->appendOwnerWidgetOnce($agentConnectionOperation, $continueWidget);
    }

    /** Outside production an outward step reaches only allow-listed recipients; fails closed. Eval runs send only through FAKE. */
    protected function requireStageGuard(AgentConnectionOperation $agentConnectionOperation): void
    {
        $agentConnection = $agentConnectionOperation->agentConnection;
        if ($agentConnectionOperation->callProvenance?->isEvalHarnessCall && $agentConnection->provider !== AgentConnection::PROVIDER_FAKE) {
            $this->refuse($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD, 'Evaluation runs never send through a real connection.');
        }
        $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
        if ($agentConnectionsConfiguration->isProductionStage() || $agentConnection->provider === AgentConnection::PROVIDER_FAKE) {
            return;
        }
        if ($agentConnectionOperation->capability === AgentConnectionPermission::CAPABILITY_FILES || $agentConnectionOperation->capability === AgentConnectionPermission::CAPABILITY_CONTACTS) {
            // a file or a contact written into the connected account reaches nobody (sharing a file is a SEND and is checked below)
            if ($agentConnectionOperation->recipients === []) {
                return;
            }
        }
        if ($agentConnectionOperation->capability === AgentConnectionPermission::CAPABILITY_CHAT) {
            // outside production a post reaches only channels configured on the connection as internal
            foreach ($agentConnectionOperation->recipients as $channelId) {
                if (!in_array($channelId, $this->findInternalChannelIds($agentConnection), true)) {
                    $this->refuse($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD, "Outside production a post reaches only the connection's internal channels; $channelId is not one. Nothing was posted.");
                }
            }
            return;
        }
        $allowedRecipients = $agentConnectionsConfiguration->getNonProductionAllowedRecipients();
        foreach ($agentConnectionOperation->recipients as $recipient) {
            if (!in_array(mb_strtolower($recipient), $allowedRecipients, true)) {
                $this->refuse(
                    $agentConnectionOperation,
                    AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD,
                    'Outside production this step may reach only the test addresses of the allow-list; ' . static::describeTarget($recipient) . ' is not on it. Nothing was sent.'
                );
            }
        }
    }

    /** @throws AgentConnectionRefusedException */
    protected function requireLimits(AgentConnectionOperation $agentConnectionOperation): void
    {
        $agentConnection = $agentConnectionOperation->agentConnection;
        $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
        $agentConnectionActionsService = $this->getActionsService();
        $perHour = $agentConnection->sendsPerHourLimit ?? $agentConnectionsConfiguration->getLimit('sendsPerHour', 20);
        $perDay = $agentConnection->sendsPerDayLimit ?? $agentConnectionsConfiguration->getLimit('sendsPerDay', 100);
        $perRecipient = $agentConnection->sendsPerRecipientPerDayLimit ?? $agentConnectionsConfiguration->getLimit('sendsPerRecipientPerDay', 10);
        if ($agentConnectionActionsService->countOutwardDoneSince((int)$agentConnection->id, (new DateTime())->modify('-1 hour')) >= $perHour
            || $agentConnectionActionsService->countOutwardDoneSince((int)$agentConnection->id, (new DateTime())->modify('-1 day')) >= $perDay) {
            $this->refuse($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_LIMIT, "The connection \"{$agentConnection->getLabel()}\" reached its send limit ($perHour per hour, $perDay per day). Try later.");
        }
        foreach ($agentConnectionOperation->recipients as $recipient) {
            if ($agentConnectionActionsService->countDoneToTargetSince((int)$agentConnection->id, (new DateTime())->modify('-1 day'), static::describeTarget($recipient)) >= $perRecipient) {
                $this->refuse($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_LIMIT, 'The daily limit of sends to ' . static::describeTarget($recipient) . " ($perRecipient) is reached.");
            }
        }
    }

    /**
     * Whether every recipient belongs to the team: the internal domains of the configuration
     * (`AI.agentConnections.internalEmailDomains`), the connection's own address, or no recipient at all (an event
     * without attendees in the account's own calendar, a post into the team's chat).
     */
    protected function areAllRecipientsInternal(AgentConnectionOperation $agentConnectionOperation): bool
    {
        if ($agentConnectionOperation->capability === AgentConnectionPermission::CAPABILITY_CHAT) {
            // a channel is internal only when the connection lists it with the audience INTERNAL
            $internalChannelIds = $this->findInternalChannelIds($agentConnectionOperation->agentConnection);
            foreach ($agentConnectionOperation->recipients as $channelId) {
                if (!in_array($channelId, $internalChannelIds, true)) {
                    return false;
                }
            }
            return $agentConnectionOperation->recipients !== [];
        }
        $internalDomains = array_map('mb_strtolower', (array)(Config::get(AgentConnectionsConfiguration::CONFIG_KEY . '.internalEmailDomains') ?? []));
        $ownAddress = mb_strtolower((string)$agentConnectionOperation->agentConnection->externalAddress);
        foreach ($agentConnectionOperation->recipients as $recipient) {
            $recipient = mb_strtolower($recipient);
            $domain = substr((string)strrchr($recipient, '@'), 1);
            if ($recipient !== $ownAddress && !in_array($domain, $internalDomains, true)) {
                return false;
            }
        }
        return true;
    }

    /** @return string[] the channels the connection lists with the audience INTERNAL */
    protected function findInternalChannelIds(AgentConnection $agentConnection): array
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionAddresses::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', (int)$agentConnection->id)
                ->andWhere("$alias.audience = :internalAudience")->setParameter('internalAudience', AgentConnectionAddress::AUDIENCE_INTERNAL);
            $agentConnectionAddresses = $repository->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $internalChannelIds = [];
        foreach ($agentConnectionAddresses?->getElements() ?? [] as $agentConnectionAddress) {
            $internalChannelIds[] = (string)$agentConnectionAddress->externalAddress;
        }
        return $internalChannelIds;
    }

    /**
     * The index of the agent's own text row (never a widget or tool row: their text may come from outside) that showed
     * this step's whole content — every recipient, the subject and the body, as they are now — before the owner
     * message of this turn. Evidence for the consent step, never a consent of its own. Null when no such row exists or
     * for a step without such content. Only a mail send so far.
     */
    public function findShownContentMessageIndex(AgentConnectionOperation $agentConnectionOperation): ?int
    {
        $callProvenance = $agentConnectionOperation->callProvenance;
        $mailDraft = $agentConnectionOperation->stepArguments->mailDraft;
        $ownerMessageIndex = $callProvenance?->sourceUserMessage?->indexInConversation ?? null;
        if ($callProvenance === null || $mailDraft === null || $ownerMessageIndex === null || trim($mailDraft->bodyText) === '') {
            return null;
        }
        $conversation = $callProvenance->conversation;
        $messages = $callProvenance->conversationMessages ?? ($conversation instanceof AIConversation && isset($conversation->messages) ? $conversation->messages : null);
        if (!$messages instanceof AIConversationMessages) {
            return null;
        }
        $requiredParts = [static::normalizeShownText($mailDraft->bodyText)];
        if (trim($mailDraft->subject) !== '') {
            $requiredParts[] = static::normalizeShownText($mailDraft->subject);
        }
        foreach ($mailDraft->getAllRecipients() as $recipient) {
            $requiredParts[] = static::normalizeShownText((string)$recipient);
        }
        $shownContentMessageIndex = null;
        foreach ($messages->getRowsSortedByIndex() as $row) {
            $rowIndex = $row->indexInConversation ?? null;
            if ($rowIndex === null || $rowIndex >= (int)$ownerMessageIndex || !$row->isAssistantText()) {
                continue;
            }
            $shownText = static::normalizeShownText((string)($row->message ?? ''));
            $carriesAll = $shownText !== '';
            foreach ($requiredParts as $requiredPart) {
                $carriesAll = $carriesAll && str_contains($shownText, $requiredPart);
            }
            if ($carriesAll) {
                $shownContentMessageIndex = (int)$rowIndex;
            }
        }
        return $shownContentMessageIndex;
    }

    /** Text as the owner saw it, for a content comparison: lower case, no markdown marks, single spaces */
    public static function normalizeShownText(string $text): string
    {
        $text = preg_replace('/[*_`>#\\\\]+/u', '', $text) ?? $text;
        $text = preg_replace('/\\s+/u', ' ', $text) ?? $text;
        return trim(mb_strtolower($text));
    }

    /**
     * Whether nobody can answer the consent question where the run runs: a schedule, a task, a trigger. An
     * application with further unattended runs widens it.
     */
    public function isRunUnattended(AgentConnectionOperation $agentConnectionOperation): bool
    {
        $callProvenance = $agentConnectionOperation->callProvenance;
        if ($callProvenance === null) {
            return true;
        }
        if ($callProvenance->scheduleId !== null) {
            return true;
        }
        $conversation = $callProvenance->conversation;
        if ($conversation instanceof AIConversation) {
            $initiatorType = $conversation->initiatorType ?? null;
            if ($initiatorType !== null && in_array($initiatorType, AIConversation::getOwnerAbsentInitiatorTypes(), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @template T
     * @param object $providerCall an invokable object ({@see AgentConnectionProviderCall} or a named call class), never a closure
     * @return T
     */
    protected function callProvider(AgentConnectionOperation $agentConnectionOperation, object $providerCall, string $target, ?int $approvalRequestId = null): mixed
    {
        $agentConnectionsService = $this->getAgentConnectionsService();
        try {
            $result = $providerCall();
        } catch (AgentConnectionExpiredException $expired) {
            $agentConnectionsService->markExpired($agentConnectionOperation->agentConnection, $expired->getMessage());
            $this->audit($agentConnectionOperation, AgentConnectionAction::OUTCOME_FAILED, $target, 'expired', $approvalRequestId);
            throw new AgentConnectionRefusedException("The connection \"{$agentConnectionOperation->agentConnection->getLabel()}\" has expired: its owner must connect it again in the connection settings.");
        } catch (AgentConnectionRefusedException $refused) {
            $this->audit($agentConnectionOperation, AgentConnectionAction::OUTCOME_REFUSED_BY_GUARD, $target, $refused->getMessage(), $approvalRequestId);
            throw $refused;
        } catch (Throwable $providerFailure) {
            $agentConnectionsService->recordProviderError($agentConnectionOperation->agentConnection, $providerFailure->getMessage());
            $this->audit($agentConnectionOperation, AgentConnectionAction::OUTCOME_FAILED, $target, $providerFailure->getMessage(), $approvalRequestId);
            throw new AgentConnectionProviderException("The provider of \"{$agentConnectionOperation->agentConnection->getLabel()}\" answered with an error: " . mb_substr($providerFailure->getMessage(), 0, 300), 0, $providerFailure);
        }
        $agentConnectionsService->recordProviderSuccess($agentConnectionOperation->agentConnection);
        $this->audit($agentConnectionOperation, AgentConnectionAction::OUTCOME_DONE, $target, null, $approvalRequestId);
        return $result;
    }

    /** @throws AgentConnectionRefusedException always */
    protected function refuse(AgentConnectionOperation $agentConnectionOperation, string $outcome, string $reason): never
    {
        $this->audit($agentConnectionOperation, $outcome, $this->describeTargets($agentConnectionOperation->recipients), $reason);
        throw new AgentConnectionRefusedException($reason);
    }

    protected function audit(AgentConnectionOperation $agentConnectionOperation, string $outcome, string $target, ?string $outcomeText, ?int $approvalRequestId = null): void
    {
        $this->getActionsService()->record(
            $agentConnectionOperation,
            $outcome,
            $target,
            in_array($agentConnectionOperation->operation, AgentConnectionAction::OUTWARD_OPERATIONS, true) ? $agentConnectionOperation->getContentHash() : null,
            $outcomeText,
            $approvalRequestId
        );
    }

    /** @param string[] $recipients */
    public function describeTargets(array $recipients): string
    {
        $describedTargets = [];
        foreach ($recipients as $recipient) {
            $describedTargets[] = static::describeTarget($recipient);
        }
        return implode(', ', $describedTargets);
    }

    /** An address of the team in clear, any other as a hash */
    public static function describeTarget(string $recipient): string
    {
        $recipient = mb_strtolower(trim($recipient));
        $internalDomains = array_map('mb_strtolower', (array)(Config::get(AgentConnectionsConfiguration::CONFIG_KEY . '.internalEmailDomains') ?? []));
        $domain = substr((string)strrchr($recipient, '@'), 1);
        if ($domain === '' || in_array($domain, $internalDomains, true)) {
            return $recipient;
        }
        return 'external:' . substr(hash('sha256', $recipient), 0, 16) . '@' . $domain;
    }

    protected function getAgentConnectionsService(): AgentConnectionsService
    {
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        return $agentConnectionsService;
    }

    protected function getAdaptersService(): AgentConnectionAdaptersService
    {
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        return $agentConnectionAdaptersService;
    }

    protected function getActionsService(): AgentConnectionActionsService
    {
        /** @var AgentConnectionActionsService $agentConnectionActionsService */
        $agentConnectionActionsService = DDDService::instance()->getService(AgentConnectionActionsService::class);
        return $agentConnectionActionsService;
    }

    protected function getApprovalRequestsService(): AgentConnectionApprovalRequestsService
    {
        /** @var AgentConnectionApprovalRequestsService $agentConnectionApprovalRequestsService */
        $agentConnectionApprovalRequestsService = DDDService::instance()->getService(AgentConnectionApprovalRequestsService::class);
        return $agentConnectionApprovalRequestsService;
    }
}
