<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use Throwable;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleDueAgentSpace;
use DDD\Domain\AI\Entities\Conversations\Scopes\AgentApplicationSqlFilter;
use DDD\Domain\AI\Entities\Evals\OutboundRecipients\EvalRunOutboundScope;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleOccurrenceSpawn;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedules;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleTransition;
use DDD\Domain\AI\Entities\Conversations\Schedules\RecurringScheduleMonthlyCostEstimate;
use DDD\Domain\AI\Entities\Conversations\Schedules\ScheduleMonthlyCostEstimate;
use DDD\Domain\AI\Entities\Conversations\Schedules\ScheduleBudgetPotStatus;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\AI\Repo\Argus\Conversations\Schedules\ArgusScheduleDoneCriteriaJudgment;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationSchedule;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationSchedules;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationStateReason;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\DBAL\ArrayParameterType;

/**
 * The recurring-task coordinator service. Owns the fire-claim transaction
 * ({@see claimFireOccurrence} — claim-by-UPDATE with compare-and-set, no double fire across workers) and the
 * bounded due-scan; the pure schedule math (next occurrence, failure backoff, expiry) lives on
 * {@see AIConversationSchedule}. The fire COMMAND (a console command of the application)
 * drives: due-scan → claim → guardrails → spawn the run conversation → bookkeeping via {@see recordRunTerminal}.
 *
 * @method AIConversationSchedule|null find(string|int|null $entityId, bool $useEntityRegistrCache = true)
 * @method AIConversationSchedules findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AIConversationSchedule update(AIConversationSchedule $entity)
 * @method DBAIConversationSchedule getEntityRepoClassInstance()
 * @method DBAIConversationSchedules getEntitySetRepoClassInstance()
 */
class AIConversationSchedulesService extends EntitiesService
{
    use AITraceLogTrait;

    public const string DEFAULT_ENTITY_CLASS = AIConversationSchedule::class;

    /** Per-pass candidate cap for the fire cron — bounded like every cron pass. */
    public const int DUE_SCHEDULES_BATCH_LIMIT = 50;

    /**
     * Per-pass candidate cap of the state sweep (the application's command that closes the schedules of spaces that are
     * no longer active).
     */
    public const int STATE_SWEEP_BATCH_LIMIT = 200;

    /** @var string Skip reason: the schedule's previous run conversation is still in progress. */
    public const string OCCURRENCE_SKIP_REASON_PREVIOUS_RUN_IN_PROGRESS = 'PREVIOUS_RUN_IN_PROGRESS';

    /** @var string Skip reason: the account's SCHEDULE BUDGET POT is exhausted — runs pause until the 1st. */
    public const string OCCURRENCE_SKIP_REASON_BUDGET_POT_EXHAUSTED = 'BUDGET_POT_EXHAUSTED';

    /**
     * @var string Skip reason: the space of the schedule — or, for a schedule without a space, its account — is not
     * eligible for automated AI work: no spawn, no streak, no notice. An application that has another word for the
     * scope redeclares the constant in its own schedules service; a caller compares with the constant of the service
     * class the container resolves.
     */
    public const string OCCURRENCE_SKIP_REASON_AGENT_SPACE_NOT_ELIGIBLE = 'AGENT_SPACE_NOT_ELIGIBLE';

    /**
     * @var string Skip reason: the schedule names a space that no longer exists — it can never run; closed CANCELLED /
     * AGENT_SPACE_ENDED. A schedule WITHOUT a space is no such row: it runs at account level. An application may
     * redeclare the constant like {@see OCCURRENCE_SKIP_REASON_AGENT_SPACE_NOT_ELIGIBLE}.
     */
    public const string OCCURRENCE_SKIP_REASON_AGENT_SPACE_MISSING = 'AGENT_SPACE_MISSING';

    /**
     * @var string The refusal when no account can be named for a new schedule: nobody is signed in, its space names
     * no owner (or it has no space) and its creator set none. An application words it its own way in its same-path
     * subclass.
     */
    protected const string NO_ACCOUNT_FOR_NEW_SCHEDULE_MESSAGE = 'A schedule needs an account: none is signed in, and neither the space of the schedule nor the schedule itself names one.';

    /**
     * @var string The refusal when an edit names the space of a schedule among its changed columns: the space is
     * written when the schedule is created and never changes. An application words it its own way in its same-path
     * subclass.
     */
    protected const string AGENT_SPACE_OF_SCHEDULE_NEVER_CHANGES_MESSAGE = 'The space of a schedule is set when the schedule is created and cannot be changed afterwards.';

    /** The owner notice a finished run writes into the schedule's creating conversation ({@see composeOwnerNoticeText()}). */
    protected const string OWNER_NOTICE_RUN_COMPLETED = 'RUN_COMPLETED';

    /** The owner notice a run that failed a second time in a row writes ({@see composeOwnerNoticeText()}). */
    protected const string OWNER_NOTICE_RUN_FAILED = 'RUN_FAILED';

    /** The once-a-month owner notice that the schedule budget pot is exhausted ({@see composeOwnerNoticeText()}). */
    protected const string OWNER_NOTICE_BUDGET_PAUSED = 'BUDGET_PAUSED';

    /**
     * The schedules of `$agentSpace`, newest first — READ-RIGHTS APPLIED (the set repo's find runs
     * `applyReadRightsQuery`, so a customer only ever sees schedules of accounts it may read). The customer list
     * endpoint's query. The schedules an account holds WITHOUT a space are no part of this list: they belong to the
     * account, not to one of its spaces ({@see findSchedulesOfAccountWithoutAgentSpace()}).
     *
     * @param AgentSpace $agentSpace
     * @param bool $includeArchived true = the ARCHIVED schedules too
     * @return AIConversationSchedules
     */
    public function findSchedulesOfAgentSpace(AgentSpace $agentSpace, bool $includeArchived = false): AIConversationSchedules
    {
        return $this->findSchedulesInAgentSpacePartition(AgentSpaceSqlCondition::forAgentSpace($agentSpace), $includeArchived);
    }

    /**
     * The schedules `$account` holds WITHOUT a space (its account-level schedules), newest first — read rights
     * applied as in {@see findSchedulesOfAgentSpace()}. The list of an application that has no spaces, and of the
     * account level of one that has them. Never a schedule of a space, never one of another account.
     *
     * @param Account $account
     * @param bool $includeArchived true = the ARCHIVED schedules too
     * @return AIConversationSchedules
     */
    public function findSchedulesOfAccountWithoutAgentSpace(Account $account, bool $includeArchived = false): AIConversationSchedules
    {
        return $this->findSchedulesInAgentSpacePartition(AgentSpaceSqlCondition::forAccountWithoutAgentSpace($account), $includeArchived);
    }

