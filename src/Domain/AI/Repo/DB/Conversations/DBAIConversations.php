<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\AIConversationSubjectBindingsService;
use DDD\Domain\AI\Services\Conversations\Environments\AgentEnvironmentNamesService;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * @method AIConversations find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversations extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversation::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversations::class;

    /**
     * The SQL predicate that keeps a reaper read to the conversations of ONE environment (`$alias` = the conversations
     * table alias, `:environment` bound by the caller) — the stamp a won run claim writes onto `agentWorkingState`.
     * Rows without a stamp were never driven since the stamp exists; only production treats them as its own, so no
     * conversation is orphaned. The alias and the column are written in backticks, so a statement that defines the
     * alias writes it in backticks too.
     */
    public static function buildEnvironmentPredicate(string $alias, string $environment): string
    {
        $environmentStampSql = '(' . EntityManagerFactory::getDialect()->jsonFieldText('`' . $alias . '`.`agentWorkingState`', 'environment') . ')';
        return $environment === AgentEnvironmentNamesService::ENVIRONMENT_PRODUCTION
            ? "({$environmentStampSql} = :environment OR {$environmentStampSql} IS NULL)"
            : "{$environmentStampSql} = :environment";
    }

    /**
     * The SQL predicate that keeps an eval conversation (`runSettings.evalRun` = true, stamped by the eval runner) out of
     * the owed-turn recovery (`$alias` = the conversations table alias): an eval drives its own turns; a lost re-fire is
     * the case's failure, never a turn the cron resumes — on a workspace whose re-fire fails, the failure path re-stamps
     * `agentTurnOwedAt` every tick and the tree never ages out of the eval purge.
     */
    public static function buildNotAnEvalRunPredicate(string $alias): string
    {
        $evalRunStampSql = '(' . EntityManagerFactory::getDialect()->jsonFieldText('`' . $alias . '`.`runSettings`', 'evalRun') . ')';
        return "({$evalRunStampSql} IS NULL OR {$evalRunStampSql} <> 'true')";
    }

    /**
     * Restrict a conversation query to what a CUSTOMER may see — the ONE visibility rule of every customer-facing
     * list: no supervisor tandem, no conversation of a platform-internal purpose
     * ({@see AIConversationPurpose::CUSTOMER_HIDDEN_PURPOSE_NAMES}) and no conversation of such a conversation's
     * delegation tree. Deliberately NOT part of the read rights: the worker that runs a hidden conversation loads it
     * under the customer's rights.
     */
    public static function restrictToCustomerVisibleConversations(DoctrineQueryBuilder $queryBuilder): void
    {
        $alias = static::getBaseModelAlias();
        $modelClass = DBAIConversation::BASE_ORM_MODEL;
        $queryBuilder
            ->andWhere("$alias.supervisedConversationId IS NULL")
            ->andWhere("($alias.purposeName IS NULL OR $alias.purposeName NOT IN (:customerHiddenPurposeNames))")
            ->andWhere(
                "NOT EXISTS (SELECT hiddenRootConversation.id FROM {$modelClass} hiddenRootConversation"
                . " WHERE hiddenRootConversation.id = $alias.rootConversationId"
                . ' AND hiddenRootConversation.purposeName IN (:customerHiddenPurposeNames))'
            )
            ->setParameter('customerHiddenPurposeNames', AIConversationPurpose::getCustomerHiddenPurposeNames());
    }

    /**
     * Restrict a query that joins the conversation row as `$conversationAlias` to the conversations the search across
     * an account's conversations answers from — the owner's dialogue. Left out, inside the query (so their rows are
     * neither read for a distance nor ranked):
     * - a sub-agent's conversation (`delegatedFromMessageId` set): never something the owner talked about, and a child
     *   of an eval run carries no eval mark of its own; what a sub-agent found reached its parent as a notification;
     * - an eval run (`runSettings.evalRun` true) unless it holds the history an eval seeded (`runSettings.evalSeededHistory`);
     * - a conversation whose own purpose or whose root's purpose is in
     *   {@see AIConversationPurpose::getPurposeNamesLeftOutOfTheSearchAcrossConversations()} (the customer-hidden purposes
     *   and the schedule run);
     * - a conversation whose own initiator type or whose root's is in
     *   {@see AIConversation::getInitiatorTypesLeftOutOfTheSearchAcrossConversations()} (a scheduled run).
     * Both lists are the application's, redeclared on its purpose and conversation class.
     */
    public static function restrictToConversationsTheSearchAcrossConversationsAnswersFrom(
        DoctrineQueryBuilder $queryBuilder,
        string $conversationAlias
    ): void {
        $queryBuilder->andWhere("$conversationAlias.delegatedFromMessageId IS NULL");
        $evalRunMark = "JSON_FIELD_TEXT($conversationAlias.runSettings, 'evalRun')";
        $evalSeededHistoryMark = "JSON_FIELD_TEXT($conversationAlias.runSettings, 'evalSeededHistory')";
        $queryBuilder->andWhere("($evalRunMark IS NULL OR $evalRunMark <> 'true' OR $evalSeededHistoryMark = 'true')");

        $rootConditions = [];
        $leftOutPurposeNames = AIConversationPurpose::getPurposeNamesLeftOutOfTheSearchAcrossConversations();
        if ($leftOutPurposeNames !== []) {
            $queryBuilder
                ->andWhere("($conversationAlias.purposeName IS NULL OR $conversationAlias.purposeName NOT IN (:searchLeftOutPurposeNames))")
                ->setParameter('searchLeftOutPurposeNames', $leftOutPurposeNames);
            $rootConditions[] = 'searchLeftOutRootConversation.purposeName IN (:searchLeftOutPurposeNames)';
        }
        $leftOutInitiatorTypes = AIConversation::getInitiatorTypesLeftOutOfTheSearchAcrossConversations();
        if ($leftOutInitiatorTypes !== []) {
            $queryBuilder
                ->andWhere("($conversationAlias.initiatorType IS NULL OR $conversationAlias.initiatorType NOT IN (:searchLeftOutInitiatorTypes))")
                ->setParameter('searchLeftOutInitiatorTypes', $leftOutInitiatorTypes);
            $rootConditions[] = 'searchLeftOutRootConversation.initiatorType IN (:searchLeftOutInitiatorTypes)';
        }
        if ($rootConditions !== []) {
            $modelClass = DBAIConversation::BASE_ORM_MODEL;
            $queryBuilder->andWhere(
                "NOT EXISTS (SELECT searchLeftOutRootConversation.id FROM {$modelClass} searchLeftOutRootConversation"
                . " WHERE searchLeftOutRootConversation.id = $conversationAlias.rootConversationId"
                . ' AND (' . implode(' OR ', $rootConditions) . '))'
            );
        }
    }

    /**
     * Every conversation the authenticated account may read AND a customer may see, under the default query options.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAllVisibleToCustomer(): AIConversations
    {
        $queryBuilder = self::createQueryBuilder(true);
        static::restrictToCustomerVisibleConversations($queryBuilder);
        return $this->find($queryBuilder);
    }

    /**
     * Every conversation about one record ({@see AIConversationSubject}) that the authenticated account may read AND
     * a customer may see, under the default query options (a list read orders it newest first): the free
     * conversations started about the record and the purposed ones started for it, of every space of the account.
     * Empty where the subject table is missing.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAllVisibleToCustomerOfSubject(AIConversationSubject $aIConversationSubject): AIConversations
    {
        /** @var AIConversationSubjectBindingsService $aIConversationSubjectBindingsService */
        $aIConversationSubjectBindingsService = DDDService::instance()->getService(AIConversationSubjectBindingsService::class);
        $queryBuilder = self::createQueryBuilder(true);
        static::restrictToCustomerVisibleConversations($queryBuilder);
        if ($aIConversationSubjectBindingsService->isBindingTableAvailable()) {
            AIConversationSubjectBindingsService::restrictConversationQueryToSubject($queryBuilder, static::getBaseModelAlias(), $aIConversationSubject);
        } else {
            // no table, no subjects: the read answers an empty set and never names the missing table
            $queryBuilder->andWhere(static::getBaseModelAlias() . '.id IS NULL');
        }
        return $this->find($queryBuilder);
    }

    /**
     * Restrict a conversation query to the conversations OF `$agentSpace`. The space fixes the account (a conversation
     * is stored with a space only after {@see AgentSpacesService::requireAgentSpaceUsableByAccount()}), so the query
     * needs no account condition of its own.
     *
     * A read that may be asked WITHOUT a space does not use this method: it goes through
     * {@see self::restrictToConversationsOfAccountInAgentSpacePartition()}, which filters the account in the same
     * statement.
     *
     * @param DoctrineQueryBuilder $queryBuilder a builder on the conversation model (of this repository or of {@see DBAIConversation})
     * @param AgentSpace $agentSpace
     * @param string $parameterName the name of the bound parameter; a query that carries two space conditions gives each its own
     * @return void
     * @throws InternalErrorException
     */
    public static function restrictToConversationsOfAgentSpace(
        DoctrineQueryBuilder $queryBuilder,
        AgentSpace $agentSpace,
        string $parameterName = AgentSpaceSqlCondition::COLUMN_NAME
    ): void {
        static::applyAgentSpaceSqlCondition($queryBuilder, AgentSpaceSqlCondition::forAgentSpace($agentSpace, $parameterName));
    }

    /**
     * Restrict a conversation query to ONE partition of ONE account: the conversations of account `$accountId` in the
     * space of `$agentSpaceSqlCondition`, or — when the condition names no space — the ACCOUNT-LEVEL conversations of
     * that account (`agentSpaceId IS NULL`).
     *
     * The account and the space condition are added TOGETHER, on purpose: a conversation without a space belongs to
     * one account, so a read without a space that did not filter the account would select the account-level
     * conversations of every account. "No space" never selects the conversations of all spaces of the account.
     *
     * @param DoctrineQueryBuilder $queryBuilder a builder on the conversation model (of this repository or of {@see DBAIConversation})
     * @param int $accountId
     * @param AgentSpaceSqlCondition $agentSpaceSqlCondition the rows OF a space ({@see AgentSpaceSqlCondition::forAgentSpace()} / {@see AgentSpaceSqlCondition::forAgentSpaceId()})
     * @param string $accountIdParameterName the name of the bound account parameter
     * @return void
     * @throws InternalErrorException when the condition is of the "or account-wide" mode
     */
    public static function restrictToConversationsOfAccountInAgentSpacePartition(
        DoctrineQueryBuilder $queryBuilder,
        int $accountId,
        AgentSpaceSqlCondition $agentSpaceSqlCondition,
        string $accountIdParameterName = 'accountId'
    ): void {
        $alias = static::getBaseModelAlias();
        $queryBuilder
            ->andWhere("$alias.accountId = :$accountIdParameterName")
            ->setParameter($accountIdParameterName, $accountId);
        static::applyAgentSpaceSqlCondition($queryBuilder, $agentSpaceSqlCondition);
    }

    /**
     * Adds the partition condition of `$agentSpaceSqlCondition` and its bound value to a conversation query.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param AgentSpaceSqlCondition $agentSpaceSqlCondition
     * @return void
     * @throws InternalErrorException when the condition is of the "or account-wide" mode: an empty reference on a
     *                                conversation means account level, never "applies to every space"
     */
    protected static function applyAgentSpaceSqlCondition(
        DoctrineQueryBuilder $queryBuilder,
        AgentSpaceSqlCondition $agentSpaceSqlCondition
    ): void {
        if ($agentSpaceSqlCondition->includesRowsWithoutSpace) {
            throw new InternalErrorException('A conversation read takes the conversations OF a space, never the "or account-wide" condition.');
        }
        $queryBuilder->andWhere($agentSpaceSqlCondition->getConditionDql(static::getBaseModelAlias()));
        foreach ($agentSpaceSqlCondition->getParameters() as $parameterName => $parameterValue) {
            $queryBuilder->setParameter($parameterName, $parameterValue);
        }
    }

    /**
     * The customer-visible conversations of a space, optionally narrowed by a search term. The read filters the
     * space, which fixes the account, under the read rights of the caller.
     *
     * @param AgentSpace $agentSpace
     * @param string|null $searchTerm
     * @param bool $includeUnsummarized When true, also return conversations whose summary is still null (a
     *                                  just-started agentic conversation gets its summary only after the async drive
     *                                  completes). The default keeps the summary-gated AI-Extensions chat-history view.
     *
     * @return AIConversations|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findByAgentSpaceAndSearchTerm(
        AgentSpace $agentSpace,
        ?string $searchTerm = null,
        bool $includeUnsummarized = false,
    ): ?AIConversations
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = self::createQueryBuilder(true);
        static::restrictToConversationsOfAgentSpace($queryBuilder, $agentSpace);

        // CUSTOMER-VISIBLE conversations only — the system-internal rails never surface in the client history
        // (this is the CLIENT list endpoint; the admin lists load without these filters):
        //  - supervisor tandem conversations (purpose Supervisor.Checkpoint; they also carry the observed
        //    conversation's id in supervisedConversationId — filtered on BOTH marks for robustness),
        //  - delegation children (delegatedFromMessageId set — sub-agent conversations of a customer dialog),
        //  - background mechanical children (system-spawned fire-and-forget operations).
        $queryBuilder->andWhere("$alias.delegatedFromMessageId IS NULL");
        static::restrictToCustomerVisibleConversations($queryBuilder);

        if (!$includeUnsummarized) {
            $queryBuilder->andWhere("$alias.summary is not null");
        }

        // the summary is a JSON column: its title and text are read as text (LOWER over json fails on PostgreSQL)
        if ($searchTerm) {
            $searchTermLower = mb_strtolower(trim(urldecode($searchTerm)));
            $queryBuilder
                ->andWhere("(LOWER(JSON_FIELD_TEXT($alias.summary, 'title')) LIKE :search_term OR LOWER(JSON_FIELD_TEXT($alias.summary, 'text')) LIKE :search_term)")
                ->setParameter('search_term', '%' . addcslashes($searchTermLower, '%_\\') . '%');
        }

        return $this->find($queryBuilder);
    }

    /**
     * The conversations of account `$accountId` the agent's conversation recall answers from
     * ({@see \DDD\Domain\AI\Services\Conversations\AgentConversationRecallService}), newest activity first: under the
     * read rights of the caller, visible to a customer, and only the owner's dialogue
     * ({@see self::restrictToConversationsTheSearchAcrossConversationsAnswersFrom()}). Optional narrowing: a title or
     * summary holding ANY of `$words` (case-insensitive), one space, and an activity window (`$from` ≤ updated,
     * created ≤ `$to`).
     *
     * @param int $accountId
     * @param string[] $words lower-case words; empty = no text condition
     * @param int|null $agentSpaceId
     * @param DateTime|null $from
     * @param DateTime|null $to
     * @param int $limit
     * @return AIConversations|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findRecallCandidatesOfAccount(
        int $accountId,
        array $words,
        ?int $agentSpaceId,
        ?DateTime $from,
        ?DateTime $to,
        int $limit
    ): ?AIConversations {
        $alias = static::getBaseModelAlias();
        $queryBuilder = self::createQueryBuilder(true);
        $queryBuilder
            ->andWhere("$alias.accountId = :recallAccountId")
            ->setParameter('recallAccountId', $accountId);
        static::restrictToCustomerVisibleConversations($queryBuilder);
        static::restrictToConversationsTheSearchAcrossConversationsAnswersFrom($queryBuilder, $alias);
        // the summary is a JSON column: its title and text are read as text (LOWER over json fails on PostgreSQL)
        $summaryTitleSql = "LOWER(JSON_FIELD_TEXT($alias.summary, 'title'))";
        $summaryTextSql = "LOWER(JSON_FIELD_TEXT($alias.summary, 'text'))";
        $wordConditions = [];
        foreach (array_values($words) as $wordIndex => $word) {
            $wordConditions[] = "$summaryTitleSql LIKE :recallWord$wordIndex OR $summaryTextSql LIKE :recallWord$wordIndex";
            $queryBuilder->setParameter("recallWord$wordIndex", '%' . addcslashes($word, '%_\\') . '%');
        }
        if ($wordConditions !== []) {
            $queryBuilder->andWhere('(' . implode(' OR ', $wordConditions) . ')');
        }
        if ($agentSpaceId !== null) {
            $queryBuilder->andWhere("$alias.agentSpaceId = :recallAgentSpaceId")->setParameter('recallAgentSpaceId', $agentSpaceId);
        }
        if ($from !== null) {
            $queryBuilder->andWhere("$alias.updated >= :recallFrom")->setParameter('recallFrom', $from->format('Y-m-d H:i:s'));
        }
        if ($to !== null) {
            $queryBuilder->andWhere("$alias.created <= :recallTo")->setParameter('recallTo', $to->format('Y-m-d H:i:s'));
        }
        $queryBuilder->orderBy("$alias.updated", 'DESC')->addOrderBy("$alias.id", 'DESC')->setMaxResults(max(1, $limit));
        return $this->find($queryBuilder);
    }
}
