<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentControlPanelStatistics;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentConversationStat;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentConversationStats;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;
use DDD\Domain\AI\Entities\Conversations\Usage\AIUsageBookedCostsStoredForm;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Common\Entities\Charts\DateValue;
use DDD\Domain\Common\Entities\Charts\DateValueSequence;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;

/**
 * @method AIConversation find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversation extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversation::class;
    public const string BASE_ORM_MODEL = DBAIConversationModel::class;

    /**
     * The account read rights of the conversation's own `accountId` column, through the account-rights service
     * ({@see AgentAccountRightsService::applyAccountReadRights()}: no account → nothing, a full admin → everything,
     * otherwise the account's rows; the application's subclass adds its account hierarchy).
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAccountReadRights($queryBuilder, static::getBaseModelAlias());
    }

    /**
     * The loaded row's overhead costs come from the column's keyed form ({@see AIUsageBookedCostsStoredForm}): the
     * per-scope amounts land in {@see AIUsageBookedCosts::$scopeBookedCosts}.
     */
    public function mapToEntity(bool $useEntityRegistryCache = true, array $initiatorClasses = []): ?DefaultObject
    {
        $aIConversation = parent::mapToEntity($useEntityRegistryCache, $initiatorClasses);
        if ($aIConversation instanceof AIConversation) {
            $this->mapOverheadBookedCostsColumnToEntity($aIConversation);
        }
        return $aIConversation;
    }

    /**
     * Writes the overhead costs in the column's keyed form ({@see AIUsageBookedCostsStoredForm::buildKeyedForm()}),
     * the form the atomic increment of {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::addOverheadCostsToConversation()}
     * adds to. Every other property maps as usual.
     */
    public function mapPropertyToRepository(DefaultObject &$entity, string $propertyName): void
    {
        parent::mapPropertyToRepository($entity, $propertyName);
        if (
            $propertyName === 'overheadBookedCosts'
            && $entity instanceof AIConversation
            && isset($entity->overheadBookedCosts)
        ) {
            $this->ormInstance->overheadBookedCosts = AIUsageBookedCostsStoredForm::buildKeyedForm($entity->overheadBookedCosts);
        }
    }

    /** The overhead costs of the loaded row, read from the column in either stored form; a NULL column stays null. */
    protected function mapOverheadBookedCostsColumnToEntity(AIConversation $aIConversation): void
    {
        if (!isset($this->ormInstance->overheadBookedCosts)) {
            return;
        }
        $overheadBookedCostsColumnValue = $this->ormInstance->overheadBookedCosts;
        if (is_string($overheadBookedCostsColumnValue)) {
            $overheadBookedCostsColumnValue = json_decode($overheadBookedCostsColumnValue);
        }
        if (!is_array($overheadBookedCostsColumnValue) && !is_object($overheadBookedCostsColumnValue)) {
            return;
        }
        $aIConversation->overheadBookedCosts = AIUsageBookedCostsStoredForm::buildBookedCostsFromStoredForm($overheadBookedCostsColumnValue);
    }

    /**
     * Override point: the join and the condition that leave test accounts out of the statistics readers
     * ({@see findConversationCountsPerBucket()}, {@see findActiveAccountCountsPerBucket()},
     * {@see findEarliestConversationDate()}). The statements name the conversation table `conversation`; the account of
     * a conversation is `conversation.accountId`.
     *
     * The package knows no account table, so the default is the empty filter and `$excludeTestUsers` changes nothing.
     * An application that marks test accounts returns its join and condition from its same-path subclass. Keep both
     * tolerant of a conversation without an account (a LEFT JOIN and an `IS NULL OR …` condition): only a row that is
     * proven to belong to a test account may be dropped. An application that runs on PostgreSQL writes its identifiers
     * in backticks, as the statements here do: an unquoted camelCase name is read in lower case there.
     *
     * @return AgentApplicationSqlFilter
     */
    protected function buildTestAccountsExclusionSqlFilter(): AgentApplicationSqlFilter
    {
        return AgentApplicationSqlFilter::none();
    }

    /**
     * Builds the SQL for {@see findConversationCountsPerBucket()}.
     *
     * The bucket key is the bucket's START DATE in both granularities — the calendar day, or the ISO week's
     * Monday — which is exactly what the KPI cube's period buckets carry as `startDate`, so a caller can re-key
     * the result onto its own period keys without a second lookup.
     *
     * BOTH customer-scoping predicates are NULL-TOLERANT on purpose — only a row we can positively prove is
     * out of scope is dropped: an account-less conversation (admin/ephemeral) is neither the excluded account
     * nor a test account, and a bare `accountId <> :excludedAccountId` / an INNER join on an account table would silently
     * drop it. Hence the explicit `IS NULL OR` and the LEFT JOIN.
     *
     * Every column is table-qualified because the optional join makes bare column names ambiguous.
     *
     * Pure: depends only on its arguments, performs no DB access.
     *
     * @param bool $byWeek Bucket by the ISO week's Monday instead of the calendar day.
     * @param bool $filterByExcludedAccount Add the `:excludedAccountId` predicate.
     * @param bool $excludeTestUsers Add the application's test account filter
     * ({@see buildTestAccountsExclusionSqlFilter()}; empty in the package) — what makes this an aggregate CUSTOMER figure.
     */
    protected function buildConversationCountsPerBucketSql(
        bool $byWeek,
        bool $filterByExcludedAccount,
        bool $excludeTestUsers
    ): string {
        $tableName = DBAIConversationModel::getTableName();
        $bucketExpression = EntityManagerFactory::getDialect()->dateBucket('`conversation`.`created`', $byWeek ? 'week' : 'day');
        $excludedAccountFilter = $filterByExcludedAccount
            ? 'AND (`conversation`.`accountId` IS NULL OR `conversation`.`accountId` <> :excludedAccountId)'
            : '';
        $testAccountsFilter = $excludeTestUsers ? $this->buildTestAccountsExclusionSqlFilter() : AgentApplicationSqlFilter::none();
        $testUserJoin = $testAccountsFilter->joinSql;
        $testUserFilter = $testAccountsFilter->getConditionSqlConnectedByAnd();

        // Grouped by the bucket expression itself, not by its alias: a column named like the alias in a table the
        // application joins would otherwise take its place.
        return <<<SQL
            SELECT
                $bucketExpression AS `bucket`,
                COUNT(*) AS `conversationCount`
            FROM `$tableName` `conversation`
            $testUserJoin
            WHERE `conversation`.`created` >= :from
            AND `conversation`.`created` <= :to
            AND `conversation`.`delegatedFromMessageId` IS NULL
            $excludedAccountFilter
            $testUserFilter
            GROUP BY $bucketExpression
            ORDER BY `bucket` ASC
        SQL;
    }

    /**
     * Builds the SQL for {@see findEarliestConversationDate()}: `MIN(created)` under exactly the customer
     * scoping {@see buildConversationCountsPerBucketSql()} applies, so the date it returns is the first
     * bucket that reader could ever have reported a count for.
     *
     * Pure: depends only on its arguments, performs no DB access.
     *
     * @param bool $filterByExcludedAccount Add the `:excludedAccountId` predicate.
     * @param bool $excludeTestUsers Add the application's test account filter ({@see buildTestAccountsExclusionSqlFilter()}).
     */
    protected function buildEarliestConversationDateSql(
        bool $filterByExcludedAccount,
        bool $excludeTestUsers
    ): string {
        $tableName = DBAIConversationModel::getTableName();
        $excludedAccountFilter = $filterByExcludedAccount
            ? 'AND (`conversation`.`accountId` IS NULL OR `conversation`.`accountId` <> :excludedAccountId)'
            : '';
        $testAccountsFilter = $excludeTestUsers ? $this->buildTestAccountsExclusionSqlFilter() : AgentApplicationSqlFilter::none();
        $testUserJoin = $testAccountsFilter->joinSql;
        $testUserFilter = $testAccountsFilter->getConditionSqlConnectedByAnd();

        return <<<SQL
            SELECT MIN(`conversation`.`created`) AS `earliestConversationCreated`
            FROM `$tableName` `conversation`
            $testUserJoin
            WHERE `conversation`.`delegatedFromMessageId` IS NULL
            $excludedAccountFilter
            $testUserFilter
        SQL;
    }

    /**
     * The earliest date the agent conversation records reach back to under the SAME customer scoping as
     * {@see findConversationCountsPerBucket()} — or `null` when no conversation matches at all.
     *
     * WHY THE KPI CUBE NEEDS THIS. The dashboard's agent section composes two sources with DIFFERENT
     * history depth: cost, tokens and operations come from the usage log, which predates the agent,
     * while `activeAccounts` and `conversationsStarted` come from these conversation tables. The two
     * per-bucket readers `GROUP BY bucket`, so a bucket with no rows is OMITTED rather than reported
     * as zero — which makes "the agent served nobody that week" and "these tables do not reach back
     * that far" indistinguishable downstream. Both currently render as a flat `0` next to a
     * five-figure operation count. This date is the fact that separates them.
     *
     * `MIN(conversation.created)` is a sound lower bound for BOTH figures: a user message cannot exist
     * without its owning conversation, so no user message predates the first conversation either.
     *
     * Uncached, like the two per-bucket readers it accompanies — one indexed `MIN()` over a single
     * column, and there is no cached payload here to fork per test-user toggle.
     *
     * @param int|null $excludedAccountId Account id to leave out — see {@see findConversationCountsPerBucket()}.
     * @param bool $excludeTestUsers Leave test accounts out — see {@see findConversationCountsPerBucket()}.
     * @throws DBALException
     */
    public function findEarliestConversationDate(
        ?int $excludedAccountId = null,
        bool $excludeTestUsers = false
    ): ?Date {
        $connection = EntityManagerFactory::getInstance()->getConnection();

        $statement = $connection->prepare(
            $this->buildEarliestConversationDateSql($excludedAccountId !== null, $excludeTestUsers)
        );
        if ($excludedAccountId !== null) {
            $statement->bindValue('excludedAccountId', $excludedAccountId, ParameterType::INTEGER);
        }

        $earliestConversationCreated = $statement->executeQuery()->fetchOne();
        if ($earliestConversationCreated === false || $earliestConversationCreated === null) {
            return null;
        }

        return Date::fromString(substr((string)$earliestConversationCreated, 0, 10));
    }

    /**
     * FLEET-WIDE conversation starts per time bucket for the agent admin control panel, as a chart-ready
     * {@see DateValueSequence}: `COUNT(*)` over the agent conversation table grouped by calendar day or ISO week
     * (`$byWeek` — bucket keyed by the week's Monday). Delegation children are EXCLUDED
     * (`delegatedFromMessageId IS NULL`) — a customer conversation that fans out into 6 sub-agents is still ONE
     * conversation in the volume trend. Buckets ascending.
     *
     * @param int|null $excludedAccountId Account id to leave out (the admin AI usage KPI dashboard passes the
     * internal-provisioning account, which every other customer figure excludes); `null` counts every account,
     * the pre-existing control-panel behaviour.
     * @param bool $excludeTestUsers Leave test accounts out — what turns this fleet-wide figure into a CUSTOMER
     * figure, matching every aggregate reader on the host's AI usage log. Off by
     * default so the control panel's fleet view is unchanged.
     * @throws DBALException
     */
    public function findConversationCountsPerBucket(
        DateTime $from,
        DateTime $to,
        bool $byWeek = false,
        ?int $excludedAccountId = null,
        bool $excludeTestUsers = false
    ): DateValueSequence {
        $connection = EntityManagerFactory::getInstance()->getConnection();

        $statement = $connection->prepare(
            $this->buildConversationCountsPerBucketSql($byWeek, $excludedAccountId !== null, $excludeTestUsers)
        );
        $statement->bindValue('from', $from->format('Y-m-d H:i:s'));
        $statement->bindValue('to', $to->format('Y-m-d H:i:s'));
        if ($excludedAccountId !== null) {
            $statement->bindValue('excludedAccountId', $excludedAccountId, ParameterType::INTEGER);
        }

        $conversationsPerBucket = new DateValueSequence(AgentControlPanelStatistics::METRIC_CONVERSATIONS);
        foreach ($statement->executeQuery()->fetchAllAssociative() as $countRow) {
            $conversationCountDateValue = new DateValue();
            $conversationCountDateValue->x = Date::fromString((string)$countRow['bucket']);
            $conversationCountDateValue->y = (float)$countRow['conversationCount'];
            $conversationsPerBucket->add($conversationCountDateValue);
        }
        return $conversationsPerBucket;
    }

    /**
     * Builds the SQL for {@see findActiveAccountCountsPerBucket()}. Same bucket-key and NULL-tolerant
     * customer-scoping rules as {@see buildConversationCountsPerBucketSql()}, with the account read through the
     * owning conversation (`conversation.accountId`).
     *
     * Pure: depends only on its arguments, performs no DB access.
     *
     * @param bool $byWeek Bucket by the ISO week's Monday instead of the calendar day.
     * @param bool $filterByExcludedAccount Add the `:excludedAccountId` predicate.
     * @param bool $excludeTestUsers Add the application's test account filter ({@see buildTestAccountsExclusionSqlFilter()}).
     */
    protected function buildActiveAccountCountsPerBucketSql(
        bool $byWeek,
        bool $filterByExcludedAccount,
        bool $excludeTestUsers
    ): string {
        $conversationsTableName = DBAIConversationModel::getTableName();
        $messagesTableName = DBAIConversationMessageModel::getTableName();
        $bucketExpression = EntityManagerFactory::getDialect()->dateBucket('`message`.`created`', $byWeek ? 'week' : 'day');
        $excludedAccountFilter = $filterByExcludedAccount
            ? 'AND (`conversation`.`accountId` IS NULL OR `conversation`.`accountId` <> :excludedAccountId)'
            : '';
        $testAccountsFilter = $excludeTestUsers ? $this->buildTestAccountsExclusionSqlFilter() : AgentApplicationSqlFilter::none();
        $testUserJoin = $testAccountsFilter->joinSql;
        $testUserFilter = $testAccountsFilter->getConditionSqlConnectedByAnd();

        return <<<SQL
            SELECT
                $bucketExpression AS `bucket`,
                COUNT(DISTINCT `conversation`.`accountId`) AS `activeAccountCount`
            FROM `$messagesTableName` `message`
            JOIN `$conversationsTableName` `conversation` ON `conversation`.`id` = `message`.`aiConversationId`
            $testUserJoin
            WHERE `message`.`created` >= :from
            AND `message`.`created` <= :to
            AND `message`.`authorType` = :authorType
            $excludedAccountFilter
            $testUserFilter
            GROUP BY $bucketExpression
            ORDER BY `bucket` ASC
        SQL;
    }

    /**
     * ACTIVE accounts per time bucket for the agent admin control panel, as a chart-ready
     * {@see DateValueSequence} — the agreed active-user definition: an ACCOUNT that SENT at least one user message
     * in the bucket (`authorType = 'USER'` on the message, account via the owning conversation).
     * `COUNT(DISTINCT accountId)` per calendar day, or per ISO week (`$byWeek`, keyed by the week's Monday) — the
     * weekly number is a REAL distinct count over the week, never a sum of daily counts. Buckets ascending.
     *
     * @param int|null $excludedAccountId Account id to leave out — see {@see findConversationCountsPerBucket()}.
     * @param bool $excludeTestUsers Leave test accounts out — see {@see findConversationCountsPerBucket()}.
     * @throws DBALException
     */
    public function findActiveAccountCountsPerBucket(
        DateTime $from,
        DateTime $to,
        bool $byWeek = false,
        ?int $excludedAccountId = null,
        bool $excludeTestUsers = false
    ): DateValueSequence {
        $connection = EntityManagerFactory::getInstance()->getConnection();

        $statement = $connection->prepare(
            $this->buildActiveAccountCountsPerBucketSql($byWeek, $excludedAccountId !== null, $excludeTestUsers)
        );
        $statement->bindValue('from', $from->format('Y-m-d H:i:s'));
        $statement->bindValue('to', $to->format('Y-m-d H:i:s'));
        $statement->bindValue('authorType', AIConversationMessage::AUTHOR_USER);
        if ($excludedAccountId !== null) {
            $statement->bindValue('excludedAccountId', $excludedAccountId, ParameterType::INTEGER);
        }

        $activeAccountsPerBucket = new DateValueSequence(AgentControlPanelStatistics::METRIC_ACTIVE_ACCOUNTS);
        foreach ($statement->executeQuery()->fetchAllAssociative() as $activeAccountsRow) {
            $activeAccountCountDateValue = new DateValue();
            $activeAccountCountDateValue->x = Date::fromString((string)$activeAccountsRow['bucket']);
            $activeAccountCountDateValue->y = (float)$activeAccountsRow['activeAccountCount'];
            $activeAccountsPerBucket->add($activeAccountCountDateValue);
        }
        return $activeAccountsPerBucket;
    }

    /**
     * The TOP conversations of a range for the agent admin control panel, as the
     * {@see AgentConversationStats} set — per ROOT conversation the whole TREE's totals (delegation children folded
     * in via `COALESCE(rootConversationId, id)`, so a fork-join's spend lands on the customer conversation that
     * caused it): message count, tokens, cost. Ordered by one of the whitelisted columns (default: costliest
     * first), limited (default 50) — the "which conversations are expensive/long" breakdown, each row linkable to
     * the admin inspector.
     *
     * @param string $orderBy 'totalCostInUSD' | 'totalTokens' | 'messageCount' (whitelisted; anything else falls back to cost)
     * @throws DBALException
     */
    public function findTopConversationStats(DateTime $from, DateTime $to, string $orderBy = 'totalCostInUSD', int $limit = 50): AgentConversationStats
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $conversationsTableName = DBAIConversationModel::getTableName();
        $messagesTableName = DBAIConversationMessageModel::getTableName();

        // Whitelist — $orderBy is interpolated into SQL, so it must never pass through unchecked.
        $orderByColumn = in_array($orderBy, ['totalCostInUSD', 'totalTokens', 'messageCount'], true)
            ? $orderBy
            : 'totalCostInUSD';
        $limit = max(1, min(200, $limit));
        $messageCostInUSDSql = DBAIConversationMessage::buildCostInUSDIncludingToolCallExecutionSql('message');

        // A tree = the root, its delegation children and its supervisor conversation (supervisedConversationId). The
        // overhead costs (AIConversation::$overheadCostsInUSD — decision calls, summary, compaction, …) are added per
        // tree OUTSIDE the message join, which would repeat a conversation's overhead once per message row — and only
        // for the ranked rows: the inner query ranks and limits by the message cost (overhead is ~5 % of it), so the
        // per-tree overhead lookup runs at most $limit times instead of once per tree of the range.
        $innerOrderByColumn = $orderByColumn === 'totalCostInUSD' ? 'messageCostInUSD' : $orderByColumn;
        // The overhead of a tree is read with three lookups, each over one index: one OR over the three columns is
        // about five times slower per row. The inner query groups by the root row itself — the join condition makes
        // its id the tree's id — because the name `rootConversationId` is also a column of both joined tables.
        $sql = <<<SQL
            SELECT `tree`.*,
                `tree`.`messageCostInUSD`
                    + COALESCE((SELECT `rootOfTree`.`overheadCostsInUSD` FROM `$conversationsTableName` `rootOfTree` WHERE `rootOfTree`.`id` = `tree`.`rootConversationId`), 0)
                    + COALESCE((SELECT SUM(`childOfTree`.`overheadCostsInUSD`) FROM `$conversationsTableName` `childOfTree` WHERE `childOfTree`.`rootConversationId` = `tree`.`rootConversationId`), 0)
                    + COALESCE((SELECT SUM(`supervisorOfTree`.`overheadCostsInUSD`) FROM `$conversationsTableName` `supervisorOfTree` WHERE `supervisorOfTree`.`supervisedConversationId` = `tree`.`rootConversationId`), 0)
                    AS `totalCostInUSD`
            FROM (
                SELECT
                    `root`.`id` AS `rootConversationId`,
                    `root`.`accountId` AS `accountId`,
                    `root`.`agentSpaceId` AS `agentSpaceId`,
                    `root`.`created` AS `rootCreated`,
                    COUNT(`message`.`id`) AS `messageCount`,
                    SUM(COALESCE(`message`.`inputTokens`, 0) + COALESCE(`message`.`outputTokens`, 0)) AS `totalTokens`,
                    SUM($messageCostInUSDSql) AS `messageCostInUSD`
                FROM `$messagesTableName` `message`
                JOIN `$conversationsTableName` `conversation` ON `conversation`.`id` = `message`.`aiConversationId`
                JOIN `$conversationsTableName` `root` ON `root`.`id` = COALESCE(`conversation`.`rootConversationId`, `conversation`.`supervisedConversationId`, `conversation`.`id`)
                WHERE `message`.`created` >= :from
                AND `message`.`created` <= :to
                GROUP BY `root`.`id`, `root`.`accountId`, `root`.`agentSpaceId`, `root`.`created`
                ORDER BY `$innerOrderByColumn` DESC
                LIMIT $limit
            ) `tree`
            ORDER BY `$orderByColumn` DESC
        SQL;

        $statement = $connection->prepare($sql);
        $statement->bindValue('from', $from->format('Y-m-d H:i:s'));
        $statement->bindValue('to', $to->format('Y-m-d H:i:s'));

        $agentConversationStats = AgentConversationStats::newInstance();
        foreach ($statement->executeQuery()->fetchAllAssociative() as $conversationStatRow) {
            $agentConversationStat = AgentConversationStat::newInstance();
            $agentConversationStat->conversationId = (int)$conversationStatRow['rootConversationId'];
            $agentConversationStat->accountId = (int)$conversationStatRow['accountId'];
            $agentConversationStat->agentSpaceId = $conversationStatRow['agentSpaceId'] !== null ? (int)$conversationStatRow['agentSpaceId'] : null;
            $agentConversationStat->messageCount = (int)$conversationStatRow['messageCount'];
            $agentConversationStat->totalTokens = (int)$conversationStatRow['totalTokens'];
            $agentConversationStat->totalCostInUSD = round((float)$conversationStatRow['totalCostInUSD'], 4);
            $agentConversationStat->created = $conversationStatRow['rootCreated'] !== null
                ? new DateTime((string)$conversationStatRow['rootCreated'])
                : null;
            $agentConversationStats->add($agentConversationStat);
        }
        return $agentConversationStats;
    }

    /**
     * DISTINCT active accounts over ONE window for the agent admin control panel (the WAU/MAU number): accounts that
     * sent at least one user message between `$from` and `$to` (same active-user definition as
     * {@see self::findActiveAccountCountsPerDay()}).
     *
     * @throws DBALException
     */
    public function countDistinctActiveAccounts(DateTime $from, DateTime $to): int
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $conversationsTableName = DBAIConversationModel::getTableName();
        $messagesTableName = DBAIConversationMessageModel::getTableName();

        $sql = <<<SQL
            SELECT COUNT(DISTINCT `conversation`.`accountId`)
            FROM `$messagesTableName` `message`
            JOIN `$conversationsTableName` `conversation` ON `conversation`.`id` = `message`.`aiConversationId`
            WHERE `message`.`created` >= :from
            AND `message`.`created` <= :to
            AND `message`.`authorType` = :authorType
        SQL;

        $statement = $connection->prepare($sql);
        $statement->bindValue('from', $from->format('Y-m-d H:i:s'));
        $statement->bindValue('to', $to->format('Y-m-d H:i:s'));
        $statement->bindValue('authorType', AIConversationMessage::AUTHOR_USER);

        return (int)$statement->executeQuery()->fetchOne();
    }
}