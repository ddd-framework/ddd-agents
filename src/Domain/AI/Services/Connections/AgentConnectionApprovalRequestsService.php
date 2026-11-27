<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionStepArguments;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddresses;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionApprovalRequestModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatButton;
use DDD\Domain\AI\Services\Connections\Adapters\Slack\SlackAgentConnectionAdapter;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatPost;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddress;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalOptions;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequest;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequests;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\ChatAgentConnectionAdapter;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The approval bridge of unattended runs. An outward step that needs a yes and has nobody to ask becomes a request
 * with the exact content, its hash and the arguments it runs with; it is shown in the approval list of the admin and
 * posted with buttons into every chat channel a system connection names for approvals
 * ({@see AgentConnectionAddress::USE_APPROVALS}). A person's answer, in the admin or by a button, is checked (the
 * request is open, the person may approve this step, the hash still matches) and then EXACTLY the approved step is
 * carried out by the gate ({@see AgentConnectionOperationsService::runOutwardStep()} with the request's id); the
 * conversation is told the outcome as a signal and continues. A changed text is a new request. Nothing is ever sent
 * because nobody answered.
 *
 * @method AgentConnectionApprovalRequest|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionApprovalRequests findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 */
class AgentConnectionApprovalRequestsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionApprovalRequest::class;

    public const int OPEN_DAYS = 7;

    public const string SIGNAL_APPROVED = 'A person (account %d) APPROVED approval request #%d (%s) via %s. The step was carried out exactly as requested%s. Continue the task; do not repeat the step.';
    public const string SIGNAL_REJECTED = 'A person (account %d) REJECTED approval request #%d (%s) via %s. The step was NOT carried out. Continue without it, or report to the owner.';
    public const string SIGNAL_FAILED = 'Approval request #%d (%s) was approved, but carrying it out failed: %s';

    public function openRequest(AgentConnectionOperation $agentConnectionOperation): AgentConnectionApprovalRequest
    {
        $agentConnectionApprovalRequest = AgentConnectionApprovalRequest::newInstance();
        $agentConnectionApprovalRequest->agentConnectionId = (int)$agentConnectionOperation->agentConnection->id;
        $agentConnectionApprovalRequest->accountId = $agentConnectionOperation->actingAccountId;
        $agentConnectionApprovalRequest->aiConversationId = $agentConnectionOperation->getConversationId();
        $agentConnectionApprovalRequest->toolName = mb_substr($agentConnectionOperation->toolName, 0, 100);
        $agentConnectionApprovalRequest->capability = $agentConnectionOperation->capability;
        $agentConnectionApprovalRequest->operation = $agentConnectionOperation->operation;
        $agentConnectionApprovalRequest->summary = mb_substr($agentConnectionOperation->agentConnection->getLabel() . ': ' . $agentConnectionOperation->approvalSummary, 0, 500);
        $agentConnectionApprovalRequest->content = $agentConnectionOperation->approvalContent;
        $agentConnectionApprovalRequest->stepArgumentsJson = $agentConnectionOperation->stepArguments->toStoredJson();
        $agentConnectionApprovalRequest->contentHash = $agentConnectionOperation->getContentHash();
        $agentConnectionApprovalRequest->options = AgentConnectionApprovalOptions::approveOrReject();
        $agentConnectionApprovalRequest->approvalToken = bin2hex(random_bytes(16));
        $agentConnectionApprovalRequest->expiresAt = (new DateTime())->modify('+' . static::OPEN_DAYS . ' days');
        $this->persist($agentConnectionApprovalRequest);
        $this->postToApprovalChannels($agentConnectionApprovalRequest);
        return $agentConnectionApprovalRequest;
    }

    /**
     * Posts the request with its buttons into each approval channel of the system chat connections. A channel that
     * fails is skipped (the request stays in the admin list); the first post that worked is kept for the update.
     */
    public function postToApprovalChannels(AgentConnectionApprovalRequest $agentConnectionApprovalRequest): void
    {
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        foreach ($this->findApprovalChannels()->getElements() as $approvalAddress) {
            $chatConnection = $approvalAddress->agentConnection;
            try {
                $chatAdapter = $agentConnectionAdaptersService->getAdapterOfConnection($chatConnection);
                if (!$chatAdapter instanceof ChatAgentConnectionAdapter || !$chatConnection->isUsable()) {
                    continue;
                }
                $sendResult = $chatAdapter->postChatMessage($this->buildApprovalPost($agentConnectionApprovalRequest, (string)$approvalAddress->externalAddress));
                if ($agentConnectionApprovalRequest->postedExternalReference === null) {
                    $agentConnectionApprovalRequest->postedExternalReference = $chatConnection->id . ':' . $approvalAddress->externalAddress . ':' . $sendResult->externalId;
                    $this->persist($agentConnectionApprovalRequest);
                }
            } catch (Throwable $postFailure) {
                $agentConnectionsService->recordProviderError($chatConnection, 'approval post: ' . $postFailure->getMessage());
            }
        }
    }

    public function buildApprovalPost(AgentConnectionApprovalRequest $agentConnectionApprovalRequest, string $channelId): AgentConnectionChatPost
    {
        $chatPost = AgentConnectionChatPost::newInstance();
        $chatPost->channelId = $channelId;
        $chatPost->text = '*Approval needed #' . $agentConnectionApprovalRequest->id . '* ' . $agentConnectionApprovalRequest->summary . "\n" . SlackAgentConnectionAdapter::fenceAsCode(mb_substr((string)$agentConnectionApprovalRequest->content, 0, 2500));
        foreach ($agentConnectionApprovalRequest->options?->getElements() ?? [] as $option) {
            $button = AgentConnectionChatButton::newInstance();
            $button->label = $option->label;
            $button->actionValue = 'approval:' . $agentConnectionApprovalRequest->approvalToken . ':' . $option->key;
            $button->style = $option->key === AgentConnectionApprovalRequest::OPTION_APPROVE ? 'primary' : 'danger';
            $chatPost->addButton($button);
        }
        return $chatPost;
    }

    /**
     * A person answers. Refused when the request is not open, the option unknown, or the person may not approve:
     * the account of the conversation, or an account that reaches the connection at WRITE_WITH_APPROVAL or higher.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    public function answer(AgentConnectionApprovalRequest $agentConnectionApprovalRequest, string $optionKey, int $answeringAccountId, string $answeredVia, ?int $answeredThroughAgentConnectionId = null): AgentConnectionApprovalRequest
    {
        // expiry first: an open request past its time is marked EXPIRED and says so (isOpen() is false for it)
        if ($agentConnectionApprovalRequest->status === AgentConnectionApprovalRequest::STATUS_OPEN && $agentConnectionApprovalRequest->isExpired()) {
            $this->claimOpenRequest($agentConnectionApprovalRequest, AgentConnectionApprovalRequest::STATUS_EXPIRED, null, null, null);
            throw new ForbiddenException('This approval request has expired.');
        }
        if (!$agentConnectionApprovalRequest->isOpen()) {
            throw new ForbiddenException('This approval request is already ' . strtolower($agentConnectionApprovalRequest->status) . '.');
        }
        if (!$agentConnectionApprovalRequest->options?->hasKey($optionKey)) {
            throw new ForbiddenException('Unknown answer.');
        }
        $agentConnection = $this->getAgentConnectionsService()->findIgnoringRights((int)$agentConnectionApprovalRequest->agentConnectionId);
        if (!$agentConnection instanceof AgentConnection) {
            throw new NotFoundException('The connection of this request no longer exists.');
        }
        if (!$this->mayApprove($agentConnectionApprovalRequest, $agentConnection, $answeringAccountId)) {
            throw new ForbiddenException('You may not answer this approval request.');
        }
        $newStatus = $optionKey === AgentConnectionApprovalRequest::OPTION_APPROVE ? AgentConnectionApprovalRequest::STATUS_APPROVED : AgentConnectionApprovalRequest::STATUS_REJECTED;
        // one answer wins: a conditional UPDATE … WHERE status = OPEN; a second click, a retry or a parallel channel
        // changes no row and is refused, so the step runs at most once
        if (!$this->claimOpenRequest($agentConnectionApprovalRequest, $newStatus, $answeringAccountId, $answeredVia, $answeredThroughAgentConnectionId)) {
            throw new ForbiddenException('This approval request was answered meanwhile.');
        }
        /** @var AgentConnectionActionsService $agentConnectionActionsService */
        $agentConnectionActionsService = DDDService::instance()->getService(AgentConnectionActionsService::class);
        $agentConnectionActionsService->recordAdministration($agentConnection, AgentConnectionAction::OPERATION_APPROVAL, $answeringAccountId, "request #{$agentConnectionApprovalRequest->id} $optionKey via $answeredVia");
        if ($newStatus === AgentConnectionApprovalRequest::STATUS_REJECTED) {
            $this->signalConversation($agentConnectionApprovalRequest, sprintf(static::SIGNAL_REJECTED, $answeringAccountId, $agentConnectionApprovalRequest->id, $agentConnectionApprovalRequest->summary, $answeredVia));
            return $agentConnectionApprovalRequest;
        }
        $this->executeApproved($agentConnectionApprovalRequest, $agentConnection);
        return $agentConnectionApprovalRequest;
    }

    /**
     * Moves an OPEN, not expired request to `$newStatus` with one conditional UPDATE and mirrors it on the entity.
     *
     * @return bool true when this call changed the row (it won the answer); false when another answer came first
     */
    public function claimOpenRequest(AgentConnectionApprovalRequest $agentConnectionApprovalRequest, string $newStatus, ?int $answeringAccountId, ?string $answeredVia, ?int $answeredThroughAgentConnectionId): bool
    {
        $answeredAt = new DateTime();
        $updateQuery = EntityManagerFactory::getInstance()->createQuery(
            'UPDATE ' . DBAgentConnectionApprovalRequestModel::class . ' approvalRequest'
            . ' SET approvalRequest.status = :newStatus, approvalRequest.answeredByAccountId = :answeredByAccountId,'
            . ' approvalRequest.answeredVia = :answeredVia, approvalRequest.answeredThroughAgentConnectionId = :answeredThroughAgentConnectionId,'
            . ' approvalRequest.answeredAt = :answeredAt'
            . ' WHERE approvalRequest.id = :approvalRequestId AND approvalRequest.status = :openStatus'
        );
        $updateQuery->setParameters([
            'newStatus' => $newStatus, 'answeredByAccountId' => $answeringAccountId, 'answeredVia' => $answeredVia,
            'answeredThroughAgentConnectionId' => $answeredThroughAgentConnectionId, 'answeredAt' => $answeredAt,
            'approvalRequestId' => (int)$agentConnectionApprovalRequest->id, 'openStatus' => AgentConnectionApprovalRequest::STATUS_OPEN,
        ]);
        if ((int)$updateQuery->execute() !== 1) {
            return false;
        }
        $agentConnectionApprovalRequest->status = $newStatus;
        $agentConnectionApprovalRequest->answeredByAccountId = $answeringAccountId;
        $agentConnectionApprovalRequest->answeredVia = $answeredVia;
        $agentConnectionApprovalRequest->answeredThroughAgentConnectionId = $answeredThroughAgentConnectionId;
        $agentConnectionApprovalRequest->answeredAt = $answeredAt;
        return true;
    }

    /** Marks the outcome of an approved step (EXECUTED or FAILED) on its row only */
    protected function markOutcome(AgentConnectionApprovalRequest $agentConnectionApprovalRequest, string $outcomeStatus): void
    {
        $agentConnectionApprovalRequest->status = $outcomeStatus;
        $agentConnectionApprovalRequest->updatePartialIgnoringRights('status');
    }

    /** The sweep: OPEN requests past their expiry become EXPIRED (one conditional update each) */
    public function expireOpenRequests(): int
    {
        $updateQuery = EntityManagerFactory::getInstance()->createQuery(
            'UPDATE ' . DBAgentConnectionApprovalRequestModel::class . ' approvalRequest SET approvalRequest.status = :expiredStatus'
            . ' WHERE approvalRequest.status = :openStatus AND approvalRequest.expiresAt IS NOT NULL AND approvalRequest.expiresAt < :now'
        );
        $updateQuery->setParameters(['expiredStatus' => AgentConnectionApprovalRequest::STATUS_EXPIRED, 'openStatus' => AgentConnectionApprovalRequest::STATUS_OPEN, 'now' => new DateTime()]);
        return (int)$updateQuery->execute();
    }

    /** Carries out exactly the approved step; a hash that no longer matches the stored arguments is refused */
    protected function executeApproved(AgentConnectionApprovalRequest $agentConnectionApprovalRequest, AgentConnection $agentConnection): void
    {
        $agentConnectionOperation = AgentConnectionOperation::create(
            $agentConnection,
            (string)$agentConnectionApprovalRequest->capability,
            (string)$agentConnectionApprovalRequest->operation,
            (string)$agentConnectionApprovalRequest->toolName,
            $agentConnectionApprovalRequest->accountId,
            null
        );
        $agentConnectionOperation->stepArguments = AgentConnectionStepArguments::fromStoredJson($agentConnectionApprovalRequest->stepArgumentsJson);
        $agentConnectionOperation->recipients = $agentConnectionOperation->stepArguments->getRecipients();
        $agentConnectionOperation->approvedRequestId = (int)$agentConnectionApprovalRequest->id;
        $label = (string)$agentConnectionApprovalRequest->summary;
        if ($agentConnectionOperation->getContentHash() !== $agentConnectionApprovalRequest->contentHash) {
            $this->markOutcome($agentConnectionApprovalRequest, AgentConnectionApprovalRequest::STATUS_FAILED);
            $this->signalConversation($agentConnectionApprovalRequest, sprintf(static::SIGNAL_FAILED, $agentConnectionApprovalRequest->id, $label, 'the stored content does not match its hash'));
            return;
        }
        /** @var AgentConnectionOperationsService $agentConnectionOperationsService */
        $agentConnectionOperationsService = DDDService::instance()->getService(AgentConnectionOperationsService::class);
        try {
            $sendResult = $agentConnectionOperationsService->runOutwardStep($agentConnectionOperation);
        } catch (Throwable $executionFailure) {
            // reported to the conversation (and audited by the gate); the request ends FAILED, never runs again
            $this->markOutcome($agentConnectionApprovalRequest, AgentConnectionApprovalRequest::STATUS_FAILED);
            $this->signalConversation($agentConnectionApprovalRequest, sprintf(static::SIGNAL_FAILED, $agentConnectionApprovalRequest->id, $label, $executionFailure->getMessage()));
            return;
        }
        $this->markOutcome($agentConnectionApprovalRequest, AgentConnectionApprovalRequest::STATUS_EXECUTED);
        $this->signalConversation($agentConnectionApprovalRequest, sprintf(
            static::SIGNAL_APPROVED,
            (int)$agentConnectionApprovalRequest->answeredByAccountId,
            $agentConnectionApprovalRequest->id,
            $label,
            (string)$agentConnectionApprovalRequest->answeredVia,
            $sendResult->externalId !== '' ? ' (provider id ' . $sendResult->externalId . ')' : ''
        ));
    }

    public function mayApprove(AgentConnectionApprovalRequest $agentConnectionApprovalRequest, AgentConnection $agentConnection, int $answeringAccountId): bool
    {
        if ($agentConnectionApprovalRequest->accountId !== null && (int)$agentConnectionApprovalRequest->accountId === $answeringAccountId) {
            return true;
        }
        $agentConnectionsService = $this->getAgentConnectionsService();
        $level = $agentConnectionsService->getEffectiveLevel($agentConnection, (string)$agentConnectionApprovalRequest->capability, $answeringAccountId, AgentConnectionReach::acrossMemberSpaces());
        return AgentConnectionPermission::rankOfLevel($level) >= AgentConnectionPermission::rankOfLevel(AgentConnectionPermission::LEVEL_WRITE_WITH_APPROVAL);
    }

    public function findByToken(string $approvalToken): ?AgentConnectionApprovalRequest
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $approvalToken)) {
            return null;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionApprovalRequests::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.approvalToken = :approvalToken")->setParameter('approvalToken', $approvalToken);
            return $repository->find($queryBuilder, false)?->first();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function findIgnoringRights(int $agentConnectionApprovalRequestId): ?AgentConnectionApprovalRequest
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnectionApprovalRequest = $this->find($agentConnectionApprovalRequestId, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $agentConnectionApprovalRequest instanceof AgentConnectionApprovalRequest ? $agentConnectionApprovalRequest : null;
    }

    /**
     * The requests an account may answer: its own, and those of connections it reaches at WRITE_WITH_APPROVAL or higher.
     * Newest first; open ones only unless `$includeAnswered`.
     */
    public function findAnswerableByAccount(int $accountId, bool $includeAnswered, int $limit = 100): AgentConnectionApprovalRequests
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionApprovalRequests::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            if (!$includeAnswered) {
                $queryBuilder->andWhere("$alias.status = :open")->setParameter('open', AgentConnectionApprovalRequest::STATUS_OPEN);
            }
            // only requests of connections the account reaches (its own requests, or a connection it uses): the
            // level check below then runs on a short list, and older requests never fall out of a window
            $usableConnectionIds = [0];
            foreach ($this->getAgentConnectionsService()->findUsableConnectionsOfAccount($accountId, AgentConnectionReach::acrossMemberSpaces())->getElements() as $usableConnection) {
                $usableConnectionIds[] = (int)$usableConnection->id;
            }
            $queryBuilder->andWhere("($alias.accountId = :accountId OR $alias.agentConnectionId IN (:usableConnectionIds))")
                ->setParameter('accountId', $accountId)->setParameter('usableConnectionIds', $usableConnectionIds)
                ->orderBy("$alias.id", 'DESC')->setMaxResults($limit);
            $candidates = $repository->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $answerable = AgentConnectionApprovalRequests::newInstance();
        $connectionsById = [];
        foreach ($candidates?->getElements() ?? [] as $agentConnectionApprovalRequest) {
            $agentConnectionId = (int)$agentConnectionApprovalRequest->agentConnectionId;
            $connectionsById[$agentConnectionId] ??= $this->getAgentConnectionsService()->findIgnoringRights($agentConnectionId);
            if ($connectionsById[$agentConnectionId] instanceof AgentConnection && $this->mayApprove($agentConnectionApprovalRequest, $connectionsById[$agentConnectionId], $accountId)) {
                $answerable->add($agentConnectionApprovalRequest);
                if ($answerable->count() >= $limit) {
                    break;
                }
            }
        }
        return $answerable;
    }

    /** The approval channels of the stage's system chat connections, each with its connection loaded */
    protected function findApprovalChannels(): AgentConnectionAddresses
    {
        $approvalChannels = AgentConnectionAddresses::newInstance();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionAddresses::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.postingUse = :approvals")->setParameter('approvals', AgentConnectionAddress::USE_APPROVALS);
            $approvalAddresses = $repository->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        foreach ($approvalAddresses?->getElements() ?? [] as $approvalAddress) {
            $chatConnection = $this->getAgentConnectionsService()->findIgnoringRights((int)$approvalAddress->agentConnectionId);
            if ($chatConnection instanceof AgentConnection && $chatConnection->isAgentOwned()
                && $chatConnection->belongsToStage(AgentConnectionsConfiguration::current()->getCurrentStage())) {
                $approvalAddress->agentConnection = $chatConnection;
                $approvalChannels->add($approvalAddress);
            }
        }
        return $approvalChannels;
    }

    /** The outcome reaches the conversation as an operator statement of the platform; the run continues */
    protected function signalConversation(AgentConnectionApprovalRequest $agentConnectionApprovalRequest, string $signalText): void
    {
        if ($agentConnectionApprovalRequest->aiConversationId === null) {
            return;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
            $conversation = $aIConversationsService->find((int)$agentConnectionApprovalRequest->aiConversationId, false);
            if (!$conversation instanceof AIConversation) {
                return;
            }
            /** @var AIConversationTurnSignalsService $aIConversationTurnSignalsService */
            $aIConversationTurnSignalsService = DDDService::instance()->getService(AIConversationTurnSignalsService::class);
            $aIConversationTurnSignalsService->injectSignal($conversation, $signalText, static::getSignalSource());
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public static function getSignalSource(): string
    {
        return AIConversationMessage::SIGNAL_SOURCE_CONNECTION_APPROVAL;
    }

    protected function getAgentConnectionsService(): AgentConnectionsService
    {
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        return $agentConnectionsService;
    }

    protected function persist(AgentConnectionApprovalRequest $agentConnectionApprovalRequest): void
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnectionApprovalRequest->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }
}
