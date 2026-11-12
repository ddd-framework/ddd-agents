<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\TraceEvents;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvents;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AIConversationTraceEvents find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationTraceEvents extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversationTraceEvent::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationTraceEvents::class;

    /**
     * The events of one conversation in time order (ties by provider clock, then id), bounded. The events of the
     * conversation's calls that were written before the call was attached are linked on attach, so one conversation
     * filter covers them.
     */
    public function findForConversation(int $conversationId, int $limit = 5000): ?AIConversationTraceEvents
    {
        $alias = self::getBaseModelAlias();
        $queryBuilder = self::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.aiConversationId = :conversationId")
            ->setParameter('conversationId', $conversationId)
            ->addOrderBy("$alias.occurredAtUnixMs", 'ASC')
            ->addOrderBy("$alias.providerOffsetMs", 'ASC')
            ->addOrderBy("$alias.id", 'ASC')
            ->setMaxResults($limit);

        return $this->find($queryBuilder);
    }

    /**
     * The provenance events (`transcript.sentence_persisted`, `task.started`) of a range of rows — the writer chips
     * of one inspector page. One query per page, on the `(aiConversationId, occurredAtUnixMs)` index.
     */
    public function findProvenanceForMessageIndexRange(int $conversationId, int $fromMessageIndex, int $untilMessageIndex): ?AIConversationTraceEvents
    {
        $alias = self::getBaseModelAlias();
        $queryBuilder = self::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.aiConversationId = :conversationId")
            ->andWhere("$alias.eventType IN (:provenanceEventTypes)")
            ->andWhere("$alias.messageIndex BETWEEN :fromMessageIndex AND :untilMessageIndex")
            ->setParameter('conversationId', $conversationId)
            ->setParameter('provenanceEventTypes', AIConversationTraceEvent::PROVENANCE_EVENT_TYPES)
            ->setParameter('fromMessageIndex', $fromMessageIndex)
            ->setParameter('untilMessageIndex', $untilMessageIndex)
            ->addOrderBy("$alias.id", 'ASC')
            ->setMaxResults(2000);

        return $this->find($queryBuilder);
    }
}
