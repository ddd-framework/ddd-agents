<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\TurnEvents;

use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvents;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AIConversationTurnEvents find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationTurnEvents extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversationTurnEvent::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationTurnEvents::class;

    /** The events of one conversation — optionally of one turn (by initiator index) — oldest first, bounded. */
    public function findForConversation(int $conversationId, ?int $initiatorMessageIndex = null, int $limit = 500): ?AIConversationTurnEvents
    {
        $alias = self::getBaseModelAlias();
        $queryBuilder = self::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.aiConversationId = :conversationId")
            ->setParameter('conversationId', $conversationId)
            ->addOrderBy("$alias.id", 'ASC')
            ->setMaxResults($limit);
        if ($initiatorMessageIndex !== null) {
            $queryBuilder->andWhere("$alias.initiatorMessageIndex = :initiatorMessageIndex")->setParameter('initiatorMessageIndex', $initiatorMessageIndex);
        }
        return $this->find($queryBuilder);
    }
}
