<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfig;
use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfigs;
use DDD\Domain\AI\Repo\DB\Conversations\A2A\DBAIConversationTaskPushNotificationConfigModel;
use DDD\Domain\AI\Repo\DB\Conversations\A2A\DBAIConversationTaskPushNotificationConfigs;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Repo-touching tail of the A2A push-notification configs: rights-scoped CRUD reads for the
 * wire methods, the hot-path existence probe for the state-change hook, and the worker-side raw reads for the
 * delivery handler. The delivery DECISION logic (dedup, failure cap) lives ON the entity.
 *
 * @method DBAIConversationTaskPushNotificationConfigs getEntitySetRepoClassInstance()
 */
class AIConversationTaskPushNotificationConfigsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AIConversationTaskPushNotificationConfig::class;

    /**
     * The rights-scoped configs of ONE task (wire List/Get/Delete resolution). Under CUSTOMER/PARTNER auth the
     * rights layer confines this to the acting account's conversations — a foreign task yields an empty set.
     */
    public function findConfigsForTask(int $aiConversationId, int $taskUserMessageId): ?AIConversationTaskPushNotificationConfigs
    {
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $alias = $entitySetRepo::getBaseModelAlias();
        $queryBuilder = $entitySetRepo::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.aiConversationId = :aiConversationId AND $alias.taskUserMessageId = :taskUserMessageId")
            ->setParameter('aiConversationId', $aiConversationId)
            ->setParameter('taskUserMessageId', $taskUserMessageId)
            ->orderBy("$alias.id", 'ASC');
        return $entitySetRepo->find($queryBuilder);
    }

    /**
     * The ENABLED configs of a whole conversation — the delivery worker's read (rights are bypassed by the CALLER,
     * worker-side like the reaper: the worker acts for the system, not for an HTTP caller).
     */
    public function findEnabledConfigsForConversation(int $aiConversationId): ?AIConversationTaskPushNotificationConfigs
    {
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $alias = $entitySetRepo::getBaseModelAlias();
        $queryBuilder = $entitySetRepo::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.aiConversationId = :aiConversationId AND $alias.disabledAt IS NULL")
            ->setParameter('aiConversationId', $aiConversationId);
        return $entitySetRepo->find($queryBuilder);
    }

    /**
     * The HOT-PATH probe: does ANY enabled config exist for this conversation? Runs on every state change of every
     * conversation (the publishChangeSignal seam), so it is a single indexed COUNT over the raw connection — no
     * entity hydration, no rights (the caller is the loop/worker, and the answer only gates a dispatch).
     */
    public function hasEnabledConfigsForConversation(int $aiConversationId): bool
    {
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationTaskPushNotificationConfigModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :aiConversationId AND `disabledAt` IS NULL',
            ['aiConversationId' => $aiConversationId]
        ) > 0;
    }
}
