<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Entities\Connections\AgentConnectionThreads;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionThreadModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatPost;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionThread;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Listeners\Conversations\AIConversationSettledOwnerTurnListener;
use DDD\Domain\AI\Services\Connections\Adapters\ChatAgentConnectionAdapter;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The answer goes where the message was written: after a settled turn of a conversation whose
 * thread owes an answer, the agent's new text messages are posted into that thread, in order, once. A turn started
 * from the application's own chat leaves the thread alone ({@see AgentConnectionThread::$answerOwed} is set only by an
 * inbound message). Widgets cannot be shown in a chat: the text is posted as it is.
 */
class AgentConnectionThreadRepliesService implements AIConversationSettledOwnerTurnListener
{
    public const int MAXIMUM_POST_CHARACTERS = 3900;

    public function afterSettledOwnerTurn(AIConversation $conversation): void
    {
        // the thread table exists only while AgentConnections is switched on
        if (!isset($conversation->id) || !AgentConnectionsConfiguration::current()->isEnabled()) {
            return;
        }
        foreach ($this->findThreadsOwingAnswer((int)$conversation->id)->getElements() as $agentConnectionThread) {
            // one poster per answer: the claim flips answerOwed with a conditional UPDATE; a newer inbound message
            // sets it again afterwards and is answered after its own turn
            if ($this->updateThread('SET thread.answerOwed = :notOwed WHERE thread.id = :threadId AND thread.answerOwed = :owed', ['notOwed' => false, 'owed' => true, 'threadId' => (int)$agentConnectionThread->id]) !== 1) {
                continue;
            }
            $this->postNewAnswers($conversation, $agentConnectionThread);
        }
    }

    /** Posts the new assistant texts of the conversation into the claimed thread; on a failed post the answer stays owed */
    public function postNewAnswers(AIConversation $conversation, AgentConnectionThread $agentConnectionThread): void
    {
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        $agentConnection = $agentConnectionsService->findIgnoringRights((int)$agentConnectionThread->agentConnectionId);
        if (!$agentConnection instanceof AgentConnection || !$agentConnection->isUsable()) {
            $this->markAnswerOwedAgain($agentConnectionThread);
            return;
        }
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $chatAdapter = $agentConnectionAdaptersService->getAdapterOfConnection($agentConnection);
        if (!$chatAdapter instanceof ChatAgentConnectionAdapter) {
            return;
        }
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        $messagesByIndex = [];
        foreach ($aIConversationMessagesService->findNewestMessages($conversation, 40)->getElements() as $message) {
            $messagesByIndex[(int)$message->indexInConversation] = $message;
        }
        ksort($messagesByIndex);
        $postedUpToIndex = $agentConnectionThread->postedUpToMessageIndex;
        foreach ($messagesByIndex as $messageIndex => $message) {
            if ($messageIndex <= $agentConnectionThread->postedUpToMessageIndex || !$message->isAssistantAuthoredText()) {
                continue;
            }
            $text = trim((string)($message->message ?? ''));
            if ($text === '') {
                continue;
            }
            $chatPost = AgentConnectionChatPost::newInstance();
            $chatPost->channelId = (string)$agentConnectionThread->externalChannelId;
            $chatPost->threadId = $agentConnectionThread->externalThreadId;
            $chatPost->text = mb_strlen($text) > static::MAXIMUM_POST_CHARACTERS ? mb_substr($text, 0, static::MAXIMUM_POST_CHARACTERS) . ' […]' : $text;
            try {
                $chatAdapter->postChatMessage($chatPost);
            } catch (Throwable $postFailure) {
                // recorded on the connection; the answer stays owed and is posted after the next settled turn
                $agentConnectionsService->recordProviderError($agentConnection, 'thread reply: ' . $postFailure->getMessage());
                $this->markAnswerOwedAgain($agentConnectionThread);
                break;
            }
            $postedUpToIndex = max($postedUpToIndex, $messageIndex);
        }
        if ($postedUpToIndex > $agentConnectionThread->postedUpToMessageIndex) {
            $this->updateThread(
                'SET thread.postedUpToMessageIndex = :postedUpToIndex WHERE thread.id = :threadId AND thread.postedUpToMessageIndex < :postedUpToIndex',
                ['postedUpToIndex' => $postedUpToIndex, 'threadId' => (int)$agentConnectionThread->id]
            );
            $agentConnectionThread->postedUpToMessageIndex = $postedUpToIndex;
        }
    }

    protected function markAnswerOwedAgain(AgentConnectionThread $agentConnectionThread): void
    {
        $this->updateThread('SET thread.answerOwed = :owed WHERE thread.id = :threadId', ['owed' => true, 'threadId' => (int)$agentConnectionThread->id]);
    }

    protected function updateThread(string $setAndWhereClause, array $parameters): int
    {
        $updateQuery = EntityManagerFactory::getInstance()->createQuery('UPDATE ' . DBAgentConnectionThreadModel::class . ' thread ' . $setAndWhereClause);
        $updateQuery->setParameters($parameters);
        return (int)$updateQuery->execute();
    }

    protected function findThreadsOwingAnswer(int $conversationId): AgentConnectionThreads
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repository = AgentConnectionThreads::getRepoClassInstance();
            $alias = $repository::getBaseModelAlias();
            $queryBuilder = $repository::createQueryBuilder(true);
            $queryBuilder->andWhere("$alias.aiConversationId = :conversationId")->setParameter('conversationId', $conversationId)
                ->andWhere("$alias.answerOwed = :owed")->setParameter('owed', true);
            return $repository->find($queryBuilder, false) ?? AgentConnectionThreads::newInstance();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }
}
