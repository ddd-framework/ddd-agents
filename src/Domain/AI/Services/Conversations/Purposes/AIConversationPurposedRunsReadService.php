<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Purposes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposeCriteriaReview;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRun;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRuns;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRunsFilter;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRunStallReason;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRunStoredPlan;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRunsWindowSqlCondition;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DBALException;
use Throwable;

/**
 * Admin population reads of purposed runs: `findPurposedRuns` (a page of runs with their stall verdict,
 * plan progress and open leaves) and `reviewPurposeCriteria` (per done-criterion key the done / open / blocked / not
 * applicable count across a purpose's runs). Read only, raw SQL over the indexed columns: the window runs on the index
 * (purposeName, created); the heavy working-state column is read ONLY for the rows of the page (just its `taskPlan`
 * and `environment` keys), never for the whole window. Purposed ROOT runs only (`delegatedFromMessageId` NULL).
 * Authorization is the caller's: every row of every account is read.
 */
class AIConversationPurposedRunsReadService extends Service
{
    /** The most runs `reviewPurposeCriteria` reads plans of (the newest ones); about 40 KB of plan per run. */
    public const int MAX_REVIEWED_RUNS = 600;

    /** Plans read per statement. */
    public const int PLAN_READ_CHUNK = 50;

    /** @var array<string, TaskPlanDoneCriteria|null> the criterion registries read in this process, by purpose name (purposes are config files) */
    protected array $doneCriteriaByPurposeName = [];

    /**
     * One page of purposed runs, newest first, with the total and the scope.
     *
     * @throws DBALException
     */
    public function findPurposedRuns(AIConversationPurposedRunsFilter $filter, ?DateTime $now = null): AIConversationPurposedRuns
    {
        $now ??= new DateTime();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $windowSqlCondition = $this->buildWindowSqlCondition($filter, $now, true);
        $stallReasonSql = $this->buildStallReasonSql();
        $tableName = DBAIConversationModel::getTableName();

        $purposedRuns = AIConversationPurposedRuns::newInstance();
        $purposedRuns->scope = $filter->describeScope();
        $purposedRuns->skip = $filter->skip;
        $purposedRuns->top = $filter->top;
        $purposedRuns->totalCount = (int)$connection->fetchOne("SELECT COUNT(*) FROM `{$tableName}` `c` WHERE {$windowSqlCondition->conditionSql}", $windowSqlCondition->parameters, $windowSqlCondition->parameterTypes);
        if ($purposedRuns->totalCount === 0 || $filter->skip >= $purposedRuns->totalCount) {
            return $purposedRuns;
        }
        $runRows = $connection->fetchAllAssociative(
            'SELECT `c`.`id`, `c`.`accountId`, `c`.`agentSpaceId`, `c`.`purposeName`, `c`.`runState`, `c`.`initiatorType`, `c`.`created`,'
            . ' `c`.`runHeartbeatAt`, `c`.`lastInboundMessageAt`, `c`.`resumeAttempts`, `c`.`agentTurnOwedAt`, `c`.`taskPlanOpenAt`,'
            . ' `c`.`reportOwedAt`, `c`.`reportDeliveredAt`, `c`.`suppressionReason`,'
            . " {$stallReasonSql} AS `stalledReason`, " . $this->buildIsEvalRunSql() . ' AS `isEvalRun`'
            . " FROM `{$tableName}` `c` WHERE {$windowSqlCondition->conditionSql} ORDER BY `c`.`created` DESC, `c`.`id` DESC LIMIT {$filter->top} OFFSET {$filter->skip}",
            $windowSqlCondition->parameters,
            $windowSqlCondition->parameterTypes
        );
        foreach ($runRows as $runRow) {
            $purposedRun = AIConversationPurposedRun::fromRow($runRow);
            $purposedRuns->add($purposedRun);
        }
        $this->applyTaskPlansOfRuns($purposedRuns);
        $this->applyTreeCostsOfRuns($purposedRuns);
        return $purposedRuns;
    }

