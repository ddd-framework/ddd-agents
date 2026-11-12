<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Metrics;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetricsTimeIntervals;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\Exception as DBALException;

/**
 * Reads what {@see AIConversationMetrics} is computed from: the root conversation's message rows, the egress sum
 * of its delegation children and the intervals of the delegation batches it forked. Read-only raw SQL — the figures
 * are derived, never stored by this service.
 */
class AIConversationMetricsService extends Service
{
    /** More rows than this are no single run; the read stops there. */
    public const int MAX_ROWS = 4000;

    /**
     * The run metrics of a ROOT conversation; null without rows.
     *
     * @throws DBALException
     */
    public function buildMetricsForConversation(AIConversation $conversation): ?AIConversationMetrics
    {
        return AIConversationMetrics::buildFromRows(
            $this->findMessageRowsForConversation($conversation),
            $this->findDelegationBatchIntervalsForConversation($conversation),
            $this->findChildModelSecondsForConversation($conversation)
        );
    }

    /**
     * The message rows in index order, with every key {@see AIConversationMetrics::buildFromRows()} reads plus the
     * run report's own (`u`, `cost`, `args`, `refusedFlag`, `resultHead`, `variants`, `skillSections`).
     *
     * @return array<int, array<string, mixed>>
     * @throws DBALException
     */
    public function findMessageRowsForConversation(AIConversation $conversation): array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $sqlDialect = EntityManagerFactory::getDialect();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        // a flag is "the key holds JSON true": the text `true` on both engines, a JSON null or a missing key is not
        $refusedByGateSql = $sqlDialect->jsonFieldText('`toolCall`', 'wasRefusedByGate') . " = 'true'";
        $refusedAsDuplicateSql = $sqlDialect->jsonFieldText('`toolCall`', 'wasRefusedAsDuplicate') . " = 'true'";
        return $connection->fetchAllAssociative(
            'SELECT `indexInConversation` AS `i`, `role`, `messageType` AS `t`, `authorType` AS `author`, `signalSource` AS `signal`,'
            . ' `created` AS `c`, `updated` AS `u`,'
            . ' CASE WHEN `requestSnapshot` IS NOT NULL THEN 1 ELSE 0 END AS `snap`,'
            . ' ' . $sqlDialect->jsonDecimal('`toolCall`', JsonPath::fromKeys('durationSeconds'), 10, 3) . ' AS `dur`,'
            . ' ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('executionStartedAt')) . ' AS `startedAt`,'
            . ' ' . $this->buildEgressMillisecondsSql('`requestSnapshot`') . ' AS `egress`, `costInUSD` AS `cost`,'
            . ' `virtualToolName` AS `tool`, ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('arguments')) . ' AS `args`,'
            . ' ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('status')) . ' AS `toolStatus`,'
            . " CASE WHEN {$refusedByGateSql} OR {$refusedAsDuplicateSql} THEN 1 ELSE 0 END AS `refusedFlag`,"
            . " CASE WHEN {$refusedByGateSql} THEN 1 ELSE 0 END AS `gateRefused`, CASE WHEN {$refusedAsDuplicateSql} THEN 1 ELSE 0 END AS `dupRefused`,"
            // 300: the failure kind reads past the executor's prefix ("Execution failed for Tool 'x': …")
            . ' LEFT(' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('result')) . ', 300) AS `resultHead`,'
            . ' ' . $sqlDialect->jsonText('`requestSnapshot`', JsonPath::fromKeys('variants')) . ' AS `variants`,'
            . ' ' . $sqlDialect->jsonText('`requestSnapshot`', JsonPath::fromKeys('skillSections')) . ' AS `skillSections`'
            // a truncated branch (soft-deleted by an edit or a rewind) is no longer part of the conversation
            . " FROM `{$messagesTable}` WHERE `aiConversationId` = :id AND `truncatedAt` IS NULL ORDER BY `indexInConversation` LIMIT " . self::MAX_ROWS,
            ['id' => (int)$conversation->id]
        );
    }

    /**
     * Σ egress of the model calls of every delegation child under this root, seconds.
     *
     * @throws DBALException
     */
    public function findChildModelSecondsForConversation(AIConversation $conversation): float
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $conversationsTable = DBAIConversationModel::getTableName();
        $egressMilliseconds = $connection->fetchOne(
            'SELECT COALESCE(SUM(' . $this->buildEgressMillisecondsSql('`m`.`requestSnapshot`') . '), 0)'
            . " FROM `{$messagesTable}` `m` WHERE `m`.`requestSnapshot` IS NOT NULL"
            . " AND `m`.`aiConversationId` IN (SELECT `c`.`id` FROM `{$conversationsTable}` `c` WHERE `c`.`rootConversationId` = :id)",
            ['id' => (int)$conversation->id]
        );
        return (float)$egressMilliseconds / 1000.0;
    }

    /** The egress milliseconds of one model call (`loopStepOverheadMs.egress` of its request snapshot) as a whole number. */
    protected function buildEgressMillisecondsSql(string $requestSnapshotColumnSql): string
    {
        return EntityManagerFactory::getDialect()->jsonInt($requestSnapshotColumnSql, JsonPath::fromKeys('loopStepOverheadMs', 'egress'));
    }

    /**
     * The delegation batches this conversation forked, each as the interval from its `created` to its `settledAt`
     * (no end while the batch is open).
     *
     * @param AIConversation $conversation
     * @return AIConversationMetricsTimeIntervals
     * @throws DBALException
     */
    public function findDelegationBatchIntervalsForConversation(AIConversation $conversation): AIConversationMetricsTimeIntervals
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $batchesTable = DBAIConversationDelegationBatchModel::getTableName();
        $batchRows = $connection->fetchAllAssociative(
            "SELECT `created`, `settledAt` FROM `{$batchesTable}` WHERE `parentConversationId` = :id",
            ['id' => (int)$conversation->id]
        );
        $delegationBatchIntervals = new AIConversationMetricsTimeIntervals();
        foreach ($batchRows as $batchRow) {
            $createdAt = AIConversationMetrics::toUnixSeconds($batchRow['created'] ?? null);
            if ($createdAt === null) {
                continue;
            }
            $delegationBatchIntervals->addStartToEnd($createdAt, AIConversationMetrics::toUnixSeconds($batchRow['settledAt'] ?? null));
        }
        return $delegationBatchIntervals;
    }
}
