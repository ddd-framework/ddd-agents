<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentToolResponseSample;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentToolResponseSamples;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentToolResponseStat;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentToolResponseStats;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\DBAL\Exception as DBALException;

/**
 * @method AIConversationMessage find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationMessageModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationMessage extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationMessage::class;
    public const string BASE_ORM_MODEL = DBAIConversationMessageModel::class;


    /**
     * Set true to INCLUDE soft-deleted (truncated) messages in reads — used ONLY by
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::truncateFrom} while it persists the
     * marker: update()'s post-write re-find would otherwise apply the truncatedAt filter to the row it just truncated,
     * find null, and crash. Toggled within a try/finally; PHP-FPM serves one request per worker, so it never leaks.
     */
    public static bool $includeTruncated = false;

    /** @var string[] The keys of {@see ToolCall::$executionCosts}' total inside the `toolCall` column, outermost first */
    public const array TOOL_CALL_EXECUTION_COST_JSON_KEYS = ['executionCosts', 'totalCostInUSD'];

    /**
     * The SQL of one row's contribution to its conversation's total cost — the SQL twin of
     * {@see AIConversationMessage::getCostInUSDIncludingToolCallExecution()}: the row's `costInUSD` plus the costs its
     * tool call's execution booked outside the model call (the web search fee, the page readings), read from the
     * `toolCall` JSON. Every raw-SQL reader of a conversation total sums THIS instead of `costInUSD`, so each amount
     * counts once. The execution cost reads NULL for a missing key and for a JSON null on both engines, so a row
     * without a tool call or without execution costs adds 0. The alias and the columns are written in backticks.
     *
     * The alias is written into the statement, so it must be a plain SQL identifier (letters, digits, underscore); any
     * other text is refused.
     *
     * @param string $messageTableAlias the alias of the message table in the caller's statement (`m`, `message`, …)
     * @return string
     * @throws InternalErrorException when the alias is no plain identifier
     */
    public static function buildCostInUSDIncludingToolCallExecutionSql(string $messageTableAlias): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $messageTableAlias) !== 1) {
            throw new InternalErrorException('The message table alias must be a plain SQL identifier.');
        }
        $toolCallExecutionCostSql = static::buildToolCallExecutionCostSql('`' . $messageTableAlias . '`.`toolCall`');
        return '(COALESCE(`' . $messageTableAlias . '`.`costInUSD`, 0) + COALESCE(' . $toolCallExecutionCostSql . ', 0))';
    }

    /**
     * The SQL that reads the execution cost of a row's tool call as a decimal, NULL where the document carries none.
     *
     * This fragment sits inside sums over every row of a conversation and over the rows of many conversations, and a
     * `toolCall` document carries the tool's whole result, so the cost of the read is the number of times the engine
     * parses the document. The dialect's decimal read parses it once per row on both engines.
     *
     * @param string $toolCallColumnSql the quoted `toolCall` column of the caller's statement
     * @return string
     */
    protected static function buildToolCallExecutionCostSql(string $toolCallColumnSql): string
    {
        return EntityManagerFactory::getDialect()->jsonDecimal(
            $toolCallColumnSql,
            JsonPath::fromKeys(...self::TOOL_CALL_EXECUTION_COST_JSON_KEYS),
            16,
            8
        );
    }

    /**
     * A row stored before tool calls carried their effect gets its tool's effect from the code
     * metadata as it is loaded — the row's readers ask the call itself; a stamped row (including a declared "none") keeps
     * the kind it ran with.
     */
    public function mapToEntity(bool $useEntityRegistryCache = true, array $initiatorClasses = []): ?DefaultObject
    {
        $aIConversationMessage = parent::mapToEntity($useEntityRegistryCache, $initiatorClasses);
        if ($aIConversationMessage instanceof AIConversationMessage) {
            static::fillUnstampedToolCallEffect($aIConversationMessage);
        }
        return $aIConversationMessage;
    }

    /** The loaded row's tool call gets its tool's effect when it carries no stamp at all. */
    protected static function fillUnstampedToolCallEffect(AIConversationMessage $aIConversationMessage): void
    {
        $toolCall = isset($aIConversationMessage->toolCall) ? $aIConversationMessage->toolCall : null;
        if ($toolCall instanceof ToolCall && !$toolCall->isEffectStamped()) {
            $toolCall->stampEffect(MCPToolEffectCatalog::getEffectForToolName((string)($toolCall->name ?? '')));
        }
    }

    /**
     * Applies restrictions based on Auth::instance()->getAccount: the truncated filter on every read, then the account
     * read rights of the owning conversation ({@see AgentAccountRightsService::applyAccountReadRights()}).
     * @param DoctrineQueryBuilder $queryBuilder
     * @return bool
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        $baseModelAlias = static::getBaseModelAlias();

        // Exclude soft-deleted (truncated) messages from EVERY read — independent of rights. Admin/eval/CLI bypass
        // rights ($applyRightsRestrictions = false) but must STILL never load a truncated tail, else the agent loop
        // re-drives the pruned messages and the edit/delete-from is a no-op. Added before the rights
        // gate; the method returns true when applied so the expand-merge includes it on nested message loads too.
        // EXCEPT while truncateFrom is persisting the marker (see $includeTruncated): update()'s post-write re-find
        // would otherwise apply this filter to the row it just truncated, find null, and crash.
        $truncatedFilterApplied = false;
        if (!self::$includeTruncated) {
            $queryBuilder->andWhere("$baseModelAlias.truncatedAt is null");
            $truncatedFilterApplied = true;
        }

        if (!self::$applyRightsRestrictions) {
            return $truncatedFilterApplied;
        }

        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAccountReadRights($queryBuilder, $baseModelAlias, 'aiConversation');
    }

    /**
     * FLEET-WIDE tool-response TOTALS per tool over one range for the agent admin control panel, as the
     * {@see AgentToolResponseStats} set: per tool the call count and the sum / average / max of the tool-result
     * character lengths — the "which tool must be optimised" ranking (`toLlmMarkdown` / payload-reduction lever),
     * heaviest total first. Served by the STORED generated columns `virtualCreatedDate` / `virtualToolName` /
     * `virtualToolResultChars` and their covering composite index (index-only aggregation).
     *
     * ⚠️ Throws while the `virtual*` columns are not applied on the database yet — the caller
     * maps that to `AgentControlPanelStatistics::$toolResponseDataAvailable = false` instead of failing the panel.
     *
     * @throws DBALException
     */
    public function findToolResponseStats(DateTime $from, DateTime $to): AgentToolResponseStats
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $tableName = DBAIConversationMessageModel::getTableName();

        // The sum of a tool whose calls all carry no result reads 0, not NULL: a NULL sorts last in a descending
        // order on MariaDB and first on PostgreSQL.
        $sql = <<<SQL
            SELECT
                `virtualToolName` AS `toolName`,
                COUNT(*) AS `callCount`,
                COALESCE(SUM(`virtualToolResultChars`), 0) AS `sumResultChars`,
                AVG(`virtualToolResultChars`) AS `avgResultChars`,
                MAX(`virtualToolResultChars`) AS `maxResultChars`
            FROM `$tableName`
            WHERE `virtualCreatedDate` >= :fromDay
            AND `virtualCreatedDate` <= :toDay
            AND `virtualToolName` IS NOT NULL
            GROUP BY `virtualToolName`
            ORDER BY `sumResultChars` DESC
        SQL;

        $statement = $connection->prepare($sql);
        $statement->bindValue('fromDay', $from->format('Y-m-d'));
        $statement->bindValue('toDay', $to->format('Y-m-d'));

        $agentToolResponseStats = new AgentToolResponseStats();
        foreach ($statement->executeQuery()->fetchAllAssociative() as $toolStatRow) {
            $agentToolResponseStat = new AgentToolResponseStat();
            $agentToolResponseStat->toolName = (string)$toolStatRow['toolName'];
            $agentToolResponseStat->callCount = (int)$toolStatRow['callCount'];
            $agentToolResponseStat->sumResultChars = (int)$toolStatRow['sumResultChars'];
            $agentToolResponseStat->avgResultChars = (int)round((float)$toolStatRow['avgResultChars']);
            $agentToolResponseStat->maxResultChars = (int)$toolStatRow['maxResultChars'];
            $agentToolResponseStats->add($agentToolResponseStat);
        }
        return $agentToolResponseStats;
    }

    /**
     * The drill-down behind ONE row of {@see self::findToolResponseStats()}: every tool-call occurrence of ONE tool
     * over a range as {@see AgentToolResponseSamples} — message id, conversation id, when, result/arguments chars —
     * LARGEST result first, so the fattest real payloads are on top for inspection (the
     * per-tool view of an application's debug console command). Served by the `virtual*` tool-size columns.
     *
     * @throws DBALException
     */
    public function findLargestToolResponseSamples(string $toolName, DateTime $from, DateTime $to, int $limit = 20): AgentToolResponseSamples
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $tableName = DBAIConversationMessageModel::getTableName();
        $limit = max(1, min(500, $limit));

        // A call without a result sorts as 0 characters, so it comes last on both engines (a NULL sorts last in a
        // descending order on MariaDB and first on PostgreSQL).
        $sql = <<<SQL
            SELECT
                `id`,
                `aiConversationId`,
                `created`,
                `virtualToolResultChars` AS `resultChars`,
                COALESCE(`virtualToolArgumentsChars`, 0) AS `argumentsChars`
            FROM `$tableName`
            WHERE `virtualCreatedDate` >= :fromDay
            AND `virtualCreatedDate` <= :toDay
            AND `virtualToolName` = :toolName
            ORDER BY COALESCE(`virtualToolResultChars`, 0) DESC
            LIMIT $limit
        SQL;

        $statement = $connection->prepare($sql);
        $statement->bindValue('fromDay', $from->format('Y-m-d'));
        $statement->bindValue('toDay', $to->format('Y-m-d'));
        $statement->bindValue('toolName', $toolName);

        $agentToolResponseSamples = new AgentToolResponseSamples();
        foreach ($statement->executeQuery()->fetchAllAssociative() as $sampleRow) {
            $agentToolResponseSample = new AgentToolResponseSample();
            $agentToolResponseSample->messageId = (int)$sampleRow['id'];
            $agentToolResponseSample->conversationId = (int)$sampleRow['aiConversationId'];
            $agentToolResponseSample->created = $sampleRow['created'] !== null ? new DateTime((string)$sampleRow['created']) : null;
            $agentToolResponseSample->resultChars = (int)$sampleRow['resultChars'];
            $agentToolResponseSample->argumentsChars = (int)$sampleRow['argumentsChars'];
            $agentToolResponseSamples->add($agentToolResponseSample);
        }
        return $agentToolResponseSamples;
    }
}