    /**
     * Per done-criterion key the state across the runs of ONE purpose in the window (the newest
     * {@see MAX_REVIEWED_RUNS} runs). The registry of the purpose decides which keys gate.
     *
     * @throws BadRequestException
     * @throws DBALException
     */
    public function reviewPurposeCriteria(AIConversationPurposedRunsFilter $filter, ?DateTime $now = null): AIConversationPurposeCriteriaReview
    {
        if (count($filter->purposeNames) !== 1) {
            throw new BadRequestException('reviewPurposeCriteria reads exactly one purpose.');
        }
        $now ??= new DateTime();
        $purposeName = $filter->purposeNames[0];
        $taskPlanDoneCriteria = $this->findDoneCriteriaOfPurpose($purposeName);
        if ($taskPlanDoneCriteria === null) {
            throw new BadRequestException("Unknown purpose '{$purposeName}'.");
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $windowSqlCondition = $this->buildWindowSqlCondition($filter, $now, false);
        $tableName = DBAIConversationModel::getTableName();

        $criteriaReview = new AIConversationPurposeCriteriaReview();
        $criteriaReview->purposeName = $purposeName;
        $criteriaReview->scope = $filter->describeScope();
        $criteriaReview->registeredCriterionKeys = $taskPlanDoneCriteria->getKeys();
        $criteriaReview->totalRunCount = (int)$connection->fetchOne("SELECT COUNT(*) FROM `{$tableName}` `c` WHERE {$windowSqlCondition->conditionSql}", $windowSqlCondition->parameters, $windowSqlCondition->parameterTypes);
        $runIds = array_map('intval', $connection->fetchFirstColumn(
            "SELECT `c`.`id` FROM `{$tableName}` `c` WHERE {$windowSqlCondition->conditionSql} ORDER BY `c`.`created` DESC, `c`.`id` DESC LIMIT " . self::MAX_REVIEWED_RUNS,
            $windowSqlCondition->parameters,
            $windowSqlCondition->parameterTypes
        ));
        $criteriaReview->reviewCapReached = $criteriaReview->totalRunCount > count($runIds);
        foreach (array_chunk($runIds, self::PLAN_READ_CHUNK) as $runIdsChunk) {
            foreach ($this->findStoredPlansByConversationIds($runIdsChunk) as $conversationId => $storedPlan) {
                $criteriaReview->addTaskPlanOfRun($conversationId, $this->buildTaskPlanFromJson($storedPlan->taskPlanJson), $taskPlanDoneCriteria);
            }
        }
        return $criteriaReview;
    }

    /**
     * The WHERE clause of the window with its parameters: purposed roots, the purposes, the creation window, the
     * optional states / scope (account, space — each null = no filter by it), eval runs left out unless asked, and —
     * for a page read — `onlyStalled`.
     *
     * @param AIConversationPurposedRunsFilter $filter
     * @param DateTime $now
     * @param bool $withStallFilter
     * @return AIConversationPurposedRunsWindowSqlCondition
     */
    protected function buildWindowSqlCondition(AIConversationPurposedRunsFilter $filter, DateTime $now, bool $withStallFilter): AIConversationPurposedRunsWindowSqlCondition
    {
        $conditions = [
            '`c`.`delegatedFromMessageId` IS NULL',
            '`c`.`created` >= :createdFrom',
            '`c`.`created` < :createdTo',
        ];
        $parameters = [
            'createdFrom' => $filter->createdFrom?->format('Y-m-d H:i:s'),
            'createdTo' => $filter->createdTo?->format('Y-m-d H:i:s'),
        ];
        $parameterTypes = [];
        if ($filter->purposeNames !== []) {
            $conditions[] = '`c`.`purposeName` IN (:purposeNames)';
            $parameters['purposeNames'] = $filter->purposeNames;
            $parameterTypes['purposeNames'] = ArrayParameterType::STRING;
        } else {
            $conditions[] = '`c`.`purposeName` IS NOT NULL';
        }
        if ($filter->runStates !== []) {
            $conditions[] = '`c`.`runState` IN (:runStates)';
            $parameters['runStates'] = $filter->runStates;
            $parameterTypes['runStates'] = ArrayParameterType::STRING;
        }
        if ($filter->accountId !== null) {
            $conditions[] = '`c`.`accountId` = :accountId';
            $parameters['accountId'] = $filter->accountId;
        }
        // an admin read: without a space id the window is NOT filtered by space (every space and the account-level
        // runs) — the condition is only built for a named space, never for null (which would select the account level)
        if ($filter->agentSpaceId !== null) {
            $agentSpaceSqlCondition = AgentSpaceSqlCondition::forAgentSpaceId($filter->agentSpaceId);
            $conditions[] = $agentSpaceSqlCondition->getConditionSql('c');
            $parameters += $agentSpaceSqlCondition->getParameters();
        }
        if (!$filter->includeEvalRuns) {
            $conditions[] = DBAIConversations::buildNotAnEvalRunPredicate('c');
        }
        // the stall parameters are bound on every read: the page query selects the reason, the count may filter on it
        $parameters += $this->buildStallReasonParameters($filter, $now);
        $parameterTypes['nonTerminalRunStates'] = ArrayParameterType::STRING;
        $parameterTypes['textOrSignalTypes'] = ArrayParameterType::STRING;
        if ($withStallFilter && $filter->onlyStalled) {
            $conditions[] = $this->buildStallReasonSql() . ' IS NOT NULL';
        }
        return AIConversationPurposedRunsWindowSqlCondition::forConditionsAndParameters($conditions, $parameters, $parameterTypes);
    }

    /**
     * The stall rule of {@see AIConversationPurposedRunStallReason} as one SQL CASE over the row aliased `c` — the
     * first reason that applies wins. The open-plan reasons read the role of the run's newest TEXT / SIGNAL row (an
     * agent's text = the agent spoke last).
     */
    protected function buildStallReasonSql(): string
    {
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $quietSql = '`c`.`runState` = :idleRunState AND `c`.`taskPlanOpenAt` IS NOT NULL'
            . ' AND GREATEST(COALESCE(`c`.`runHeartbeatAt`, `c`.`created`), COALESCE(`c`.`lastInboundMessageAt`, `c`.`created`)) < :openPlanQuietBefore';
        $newestTextRoleSql = "(SELECT `newestTextOrSignal`.`role` FROM `{$messagesTable}` `newestTextOrSignal`"
            . ' WHERE `newestTextOrSignal`.`aiConversationId` = `c`.`id` AND `newestTextOrSignal`.`messageType` IN (:textOrSignalTypes)'
            . ' AND `newestTextOrSignal`.`truncatedAt` IS NULL ORDER BY `newestTextOrSignal`.`indexInConversation` DESC LIMIT 1)';
        return 'CASE'
            . " WHEN `c`.`runState` = :failedRunState THEN '" . AIConversationPurposedRunStallReason::FAILED . "'"
            . ' WHEN (`c`.`runState` = :runningRunState AND `c`.`runHeartbeatAt` < :runningHungBefore)'
            . " OR (`c`.`runState` = :awaitingRunState AND `c`.`runHeartbeatAt` < :awaitingStaleBefore) THEN '" . AIConversationPurposedRunStallReason::HUNG . "'"
            . ' WHEN `c`.`runState` = :idleRunState AND `c`.`agentTurnOwedAt` IS NOT NULL AND `c`.`agentTurnOwedAt` < :turnOwedBefore'
            . " THEN '" . AIConversationPurposedRunStallReason::TURN_OWED . "'"
            . ' WHEN `c`.`reportOwedAt` IS NOT NULL AND `c`.`reportDeliveredAt` IS NULL AND (`c`.`runState` IS NULL OR `c`.`runState` NOT IN (:nonTerminalRunStates))'
            . " THEN '" . AIConversationPurposedRunStallReason::REPORT_OWED . "'"
            . " WHEN {$quietSql} AND COALESCE({$newestTextRoleSql}, '') <> :assistantRole THEN '" . AIConversationPurposedRunStallReason::OPEN_PLAN_UNANSWERED . "'"
            . " WHEN {$quietSql} THEN '" . AIConversationPurposedRunStallReason::OPEN_PLAN_OWNER_AWAY . "'"
            . ' ELSE NULL END';
    }

    /**
     * The thresholds of the stall rule — the recovery machinery's own (the reaper's hung and fold thresholds, the
     * owed-turn quiet window) plus the filter's quiet window for the open-plan reasons.
     *
     * @return array<string, mixed>
     */
    protected function buildStallReasonParameters(AIConversationPurposedRunsFilter $filter, DateTime $now): array
    {
        return [
            'failedRunState' => AIConversationRunState::FAILED,
            'runningRunState' => AIConversationRunState::RUNNING,
            'awaitingRunState' => AIConversationRunState::AWAITING_DELEGATES,
            'idleRunState' => AIConversationRunState::IDLE,
            'runningHungBefore' => $this->formatMomentSecondsBefore($now, AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS),
            'awaitingStaleBefore' => $this->formatMomentSecondsBefore($now, AIConversationsService::FOLD_OWED_STALE_AFTER_SECONDS),
            'turnOwedBefore' => $this->formatMomentSecondsBefore($now, AIConversationsService::AGENT_TURN_OWED_QUIET_SECONDS),
            'openPlanQuietBefore' => $this->formatMomentSecondsBefore($now, $filter->quietMinutes * 60),
            'nonTerminalRunStates' => AIConversationRunState::NON_TERMINAL,
            'textOrSignalTypes' => [AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::MESSAGE_SIGNAL],
            'assistantRole' => AIConversationMessage::ROLE_ASSISTANT,
        ];
    }

    /** The SQL moment `$seconds` before `$now`. */
    protected function formatMomentSecondsBefore(DateTime $now, int $seconds): string
    {
        return (clone $now)->modify('-' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }

    /** 1 when the run's settings carry `evalRun` = true, else 0 (also for a JSON null or a missing key). */
    protected function buildIsEvalRunSql(): string
    {
        return 'CASE WHEN ' . EntityManagerFactory::getDialect()->jsonFieldText('`c`.`runSettings`', 'evalRun') . " = 'true' THEN 1 ELSE 0 END";
    }

    /** Reads the plans of the page's runs (one statement) and applies them with each purpose's criterion registry. */
    protected function applyTaskPlansOfRuns(AIConversationPurposedRuns $purposedRuns): void
    {
        $conversationIds = array_map(static fn (AIConversationPurposedRun $purposedRun): int => $purposedRun->aiConversationId, $purposedRuns->getElements());
        $storedPlansByConversationId = $this->findStoredPlansByConversationIds($conversationIds);
        foreach ($purposedRuns->getElements() as $purposedRun) {
            $storedPlan = $storedPlansByConversationId[$purposedRun->aiConversationId] ?? null;
            $purposedRun->environment = $storedPlan?->environment;
            $purposedRun->applyTaskPlan(
                $this->buildTaskPlanFromJson($storedPlan?->taskPlanJson),
                $purposedRun->purposeName !== null ? $this->findDoneCriteriaOfPurpose($purposedRun->purposeName) : null
            );
        }
    }

    /** The tree cost of the page's runs (one statement). */
    protected function applyTreeCostsOfRuns(AIConversationPurposedRuns $purposedRuns): void
    {
        $conversationIds = array_map(static fn (AIConversationPurposedRun $purposedRun): int => $purposedRun->aiConversationId, $purposedRuns->getElements());
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $costInUSDByConversationId = [];
        foreach ($aIConversationsService->sumConversationTreeCostsByRootConversationId($conversationIds)->getElements() as $treeCostSummary) {
            $costInUSDByConversationId[$treeCostSummary->rootConversationId] = $treeCostSummary->costInUSD;
        }
        foreach ($purposedRuns->getElements() as $purposedRun) {
            $purposedRun->treeCostInUSD = $costInUSDByConversationId[$purposedRun->aiConversationId] ?? null;
        }
    }

    /**
     * The stored `taskPlan` (as JSON text) and `environment` of the given conversations — two keys of the working
     * state, never the whole column.
     *
     * @param int[] $conversationIds
     * @return AIConversationPurposedRunStoredPlan[] keyed by conversation id
     * @throws DBALException
     */
    protected function findStoredPlansByConversationIds(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }
        $sqlDialect = EntityManagerFactory::getDialect();
        $storedPlanRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            // a stored JSON null reads as the text `null` on MariaDB and as NULL on PostgreSQL: both mean "none" below
            'SELECT `id`, ' . $sqlDialect->jsonFieldText('`agentWorkingState`', 'taskPlan') . ' AS `taskPlanJson`,'
            . ' ' . $sqlDialect->jsonFieldText('`agentWorkingState`', 'environment') . ' AS `environment`'
            . ' FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` IN (:conversationIds)',
            ['conversationIds' => array_values($conversationIds)],
            ['conversationIds' => ArrayParameterType::INTEGER]
        );
        $storedPlansByConversationId = [];
        foreach ($storedPlanRows as $storedPlanRow) {
            $storedPlan = AIConversationPurposedRunStoredPlan::fromStoredPlanRow($storedPlanRow);
            $storedPlansByConversationId[$storedPlan->conversationId] = $storedPlan;
        }
        return $storedPlansByConversationId;
    }