    /**
     * The schedules `$agentSpaceSqlCondition` selects — those of one space, or those one account holds without a
     * space — newest first, read rights applied.
     *
     * @param AgentSpaceSqlCondition $agentSpaceSqlCondition
     * @param bool $includeArchived true = the ARCHIVED schedules too
     * @return AIConversationSchedules
     */
    protected function findSchedulesInAgentSpacePartition(AgentSpaceSqlCondition $agentSpaceSqlCondition, bool $includeArchived): AIConversationSchedules
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $this->applyAgentSpaceConditionToQuery($queryBuilder, $baseModelAlias, $agentSpaceSqlCondition);
        $queryBuilder->orderBy("{$baseModelAlias}.id", 'DESC');
        if (!$includeArchived) {
            // ARCHIVED = deleted-after-execution — hidden from every default list.
            $queryBuilder->andWhere("{$baseModelAlias}.status != :archivedStatus")
                ->setParameter('archivedStatus', AIConversationScheduleStatus::ARCHIVED);
        }
        // FRESH load, never the registry's cached set for this query: inside ONE agent turn the list runs right after
        // a status write (pauseTaskSchedule → getTaskSchedules), and the DoctrineEntityRegistry keyed on the query
        // handed back the set loaded BEFORE the write — the paused schedule still read ACTIVE with a next firing
        // (reproduced in one process). The list is one cheap query; a stale row costs a wrong answer.
        return $repoClass->find($queryBuilder, useEntityRegistrCache: false) ?? AIConversationSchedules::newInstance();
    }

    /**
     * Adds the partition condition `$agentSpaceSqlCondition` to `$queryBuilder` (connected with AND) and binds its
     * parameters — the one place a QueryBuilder of this service gets its condition on the space of a schedule.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $baseModelAlias the alias of the schedule model in the query
     * @param AgentSpaceSqlCondition $agentSpaceSqlCondition
     * @return void
     */
    protected function applyAgentSpaceConditionToQuery(
        DoctrineQueryBuilder $queryBuilder,
        string $baseModelAlias,
        AgentSpaceSqlCondition $agentSpaceSqlCondition
    ): void {
        $queryBuilder->andWhere($agentSpaceSqlCondition->getConditionDql($baseModelAlias));
        foreach ($agentSpaceSqlCondition->getParameters() as $parameterName => $parameterValue) {
            $queryBuilder->setParameter($parameterName, $parameterValue);
        }
    }

    /**
     * How many FINISHED schedule runs of the schedules of `$agentSpace` the OWNER has not seen yet — the
     * Scheduled-Tasks tab badge, ONE batched query. A run counts when its conversation
     * (a) belongs to one of the schedules of the space, (b) is NOT in-flight (`runState` outside
     * {@see AIConversationRunState::NON_TERMINAL} — a settled root run releases to IDLE, a failed one to FAILED),
     * and (c) was created after the schedule's {@see AIConversationSchedule::$ownerSawRunsUntilAt} (NULL = the
     * schedule's own `created` stands in). AUTHORIZATION IS THE CALLER'S — the customer controller decides which
     * space it asks for the same way the list endpoint does.
     *
     * @param AgentSpace $agentSpace
     * @return int
     */
    public function countUnseenFinishedRunsOfAgentSpace(AgentSpace $agentSpace): int
    {
        return $this->countUnseenFinishedRunsInAgentSpacePartition(AgentSpaceSqlCondition::forAgentSpace($agentSpace));
    }

    /**
     * How many finished runs of the schedules `$account` holds WITHOUT a space the owner has not seen yet — the
     * badge of {@see countUnseenFinishedRunsOfAgentSpace()} for the account level. AUTHORIZATION IS THE CALLER'S.
     *
     * @param Account $account
     * @return int
     */
    public function countUnseenFinishedRunsOfAccountWithoutAgentSpace(Account $account): int
    {
        return $this->countUnseenFinishedRunsInAgentSpacePartition(AgentSpaceSqlCondition::forAccountWithoutAgentSpace($account));
    }

    /**
     * The count of {@see countUnseenFinishedRunsOfAgentSpace()} over the schedules `$agentSpaceSqlCondition`
     * selects. Fail-soft: 0 when the statement fails.
     *
     * @param AgentSpaceSqlCondition $agentSpaceSqlCondition
     * @return int
     */
    protected function countUnseenFinishedRunsInAgentSpacePartition(AgentSpaceSqlCondition $agentSpaceSqlCondition): int
    {
        try {
            return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM `' . DBAIConversationModel::getTableName() . '` `runConversation`'
                . ' INNER JOIN `' . DBAIConversationScheduleModel::getTableName() . '` `schedule`'
                . ' ON `schedule`.`id` = `runConversation`.`scheduleId`'
                . ' WHERE ' . $agentSpaceSqlCondition->getConditionSql('schedule')
                . ' AND `runConversation`.`runState` NOT IN (:inFlightRunStates)'
                . ' AND `runConversation`.`created` > COALESCE(`schedule`.`ownerSawRunsUntilAt`, `schedule`.`created`)',
                array_merge($agentSpaceSqlCondition->getParameters(), ['inFlightRunStates' => AIConversationRunState::NON_TERMINAL]),
                ['inFlightRunStates' => ArrayParameterType::STRING]
            );
        } catch (\Throwable) {
            // FAIL-SOFT: the badge is a hint, the schedules LIST is the feature — a broken count (e.g. the
            // ownerSawRunsUntilAt column not yet applied by the human-run DDL) must never 500 the list endpoint
            // (live, an InvalidFieldNameException took the whole list down until the DDL ran).
            return 0;
        }
    }

    /**
     * Stamp {@see AIConversationSchedule::$ownerSawRunsUntilAt} = NOW on ALL schedules of `$agentSpace`
     * (ARCHIVED included — an archived schedule's runs must not keep the badge alive) — the customer
     * `markRunsSeen` action fired when the owner opens the Scheduled-Tasks tab. Reads run under the schedule
     * repo's read rights ({@see findSchedulesOfAgentSpace}); each stamp is a targeted persist. Returns how many
     * schedules were stamped.
     *
     * @param AgentSpace $agentSpace
     * @return int
     */
    public function markOwnerSawRunsOfAgentSpace(AgentSpace $agentSpace): int
    {
        return $this->markOwnerSawRunsOfSchedules($this->findSchedulesOfAgentSpace($agentSpace, includeArchived: true));
    }

    /**
     * Stamp {@see AIConversationSchedule::$ownerSawRunsUntilAt} = NOW on ALL schedules `$account` holds WITHOUT a
     * space (ARCHIVED included) — {@see markOwnerSawRunsOfAgentSpace()} for the account level. Returns how many
     * schedules were stamped.
     *
     * @param Account $account
     * @return int
     */
    public function markOwnerSawRunsOfAccountWithoutAgentSpace(Account $account): int
    {
        return $this->markOwnerSawRunsOfSchedules($this->findSchedulesOfAccountWithoutAgentSpace($account, includeArchived: true));
    }

    /**
     * Stamps {@see AIConversationSchedule::$ownerSawRunsUntilAt} = NOW on each stored schedule of `$schedules` (a
     * targeted persist per schedule).
     *
     * @param AIConversationSchedules $schedules
     * @return int how many schedules were stamped
     */
    protected function markOwnerSawRunsOfSchedules(AIConversationSchedules $schedules): int
    {
        $now = new DateTime();
        $stampedScheduleCount = 0;
        foreach ($schedules->getElements() as $schedule) {
            if (!isset($schedule->id)) {
                continue;
            }
            $schedule->ownerSawRunsUntilAt = $now;
            $schedule->updatePartialIgnoringRights('ownerSawRunsUntilAt');
            $stampedScheduleCount++;
        }
        return $stampedScheduleCount;
    }

    /**
     * How many AGENT-created RECURRING schedules (cron set, any status — a deleted-again row still counts as a
     * creation while its row exists) were created for `$agentSpace` since `$since` — the daily-creation cap's
     * counter (limit the crons an agent may set per day).
     *
     * @param AgentSpace $agentSpace
     * @param DateTime $since
     * @return int
     */
    public function countAgentRecurringSchedulesCreatedForAgentSpaceSince(AgentSpace $agentSpace, DateTime $since): int
    {
        return $this->countAgentRecurringSchedulesCreatedInAgentSpacePartitionSince(AgentSpaceSqlCondition::forAgentSpace($agentSpace), $since);
    }

    /**
     * How many agent-created recurring schedules were created for `$account` WITHOUT a space since `$since` — the
     * counter of {@see countAgentRecurringSchedulesCreatedForAgentSpaceSince()} for the account level.
     *
     * @param Account $account
     * @param DateTime $since
     * @return int
     */
    public function countAgentRecurringSchedulesCreatedForAccountWithoutAgentSpaceSince(Account $account, DateTime $since): int
    {
        return $this->countAgentRecurringSchedulesCreatedInAgentSpacePartitionSince(AgentSpaceSqlCondition::forAccountWithoutAgentSpace($account), $since);
    }

    /**
     * The count of {@see countAgentRecurringSchedulesCreatedForAgentSpaceSince()} over the schedules
     * `$agentSpaceSqlCondition` selects.
     *
     * @param AgentSpaceSqlCondition $agentSpaceSqlCondition
     * @param DateTime $since
     * @return int
     */
    protected function countAgentRecurringSchedulesCreatedInAgentSpacePartitionSince(AgentSpaceSqlCondition $agentSpaceSqlCondition, DateTime $since): int
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->select("COUNT({$baseModelAlias}.id)");
        $this->applyAgentSpaceConditionToQuery($queryBuilder, $baseModelAlias, $agentSpaceSqlCondition);
        $queryBuilder->andWhere("{$baseModelAlias}.initiatorType = :initiatorType")
            ->setParameter('initiatorType', AIConversationSchedule::INITIATOR_TYPE_AGENT)
            ->andWhere("{$baseModelAlias}.schedule IS NOT NULL")
            ->andWhere("{$baseModelAlias}.created >= :since")->setParameter('since', $since->format('Y-m-d H:i:s'));
        return (int)$queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * The projected monthly cost of the ACTIVE recurring schedules of `$agentSpace` — the informational half of the
     * proactivity budget guards (the tool responses relay {@see RecurringScheduleMonthlyCostEstimate::buildAgentFacingSummary()}).
     * Firings: each cron iterated over the next 30 days. Per-run cost: the schedule's own run-history average →
     * the schedule-run average of the space → the documented assumption (see the VO). Budget + display policy come
     * from the owning account ({@see ScheduleBudgetPotsService}); under fair-use/hidden-usage the summary stays
     * qualitative.
     *
     * @param AgentSpace $agentSpace
     * @return RecurringScheduleMonthlyCostEstimate
     */
    public function buildMonthlyCostEstimateForActiveRecurringSchedulesOfAgentSpace(AgentSpace $agentSpace): RecurringScheduleMonthlyCostEstimate
    {
        return $this->buildMonthlyCostEstimateForActiveRecurringSchedulesInAgentSpacePartition(AgentSpaceSqlCondition::forAgentSpace($agentSpace));
    }

    /**
     * The projected monthly cost of the ACTIVE recurring schedules `$account` holds WITHOUT a space —
     * {@see buildMonthlyCostEstimateForActiveRecurringSchedulesOfAgentSpace()} for the account level.
     *
     * @param Account $account
     * @return RecurringScheduleMonthlyCostEstimate
     */
    public function buildMonthlyCostEstimateForActiveRecurringSchedulesOfAccountWithoutAgentSpace(Account $account): RecurringScheduleMonthlyCostEstimate
    {
        return $this->buildMonthlyCostEstimateForActiveRecurringSchedulesInAgentSpacePartition(AgentSpaceSqlCondition::forAccountWithoutAgentSpace($account));
    }

    /**
     * The estimate of {@see buildMonthlyCostEstimateForActiveRecurringSchedulesOfAgentSpace()} over the schedules
     * `$agentSpaceSqlCondition` selects; the run history is read from the conversations the same condition selects.
     *
     * @param AgentSpaceSqlCondition $agentSpaceSqlCondition
     * @return RecurringScheduleMonthlyCostEstimate
     */
    protected function buildMonthlyCostEstimateForActiveRecurringSchedulesInAgentSpacePartition(AgentSpaceSqlCondition $agentSpaceSqlCondition): RecurringScheduleMonthlyCostEstimate
    {
        $monthlyCostEstimate = new RecurringScheduleMonthlyCostEstimate();
        $activeRecurringSchedules = [];
        foreach ($this->findSchedulesInAgentSpacePartition($agentSpaceSqlCondition, false)->getElements() as $schedule) {
            if ($schedule->isActive() && !$schedule->isOneTime()) {
                $activeRecurringSchedules[] = $schedule;
            }
        }
        if ($activeRecurringSchedules === []) {
            return $monthlyCostEstimate;
        }
        $monthlyCostEstimate->activeRecurringScheduleCount = count($activeRecurringSchedules);

        // Run-history costs: the recent schedule-spawned runs the condition selects (any schedule — one query serves
        // both the per-schedule averages and the fallback over all of them), each run summed over its messages.
        $runCostRows = EntityManagerFactory::getInstance()->getConnection()->executeQuery(
            'SELECT `conversation`.`scheduleId` AS `scheduleId`, `conversation`.`id` AS `conversationId`,'
            // + the run's overhead (decision calls, summary, compaction, …); MAX: the join repeats it per message row
            . ' COALESCE(SUM(' . DBAIConversationMessage::buildCostInUSDIncludingToolCallExecutionSql('message') . '), 0)'
            . ' + COALESCE(MAX(`conversation`.`overheadCostsInUSD`), 0) AS `runCostUsd`'
            . ' FROM `' . DBAIConversationModel::getTableName() . '` `conversation`'
            . ' LEFT JOIN `' . DBAIConversationMessageModel::getTableName() . '` `message`'
            . ' ON `message`.`aiConversationId` = `conversation`.`id`'
            . ' WHERE ' . $agentSpaceSqlCondition->getConditionSql('conversation') . ' AND `conversation`.`scheduleId` IS NOT NULL'
            . ' GROUP BY `conversation`.`scheduleId`, `conversation`.`id`'
            . ' ORDER BY `conversation`.`id` DESC LIMIT 100',
            $agentSpaceSqlCondition->getParameters()
        )->fetchAllAssociative();
        $runCostsUsdPerScheduleId = [];
        foreach ($runCostRows as $runCostRow) {
            $runCostsUsdPerScheduleId[(int)$runCostRow['scheduleId']][] = (float)$runCostRow['runCostUsd'];
        }
        $allRunCostsUsd = array_merge(...array_values($runCostsUsdPerScheduleId) ?: [[]]);
        $agentSpaceAverageRunCostUsd = $allRunCostsUsd !== []
            ? array_sum($allRunCostsUsd) / count($allRunCostsUsd)
            : null;

        $now = new DateTime();
        foreach ($activeRecurringSchedules as $activeRecurringSchedule) {
            $scheduleFiringsPerMonth = $activeRecurringSchedule->countCronFiringsWithinDays($now, 30);
            $monthlyCostEstimate->estimatedFiringsPerMonth += $scheduleFiringsPerMonth;
            $ownRunCostsUsd = $runCostsUsdPerScheduleId[(int)$activeRecurringSchedule->id] ?? [];
            if ($ownRunCostsUsd !== []) {
                $costPerRunUsd = array_sum($ownRunCostsUsd) / count($ownRunCostsUsd);
            } elseif ($agentSpaceAverageRunCostUsd !== null) {
                $costPerRunUsd = $agentSpaceAverageRunCostUsd;
            } else {
                $costPerRunUsd = RecurringScheduleMonthlyCostEstimate::ASSUMED_COST_PER_RUN_USD_WITHOUT_HISTORY;
                $monthlyCostEstimate->isCostAssumed = true;
            }
            $monthlyCostEstimate->estimatedMonthlyCostUsd += $scheduleFiringsPerMonth * $costPerRunUsd;
            // The per-schedule slice — same figures the aggregate just summed,
            // captured so the customer sees WHICH schedule drives the monthly load.
            $perScheduleEstimate = new ScheduleMonthlyCostEstimate();
            $perScheduleEstimate->scheduleId = (int)$activeRecurringSchedule->id;
            $perScheduleEstimate->firingsPerMonth = $scheduleFiringsPerMonth;
            $perScheduleEstimate->estimatedMonthlyCostUsd = $scheduleFiringsPerMonth * $costPerRunUsd;
            $perScheduleEstimate->perRunCostCapUsd = $activeRecurringSchedule->perRunCostCapUsd ?? null;
            $monthlyCostEstimate->perScheduleEstimates[] = $perScheduleEstimate;
        }

        // Budget + display policy from the owning account (direct read fires the lazy loads on purpose).
        $owningAccount = $activeRecurringSchedules[0]->account;
        if ($owningAccount !== null) {
            /** @var ScheduleBudgetPotsService $scheduleBudgetPotsService */
            $scheduleBudgetPotsService = DDDService::instance()->getService(ScheduleBudgetPotsService::class);
            $monthlyCostEstimate->monthlyBudgetUsd = $scheduleBudgetPotsService->findMonthlyBudgetForAccount($owningAccount);
            $monthlyCostEstimate->hideExactFigures = $scheduleBudgetPotsService->hidesExactFiguresForAccount($owningAccount);
        }

        return $monthlyCostEstimate;
    }

    /**
     * The ADMIN list: ALL schedules, optionally filtered by status / initiatorType / accountId / agentSpaceId,
     * newest first, offset-paged. Runs behind the admin firewall — the admin passes the repo's read rights.
     *
     * @param string|null $status null = no filter
     * @param string|null $initiatorType null = no filter
     * @param int|null $accountId null = no filter
     * @param int|null $agentSpaceId the id of a space: only its schedules. null = NO FILTER — the schedules of every
     *                               space and those without a space (it never means "the schedules without a space")
     * @param int $limit
     * @param int $offset
     * @return AIConversationSchedules
     */
    public function findSchedulesForAdmin(
        ?string $status = null,
        ?string $initiatorType = null,
        ?int $accountId = null,
        ?int $agentSpaceId = null,
        int $limit = 50,
        int $offset = 0
    ): AIConversationSchedules {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $this->applyAdminScheduleFilters($queryBuilder, $baseModelAlias, $status, $initiatorType, $accountId, $agentSpaceId);
        $queryBuilder->orderBy("{$baseModelAlias}.id", 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(200, $limit)));
        return $repoClass->find($queryBuilder) ?? AIConversationSchedules::newInstance();
    }

    /**
     * The ADMIN list's total row count under the same filters (paging denominator).
     *
     * @param string|null $status null = no filter
     * @param string|null $initiatorType null = no filter
     * @param int|null $accountId null = no filter
     * @param int|null $agentSpaceId the id of a space: only its schedules. null = NO FILTER (see
     *                               {@see findSchedulesForAdmin()})
     * @return int
     */
    public function countSchedulesForAdmin(
        ?string $status = null,
        ?string $initiatorType = null,
        ?int $accountId = null,
        ?int $agentSpaceId = null
    ): int {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->select("COUNT({$baseModelAlias}.id)");
        $this->applyAdminScheduleFilters($queryBuilder, $baseModelAlias, $status, $initiatorType, $accountId, $agentSpaceId);
        return (int)$queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * The shared WHERE clauses of the admin list + count (every filter optional).
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $baseModelAlias
     * @param string|null $status null = no filter
     * @param string|null $initiatorType null = no filter
     * @param int|null $accountId null = no filter
     * @param int|null $agentSpaceId the id of a space: only its schedules. null = NO FILTER: the list then reads
     *                               across all spaces and the schedules without one. The condition on the space is
     *                               added only for an id, so a missing id never turns into "the schedules without a
     *                               space"
     * @return void
     */
    protected function applyAdminScheduleFilters(
        DoctrineQueryBuilder $queryBuilder,
        string $baseModelAlias,
        ?string $status,
        ?string $initiatorType,
        ?int $accountId,
        ?int $agentSpaceId
    ): void {
        if ($status !== null && $status !== '') {
            $queryBuilder->andWhere("{$baseModelAlias}.status = :status")->setParameter('status', $status);
        }
        if ($initiatorType !== null && $initiatorType !== '') {
            $queryBuilder->andWhere("{$baseModelAlias}.initiatorType = :initiatorType")->setParameter('initiatorType', $initiatorType);
        }
        if ($accountId !== null) {
            $queryBuilder->andWhere("{$baseModelAlias}.accountId = :accountId")->setParameter('accountId', $accountId);
        }
        if ($agentSpaceId !== null) {
            $this->applyAgentSpaceConditionToQuery($queryBuilder, $baseModelAlias, AgentSpaceSqlCondition::forAgentSpaceId($agentSpaceId));
        }
    }

    /**
     * CREATE a schedule: decides the account it is stored for, checks that this account may use the space of the
     * schedule (or, for a schedule without a space, that such a schedule may be created), validates the cron
     * expression, arms the fire anchor to the next occurrence, and persists.
     *
     * WITH OR WITHOUT A SPACE. A schedule works for the {@see AgentSpace} its creator set on it
     * ({@see AIConversationSchedule::$agentSpaceId} or the object) or, without one, for the account as a whole: its
     * runs are then conversations without a space. Both are regular cases. Whether a schedule without a space may
     * exist is the application's decision ({@see AgentSpacesService::isScheduleWithoutAgentSpaceAllowed()});
     * with a space, the account the row is stored for must be allowed to use it
     * ({@see AgentSpacesService::requireAgentSpaceUsableByAccount()}). The space is written here, once: no edit moves
     * a schedule to another space ({@see persistScheduleEdit()}).
     *
     * THE SCHEDULE BELONGS TO WHOEVER CREATED IT. Every path except the admin console
     * stores the authenticated account, so a sub-user's scheduled task stays that sub-user's.
     *
     * It used to store the owner of the space for every path, which is what made deferred work a way around a user's
     * own permissions: the row was re-assigned to the parent, the fire path drove the run as the parent, and the
     * loop read the PARENT's rights. A sub-user who may not confirm bookings could schedule "confirm the new bookings
     * every morning" and have it execute with rights they do not hold. Keeping the creator removes the escalation
     * at its source — there is no second identity to carry forward and no rights to reconcile, because the run
     * simply IS the creator's ({@see spawnRunConversationForClaimedSchedule()} drives it as `$schedule->account`).
     *
     * The admin console keeps the old derivation: an admin creates schedules FOR a customer, and an admin-owned row
     * would put the customer's recurring work under a staff account — wrong for read rights, wrong for billing. The
     * customer is the owner of the space; for a schedule without a space (or a space that names no owner) it is the
     * account the admin set on the schedule ({@see findAccountNamedForNewSchedule()}).
     *
     * A main account still sees its sub-accounts' schedules; the read-rights query resolves the family
     * ({@see \DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationSchedule::applyReadRightsQuery()}), and
     * the AI budget is counted family-wide, so nothing is lost by attributing the row to the sub-user.
     *
     * The owner and the zone of the space come from {@see AgentSpacesService}. A schedule whose creator named no
     * zone and for which none is known (no space, or a space without a zone) keeps none: its cron is evaluated in
     * UTC.
     *
     * @param AIConversationSchedule $schedule the new schedule; its space, when it has one, set by the creator
     * @return AIConversationSchedule
     * @throws BadRequestException when no account can be named for the schedule, a schedule without a space is not
     *                             allowed, or the cron is invalid
     * @throws ForbiddenException when the schedule names a space that cannot be read or that its account may not use
     */
    public function createSchedule(AIConversationSchedule $schedule): AIConversationSchedule
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $agentSpace = $this->findAgentSpaceOfNewSchedule($schedule);
        $owningAccount = $this->resolveScheduleOwnerAccount($schedule, $this->findAccountNamedForNewSchedule($schedule, $agentSpace));
        if ($owningAccount === null || !isset($owningAccount->id)) {
            throw new BadRequestException(static::NO_ACCOUNT_FOR_NEW_SCHEDULE_MESSAGE);
        }
        $schedule->accountId = (int)$owningAccount->id;
        $schedule->account = $owningAccount;
        if ($agentSpace === null) {
            $agentSpacesService->requireScheduleWithoutAgentSpaceAllowed($schedule);
        } else {
            $agentSpacesService->requireAgentSpaceUsableByAccount($agentSpace, $owningAccount);
        }
        $schedule->status = AIConversationScheduleStatus::ACTIVE;
        // Every NEW row carries a zone where one is known — a caller that decided one (the agent tool's override)
        // keeps it, any other creation path (admin console, future writers) takes the zone the space resolves and
        // its provenance. Without a known zone the row stays as rows created before zones existed (null = cron in
        // UTC, as before).
        if (($schedule->timezone ?? null) === null) {
            $agentSpaceTimezone = $agentSpacesService->findTimezoneForNewSchedule($schedule);
            if ($agentSpaceTimezone !== null) {
                $schedule->timezone = $agentSpaceTimezone->identifier;
                $schedule->timezoneUnverified = $agentSpaceTimezone->unverified;
            }
        }
        if ($schedule->isOneTime()) {
            // ONE-TIME: exactly one of oneTimeAt/cron; a single execution.
            if (($schedule->schedule ?? null) !== null) {
                throw new BadRequestException('A one-time schedule carries oneTimeAt ONLY — no cron expression.');
            }
            $schedule->maxIterations = 1;
            $schedule->nextRunAt = $schedule->computeNextRunAt(new DateTime());
            $schedule->update();
            return $schedule;
        }
        if (($schedule->schedule ?? null) === null || trim((string)$schedule->schedule) === '') {
            throw new BadRequestException('A recurring schedule needs a cron expression (or set oneTimeAt for a one-time task).');
        }
        try {
            $schedule->nextRunAt = $schedule->computeNextRunAt(new DateTime());
        } catch (\Throwable) {
            throw new BadRequestException("Invalid cron expression '{$schedule->schedule}'.");
        }
        $schedule->update();
        return $schedule;
    }

    /**
     * The space a NEW schedule is created for, as its creator set it: the attached object, else the space of
     * {@see AIConversationSchedule::$agentSpaceId} (loaded under the read rights of the caller — never lifted here).
     * The id on the schedule is aligned with the space that is returned, so the row is stored for exactly the space
     * the checks of {@see createSchedule()} ran for.
     *
     * @param AIConversationSchedule $schedule
     * @return AgentSpace|null null = the creator named no space: a schedule at account level
     * @throws ForbiddenException when the schedule names a space that cannot be read — never read as "no space",
     *                            which would create the schedule at account level instead
     * @throws InternalErrorException when the creator attached a space that is not stored (a programming error)
     */
    protected function findAgentSpaceOfNewSchedule(AIConversationSchedule $schedule): ?AgentSpace
    {
        // isset() never triggers the lazy load: only a space the creator attached counts here
        $attachedAgentSpace = isset($schedule->agentSpace) ? $schedule->agentSpace : null;
        if ($attachedAgentSpace === null && ($schedule->agentSpaceId ?? null) === null) {
            return null;
        }
        // the lazy load of the relation, by the id the creator set
        $agentSpace = $attachedAgentSpace ?? ($schedule->agentSpace ?? null);
        if ($agentSpace === null) {
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            throw new ForbiddenException($agentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        }
        if (!isset($agentSpace->id)) {
            throw new InternalErrorException('A schedule cannot be created for an agent space without an id.');
        }
        $schedule->agentSpaceId = (int)$agentSpace->id;
        return $agentSpace;
    }

    /**
     * The account a NEW schedule names besides the one that is signed in: the owner of its space, else — a schedule
     * without a space, or a space that names no owner — the account its creator set on the schedule.
     *
     * @param AIConversationSchedule $schedule
     * @param AgentSpace|null $agentSpace the space of the schedule ({@see findAgentSpaceOfNewSchedule()}); null =
     *                                    account level
     * @return Account|null null = neither names an account
     */
    protected function findAccountNamedForNewSchedule(AIConversationSchedule $schedule, ?AgentSpace $agentSpace): ?Account
    {
        if ($agentSpace !== null) {
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            $ownerAccountOfAgentSpace = $agentSpacesService->findOwnerAccountOfAgentSpace($agentSpace);
            if ($ownerAccountOfAgentSpace !== null && isset($ownerAccountOfAgentSpace->id)) {
                return $ownerAccountOfAgentSpace;
            }
        }
        // the lazy load of the relation, by the account id the creator set
        $accountSetByCreator = $schedule->account ?? null;
        return $accountSetByCreator !== null && isset($accountSetByCreator->id) ? $accountSetByCreator : null;
    }

    /**
     * Who owns the schedule — the authenticated account for every customer-side path, the account the schedule
     * names (`$accountNamedForSchedule`: the owner of its space, else the account its creator set) for the admin
     * console. See {@see createSchedule()} for why the creator keeps it.
     *
     * The admin case is decided on {@see AIConversationSchedule::INITIATOR_TYPE_ADMIN} rather than on "is the
     * session an admin", because that is what the row itself records and what a reader of the row can check later.
     *
     * Falls back to the named account when there is no authenticated account at all (a system/CLI context) —
     * an unowned schedule cannot be read back or billed, so an owner is required either way. On a customer-side
     * path the authenticated account always wins over an account set on the schedule: a schedule is never created
     * for another account by naming it.
     *
     * @param AIConversationSchedule $schedule
     * @param Account|null $accountNamedForSchedule {@see findAccountNamedForNewSchedule()}; null = none is named
     * @return Account|null null = no account can be named (the creation is refused)
     */
    protected function resolveScheduleOwnerAccount(AIConversationSchedule $schedule, ?Account $accountNamedForSchedule): ?Account
    {
        if ($schedule->initiatorType === AIConversationSchedule::INITIATOR_TYPE_ADMIN) {
            return $accountNamedForSchedule;
        }
        $creatorAccount = $this->findAuthenticatedAccount();
        return $creatorAccount !== null && isset($creatorAccount->id) ? $creatorAccount : $accountNamedForSchedule;
    }

    /**
     * The ambient authenticated account, read through its singleton — split from the decision above so that decision
     * is a pure function of its two inputs and can be exercised without an auth layer.
     */
    protected function findAuthenticatedAccount(): ?Account
    {
        return AuthService::instance()->getAccount();
    }

    /** The schedule's id as the eligibility log context carries it (null for an unsaved row). */
    protected function getScheduleIdForLog(AIConversationSchedule $schedule): ?int
    {
        return isset($schedule->id) ? (int)$schedule->id : null;
    }

    /**
     * DELETE a schedule following the archive policy (decided by
     * {@see AIConversationSchedule::getDeleteAction()}) — the one delete of every caller, an admin console included:
     * zero executions → the row is hard-deleted (a pending "ruf mich heute an" disappears for good); a
     * recurring schedule that already ran → ARCHIVED (hidden from default lists, filterable); a one-time that already
     * ran → untouched history. Returns the action taken. A row with runs is removed for good only by the account
     * erasure (a console command of the application).
     */
    public function deleteScheduleFollowingArchivePolicy(AIConversationSchedule $schedule): string
    {
        $deleteTransition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_DELETE, new DateTime());
        $deleteAction = (string)$deleteTransition->deleteAction;
        if (!isset($schedule->id)) {
            return $deleteAction;
        }
        if ($deleteAction === AIConversationSchedule::DELETE_ACTION_HARD_DELETE) {
            $schedule->delete();
            return $deleteAction;
        }
        if ($deleteTransition->targetStatus !== null) {
            $this->transitionStatus($schedule, $deleteTransition->targetStatus);
        }
        return $deleteAction;
    }

    /**
     * The EDIT guard of every edit path: only a live schedule (ACTIVE or PAUSED) changes — a DONE, CANCELLED,
     * EXPIRED or ARCHIVED row is final.
     *
     * @throws BadRequestException naming the status that refuses
     */
    public function assertScheduleMayBeEdited(AIConversationSchedule $schedule): void
    {
        if (!$schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_EDIT, new DateTime())->isAllowed) {
            throw new BadRequestException(
                'Schedule ' . ($schedule->id ?? '?') . " is {$schedule->status} — only an ACTIVE or PAUSED schedule can be changed."
            );
        }
    }

    /**
     * Persist an EDIT of the named columns (admin console, the customer PATCH, the agent's `updateTaskSchedule`
     * — targeted, the fire cron may hold a newer row concurrently). Refuses a final row
     * ({@see assertScheduleMayBeEdited()}). When the TIMING itself was edited on an ACTIVE schedule — the cron
     * expression or the one-time `oneTimeAt` moment — the fire anchor is recomputed (and `nextRunAt` joins the
     * persisted columns); an invalid cron is rejected before anything is written.
     *
     * The space of a schedule ({@see AIConversationSchedule::$agentSpaceId}) is no editable column: it is written
     * when the schedule is created and never changes, so an edit that names it is refused as a whole.
     *
     * @param AIConversationSchedule $schedule
     * @param string[] $editedColumnNames
     * @return void
     * @throws BadRequestException when the schedule is final, the edit names the space of the schedule, or the
     *                             edited cron expression is invalid
     */
    public function persistScheduleEdit(AIConversationSchedule $schedule, array $editedColumnNames): void
    {
        if (!isset($schedule->id) || $editedColumnNames === []) {
            return;
        }
        if (in_array(AgentSpaceSqlCondition::COLUMN_NAME, $editedColumnNames, true)) {
            throw new BadRequestException(static::AGENT_SPACE_OF_SCHEDULE_NEVER_CHANGES_MESSAGE);
        }
        $this->assertScheduleMayBeEdited($schedule);
        $editTransition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_EDIT, new DateTime());
        // An edited cron OR an edited zone on a recurring schedule moves the firing instants.
        if (in_array('schedule', $editedColumnNames, true)
            || (!$schedule->isOneTime() && in_array('timezone', $editedColumnNames, true))
        ) {
            try {
                $nextRunAtFromEditedCron = $schedule->computeNextRunAt(new DateTime());
            } catch (\Throwable) {
                throw new BadRequestException("Invalid cron expression '{$schedule->schedule}'.");
            }
            if ($editTransition->reArmsFireAnchor) {
                $schedule->nextRunAt = $nextRunAtFromEditedCron;
                $editedColumnNames[] = 'nextRunAt';
            }
        }
        if (in_array('oneTimeAt', $editedColumnNames, true)
            && $editTransition->reArmsFireAnchor
            && !in_array('nextRunAt', $editedColumnNames, true)
        ) {
            // A one-time schedule fires at its (edited) oneTimeAt — computeNextRunAt returns exactly that.
            $schedule->nextRunAt = $schedule->computeNextRunAt(new DateTime());
            $editedColumnNames[] = 'nextRunAt';
        }
        $schedule->updatePartialIgnoringRights(...$editedColumnNames);
    }

    /**
     * The DUE scan (rights-deactivated cron context): ACTIVE schedules whose fire anchor has passed, oldest
     * first, bounded. Served by the class-level (status, nextRunAt) index.
     *
     * @return int[]
     */
    public function findDueScheduleIds(int $limit = self::DUE_SCHEDULES_BATCH_LIMIT): array
    {
        return array_map('intval', EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT `id` FROM `' . DBAIConversationScheduleModel::getTableName() . '`'
            . ' WHERE `status` = :active AND `nextRunAt` IS NOT NULL AND `nextRunAt` <= :now'
            . ' ORDER BY `nextRunAt` ASC LIMIT ' . $limit,
            ['active' => AIConversationScheduleStatus::ACTIVE, 'now' => (new DateTime())->format('Y-m-d H:i:s')]
        ));
    }

    /**
     * The spaces whose ACTIVE schedules fire within [`$from`, `$until`) — each (account, space) once (a scheduled
     * run is prefetched by its due time). A read ACROSS accounts and spaces of the cron context: it carries no
     * condition on one space, and every row names the account of its schedules. The schedules an account holds
     * WITHOUT a space are no part of it (they have no space to prefetch for). Served by the (status, nextRunAt)
     * index.
     *
     * @param DateTime $from
     * @param DateTime $until
     * @return AIConversationScheduleDueAgentSpace[]
     * @throws \Doctrine\DBAL\Exception
     */
    public function findAgentSpacesWithRunDueBetween(DateTime $from, DateTime $until): array
    {
        $accountAndAgentSpaceIdRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT `accountId`, `agentSpaceId` FROM `' . DBAIConversationScheduleModel::getTableName() . '`'
            . ' WHERE `status` = :active AND `nextRunAt` >= :from AND `nextRunAt` < :until AND `agentSpaceId` IS NOT NULL AND `accountId` IS NOT NULL',
            ['active' => AIConversationScheduleStatus::ACTIVE, 'from' => $from->format('Y-m-d H:i:s'), 'until' => $until->format('Y-m-d H:i:s')]
        );
        $agentSpacesWithRunDue = [];
        foreach ($accountAndAgentSpaceIdRows as $accountAndAgentSpaceIdRow) {
            $agentSpacesWithRunDue[] = AIConversationScheduleDueAgentSpace::forAccountIdAndAgentSpaceId(
                (int)$accountAndAgentSpaceIdRow['accountId'],
                (int)$accountAndAgentSpaceIdRow['agentSpaceId']
            );
        }
        return $agentSpacesWithRunDue;
    }

    /**
     * CLAIM one fire occurrence exactly once across workers — compare-and-set on the loaded `nextRunAt`: ONE
     * UPDATE advances the anchor to the next cron occurrence (failure backoff included), stamps `lastFiredAt`
     * and counts the iteration, guarded by `nextRunAt = :expected AND status = ACTIVE`. 0 affected rows =
     * another worker (or a concurrent pause/cancel) won — the caller skips this schedule without firing. The
     * in-memory entity mirrors the committed state on a won claim.
     */
    public function claimFireOccurrence(AIConversationSchedule $schedule): bool
    {
        if (!isset($schedule->id) || !isset($schedule->nextRunAt)) {
            return false;
        }
        $now = new DateTime();
        // A ONE-TIME schedule has no next occurrence — the claim CLEARS the anchor (the due-scan skips NULL),
        // and the run terminal closes it as DONE. Recurring advances to the next cron occurrence as before.
        $advancedNextRunAt = $schedule->isOneTime()
            ? null
            : ($schedule->failureStreak > 0
                ? $schedule->computeNextRunAtWithFailureBackoff($now)
                : $schedule->computeNextRunAt($now));
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationScheduleModel::getTableName() . '`'
            . ' SET `nextRunAt` = :advanced, `lastFiredAt` = :now, `iterationsUsed` = `iterationsUsed` + 1'
            . ' WHERE `id` = :id AND `status` = :active AND `nextRunAt` = :expected',
            [
                'advanced' => $advancedNextRunAt?->format('Y-m-d H:i:s'),
                'now' => $now->format('Y-m-d H:i:s'),
                'id' => $schedule->id,
                'active' => AIConversationScheduleStatus::ACTIVE,
                'expected' => $schedule->nextRunAt->format('Y-m-d H:i:s'),
            ]
        );
        if ($affectedRows === 0) {
            return false;
        }
        $schedule->nextRunAt = $advancedNextRunAt;
        $schedule->lastFiredAt = $now;
        $schedule->iterationsUsed++;
        return true;
    }

    /**
     * REFUND one occurrence a claim ({@see claimFireOccurrence}) consumed but that never ran — the pot-exhausted
     * skip of a recurring schedule (`$reArmNow` false: the next cron occurrence stands) and the
     * dispatch-infrastructure-down catch of the immediate-fire callers (`$reArmNow` true: `nextRunAt` = NOW so
     * the due-schedules cron fires it as soon as the infrastructure is back). ONE RELATIVE SQL UPDATE
     * (`iterationsUsed - 1`, floored at 0) instead of writing the loaded value back absolutely: a concurrent
     * claim's `+1` must not be clobbered by an absolute write from this worker's stale in-memory count. The
     * in-memory `iterationsUsed` is resynced from the committed row afterwards.
     */
    public function refundClaimedOccurrence(AIConversationSchedule $schedule, bool $reArmNow): void
    {
        if (!isset($schedule->id)) {
            return;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $refundSql = 'UPDATE `' . DBAIConversationScheduleModel::getTableName() . '`'
            . ' SET `iterationsUsed` = GREATEST(0, `iterationsUsed` - 1)';
        $refundParameters = ['id' => (int)$schedule->id];
        if ($reArmNow) {
            $reArmedNextRunAt = new DateTime();
            $refundSql .= ', `nextRunAt` = :now';
            $refundParameters['now'] = $reArmedNextRunAt->format('Y-m-d H:i:s');
            $schedule->nextRunAt = $reArmedNextRunAt;
        }
        $connection->executeStatement($refundSql . ' WHERE `id` = :id', $refundParameters);
        $schedule->iterationsUsed = (int)$connection->fetchOne(
            'SELECT `iterationsUsed` FROM `' . DBAIConversationScheduleModel::getTableName() . '` WHERE `id` = :id',
            ['id' => (int)$schedule->id]
        );
    }

    /**
     * Spawn the run conversation for a schedule whose occurrence the caller has ALREADY claimed
     * ({@see claimFireOccurrence}) — the shared fire tail of the cron command and the admin `fire-now`:
     * guardrails first (the previous run still in progress → SKIP this occurrence, streak untouched — a long run
     * is not a failure; a space that no longer exists → SKIP and the schedule is closed; a space or account that is
     * not eligible → SKIP), then ONE new run conversation via the ADMIN drive scope (it runs as the schedule's
     * account, appends the seed user message — task + the previous run's summary-outcome line — stamps
     * scheduleId/initiatorType/runSettings at creation and dispatches the async turn worker; budget stays enforced
     * unless the console lifted it).
     *
     * The run works for the space of the schedule; the run of a schedule WITHOUT a space is a conversation without a
     * space (account level) — a regular case where the application allows such schedules
     * ({@see AgentSpacesService::isScheduleWithoutAgentSpaceAllowed()}). Where it does not, a stored schedule without
     * a space is skipped and closed like one whose space is gone: its run would be refused at the start.
     *
     * Returns the spawned run conversation, or the skip reason of the occurrence (never state on this shared service).
     *
     * @param AIConversationSchedule $schedule
     * @return AIConversationScheduleOccurrenceSpawn
     */
    public function spawnRunConversationForClaimedSchedule(AIConversationSchedule $schedule): AIConversationScheduleOccurrenceSpawn
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $previousRunConversation = $aIConversationsService->findNewestScheduleRunConversation((int)$schedule->id);
        if ($previousRunConversation !== null && $previousRunConversation->isRunInProgress()) {
            return AIConversationScheduleOccurrenceSpawn::skipped(self::OCCURRENCE_SKIP_REASON_PREVIOUS_RUN_IN_PROGRESS);
        }
        $agentSpace = $this->findAgentSpaceOfSchedule($schedule);
        if ($this->isScheduleUnableToRunForLackOfAgentSpace($schedule, $agentSpace)) {
            // The schedule can NEVER run — and one that names a space that no longer exists must not run at account
            // level instead, which would be the work of another partition. A SKIP (no failure streak, no backoff,
            // no owner notice for a row that cannot run), and the row is closed.
            $this->transitionStatusForAutomationState($schedule, AIConversationScheduleStatus::CANCELLED, (AgentAutomationStateReason::getReasonClassOfTheApplication())::AGENT_SPACE_ENDED);
            return AIConversationScheduleOccurrenceSpawn::skipped(static::OCCURRENCE_SKIP_REASON_AGENT_SPACE_MISSING);
        }
        // ELIGIBILITY GATE — BEFORE the budget pot, read FRESH (this is the guard that
        // matters, and it runs once per occurrence): a due occurrence of a deleted / locked / cancelled / erased
        // space or account is SKIPPED — no spawn, no failure streak, no owner notice, no pot notice. A recurring row
        // refunds its claimed occurrence exactly like the pot skip; a one-time row is left for the sweep to close.
        /** @var AgentAutomationEligibilityService $agentAutomationEligibilityService */
        $agentAutomationEligibilityService = DDDService::instance()->getService(AgentAutomationEligibilityService::class);
        $scheduleEligibility = $this->findEligibilityOfSchedule($schedule, $agentSpace);
        if ($agentAutomationEligibilityService->refusesAutomation(
            $scheduleEligibility,
            'scheduleFire',
            AgentEligibilityLogContext::forSchedule($this->getScheduleIdForLog($schedule), $schedule->accountId ?? null)
        )) {
            if (!$schedule->isOneTime()) {
                $this->refundClaimedOccurrence($schedule, reArmNow: false);
            }
            return AIConversationScheduleOccurrenceSpawn::skipped(static::OCCURRENCE_SKIP_REASON_AGENT_SPACE_NOT_ELIGIBLE, $scheduleEligibility);
        }
        // SCHEDULE BUDGET POT (50/50): autonomous spend past the pot never spawns.
        // NOT a failure of the task — no failureStreak. The claim already advanced the anchor (recurring) /
        // consumed the single occurrence (one-time), so: recurring → refund the iteration and wait for the next
        // occurrence; one-time → EXPIRED (a timed task a month late is worthless — the owner notice says so).
        // A pot-skipped occurrence keeps the claim's lastFiredAt stamp (accepted — the manual fire is
        // pot-blocked too, so the stamp never blocks a fire that could have run).
        // Direct property read on purpose — the lazy-load is wanted (the fire cron runs rights-deactivated).
        $owningAccount = $schedule->account;
        if ($owningAccount !== null) {
            /** @var ScheduleBudgetPotsService $scheduleBudgetPotsService */
            $scheduleBudgetPotsService = DDDService::instance()->getService(ScheduleBudgetPotsService::class);
            if ($scheduleBudgetPotsService->findScheduleBudgetPotStatusForAccount($owningAccount)->isExhausted()) {
                try {
                    $this->notifyOwnerOncePerMonthThatScheduleBudgetPotIsExhausted($schedule);
                } catch (\Throwable $budgetNoticeError) {
                    \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                        'Schedule budget-pause owner notice failed: ' . $budgetNoticeError->getMessage(),
                        ['scheduleId' => $schedule->id ?? null]
                    );
                }
                if ($schedule->isOneTime()) {
                    $this->transitionStatus($schedule, AIConversationScheduleStatus::EXPIRED);
                } else {
                    $this->refundClaimedOccurrence($schedule, reArmNow: false);
                }
                return AIConversationScheduleOccurrenceSpawn::skipped(self::OCCURRENCE_SKIP_REASON_BUDGET_POT_EXHAUSTED);
            }
        }
        // isset-guarded summary read: accessing an UNSET `summary` would FIRE the Argus summary GENERATOR
        // (lazy-load) — the fire path must never spend a model call on the previous run.
        $previousRunOutcome = $previousRunConversation !== null && isset($previousRunConversation->summary)
            ? ($previousRunConversation->summary->outcome ?? null)
            : null;
        $seedUserMessageText = $schedule->composeSeedUserMessageText(
            $previousRunOutcome,
            $previousRunConversation?->getCreatedTime()
        );
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
        $runConversation = $aIConversationAgentService->driveConversation(
            AIConversationDriveScope::ADMIN,
            null,
            // the space the run works for; null = the run of a schedule without a space, at account level
            $agentSpace,
            $seedUserMessageText,
            async: true,
            initiatorType: AIConversation::INITIATOR_TYPE_SCHEDULE,
            scheduleId: (int)$schedule->id,
            // The run belongs to whoever created the schedule, not to the owner of its space. Without this the ADMIN
            // scope would re-derive the owner and the run would carry the owner's rights — the escalation
            // createSchedule() stopped storing. `$owningAccount` is the schedule's own account, loaded above. A run
            // without a space has no owner to derive at all: this account is the one it runs as.
            runAsAccount: $owningAccount,
            // A schedule created inside an eval run fires eval runs: the marker keeps their sends on the test sinks.
            runSettings: $schedule->buildRunSettingsForRun($this->isScheduleCreatedByAnEvalRun($schedule)),
            // The run's purpose: the role statement (execute, never administer), the delivery contract per report
            // channel and the blocked owner-messaging send tools — one place for every instant, timed and recurring run.
            purposeName: AIConversationPurpose::SCHEDULE_RUN,
            // snapshot the schedule's report channel onto the run at creation (+ owed-at-spawn
            // for EMAIL inside driveConversation) — a later schedule edit never changes this run.
            reportChannel: $schedule->reportChannel
        );
        return AIConversationScheduleOccurrenceSpawn::spawned($runConversation);
    }

    /**
     * The space a STORED schedule works for, read now and with the read rights lifted: firing, sweeping and resuming
     * a schedule are the platform's own work on a row its caller was already allowed to act on, and the answer must
     * not depend on who triggers it (the fire cron, an admin, the owner pressing "run now"). The space is returned,
     * not attached to the schedule.
     *
     * A null answer has TWO meanings the caller must tell apart by {@see AIConversationSchedule::$agentSpaceId}: the
     * schedule has no space (account level — the id is empty), or it names a space that no longer exists (the id is
     * set). The second is never treated as the first.
     *
     * @param AIConversationSchedule $schedule
     * @return AgentSpace|null
     */
    protected function findAgentSpaceOfSchedule(AIConversationSchedule $schedule): ?AgentSpace
    {
        $agentSpaceId = $schedule->agentSpaceId ?? null;
        if ($agentSpaceId === null) {
            return null;
        }
        // isset() never triggers the lazy load: a space that is already attached is taken as it is
        if (isset($schedule->agentSpace) && isset($schedule->agentSpace->id) && (int)$schedule->agentSpace->id === (int)$agentSpaceId) {
            return $schedule->agentSpace;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            $agentSpace = $agentSpacesService->find((int)$agentSpaceId, useEntityRegistrCache: false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $agentSpace instanceof AgentSpace ? $agentSpace : null;
    }

    /**
     * Whether `$schedule` can never run for lack of a space: it names a space that no longer exists (the id is set,
     * no space was found), or it has no space in an application that allows no schedule without one
     * ({@see AgentSpacesService::isScheduleWithoutAgentSpaceAllowed()}) — its run would be a conversation without a
     * space, which that application refuses to start.
     *
     * @param AIConversationSchedule $schedule
     * @param AgentSpace|null $agentSpace the space {@see findAgentSpaceOfSchedule()} found for it
     * @return bool
     */
    protected function isScheduleUnableToRunForLackOfAgentSpace(AIConversationSchedule $schedule, ?AgentSpace $agentSpace): bool
    {
        if ($agentSpace !== null) {
            return false;
        }
        if (($schedule->agentSpaceId ?? null) !== null) {
            return true;
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return !$agentSpacesService->isScheduleWithoutAgentSpaceAllowed($schedule);
    }

    /**
     * The automation eligibility `$schedule` is judged by, read fresh: that of its space, and for a schedule without
     * a space that of its account.
     *
     * A schedule that names a space which no longer exists (`$agentSpace` null although the id is set) is asked at
     * account level here. That answer never starts a run: the fire path closes such a row before it asks
     * ({@see spawnRunConversationForClaimedSchedule()}); the sweep and the resume guard only read it to pause, close
     * or re-arm the row.
     *
     * @param AIConversationSchedule $schedule
     * @param AgentSpace|null $agentSpace the space of the schedule as {@see findAgentSpaceOfSchedule()} answered it
     * @return AgentAutomationEligibility
     */
    protected function findEligibilityOfSchedule(AIConversationSchedule $schedule, ?AgentSpace $agentSpace): AgentAutomationEligibility
    {
        /** @var AgentAutomationEligibilityService $agentAutomationEligibilityService */
        $agentAutomationEligibilityService = DDDService::instance()->getService(AgentAutomationEligibilityService::class);
        if ($agentSpace !== null) {
            return $agentAutomationEligibilityService->findEligibilityForAgentSpace($agentSpace, useCache: false);
        }
        return $agentAutomationEligibilityService->findEligibilityForAccountId((int)$schedule->accountId, useCache: false);
    }

    /**
     * Whether the schedule was created inside an eval run: a tool call of an eval run is spawning it right now (the
     * instant `startBackgroundTask` path, {@see EvalRunOutboundScope}), or the conversation it was created in
     * ({@see AIConversationSchedule::$createdByConversationId}) belongs to an eval run's tree. Read rights-deactivated —
     * the fire cron and the impersonated spawn may not read an admin-created sibling's conversation. A schedule
     * without a creating conversation (admin console, REST editor) is no eval schedule.
     */
    protected function isScheduleCreatedByAnEvalRun(AIConversationSchedule $schedule): bool
    {
        if (EvalRunOutboundScope::isAnyScopeOpen()) {
            return true;
        }
        $creatingConversation = $this->findCreatingConversationOfSchedule($schedule);
        if ($creatingConversation === null) {
            return false;
        }
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        return $aIConversationOwnerReportDeliveryService->belongsToAnEvalRunTree($creatingConversation);
    }

    /**
     * The conversation the schedule was created in ({@see AIConversationSchedule::$createdByConversationId}), read
     * rights-deactivated; null without one or when the row is gone.
     */
    protected function findCreatingConversationOfSchedule(AIConversationSchedule $schedule): ?AIConversation
    {
        $createdByConversationId = $schedule->createdByConversationId ?? null;
        if ($createdByConversationId === null) {
            return null;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $creatingConversation = $aIConversationsService->find($createdByConversationId, useEntityRegistrCache: false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $creatingConversation instanceof AIConversation ? $creatingConversation : null;
    }

    /**
     * Terminal bookkeeping for a schedule-spawned run (the run-terminal hook): a COMPLETED run
     * resets the failure streak; a FAILED run increments it (the next claim applies the backoff). Targeted
     * persist — never a full update() (the fire cron may hold a newer row concurrently). After the bookkeeping,
     * best-effort ONLY: tell the owner in the chat the schedule was created in that the run finished
     * ({@see appendRunTerminalNoticeToCreatingConversation}) — a failed notice append
     * must never break the terminal bookkeeping.
     */
    public function recordRunTerminal(AIConversationSchedule $schedule, bool $runCompleted): void
    {
        if (!isset($schedule->id)) {
            return;
        }
        if ($runCompleted) {
            $schedule->failureStreak = 0;
        } else {
            $schedule->failureStreak++;
            $schedule->lastFailedAt = new DateTime();
        }
        $schedule->updatePartialIgnoringRights('failureStreak', 'lastFailedAt');
        try {
            $this->appendRunTerminalNoticeToCreatingConversation($schedule, $runCompleted);
        } catch (\Throwable $noticeAppendError) {
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                'Schedule run-terminal owner notice append failed: ' . $noticeAppendError->getMessage(),
                ['scheduleId' => $schedule->id ?? null]
            );
        }
    }

    /**
     * Append ONE ASSISTANT chat message into the conversation this schedule was CREATED in
     * ({@see AIConversationSchedule::$createdByConversationId}) telling the owner the run
     * finished: a COMPLETED run always ("✅ Your task … has run. Result: …" — outcome from the run's
     * persisted summary, else its last assistant text), a FAILED run only from the SECOND consecutive failure
     * ({@see AIConversationSchedule::$failureStreak} ≥ 2 — a single failure is the backoff's business, not the
     * owner's). The ASSISTANT role is inert — {@see AIConversation::hasUnansweredInboundMessage()} only fires a
     * turn on a trailing USER message or SIGNAL, so this append never re-drives the conversation. SKIPPED when
     * the creating conversation is mid-run or itself owes a turn (a trailing owner question must not look
     * answered by this notice). Rights-deactivated: the run terminal executes impersonated as the customer, which
     * may not read an ADMIN-created sibling's conversation.
     */
    protected function appendRunTerminalNoticeToCreatingConversation(AIConversationSchedule $schedule, bool $runCompleted): void
    {
        if (!$runCompleted && $schedule->failureStreak < 2) {
            return;
        }
        $this->appendOwnerNoticeToCreatingConversation($schedule, $runCompleted ? self::OWNER_NOTICE_RUN_COMPLETED : self::OWNER_NOTICE_RUN_FAILED);
    }

    /**
     * The SCHEDULE BUDGET POT status for the account a schedule would be stored for — the task tools
     * and the customer surface need it before a schedule row exists; the caller resolves that account (the owner of
     * the space, or the account itself for work without a space)
     * under its own read rights (inside the caller's own fail-open guard) and skips
     * the pot logic on null. Null when the owning account is unknown OR anything in the pot computation throws —
     * FAIL-OPEN: the pot is a brake, never an availability risk (the sibling {@see countUnseenFinishedRunsOfAgentSpace}
     * fail-soft documents the failure class: a not-yet-applied DDL column must never take the list surface down).
     */
    public function buildScheduleBudgetPotStatusForAccount(?Account $owningAccount): ?ScheduleBudgetPotStatus
    {
        try {
            if ($owningAccount === null || !isset($owningAccount->id)) {
                return null;
            }
            /** @var ScheduleBudgetPotsService $scheduleBudgetPotsService */
            $scheduleBudgetPotsService = DDDService::instance()->getService(ScheduleBudgetPotsService::class);
            return $scheduleBudgetPotsService->findScheduleBudgetPotStatusForAccount($owningAccount);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Notify the owner ONCE PER MONTH that the SCHEDULE BUDGET POT is exhausted and autonomous runs pause:
     * before appending, the newest
     * {@see AIConversationSchedule::$scheduleBudgetPotExhaustedNotifiedAt} across ALL the account's schedules
     * decides — a marker inside the current calendar month means the owner already knows (whatever schedule told
     * them). The notice lands in THIS schedule's creating conversation
     * ({@see appendOwnerNoticeToCreatingConversation}) and the marker is stamped on THIS schedule either way,
     * so the month-wide dedupe holds even when the notice itself could not be delivered (no creating chat).
     */
    protected function notifyOwnerOncePerMonthThatScheduleBudgetPotIsExhausted(AIConversationSchedule $schedule): void
    {
        if (!isset($schedule->id) || ($schedule->accountId ?? null) === null) {
            return;
        }
        $newestNotifiedAt = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT MAX(`scheduleBudgetPotExhaustedNotifiedAt`) FROM `' . DBAIConversationScheduleModel::getTableName() . '`'
            . ' WHERE `accountId` = :accountId',
            ['accountId' => (int)$schedule->accountId]
        );
        $currentMonthStart = ScheduleBudgetPotsService::getCurrentCalendarMonthStartSqlDateTime();
        // Read-then-stamp: two workers passing this check in the same instant can each send the notice — the
        // race can double-send once per month per account (accepted; a locking read is not worth a chat notice).
        if ($newestNotifiedAt !== null && (string)$newestNotifiedAt >= $currentMonthStart) {
            return;
        }
        $schedule->scheduleBudgetPotExhaustedNotifiedAt = new DateTime();
        $schedule->updatePartialIgnoringRights('scheduleBudgetPotExhaustedNotifiedAt');
        $this->appendOwnerNoticeToCreatingConversation($schedule, self::OWNER_NOTICE_BUDGET_PAUSED);
    }

    /**
     * The shared append of ONE inert ASSISTANT chat message into the conversation this schedule was CREATED in.
     * Two callers write through here: {@see appendRunTerminalNoticeToCreatingConversation} (run finished /
     * repeatedly failed) and {@see notifyOwnerOncePerMonthThatScheduleBudgetPotIsExhausted} (budget pause).
     * The notice text of `$ownerNoticeKind` is composed INSIDE the rights-deactivated block, after the guards
     * ({@see composeOwnerNoticeText()}: its inputs may need the lifted read rights), from the loaded creating
     * conversation, which is what carries the owner's language for the translated notice
     * ({@see AIConversationSchedule::translateOwnerNotice()}).
     *
     * @param string $ownerNoticeKind one of the OWNER_NOTICE_* constants
     */
    protected function appendOwnerNoticeToCreatingConversation(AIConversationSchedule $schedule, string $ownerNoticeKind): void
    {
        if (($schedule->createdByConversationId ?? null) === null) {
            return;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $creatingConversation = $aIConversationsService->find($schedule->createdByConversationId, useEntityRegistrCache: false);
            if (!$creatingConversation instanceof AIConversation
                || !isset($creatingConversation->id)
                || $creatingConversation->isRunInProgress()
            ) {
                return;
            }
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = AIConversationMessage::getService();
            // Bounded newest-page load: enough for the monotonic next index + the trailing-message guard below.
            $creatingConversation->messages = $aIConversationMessagesService->findAllMessagesForConversationInOrder(
                $creatingConversation,
                limit: 5,
                useEntityRegistrCache: false
            ) ?? AIConversationMessages::newInstance();
            if ($creatingConversation->hasUnansweredInboundMessage()) {
                return;
            }
            $noticeText = $this->composeOwnerNoticeText($schedule, $ownerNoticeKind, $creatingConversation);
            $noticeMessage = AIConversationMessage::newInstance();
            $creatingConversation->appendMessage(
                $noticeMessage,
                AIConversationMessage::MESSAGE_TEXT,
                $noticeText,
                AIConversationMessage::ROLE_ASSISTANT,
                AIConversationMessage::AUTHOR_ASSISTANT
            );
            // update(0): the notice message IS the persistence target (the injectConflictSignal precedent —
            // bounded so the message's two same-typed relations can't mis-stamp a parent FK), collision-free.
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $aIConversationMessagesService->persistInboundRowIndexSafe($creatingConversation, $noticeMessage);
            /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
            $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
            $aIConversationRealtimeSignalService->publishChangeSignal($creatingConversation, AIConversationRealtimeSignalService::STATUS_IDLE);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The text of an owner notice of `$ownerNoticeKind` (an OWNER_NOTICE_* constant) in the creating conversation's
     * language: a finished run's notice carries the newest run's outcome ({@see findNewestRunOutcomeText()}).
     */
    protected function composeOwnerNoticeText(AIConversationSchedule $schedule, string $ownerNoticeKind, AIConversation $creatingConversation): string
    {
        return match ($ownerNoticeKind) {
            self::OWNER_NOTICE_RUN_COMPLETED => $schedule->composeRunCompletedOwnerNoticeText($creatingConversation, $this->findNewestRunOutcomeText($schedule)),
            self::OWNER_NOTICE_RUN_FAILED => $schedule->composeRunFailedOwnerNoticeText($creatingConversation),
            self::OWNER_NOTICE_BUDGET_PAUSED => $schedule->composeBudgetPausedOwnerNoticeText($creatingConversation),
        };
    }

    /**
     * The NEWEST run's outcome text for the owner notice: the run conversation's persisted summary outcome when
     * one exists (isset-guarded — an unset `summary` lazy-load would FIRE the Argus summary GENERATOR, and the
     * terminal path must never spend a model call), else the run's last assistant text over a bounded newest
     * message page. Null when the run left neither.
     */
    protected function findNewestRunOutcomeText(AIConversationSchedule $schedule): ?string
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $newestRunConversation = $aIConversationsService->findNewestScheduleRunConversation((int)$schedule->id);
        if ($newestRunConversation === null) {
            return null;
        }
        if (isset($newestRunConversation->summary) && trim((string)($newestRunConversation->summary->outcome ?? '')) !== '') {
            return $newestRunConversation->summary->outcome;
        }
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = AIConversationMessage::getService();
        $newestRunConversation->messages = $aIConversationMessagesService->findAllMessagesForConversationInOrder(
            $newestRunConversation,
            limit: 10,
            useEntityRegistrCache: false
        ) ?? AIConversationMessages::newInstance();
        return $newestRunConversation->getLastAssistantText();
    }

    /** @var int Hard cap for the run-outcome text fed to the doneCriteria judgment — one bounded classification call. */
    public const int DONE_CRITERIA_JUDGMENT_OUTCOME_MAX_CHARS = 6000;

    /**
     * Judge a COMPLETED run's final answer against the schedule's doneCriteria and transition the schedule to DONE
     * when they are fulfilled. Returns true iff the transition happened.
     * Short-circuits without a model call when there are no doneCriteria, the schedule is not ACTIVE (a pause/
     * cancel between fire and terminal must stand), or the run reported nothing to judge. A FAILED/unparseable
     * judgment call changes nothing — the next run terminal simply judges again. Callers keep this best-effort
     * (schedule bookkeeping must never break the turn path — the recordRunTerminalOfConversation contract).
     */
    public function transitionToDoneWhenDoneCriteriaFulfilled(AIConversationSchedule $schedule, string $runOutcomeText): bool
    {
        if (!isset($schedule->id)
            || !$schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_DONE_BY_CRITERIA, new DateTime())->isAllowed
            || trim($runOutcomeText) === ''
        ) {
            return false;
        }
        $doneCriteriaFulfilled = $this->judgeDoneCriteriaFulfilled($schedule, mb_substr(trim($runOutcomeText), 0, self::DONE_CRITERIA_JUDGMENT_OUTCOME_MAX_CHARS));
        if ($doneCriteriaFulfilled !== true) {
            return false;
        }
        $this->transitionStatus($schedule, AIConversationScheduleStatus::DONE);
        return true;
    }

    /**
     * The judgment MODEL CALL ({@see ArgusScheduleDoneCriteriaJudgment}, FAST_CHEAP, budget-exempt) — the method
     * container-less unit tests override. Returns the verdict, or null when the call failed / returned nothing
     * parseable (the caller then changes nothing).
     */
    protected function judgeDoneCriteriaFulfilled(AIConversationSchedule $schedule, string $boundedRunOutcomeText): ?bool
    {
        $argusScheduleDoneCriteriaJudgment = new ArgusScheduleDoneCriteriaJudgment();
        $argusScheduleDoneCriteriaJudgment->task = $schedule->task;
        $argusScheduleDoneCriteriaJudgment->doneCriteria = (string)$schedule->doneCriteria;
        $argusScheduleDoneCriteriaJudgment->runOutcomeText = $boundedRunOutcomeText;
        $argusScheduleDoneCriteriaJudgment->budgetAccount = $schedule->account ?? null;
        $argusScheduleDoneCriteriaJudgment->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
        return $argusScheduleDoneCriteriaJudgment->judged ? $argusScheduleDoneCriteriaJudgment->fulfilled : null;
    }

    /**
     * The RUN-NOW guard of the customer's run-now action and the admin fire-now (one rule for both): a
     * one-time row fires only at its moment, only an ACTIVE row fires, an expired row never fires, and a row fires by
     * hand at most once per {@see AIConversationScheduleTransition::MANUAL_FIRE_MINIMUM_INTERVAL_SECONDS}.
     *
     * @throws BadRequestException naming the rule that refuses
     */
    public function assertScheduleMayRunNow(AIConversationSchedule $schedule): void
    {
        $runNowTransition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_RUN_NOW, new DateTime());
        if ($runNowTransition->isAllowed) {
            return;
        }
        $scheduleIdentifier = (string)($schedule->id ?? '?');
        throw new BadRequestException(match ($runNowTransition->refusalReason) {
            AIConversationScheduleTransition::REFUSAL_ONE_TIME_FIRES_AT_ITS_MOMENT => "Schedule {$scheduleIdentifier} is a one-time task — it fires once at its planned moment; firing it now"
                . ' would consume that single occurrence early. Move the moment via the edit endpoint instead.',
            AIConversationScheduleTransition::REFUSAL_EXPIRED => "Schedule {$scheduleIdentifier} has expired (ttl or maximum runs reached) — it no longer fires.",
            AIConversationScheduleTransition::REFUSAL_MANUAL_FIRE_INTERVAL => "Schedule {$scheduleIdentifier} fired within the last hour — manual fire is limited to once per hour;"
                . ' try again in ' . (int)ceil((int)$runNowTransition->secondsUntilManualFireIsAllowed / 60) . ' minutes.',
            default => "Schedule {$scheduleIdentifier} cannot be fired in status {$schedule->status} — only an ACTIVE schedule fires.",
        });
    }

    /**
     * The customer's and the admin's "fire now": the run-now rule ({@see assertScheduleMayRunNow()}), then the SAME
     * arm + claim + spawn as the cron ({@see armClaimAndSpawnNow()}). Null when the rule refuses, the claim was lost
     * (concurrent fire / pause) or a guardrail skipped the occurrence.
     */
    public function fireScheduleNow(AIConversationSchedule $schedule): ?AIConversation
    {
        if (!isset($schedule->id) || !$schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_RUN_NOW, new DateTime())->isAllowed) {
            return null;
        }
        return $this->armClaimAndSpawnNow($schedule);
    }

    /**
     * Fire an EVAL schedule now, as often as the evaluation asks (the structure of the
     * reports across runs of one schedule is measured by firing it several times in a row). Only a schedule whose run
     * settings mark it an eval schedule (`evalRun` — its runs mail nothing) bypasses the once-per-hour rule of a manual
     * fire; the arm, claim and spawn are the cron's ({@see armClaimAndSpawnNow()}).
     *
     * @throws BadRequestException the schedule is no eval schedule
     */
    public function fireEvalScheduleNow(AIConversationSchedule $schedule): ?AIConversation
    {
        if (!isset($schedule->id) || !(isset($schedule->runSettings) && $schedule->runSettings->evalRun === true)) {
            throw new BadRequestException('Only an eval schedule (runSettings.evalRun) fires without the manual fire interval.');
        }
        return $this->armClaimAndSpawnNow($schedule);
    }

    /**
     * The first firing of a row created to run at once — the agent's `startBackgroundTask` (a one-time row with its
     * moment = now): ACTIVE, not expired, never fired ({@see AIConversationScheduleTransition::OPERATION_START_NOW}),
     * then the same arm + claim + spawn as the cron. Null when refused, the claim was lost or a guardrail skipped.
     */
    public function startScheduleNow(AIConversationSchedule $schedule): ?AIConversation
    {
        if (!isset($schedule->id) || !$schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_START_NOW, new DateTime())->isAllowed) {
            return null;
        }
        return $this->armClaimAndSpawnNow($schedule);
    }

    /**
     * Arm the fire anchor to NOW (targeted persist), then run the SAME claim-compare-and-set and spawn tail as the
     * cron — a fire by hand shares every guardrail and the no-double-fire property with the regular fire path.
     */
    protected function armClaimAndSpawnNow(AIConversationSchedule $schedule): ?AIConversation
    {
        $schedule->nextRunAt = new DateTime();
        $schedule->updatePartialIgnoringRights('nextRunAt');
        if (!$this->claimFireOccurrence($schedule)) {
            return null;
        }
        return $this->spawnRunConversationForClaimedSchedule($schedule)->runConversation;
    }

    /**
     * Transition the lifecycle status in a targeted persist (pause / resume / cancel / expire / done) — the one writer
     * of an owner or platform status change (the fire command's expiry and the pot skip's EXPIRED included);
     * only the state sweep writes its status with its reason ({@see transitionStatusForAutomationState()}). RESUME
     * (→ ACTIVE) recomputes the fire anchor from NOW so a long pause never fires a backlog of missed occurrences.
     */
    public function transitionStatus(AIConversationSchedule $schedule, string $status): void
    {
        if (!isset($schedule->id)) {
            return;
        }
        $schedule->status = $status;
        if ($status === AIConversationScheduleStatus::ACTIVE) {
            $schedule->nextRunAt = $schedule->computeNextRunAt(new DateTime());
            $schedule->updatePartialIgnoringRights('status', 'nextRunAt');
            return;
        }
        $schedule->updatePartialIgnoringRights('status');
    }

    /**
     * The STATE SWEEP's candidate scan (rights-deactivated cron context): non-terminal schedules which the platform
     * itself paused (a `stateReason` — the re-arm candidates), or which the application's filter names
     * ({@see buildAutomationStateSweepSqlFilter()}: a space or an owner that is no longer active). A cheap pre-filter:
     * every candidate is evaluated FRESH by the predicate afterwards, so a row selected here is never transitioned on
     * this query alone. Oldest first, bounded.
     *
     * @return int[]
     */
    public function findScheduleIdsForAutomationStateSweep(int $limit = self::STATE_SWEEP_BATCH_LIMIT): array
    {
        $automationStateSweepSqlFilter = $this->buildAutomationStateSweepSqlFilter();
        return array_map('intval', EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            $this->buildAutomationStateSweepSql($automationStateSweepSqlFilter, $limit),
            array_merge(
                $automationStateSweepSqlFilter->parameters,
                ['sweptStatuses' => [AIConversationScheduleStatus::ACTIVE, AIConversationScheduleStatus::PAUSED]]
            ),
            ['sweptStatuses' => ArrayParameterType::STRING]
        ));
    }

    /**
     * The statement of {@see findScheduleIdsForAutomationStateSweep()}. Pure: depends only on its arguments.
     *
     * @param AgentApplicationSqlFilter $automationStateSweepSqlFilter The application's join and condition
     * @param int $limit The candidate cap (at least one row)
     * @return string
     */
    protected function buildAutomationStateSweepSql(AgentApplicationSqlFilter $automationStateSweepSqlFilter, int $limit): string
    {
        return 'SELECT `schedule`.`id` FROM `' . DBAIConversationScheduleModel::getTableName() . '` `schedule`'
            . ($automationStateSweepSqlFilter->joinSql === '' ? '' : ' ' . $automationStateSweepSqlFilter->joinSql)
            . ' WHERE `schedule`.`status` IN (:sweptStatuses) AND ('
            . '   `schedule`.`stateReason` IS NOT NULL'
            . ($automationStateSweepSqlFilter->conditionSql === '' ? '' : '   ' . $automationStateSweepSqlFilter->getConditionSqlConnectedByOr())
            . ' ) ORDER BY `schedule`.`id` ASC LIMIT ' . max(1, $limit);
    }

    /**
     * Override point: the join and the condition that select the schedules whose space or owner is no longer active,
     * for {@see findScheduleIdsForAutomationStateSweep()}. The statement names the schedule table `schedule`; the space
     * of a schedule is `schedule.agentSpaceId` (empty for a schedule at account level — a join over it finds no row
     * for such a schedule, which the condition must not read as "no longer active"), its account
     * `schedule.accountId`. The condition is connected with `OR` to the platform's own
     * (`schedule.stateReason IS NOT NULL`). Both fragments must run on MariaDB and on PostgreSQL: every identifier
     * in backticks (the framework rewrites them for the database in use; an unquoted camelCase name is folded to
     * lower case on PostgreSQL), no function of one engine only.
     *
     * The package knows the state of no space and no account, so the default is the empty filter: the sweep then sees
     * only the schedules the platform paused. An application returns its tables from its same-path subclass; without
     * them a schedule of an ended space is refused when it fires (the eligibility check of the fire path), not before.
     *
     * @return AgentApplicationSqlFilter
     */
    protected function buildAutomationStateSweepSqlFilter(): AgentApplicationSqlFilter
    {
        return AgentApplicationSqlFilter::none();
    }

    /**
     * ONE row of the state sweep: evaluate the eligibility of the schedule FRESH (that of its space; of its account
     * for a schedule without a space — {@see findEligibilityOfSchedule()}), let the entity decide
     * ({@see AIConversationSchedule::decideTransitionForAutomationState()} — the four-row rule table), apply the
     * transition, and on a CLOSE cancel the schedule's in-flight runs and close their owed report obligations with
     * the same reason. Returns the transition that was applied (or would be, on `$dryRun`), null when the row stays as it is.
     *
     * @param AIConversationSchedule $schedule
     * @param bool $dryRun true = decide only, write nothing
     * @return AIConversationScheduleTransition|null
     */
    public function applyAutomationStateSweepToSchedule(AIConversationSchedule $schedule, bool $dryRun = false): ?AIConversationScheduleTransition
    {
        if (!isset($schedule->id)) {
            return null;
        }
        /** @var AgentAutomationEligibilityService $agentAutomationEligibilityService */
        $agentAutomationEligibilityService = DDDService::instance()->getService(AgentAutomationEligibilityService::class);
        $eligibility = $this->findEligibilityOfSchedule($schedule, $this->findAgentSpaceOfSchedule($schedule));
        $stateSweepTransition = $schedule->decideTransitionForAutomationState($eligibility);
        if ($stateSweepTransition === null) {
            return null;
        }
        $targetStatus = (string)$stateSweepTransition->targetStatus;
        $stateReason = $stateSweepTransition->stateReason;
        if (!$stateSweepTransition->reArmsFireAnchor) {
            // a pause / close goes through the ONE decision point, so it is logged like every other refusal
            $agentAutomationEligibilityService->refusesAutomation(
                $eligibility,
                'scheduleStateSweep:' . $targetStatus,
                AgentEligibilityLogContext::forSchedule($this->getScheduleIdForLog($schedule))
            );
        }
        if ($dryRun) {
            return $stateSweepTransition;
        }
        $this->transitionStatusForAutomationState($schedule, $targetStatus, $stateReason);
        if ($targetStatus === AIConversationScheduleStatus::CANCELLED && $stateReason !== null) {
            /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
            $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
            $aIConversationOwnerReportDeliveryService->cancelRunsAndCloseReportObligationsOfSchedule((int)$schedule->id, $stateReason);
        }
        return $stateSweepTransition;
    }

    /**
     * The PLATFORM's own transition for a state of the account or the agent space: the status AND the reason in ONE
     * targeted persist — PAUSED + AGENT_SPACE_SUSPENDED / ACCOUNT_SUSPENDED (re-armed by the sweep when the state
     * clears), CANCELLED + AGENT_SPACE_ENDED / ACCOUNT_ENDED / ACCOUNT_ERASED (terminal). A re-arm (→ ACTIVE) passes
     * a null reason: the marker is cleared and the fire anchor recomputed from NOW.
     */
    public function transitionStatusForAutomationState(AIConversationSchedule $schedule, string $status, ?string $stateReason): void
    {
        if (!isset($schedule->id)) {
            return;
        }
        $schedule->status = $status;
        $schedule->stateReason = $stateReason;
        if ($status === AIConversationScheduleStatus::ACTIVE) {
            $schedule->nextRunAt = $schedule->computeNextRunAt(new DateTime());
            $schedule->updatePartialIgnoringRights('status', 'stateReason', 'nextRunAt');
            return;
        }
        $schedule->updatePartialIgnoringRights('status', 'stateReason');
    }

    /**
     * The RESUME guard of every resume path: a row the platform paused refuses ("closed by the
     * platform"), a one-time row whose moment has passed refuses (re-arming it would fire a past moment), and
     * a resumable row on an agent space / account that is not eligible refuses naming the STATE, never "budget" — an owner
     * must not re-arm a task on a locked agent space from the automations tab.
     *
     * @throws BadRequestException naming the state that refuses
     */
    public function assertScheduleMayBeResumed(AIConversationSchedule $schedule): void
    {
        $now = new DateTime();
        $resumeTransition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_RESUME, $now);
        if (!$resumeTransition->isAllowed) {
            if ($resumeTransition->refusalReason === AIConversationScheduleTransition::REFUSAL_CLOSED_BY_PLATFORM_STATE) {
                throw new BadRequestException(___(
                    'Schedule %scheduleId% was paused by the platform (%reason%) and resumes by itself once the '
                    . AgentApplicationWording::current()->billingEntityName . ' or account is active again.',
                    placeholders: [
                        'scheduleId' => (string)($schedule->id ?? '?'),
                        'reason' => strtolower(str_replace('_', ' ', (string)$schedule->stateReason)),
                    ]
                ));
            }
            if ($resumeTransition->refusalReason === AIConversationScheduleTransition::REFUSAL_ONE_TIME_MOMENT_PASSED) {
                throw new BadRequestException(
                    'Schedule ' . ($schedule->id ?? '?') . ' cannot be resumed: its one-time moment has passed. Schedule a new one-time task instead.'
                );
            }
            throw new BadRequestException(___(
                'Schedule %scheduleId% cannot be resumed in status %status%.',
                placeholders: ['scheduleId' => (string)($schedule->id ?? '?'), 'status' => (string)$schedule->status]
            ));
        }
        /** @var AgentAutomationEligibilityService $agentAutomationEligibilityService */
        $agentAutomationEligibilityService = DDDService::instance()->getService(AgentAutomationEligibilityService::class);
        // the eligibility of the space of the schedule; of its account for a schedule without a space
        $eligibility = $this->findEligibilityOfSchedule($schedule, $this->findAgentSpaceOfSchedule($schedule));
        $resumeTransition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_RESUME, $now, $eligibility);
        if ($resumeTransition->refusalReason === AIConversationScheduleTransition::REFUSAL_SCOPE_NOT_ELIGIBLE
            && $agentAutomationEligibilityService->refusesAutomation(
                $eligibility,
                'scheduleResume',
                AgentEligibilityLogContext::forSchedule($this->getScheduleIdForLog($schedule))
            )
        ) {
            throw new BadRequestException(___(
                'Schedule %scheduleId% cannot be resumed: the ' . AgentApplicationWording::current()->billingEntityName
                . ' or account is %state% (%reason%).',
                placeholders: [
                    'scheduleId' => (string)($schedule->id ?? '?'),
                    'state' => strtolower($eligibility->state),
                    'reason' => strtolower(str_replace('_', ' ', (string)$eligibility->reason)),
                ]
            ));
        }
    }

    /**
     * Schedule-run terminal bookkeeping: when a SCHEDULE-spawned run reaches a terminal —
     * fully settled (success) or FAILED — record the outcome on the owning {@see AIConversationSchedule} so the
     * next fire-claim applies/clears the failure backoff. No-op for every conversation without a
     * {@see AIConversation::$scheduleId}. Best-effort: schedule bookkeeping must NEVER break the turn path.
     * A later CUSTOMER reply into a run conversation re-fires this on its settle — harmless (a success just
     * re-clears the streak).
     *
     * @param bool $runCompleted the run fully settled (false: it FAILED)
     */
    public function recordRunTerminalOfConversation(AIConversation $conversation, bool $runCompleted): void
    {
        if (($conversation->scheduleId ?? null) === null) {
            return;
        }
        try {
            // System bookkeeping of a row the fire cron owns — rights-deactivated (the worker runs impersonated
            // as the customer account, which could not see an ADMIN-created sibling account's schedule edge cases).
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $schedule = $this->find($conversation->scheduleId, useEntityRegistrCache: false);
                if ($schedule instanceof AIConversationSchedule) {
                    $this->recordRunTerminal($schedule, $runCompleted);
                    // ONE-TIME schedule: its single execution just terminated —
                    // close it as DONE (success or failure alike; there is no later occurrence to retry on).
                    $oneTimeDoneTransition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_DONE_AFTER_ONE_TIME_RUN, new DateTime());
                    if ($oneTimeDoneTransition->isAllowed) {
                        $this->transitionStatus($schedule, (string)$oneTimeDoneTransition->targetStatus);
                    }
                    // a COMPLETED run's final answer is judged against the schedule's
                    // doneCriteria (FAST_CHEAP, budget-exempt) — fulfilled → the schedule closes as DONE and stops
                    // firing. Guards + the no-op cases live in the service method; a failed judgment changes
                    // nothing (the next terminal judges again).
                    if ($runCompleted) {
                        $scheduleTransitionedToDone = $this->transitionToDoneWhenDoneCriteriaFulfilled(
                            $schedule,
                            (string)($conversation->getLastAssistantText() ?? '')
                        );
                        if ($scheduleTransitionedToDone) {
                            $this->aiTrace('schedule.doneCriteriaFulfilled', ['conversationId' => $conversation->id ?? null, 'scheduleId' => $conversation->scheduleId]);
                        }
                    }
                }
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
            $this->aiTrace('schedule.runTerminal', ['conversationId' => $conversation->id ?? null, 'scheduleId' => $conversation->scheduleId, 'runCompleted' => $runCompleted]);
        } catch (Throwable $scheduleBookkeepingError) {
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                "AIConversationSchedulesService: schedule-run terminal bookkeeping failed for conversation {$conversation->id} (schedule {$conversation->scheduleId}): "
                . $scheduleBookkeepingError->getMessage()
                . ' at ' . $scheduleBookkeepingError->getFile() . ':' . $scheduleBookkeepingError->getLine()
                . "\n" . $scheduleBookkeepingError->getTraceAsString()
            );
        }
    }
}
