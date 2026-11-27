<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\AI\Entities\Connections\AgentConnectionInboundEvents;
use DDD\Domain\AI\Entities\Connections\AgentConnectionThreads;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAccountLinks;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatPost;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbound;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAccountLink;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddress;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequest;
use DDD\Domain\AI\Entities\Connections\AgentConnectionInboundEvent;
use DDD\Domain\AI\Entities\Connections\AgentConnectionThread;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Services\Connections\Adapters\ChatAgentConnectionAdapter;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * What happens with a verified inbound event of a system chat connection (mode 2):
 * 1. a repeated delivery (the provider's event id) is dropped;
 * 2. the author is resolved to an account through its link, or linked once by the provider's verified e-mail when
 *    the application finds exactly one account for it ({@see findAccountIdByVerifiedEmail()}); an author without a
 *    link gets ONE fixed line and nothing else: no model call;
 * 3. a button answers its approval request (the author's account must be allowed to approve);
 * 4. a direct message or a mention opens a thread = one conversation of the author (or continues it), under the
 *    author's rights; a reply in a known thread continues it when its author is the thread's owner. A colleague in
 *    the owner's thread is told to open a thread of their own (each turn runs under its author's rights; a shared
 *    thread with several owners is not built).
 * The answer is posted back by {@see AgentConnectionThreadRepliesService} after the turn settled.
 *
 * Host hooks: {@see findAccountIdByVerifiedEmail()}, {@see accountHoldsRight()}, {@see findAgentSpaceForInboundConversation()}.
 */
class AgentConnectionInboundService
{
    public const string FOREIGN_THREAD_LINE = 'This thread belongs to another person\'s conversation. Mention me in a new message to start your own.';
    public const string NOT_ALLOWED_HERE_LINE = 'I cannot answer you in this channel.';

    /**
     * Handles one event. Runs in a worker (the HTTP route only verifies and dispatches).
     *
     * @return string what happened (for the inbound event row and logs)
     */
    public function handle(AgentConnection $agentConnection, AgentConnectionInbound $inbound): string
    {
        if (!in_array($inbound->kind, [AgentConnectionInbound::KIND_MENTION, AgentConnectionInbound::KIND_DIRECT_MESSAGE, AgentConnectionInbound::KIND_MESSAGE, AgentConnectionInbound::KIND_BUTTON], true)) {
            return 'ignored';
        }
        if (!$agentConnection->isUsable() || !$agentConnection->belongsToStage(AgentConnectionsConfiguration::current()->getCurrentStage())) {
            return 'connection not usable here';
        }
        if (!$this->registerDelivery($agentConnection, $inbound)) {
            return 'duplicate';
        }
        $accountId = $this->resolveLinkedAccountId($agentConnection, (string)$inbound->externalUserId);
        if ($inbound->kind === AgentConnectionInbound::KIND_MESSAGE) {
            $agentConnectionThread = $this->findThread($agentConnection, (string)$inbound->channelId, (string)$inbound->threadId);
            if (!$agentConnectionThread instanceof AgentConnectionThread) {
                // a message in a channel without a mention and outside the agent's threads is not read
                return 'not addressed';
            }
        }
        if ($accountId === null) {
            $this->postLine($agentConnection, $inbound, AgentConnectionsConfiguration::current()->getAdapterSettings((string)$agentConnection->provider)->unknownUserLine);
            return 'unknown author: fixed line';
        }
        if ($inbound->kind === AgentConnectionInbound::KIND_BUTTON) {
            return $this->answerButton($agentConnection, $inbound, $accountId);
        }
        if ($this->countRunsOfAuthorInLastHour($agentConnection, (string)$inbound->externalUserId) > AgentConnectionsConfiguration::current()->getLimit('inboundRunsPerSenderPerHour', 5)) {
            return 'author over the hourly limit';
        }
        if ($inbound->kind !== AgentConnectionInbound::KIND_DIRECT_MESSAGE && !$this->mayBeAnsweredInChannel($agentConnection, (string)$inbound->channelId, $accountId)) {
            $this->postLine($agentConnection, $inbound, static::NOT_ALLOWED_HERE_LINE);
            return 'author may not be answered in this channel';
        }
        return $this->startOrContinueThread($agentConnection, $inbound, $accountId);
    }

    protected function answerButton(AgentConnection $agentConnection, AgentConnectionInbound $inbound, int $accountId): string
    {
        /** @var AgentConnectionApprovalRequestsService $agentConnectionApprovalRequestsService */
        $agentConnectionApprovalRequestsService = DDDService::instance()->getService(AgentConnectionApprovalRequestsService::class);
        $agentConnectionApprovalRequest = $agentConnectionApprovalRequestsService->findByToken((string)$inbound->approvalToken);
        if (!$agentConnectionApprovalRequest instanceof AgentConnectionApprovalRequest) {
            return 'unknown approval';
        }
        try {
            $agentConnectionApprovalRequestsService->answer($agentConnectionApprovalRequest, (string)$inbound->optionKey, $accountId, AgentConnectionApprovalRequest::ANSWERED_VIA_SLACK, (int)$agentConnection->id);
            $outcomeLine = '*' . $agentConnectionApprovalRequest->status . '* by <@' . $inbound->externalUserId . '>: ' . $agentConnectionApprovalRequest->summary;
        } catch (Throwable $answerRefused) {
            $outcomeLine = $answerRefused->getMessage();
            $this->postLine($agentConnection, $inbound, $outcomeLine);
            return 'approval refused: ' . $outcomeLine;
        }
        // the post is shortened to its outcome; the buttons disappear
        if ($inbound->messageId !== null) {
            $chatPost = AgentConnectionChatPost::newInstance();
            $chatPost->channelId = (string)$inbound->channelId;
            $chatPost->replacesMessageId = $inbound->messageId;
            $chatPost->text = $outcomeLine;
            $this->postChat($agentConnection, $chatPost);
        }
        return 'approval ' . $agentConnectionApprovalRequest->status;
    }

    protected function startOrContinueThread(AgentConnection $agentConnection, AgentConnectionInbound $inbound, int $accountId): string
    {
        $account = $this->findAccount($accountId);
        if (!$account instanceof Account) {
            return 'linked account missing';
        }
        $agentConnectionThread = $this->findThread($agentConnection, (string)$inbound->channelId, (string)$inbound->threadId);
        if ($agentConnectionThread instanceof AgentConnectionThread && (int)$agentConnectionThread->openedByAccountId !== $accountId) {
            $this->postLine($agentConnection, $inbound, static::FOREIGN_THREAD_LINE);
            return 'foreign thread';
        }
        // the thread row and its owed answer exist BEFORE the turn is dispatched (a fast worker answers into them);
        // an existing row is updated field by field, never re-persisted whole (its post counter moves meanwhile)
        if ($agentConnectionThread instanceof AgentConnectionThread) {
            $agentConnectionThread->lastInboundAt = new DateTime();
            $agentConnectionThread->answerOwed = true;
            $agentConnectionThread->updatePartialIgnoringRights('lastInboundAt', 'answerOwed');
            $aiConversationId = (int)$agentConnectionThread->aiConversationId;
        } else {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
            $newConversation = $aIConversationsService->findOrCreateConversationToDrive(null, $accountId, $this->findAgentSpaceForInboundConversation($account, $agentConnection, (string)$inbound->channelId));
            $agentConnectionThread = AgentConnectionThread::newInstance();
            $agentConnectionThread->agentConnectionId = (int)$agentConnection->id;
            $agentConnectionThread->externalChannelId = (string)$inbound->channelId;
            $agentConnectionThread->externalThreadId = (string)$inbound->threadId;
            $agentConnectionThread->aiConversationId = (int)$newConversation->id;
            $agentConnectionThread->openedByAccountId = $accountId;
            $agentConnectionThread->readByOwnerOnly = $inbound->kind === AgentConnectionInbound::KIND_DIRECT_MESSAGE;
            $agentConnectionThread->lastInboundAt = new DateTime();
            $agentConnectionThread->answerOwed = true;
            $this->persist($agentConnectionThread);
            $aiConversationId = (int)$newConversation->id;
        }
        $ownerDevice = new OwnerDevice();
        $ownerDevice->kind = OwnerDevice::KIND_CHAT_CONNECTION;
        $previousAccount = AuthService::instance()->getAccount();
        AuthService::instance()->setAccount($account);
        try {
            /** @var AIConversationAgentService $aIConversationAgentService */
            $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
            $conversation = $aIConversationAgentService->driveConversation(
                AIConversationDriveScope::CUSTOMER,
                $aiConversationId,
                null,
                $inbound->text,
                async: true,
                ownerDevice: $ownerDevice
            );
        } finally {
            AuthService::instance()->setAccount($previousAccount);
        }
        return 'turn started in conversation ' . $conversation->id;
    }

    /** false = already seen */
    protected function registerDelivery(AgentConnection $agentConnection, AgentConnectionInbound $inbound): bool
    {
        $agentConnectionInboundEvent = AgentConnectionInboundEvent::newInstance();
        $agentConnectionInboundEvent->agentConnectionId = (int)$agentConnection->id;
        $agentConnectionInboundEvent->providerEventId = mb_substr($inbound->providerEventId !== '' ? $inbound->providerEventId : hash('sha256', (string)$inbound->channelId . '|' . (string)$inbound->messageId . '|' . $inbound->kind), 0, 128);
        $agentConnectionInboundEvent->eventKind = $inbound->kind;
        $agentConnectionInboundEvent->stateText = mb_substr('author ' . (string)$inbound->externalUserId, 0, 255);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AgentConnectionInboundEventsService $agentConnectionInboundEventsService */
            $agentConnectionInboundEventsService = DDDService::instance()->getService(AgentConnectionInboundEventsService::class);
            $agentConnectionInboundEventsService->insert($agentConnectionInboundEvent);
            return true;
        } catch (UniqueConstraintViolationException) {
            // the provider delivered this event before (a retry): dropped on purpose
            return false;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** The account of an external user: its link, or a new link by the verified e-mail; null = unknown */
    public function resolveLinkedAccountId(AgentConnection $agentConnection, string $externalUserId): ?int
    {
        if ($externalUserId === '') {
            return null;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionAccountLinks::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', (int)$agentConnection->id)
                ->andWhere("$alias.externalUserId = :externalUserId")->setParameter('externalUserId', $externalUserId);
            $agentConnectionAccountLink = $repository->find($queryBuilder, false)?->first();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if ($agentConnectionAccountLink instanceof AgentConnectionAccountLink) {
            return $agentConnectionAccountLink->accountId !== null ? (int)$agentConnectionAccountLink->accountId : null;
        }
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $chatAdapter = $agentConnectionAdaptersService->getAdapterOfConnection($agentConnection);
        $verifiedEmail = $chatAdapter instanceof ChatAgentConnectionAdapter ? $chatAdapter->findVerifiedEmailOfUser($externalUserId) : null;
        $accountId = $verifiedEmail !== null ? $this->findAccountIdByVerifiedEmail($verifiedEmail) : null;
        if ($accountId === null) {
            return null;
        }
        $this->linkAccount($agentConnection, $externalUserId, $accountId, AgentConnectionAccountLink::VERIFIED_BY_EMAIL_MATCH, $verifiedEmail);
        return $accountId;
    }

    public function linkAccount(AgentConnection $agentConnection, string $externalUserId, int $accountId, string $verificationMethod, ?string $externalDisplayName): AgentConnectionAccountLink
    {
        $agentConnectionAccountLink = AgentConnectionAccountLink::newInstance();
        $agentConnectionAccountLink->agentConnectionId = (int)$agentConnection->id;
        $agentConnectionAccountLink->externalUserId = $externalUserId;
        $agentConnectionAccountLink->externalDisplayName = $externalDisplayName;
        $agentConnectionAccountLink->accountId = $accountId;
        $agentConnectionAccountLink->verifiedAt = new DateTime();
        $agentConnectionAccountLink->verificationMethod = $verificationMethod;
        $this->persist($agentConnectionAccountLink);
        return $agentConnectionAccountLink;
    }

    public function findThread(AgentConnection $agentConnection, string $channelId, string $threadId): ?AgentConnectionThread
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionThreads::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', (int)$agentConnection->id)
                ->andWhere("$alias.externalChannelId = :channelId")->setParameter('channelId', $channelId)
                ->andWhere("$alias.externalThreadId = :threadId")->setParameter('threadId', $threadId);
            return $repository->find($queryBuilder, false)?->first();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** Whether the author may be answered in this channel: the channel's behaviour and its required right */
    protected function mayBeAnsweredInChannel(AgentConnection $agentConnection, string $channelId, int $accountId): bool
    {
        foreach ($agentConnection->addresses?->getElements() ?? [] as $agentConnectionAddress) {
            if ($agentConnectionAddress->externalAddress !== $channelId) {
                continue;
            }
            if ($agentConnectionAddress->behaviour === AgentConnectionAddress::BEHAVIOUR_READ_WHEN_ASKED) {
                return false;
            }
            return $agentConnectionAddress->requiredAccountRight === null || $this->accountHoldsRight($accountId, $agentConnectionAddress->requiredAccountRight);
        }
        // a channel the connection does not name: not answered unless the adapter settings allow unnamed channels
        return AgentConnectionsConfiguration::current()->getAdapterSettings((string)$agentConnection->provider)->answerInUnnamedChannels;
    }

    /** The inbound events of one author on this connection in the last hour (flood and loop protection) */
    protected function countRunsOfAuthorInLastHour(AgentConnection $agentConnection, string $externalUserId): int
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionInboundEvents::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->select("COUNT($alias.id)")
                ->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', (int)$agentConnection->id)
                ->andWhere("$alias.stateText = :stateText")->setParameter('stateText', mb_substr('author ' . $externalUserId, 0, 255))
                ->andWhere("$alias.created >= :since")->setParameter('since', (new DateTime())->modify('-1 hour'));
            return (int)$queryBuilder->getQuery()->getSingleScalarResult();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** HOST HOOK. The one account whose verified e-mail this is; null when none or several. Default: none (link by hand). */
    public function findAccountIdByVerifiedEmail(string $verifiedEmail): ?int
    {
        return null;
    }

    /** HOST HOOK. Whether the account holds an account right of the application. Default: no. */
    public function accountHoldsRight(int $accountId, string $rightName): bool
    {
        return false;
    }

    /** HOST HOOK. The space a new conversation from a chat thread works in. Default: none (account level). */
    public function findAgentSpaceForInboundConversation(Account $account, AgentConnection $agentConnection, string $channelId): ?AgentSpace
    {
        return null;
    }

    protected function findAccount(int $accountId): ?Account
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AccountsService $accountsService */
            $accountsService = Account::getService();
            $account = $accountsService->find($accountId, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $account instanceof Account ? $account : null;
    }

    protected function postLine(AgentConnection $agentConnection, AgentConnectionInbound $inbound, string $line): void
    {
        $chatPost = AgentConnectionChatPost::newInstance();
        $chatPost->channelId = (string)$inbound->channelId;
        $chatPost->threadId = $inbound->threadId;
        $chatPost->text = $line;
        $this->postChat($agentConnection, $chatPost);
    }

    protected function postChat(AgentConnection $agentConnection, AgentConnectionChatPost $chatPost): void
    {
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $chatAdapter = $agentConnectionAdaptersService->getAdapterOfConnection($agentConnection);
        if ($chatAdapter instanceof ChatAgentConnectionAdapter) {
            $chatAdapter->postChatMessage($chatPost);
        }
    }

    protected function persist(Entity $entity): void
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $entity->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }
}