    /** The plan of a stored `taskPlan` JSON text; null for none, JSON null or an unreadable plan. */
    protected function buildTaskPlanFromJson(?string $taskPlanJson): ?TaskPlan
    {
        if ($taskPlanJson === null || $taskPlanJson === '' || $taskPlanJson === 'null') {
            return null;
        }
        $taskPlanArray = json_decode($taskPlanJson, true);
        if (!is_array($taskPlanArray)) {
            return null;
        }
        try {
            return TaskPlan::fromRepositoryArray($taskPlanArray);
        } catch (Throwable) {
            return null;
        }
    }

    /** The criterion registry of a purpose; null for an unknown purpose. */
    protected function findDoneCriteriaOfPurpose(string $purposeName): ?TaskPlanDoneCriteria
    {
        if (!array_key_exists($purposeName, $this->doneCriteriaByPurposeName)) {
            /** @var AIConversationPurposesService $aIConversationPurposesService */
            $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
            $throwErrorsBefore = $aIConversationPurposesService->disableThrowErrors();
            try {
                $this->doneCriteriaByPurposeName[$purposeName] = $aIConversationPurposesService->getPurposeByName($purposeName)?->getDoneCriteria();
            } catch (Throwable) {
                $this->doneCriteriaByPurposeName[$purposeName] = null;
            } finally {
                $aIConversationPurposesService->restoreThrowErrors($throwErrorsBefore);
            }
        }
        return $this->doneCriteriaByPurposeName[$purposeName];
    }
}
