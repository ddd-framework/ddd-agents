<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleNewestRun;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleRunSummary;
use DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationAnchor;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunRelease;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveTarget;
use DDD\Domain\AI\Entities\Conversations\EntryPoints\AIConversationEntryPointStartLock;
use DDD\Domain\AI\Exceptions\Conversations\ConversationClosedException;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\CostSummaries\AIConversationCostRow;
use DDD\Domain\AI\Entities\Conversations\CostSummaries\AIConversationCostRows;
use DDD\Domain\AI\Entities\Conversations\CostSummaries\AIConversationToolCallTraceEntries;
use DDD\Domain\AI\Entities\Conversations\CostSummaries\AIConversationToolCallTraceEntry;
use DDD\Domain\AI\Entities\Conversations\CostSummaries\AIConversationTreeCostSummaries;
use DDD\Domain\AI\Entities\Conversations\CostSummaries\AIConversationTreeCostSummary;
use DDD\Domain\AI\Entities\Conversations\AIConversationSummary;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\MessageHandlers\AIConversationSummarizeMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIConversationSummary;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversation;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\AI\Services\Conversations\Situations\AgentSituationalContextService;
use DDD\Domain\AI\Services\Conversations\Environments\AgentEnvironmentNamesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonValueKind;
use DDD\Domain\Base\Repo\DB\Dialect\Locks\LockService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Charts\DateValueSequence;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Throwable;
use DDD\Infrastructure\Exceptions\NotFoundException;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\Mapping\MappingException;
use JsonException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * @method AIConversation|null find(string|int|null $entityId, bool $useEntityRegistrCache = true)
 * @method AIConversations findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AIConversation update(AIConversation $entity, int $depth = \DDD\Domain\Base\Repo\DatabaseRepoEntity::UPDATE_DEFAULT_RECURSIVE_DEPTH)
 * @method DBAIConversation getEntityRepoClassInstance()
 * @method DBAIConversations getEntitySetRepoClassInstance()
 */
class AIConversationsService extends EntitiesService
{
    use AITraceLogTrait;

    public const string DEFAULT_ENTITY_CLASS = AIConversation::class;

    /**
     * FLEET-WIDE conversation starts per day for the agent admin control panel — delegation children
     * excluded, as a chart-ready {@see DateValueSequence}.
     *
     * @param int|null $excludedAccountId Account id to leave out (the admin AI usage KPI dashboard passes the
     * internal-provisioning account, excluded from every customer figure); `null` counts every account.
     * @param bool $excludeTestUsers Leave test accounts out — together with the exclusion above this turns the
     * fleet-wide control-panel figure into the CUSTOMER figure the KPI cube reports. Off by default.
     * @throws \Doctrine\DBAL\Exception
     */
    public function findConversationCountsPerBucket(
        DateTime $from,
        DateTime $to,
        bool $byWeek = false,
        ?int $excludedAccountId = null,
        bool $excludeTestUsers = false
    ): DateValueSequence {
        return $this->getEntityRepoClassInstance()
            ->findConversationCountsPerBucket($from, $to, $byWeek, $excludedAccountId, $excludeTestUsers);
    }

    /**
     * ACTIVE accounts per day for the agent admin control panel — an account counts as active on a day it
     * sent at least one user message, as a chart-ready {@see DateValueSequence}.
     *
     * @param int|null $excludedAccountId Account id to leave out — see {@see findConversationCountsPerBucket()}.
     * @param bool $excludeTestUsers Leave test accounts out — see {@see findConversationCountsPerBucket()}.
     * @throws \Doctrine\DBAL\Exception
     */
    public function findActiveAccountCountsPerBucket(
        DateTime $from,
        DateTime $to,
        bool $byWeek = false,
        ?int $excludedAccountId = null,
        bool $excludeTestUsers = false
    ): DateValueSequence {
        return $this->getEntityRepoClassInstance()
            ->findActiveAccountCountsPerBucket($from, $to, $byWeek, $excludedAccountId, $excludeTestUsers);
    }

    /**
     * The earliest date the agent conversation records reach back to, under the same customer scoping as
     * {@see findConversationCountsPerBucket()} — `null` when no conversation matches at all.
     *
     * The admin KPI cube reports this so its agent section can tell "the agent served nobody in this
     * period" apart from "the conversation records do not reach back this far". The two per-bucket
     * readers omit an empty bucket rather than reporting a zero for it, so downstream the two cases
     * are indistinguishable without this date. See {@see DBAIConversation::findEarliestConversationDate()}.
     *
     * @param int|null $excludedAccountId Account id to leave out — see {@see findConversationCountsPerBucket()}.
     * @param bool $excludeTestUsers Leave test accounts out — see {@see findConversationCountsPerBucket()}.
     * @throws \Doctrine\DBAL\Exception
     */
    public function findEarliestConversationDate(
        ?int $excludedAccountId = null,
        bool $excludeTestUsers = false
    ): ?Date {
        return $this->getEntityRepoClassInstance()->findEarliestConversationDate($excludedAccountId, $excludeTestUsers);
    }

    /**
     * DISTINCT active accounts over one window (the control panel's WAU/MAU number) — same active-user definition as
     * {@see self::findActiveAccountCountsPerBucket()}.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function countDistinctActiveAccounts(DateTime $from, DateTime $to): int
    {
        return $this->getEntityRepoClassInstance()->countDistinctActiveAccounts($from, $to);
    }

    /**
     * The TOP conversations of a range for the agent admin control panel — per ROOT conversation the whole
     * tree's message count / tokens / cost (delegation children folded in), ordered by a whitelisted column
     * (default: costliest first), limited.
     *
     * @param string $orderBy 'totalCostInUSD' | 'totalTokens' | 'messageCount'
     * @throws \Doctrine\DBAL\Exception
     */
    public function findTopConversationStats(DateTime $from, DateTime $to, string $orderBy = 'totalCostInUSD', int $limit = 50): \DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentConversationStats
    {
        return $this->getEntityRepoClassInstance()->findTopConversationStats($from, $to, $orderBy, $limit);
    }

    /**
     * Set (or clear) the per-conversation compaction thresholds — the admin/debug knob that overrides the
     * global defaults so a tester can force compaction on a short conversation. Pass null to clear the override and
     * fall back to the global defaults. Persists the conversation's `compactionSettings` JSON column and returns it.
     *
     * @param AIConversation $conversation
     * @param CompactionSettings|null $compactionSettings
     * @return AIConversation
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function updateCompactionSettings(AIConversation $conversation, ?CompactionSettings $compactionSettings): AIConversation
    {
        $conversation->compactionSettings = $compactionSettings;
        // targeted single-column persist — NEVER a full-row update (it would clobber a concurrent run-claim's
        // runState/runToken written by the atomic SQL primitives).
        $this->persistCompactionSettings($conversation);
        return $conversation;
    }

    // TARGETED single-column persists: each writes ONLY its column via the entity's
    // updatePartialIgnoringRights() so a concurrent atomic run-claim/heartbeat is never clobbered by a full-row update
    // writing back stale run-state — and, unlike a full update(), it does NOT reload/detach the caller's live conversation.

    /** Persist ONLY `budgetBypass`. */
    public function persistBudgetBypass(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->updatePartialIgnoringRights('budgetBypass');
    }

    /** Persist ONLY `aiModelNameOverride`. */
    public function persistAiModelNameOverride(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->updatePartialIgnoringRights('aiModelNameOverride');
    }

    /** Persist ONLY the `orchestratorRunCapOverride` column (0 = supervision fully OFF) — targeted, never a full-row update. */
    public function persistOrchestratorRunCapOverride(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->updatePartialIgnoringRights('orchestratorRunCapOverride');
    }

    /** Persist ONLY the `orchestratorModelNameOverride` column (the supervisor VERDICT model) — targeted, never a full-row update. */
    public function persistOrchestratorModelNameOverride(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->updatePartialIgnoringRights('orchestratorModelNameOverride');
    }

    /** Persist ONLY the `summary` VO column (no-op if no summary set). */
    protected function persistSummary(AIConversation $conversation): void
    {
        if (!isset($conversation->id) || !isset($conversation->summary)) {
            return;
        }
        $conversation->updatePartialIgnoringRights('summary');
    }

    /** Persist ONLY the `compactionSettings` VO column (null clears it). */
    public function persistCompactionSettings(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->updatePartialIgnoringRights('compactionSettings');
    }

    /** Persist ONLY the `runSettings` VO column (null clears it) — same targeted-write rationale as its siblings. */
    public function persistRunSettings(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->updatePartialIgnoringRights('runSettings');
    }

    /**
     * Record a TURN-OWING inbound persist: stamp `lastInboundMessageAt = now` AND NULL `finalizedAt` in ONE
     * targeted two-column persist — a new user message / injected signal both mark the conversation active and re-arm
     * the idle-finalize cycle (a resumed finalized conversation gets marked + completion-compacted again after the
     * next idle window). Called wherever a turn-owing inbound is persisted (driveConversation's user message + start
     * signal, injectSignal). No-op on an ephemeral conversation.
     */
    public function recordInboundMessageActivity(AIConversation $conversation, bool $stampAgentTurnOwed = false): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $previousInboundAt = $conversation->lastInboundMessageAt ?? null;
        $conversation->lastInboundMessageAt = new DateTime();
        $conversation->finalizedAt = null;
        // the first message after a pause in an EXISTING conversation is the owner's first contact of the day as
        // much as a new conversation is: today's check, claim-fenced, so a
        // second pause of the same day starts nothing
        if ($previousInboundAt !== null
            && $conversation->lastInboundMessageAt->getTimestamp() - $previousInboundAt->getTimestamp() >= self::LOCAL_SITUATION_PAUSE_SECONDS) {
            /** @var AgentSituationalContextService $agentSituationalContextService */
            $agentSituationalContextService = DDDService::instance()->getService(AgentSituationalContextService::class);
            $agentSituationalContextService->startLocalSituationResearchOnFirstContact($conversation);
        }
        // every message reads the local situation as it stands now: a conversation of yesterday
        // gets today's situation, the first conversation of a space the research that finished after it began
        $this->refreshLocalSituation($conversation);
        if ($stampAgentTurnOwed) {
            // the chunk-yield path ONLY — the handover marker ({@see AIConversation::$agentTurnOwedAt}):
            // set here, cleared by claimRun's UPDATE when the next worker takes over. The owed-run recovery cron re-fires
            // any stamp that survives the quiet window (= the handover message was lost).
            $conversation->agentTurnOwedAt = new DateTime();
            $conversation->updatePartialIgnoringRights('lastInboundMessageAt', 'finalizedAt', 'agentTurnOwedAt');
            return;
        }
        $conversation->updatePartialIgnoringRights('lastInboundMessageAt', 'finalizedAt');
    }

    /** An inbound after at least this pause starts today's situation check (claim-fenced, once a day). */
    public const int LOCAL_SITUATION_PAUSE_SECONDS = 10800;

    /** Quiet window for the owed-turns recovery pass: a healthy yield→claim handover completes in seconds; a handover stamp older than this = the re-fire was lost. */
    public const int AGENT_TURN_OWED_QUIET_SECONDS = 300;

    /** Per-pass candidate cap for the owed-run recovery cron — bounded like every cron pass. */
    public const int OWED_CONVERSATIONS_BATCH_LIMIT = 50;

    /** How far back the lost-resume pass looks for an IDLE conversation with an unanswered customer message (one day). */
    public const int LOST_RESUME_LOOKBACK_SECONDS = 86400;

    /**
     * Owed-REPORTS selector, restricted to THIS environment's conversations ({@see DBAIConversations::buildEnvironmentPredicate()}):
     * conversations whose terminal stamped a report obligation that was never
     * delivered — `reportDeliveredAt IS NULL AND reportOwedAt IS NOT NULL` and NOT in a non-terminal run state —
     * oldest first, bounded. Self-clearing: delivery stamps `reportDeliveredAt`. Served by the
     * (reportDeliveredAt, reportOwedAt) index.
     *
     * The run-state condition: an async run in progress is not late — its obligation is stamped at spawn, so without
     * it the late-delivery pass claims a live run's report and appends a template while the run is about to deliver
     * the real one (two reports, and the run's own send refused). A run whose worker died stays non-terminal until
     * the application's reaper cron expires it; only then does it qualify here — late delivery for a crashed
     * worker therefore depends on that cron. A SYNCHRONOUSLY driven conversation never takes the run claim and stays
     * IDLE, so the condition does not cover it; there the send tool's stale-claim take-back is the guard.
     *
     * An EVAL run's tree is never selected ({@see buildNotAnEvalRunTreeSqlPredicate()}): its row carries the case's
     * report channel and initiator, and a late delivery would send a real owner mail for a test run.
     *
     * @return int[]
     */
    public function findConversationIdsOwingReports(int $limit = self::OWED_CONVERSATIONS_BATCH_LIMIT): array
    {
        return array_map('intval', EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT `id` FROM `' . DBAIConversationModel::getTableName() . '`'
            . ' WHERE `reportDeliveredAt` IS NULL AND `reportOwedAt` IS NOT NULL'
            . ' AND (`runState` IS NULL OR `runState` NOT IN (:nonTerminalRunStates))'
            . ' AND ' . self::buildNotAnEvalRunTreeSqlPredicate(DBAIConversationModel::getTableName())
            . ' AND ' . DBAIConversations::buildEnvironmentPredicate(DBAIConversationModel::getTableName(), self::currentEnvironment())
            . ' ORDER BY `reportOwedAt` ASC LIMIT ' . $limit,
            ['nonTerminalRunStates' => AIConversationRunState::NON_TERMINAL, 'environment' => self::currentEnvironment()],
            ['nonTerminalRunStates' => \Doctrine\DBAL\ArrayParameterType::STRING]
        ));
    }

    /**
     * The SQL condition "the row belongs to no eval run's tree": neither the row itself nor its delegation root carries
     * `runSettings.evalRun = true`. `$tableName` is the conversations table the outer query reads unaliased.
     */
    public static function buildNotAnEvalRunTreeSqlPredicate(string $tableName): string
    {
        $evalRunStampOfRootSql = '(' . EntityManagerFactory::getDialect()->jsonFieldText('`evalRunRoot`.`runSettings`', 'evalRun') . ')';
        return DBAIConversations::buildNotAnEvalRunPredicate($tableName)
            . ' AND (`' . $tableName . '`.`rootConversationId` IS NULL OR `' . $tableName . '`.`rootConversationId` NOT IN ('
            . 'SELECT `evalRunRoot`.`id` FROM `' . $tableName . '` `evalRunRoot` WHERE ' . $evalRunStampOfRootSql . " = 'true'))";
    }

    /**
     * Owed-TURNS selector: conversations whose yield→claim HANDOVER is still open past the quiet window
     * — `runState = IDLE AND agentTurnOwedAt IS NOT NULL AND agentTurnOwedAt < :quietBefore`, oldest first,
     * bounded. Self-clearing by construction (claimRun NULLs the stamp), so every row here IS a lost re-fire
     * candidate. Served by the (runState, agentTurnOwedAt) index. An eval conversation is never resumed
     * ({@see DBAIConversations::buildNotAnEvalRunPredicate()}).
     *
     * @return int[]
     */
    public function findConversationIdsOwingContinuations(
        int $quietSeconds = self::AGENT_TURN_OWED_QUIET_SECONDS,
        int $limit = self::OWED_CONVERSATIONS_BATCH_LIMIT
    ): array {
        $quietBefore = (new DateTime())->modify('-' . $quietSeconds . ' seconds');
        return array_map('intval', EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT `id` FROM `' . DBAIConversationModel::getTableName() . '`'
            . ' WHERE `runState` = :idle AND `agentTurnOwedAt` IS NOT NULL AND `agentTurnOwedAt` < :quietBefore'
            . ' AND ' . DBAIConversations::buildEnvironmentPredicate(DBAIConversationModel::getTableName(), self::currentEnvironment())
            . ' AND ' . DBAIConversations::buildNotAnEvalRunPredicate(DBAIConversationModel::getTableName())
            . ' ORDER BY `agentTurnOwedAt` ASC LIMIT ' . $limit,
            ['idle' => AIConversationRunState::IDLE, 'quietBefore' => $quietBefore->format('Y-m-d H:i:s'), 'environment' => self::currentEnvironment()]
        ));
    }

    /**
     * Lost-resume selector: conversations left IDLE with a customer message NEWER than the last heartbeat and NO
     * handover marker — the shape a resume leaves behind when the consuming worker died before claiming and the
     * broker's redelivery was rejected (the message is gone, nothing re-fires, no sweep owns the row). The DB
     * predicate is deliberately coarse (a message persisted a second after the last step's heartbeat looks the
     * same); the caller derives precisely on the loaded tail ({@see AIConversation::lastWireMessageIsFinalAssistantText()})
     * and only then stamps the handover marker + re-fires. Bounded to the last {@see LOST_RESUME_LOOKBACK_SECONDS}
     * (older rows are history, not a lost turn), newest first, delegation children excluded (their fan-in owns them).
     *
     * The second shape (the safety net for a turn that failed AFTER its first heartbeat): an IDLE row whose last turn
     * counted a failed attempt (`resumeAttempts` between 1 and {@see AgentRunRelease::MAX_RESUME_ATTEMPTS} — an exhausted
     * count is never re-fired) and whose heartbeat is older than the quiet window, again without a handover marker. Its
     * own heartbeat is NEWER than the owner's message, so the first shape never sees it; the failure release stamps the
     * marker today, so this shape catches only a release that could not (a lost claim, a row released before that
     * change). The SQL drops a row whose newest TEXT / SIGNAL row is the agent's text (answered — it would otherwise be
     * reselected every tick for the lookback); the caller derives the full tail before it re-fires. Rows are taken newest
     * inbound first, so a fresh owed row never waits behind an older one. IDLE in the predicate means no live claim: a
     * claimed row is RUNNING. An eval conversation is never resumed ({@see DBAIConversations::buildNotAnEvalRunPredicate()}).
     *
     * @return int[]
     */
    public function findConversationIdsWithInboundLeftIdle(
        int $quietSeconds = self::AGENT_TURN_OWED_QUIET_SECONDS,
        int $limit = self::OWED_CONVERSATIONS_BATCH_LIMIT,
        int $lookbackSeconds = self::LOST_RESUME_LOOKBACK_SECONDS
    ): array {
        $quietBefore = (new DateTime())->modify('-' . $quietSeconds . ' seconds');
        $lookbackAfter = (new DateTime())->modify('-' . $lookbackSeconds . ' seconds');
        return array_map('intval', EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT `id` FROM `' . DBAIConversationModel::getTableName() . '`'
            . ' WHERE `runState` = :idle AND `agentTurnOwedAt` IS NULL AND `delegatedFromMessageId` IS NULL'
            . ' AND `lastInboundMessageAt` IS NOT NULL AND `lastInboundMessageAt` > :lookbackAfter'
            . ' AND ('
            . '(`lastInboundMessageAt` < :quietBefore AND (`runHeartbeatAt` IS NULL OR `runHeartbeatAt` < `lastInboundMessageAt`))'
            . ' OR (`resumeAttempts` > 0 AND `resumeAttempts` <= :maxResumeAttempts AND `runHeartbeatAt` IS NOT NULL AND `runHeartbeatAt` < :quietBefore'
            // the cheap half of the tail derive in SQL: the newest TEXT / SIGNAL row is not the agent's text — an
            // answered row would otherwise be reselected every tick for the whole lookback and take batch slots
            . ' AND COALESCE((SELECT `newestTextOrSignal`.`role` FROM `' . DBAIConversationMessageModel::getTableName() . '` `newestTextOrSignal`'
            . ' WHERE `newestTextOrSignal`.`aiConversationId` = `' . DBAIConversationModel::getTableName() . '`.`id`'
            . ' AND `newestTextOrSignal`.`messageType` IN (:textOrSignalTypes) AND `newestTextOrSignal`.`truncatedAt` IS NULL'
            . " ORDER BY `newestTextOrSignal`.`indexInConversation` DESC LIMIT 1), '') <> :assistantRole)"
            . ')'
            . ' AND ' . DBAIConversations::buildEnvironmentPredicate(DBAIConversationModel::getTableName(), self::currentEnvironment())
            . ' AND ' . DBAIConversations::buildNotAnEvalRunPredicate(DBAIConversationModel::getTableName())
            . ' ORDER BY `lastInboundMessageAt` DESC LIMIT ' . $limit,
            [
                'idle' => AIConversationRunState::IDLE,
                'quietBefore' => $quietBefore->format('Y-m-d H:i:s'),
                'lookbackAfter' => $lookbackAfter->format('Y-m-d H:i:s'),
                'maxResumeAttempts' => AgentRunRelease::MAX_RESUME_ATTEMPTS,
                'textOrSignalTypes' => [AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::MESSAGE_SIGNAL],
                'assistantRole' => AIConversationMessage::ROLE_ASSISTANT,
                'environment' => self::currentEnvironment(),
            ],
            ['textOrSignalTypes' => \Doctrine\DBAL\ArrayParameterType::STRING]
        ));
    }

    /**
     * Owed-turns CLEAR (belt-and-suspenders): the precise derive found the candidate NOT owed after all
     * (it raced a healthy drive that ended before the recovery pass loaded it) — clear the handover marker in a targeted
     * write so the row leaves the candidate set without a re-fire.
     */
    public function clearAgentTurnOwed(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->agentTurnOwedAt = null;
        $conversation->updatePartialIgnoringRights('agentTurnOwedAt');
    }

    /**
     * The NEWEST run conversation of a schedule — the fire path's guardrail input ("is the previous
     * run still in progress?") and the source of the previous-outcome seed line. Newest = highest id (one run per
     * firing, ids are monotonic). Null when the schedule has not fired yet.
     */
    public function findNewestScheduleRunConversation(int $scheduleId): ?AIConversation
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.scheduleId = :scheduleId")
            ->setParameter('scheduleId', $scheduleId)
            ->orderBy("{$baseModelAlias}.id", 'DESC')
            ->setMaxResults(1);
        // Positional arg: the runtime signature's param is the historic `useEntityRegistrCache` (no `y`) — the
        // repo docblocks advertise the corrected name, so a named arg here fails at runtime.
        return $repoClass->find($queryBuilder, false);
    }

    /**
     * The earlier ROOT runs of one schedule, newest first, without `$runToExclude` (the run asking): the candidates whose
     * reports `getPreviousTaskRunReports` reads. A delegation child inherits its
     * parent's `scheduleId` and is no run of its own. Rights-scoped like every conversation read.
     *
     * @return AIConversation[]
     */
    public function findEarlierRunConversationsOfSchedule(AIConversationSchedule $schedule, AIConversation $runToExclude, int $limit): array
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.scheduleId = :scheduleId")
            ->andWhere("{$baseModelAlias}.delegatedFromMessageId IS NULL")
            ->andWhere("{$baseModelAlias}.id < :runToExcludeId")
            ->setParameter('scheduleId', (int)$schedule->id)
            ->setParameter('runToExcludeId', (int)($runToExclude->id ?? PHP_INT_MAX))
            ->orderBy("{$baseModelAlias}.id", 'DESC')
            ->setMaxResults($limit);
        // positional arg: the runtime signature's param is the historic `useEntityRegistrCache`
        $earlierRunConversations = $repoClass->find($queryBuilder, false);
        return $earlierRunConversations instanceof AIConversations ? $earlierRunConversations->getElements() : [];
    }

    /** @var string The refusal of a drive for which no account can be determined; an application may word it its own way. */
    protected const string NO_ACCOUNT_TO_DRIVE_AS_MESSAGE = 'The account this conversation runs as could not be determined.';

    /**
     * What a drive of a conversation runs on (formerly inline in
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::driveConversation()}): the conversation it
     * continues, the space the conversation works for (none: account level) and the account it runs as.
     *
     * THE SPACE. A conversation that is continued keeps its own: its stored reference decides, also when it is empty
     * (an account-level conversation never takes the space a request names), and `$agentSpace` is ignored. A NEW
     * conversation works for `$agentSpace`, or at account level when none is given — a regular case, never refused
     * for the missing space. Before a new conversation is created the account is checked HERE, on every path and
     * never only in an endpoint: with a space {@see AgentSpacesService::requireAgentSpaceUsableByAccount()}, without
     * one {@see AgentSpacesService::requireConversationStartWithoutAgentSpaceAllowed()}.
     *
     * THE ACCOUNT.
     * - ADMIN: the HTTP caller is an admin (the wrong account) → the existing conversation and its space are read
     *   with rights bypassed; the drive then impersonates the account. An EXISTING conversation's OWN account is the
     *   authoritative owner — it may have been started by a DIFFERENT account than the owner of its space (a managing
     *   account operating on a client's space): impersonating the owner of the space would make the appended
     *   message's create-rights re-check fail (the message's aiConversation.accountId would not match → 403), so the
     *   owner of the space is asked ONLY when nothing else names the account. A caller that already KNOWS whose run
     *   this is (`$runAsAccount`, the schedule fire path: a sub-user's task belongs to that sub-user, which is what
     *   {@see AIConversation::getAccountRights()} reads) wins over the owner of the space — for a new conversation
     *   only. Without a space there is no owner to fall back to: the existing conversation's account, else
     *   `$runAsAccount` decides, and with neither the drive is refused.
     * - CUSTOMER: the caller IS the account; rights APPLIED on the find → only its OWN conversation, and
     *   the platform's own hidden run answers like a missing one. A COMPLETED / CANCELLED conversation accepts no further
     *   customer message (new concerns start a new conversation; the ADMIN scope bypasses
     *   this for debugging). A FAILED run is terminal for the WORKERS (retry cap exhausted), not for the customer: a new
     *   message revives it exactly like the chat's "retry" button — without this the message was persisted and never
     *   answered (seven "continue" into a dead run).
     *
     * @param bool $isAdmin true = the admin path (rights lifted, the account impersonated by the caller)
     * @param int|null $aiConversationId the conversation to continue; null = a new conversation
     * @param AgentSpace|null $agentSpace the space a NEW conversation works for; null = account level
     * @param Account|null $runAsAccount the account a new conversation of the admin path runs as
     * @param string|null $purposeName the purpose of a new conversation (asked of a start without a space)
     * @param string|null $entryPointKey the entry point a new conversation is started from (asked of a start without a space)
     * @return AIConversationDriveTarget
     * @throws NotFoundException when the conversation to continue is unknown, foreign or hidden from the caller
     * @throws BadRequestException when the account cannot be determined, or the application refuses the start
     * @throws ForbiddenException when the account may not use the space, or the application refuses the start
     * @throws ConversationClosedException a customer continuing a closed conversation
     */
    public function findConversationToDrive(
        bool $isAdmin,
        ?int $aiConversationId,
        ?AgentSpace $agentSpace,
        ?Account $runAsAccount,
        ?string $purposeName = null,
        ?string $entryPointKey = null
    ): AIConversationDriveTarget {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        if ($isAdmin) {
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $existingConversation = $aiConversationId !== null ? $this->find($aiConversationId) : null;
                // the space is read inside the rights-lifted block; its owner only when the conversation's account /
                // the run-as account does not decide
                $effectiveAgentSpace = $existingConversation instanceof AIConversation
                    ? $this->findAgentSpaceOfStoredConversation($existingConversation)
                    : $agentSpace;
                $account = $existingConversation instanceof AIConversation ? ($existingConversation->account ?? null) : $runAsAccount;
                if (!$account instanceof Account && $effectiveAgentSpace !== null) {
                    $account = $agentSpacesService->findOwnerAccountOfAgentSpace($effectiveAgentSpace);
                }
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        } else {
            $account = AuthService::instance()->getAccount();
            $existingConversation = $aiConversationId !== null ? $this->find($aiConversationId) : null;
            $effectiveAgentSpace = $agentSpace;
            if ($existingConversation instanceof AIConversation && $existingConversation->isHiddenFromCustomer()) {
                $existingConversation = null;
            }
            if ($existingConversation instanceof AIConversation) {
                $effectiveAgentSpace = $this->findAgentSpaceOfStoredConversation($existingConversation);
                if ($existingConversation->isClosed()) {
                    throw new ConversationClosedException();
                }
                if ($existingConversation->runState === AIConversationRunState::FAILED && $this->runClaimsService()->rearmRunForRetry($existingConversation)) {
                    $this->aiTrace('turn.retry.on_inbound', ['conversationId' => $existingConversation->id]);
                }
            }
        }

        if ($aiConversationId !== null && !$existingConversation instanceof AIConversation) {
            throw new NotFoundException("AIConversation {$aiConversationId} not found.");
        }
        if (!$account instanceof Account) {
            throw new BadRequestException($isAdmin ? static::NO_ACCOUNT_TO_DRIVE_AS_MESSAGE : 'Authentication is required to drive a conversation.');
        }
        if (!$existingConversation instanceof AIConversation) {
            // a NEW conversation: the one check before a row is stored with (or without) a space
            if ($effectiveAgentSpace !== null) {
                $agentSpacesService->requireAgentSpaceUsableByAccount($effectiveAgentSpace, $account);
            } else {
                $agentSpacesService->requireConversationStartWithoutAgentSpaceAllowed($account, $purposeName, $entryPointKey);
            }
        }
        return AIConversationDriveTarget::of($existingConversation instanceof AIConversation ? $existingConversation : null, $effectiveAgentSpace, $account);
    }

    /**
     * The space a stored conversation works for; null = account level. The stored reference alone decides: a
     * conversation without one stays at account level whatever space a request names. Loads the space only when the
     * conversation references one; that load passes the read rights
     * ({@see \DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace::lazyload()}), so a stored reference yields its space.
     *
     * @param AIConversation $conversation a stored conversation
     * @return AgentSpace|null
     */
    protected function findAgentSpaceOfStoredConversation(AIConversation $conversation): ?AgentSpace
    {
        if (!isset($conversation->agentSpaceId)) {
            return null;
        }
        return $conversation->agentSpace ?? null;
    }

    /**
     * The newest STILL-ACTIVE purposed conversation for (space, purposeName) — the get-or-create singleton
     * (one non-terminal purposed conversation per (space, purpose); every entry
     * point RESUMES this one instead of spawning a second). "Active" excludes the terminal states
     * (COMPLETED / CANCELLED) — an IDLE-at-rest conversation between turns is still an active one the owner
     * resumes. Read-rights applied (the customer resolves only its OWN conversation).
     *
     * @param AgentSpace $agentSpace
     * @param string $purposeName
     * @return AIConversation|null
     */
    public function findNewestActivePurposedConversationOfAgentSpace(AgentSpace $agentSpace, string $purposeName): ?AIConversation
    {
        return $this->findNewestPurposedConversationOfAgentSpace($agentSpace, $purposeName, includeCompleted: false);
    }

    /**
     * The newest purposed conversation of a space — by default the newest one still running (the singleton
     * rule), with $includeCompleted also a COMPLETED or CANCELLED one, for a purpose whose finished conversation
     * stays the singleton ({@see AIConversationPurpose::$completedConversationStaysSingleton}). The singleton of a
     * purpose is per space: the read filters the space, which fixes the account.
     *
     * @param AgentSpace $agentSpace
     * @param string $purposeName
     * @param bool $includeCompleted
     * @return AIConversation|null
     */
    public function findNewestPurposedConversationOfAgentSpace(AgentSpace $agentSpace, string $purposeName, bool $includeCompleted = false): ?AIConversation
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.purposeName = :purposeName")
            ->setParameter('purposeName', $purposeName)
            ->orderBy("{$baseModelAlias}.id", 'DESC')
            ->setMaxResults(1);
        DBAIConversations::restrictToConversationsOfAgentSpace($queryBuilder, $agentSpace);
        if (!$includeCompleted) {
            $queryBuilder->andWhere("{$baseModelAlias}.runState NOT IN (:closedRunStates)")
                ->setParameter('closedRunStates', AIConversationRunState::CLOSED);
        }
        return $repoClass->find($queryBuilder, false);
    }

    /**
     * The ROOT conversations of a space a customer could have written in since `$inboundSince` — the DISCOVERY of
     * owner input by a background run: rows with `lastInboundMessageAt` at or
     * after the moment, never a delegation child, never a supervisor conversation, never the platform's hidden runs
     * ({@see DBAIConversations::restrictToCustomerVisibleConversations()}), newest inbound first, at most `$limit`. The
     * read runs on the index of `agentSpaceId` (the FK column); the caller applies the eligibility rule to each row. Fresh
     * (no registry cache): the working state's screening verdicts must be the persisted ones. Rights are the caller's
     * (a worker lifts them for the space it works on).
     *
     * @param AgentSpace $agentSpace
     * @param DateTime $inboundSince
     * @param int $limit
     * @return AIConversations
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCustomerRootConversationsOfAgentSpaceWithInboundSince(AgentSpace $agentSpace, DateTime $inboundSince, int $limit): AIConversations
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.lastInboundMessageAt >= :inboundSince")
            ->andWhere("{$baseModelAlias}.delegatedFromMessageId IS NULL")
            ->setParameter('inboundSince', $inboundSince)
            ->orderBy("{$baseModelAlias}.lastInboundMessageAt", 'DESC')
            ->setMaxResults(max(1, $limit));
        DBAIConversations::restrictToConversationsOfAgentSpace($queryBuilder, $agentSpace);
        DBAIConversations::restrictToCustomerVisibleConversations($queryBuilder);
        return $repoClass->find($queryBuilder, false);
    }

    /**
     * The conversations named by id, fresh (no registry cache) — the owner-input intake loads the run's source and build
     * conversations and the conversations of the calls of its space through this.
     *
     * @param int[] $conversationIds
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findConversationsByIdsFresh(array $conversationIds): AIConversations
    {
        $conversationIds = array_values(array_unique(array_filter(array_map('intval', $conversationIds))));
        if ($conversationIds === []) {
            return AIConversations::newInstance();
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.id IN (:conversationIds)")
            ->setParameter('conversationIds', $conversationIds)
            ->setMaxResults(count($conversationIds));
        return $repoClass->find($queryBuilder, false);
    }

    /**
     * The id of the newest OPEN CUSTOMER CHAT of a space — a root conversation a customer sees (no delegation child, no
     * supervisor tandem, no purpose at all, so no platform-hidden run either), not closed, not an eval conversation
     * (`runSettings.evalRun`), of THIS environment ({@see DBAIConversations::buildEnvironmentPredicate()}), with an inbound since `$inboundSince`.
     * `$preferredConversationId` wins when it passes the same predicate (the chat a background run was requested from);
     * `$excludedConversationId` never qualifies (the run's own conversation). The whole predicate sits in the query BEFORE the
     * limit — a read that limits first and filters after lets newer ineligible chats hide an eligible older one. One raw
     * read on the index of `agentSpaceId`, no rights (the caller works on its own space): the space fixes the account.
     * Read by the question relay of a background run.
     *
     * @param AgentSpace $agentSpace
     * @param DateTime $inboundSince
     * @param int|null $preferredConversationId
     * @param int|null $excludedConversationId
     * @return int|null null also for a space that is not stored (it has no conversation)
     * @throws \Doctrine\DBAL\Exception
     */
    public function findNewestOpenCustomerChatIdOfAgentSpace(AgentSpace $agentSpace, DateTime $inboundSince, ?int $preferredConversationId = null, ?int $excludedConversationId = null): ?int
    {
        $tableName = DBAIConversationModel::getTableName();
        $agentSpaceSqlCondition = AgentSpaceSqlCondition::forAgentSpace($agentSpace);
        $openChatId = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `id` FROM `' . $tableName . '`'
            . ' WHERE ' . $agentSpaceSqlCondition->getConditionSql()
            . ' AND `delegatedFromMessageId` IS NULL'
            . ' AND `supervisedConversationId` IS NULL'
            . ' AND `purposeName` IS NULL'
            . ' AND `runState` NOT IN (:closedRunStates)'
            . ' AND `lastInboundMessageAt` >= :inboundSince'
            . ' AND ' . DBAIConversations::buildNotAnEvalRunPredicate($tableName)
            . ' AND `id` <> :excludedConversationId'
            . ' AND ' . DBAIConversations::buildEnvironmentPredicate($tableName, self::currentEnvironment())
            . ' ORDER BY (`id` = :preferredConversationId) DESC, `lastInboundMessageAt` DESC, `id` DESC'
            . ' LIMIT 1',
            $agentSpaceSqlCondition->getParameters() + [
                'closedRunStates' => AIConversationRunState::CLOSED,
                'inboundSince' => $inboundSince->format('Y-m-d H:i:s'),
                'excludedConversationId' => $excludedConversationId ?? 0,
                'environment' => self::currentEnvironment(),
                'preferredConversationId' => $preferredConversationId ?? 0,
            ],
            ['closedRunStates' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        return $openChatId === false || $openChatId === null ? null : (int)$openChatId;
    }

    /** @var int How long an entry point's conversation is reopened instead of starting a new one: 6 h since its last inbound, or since its creation when it has none. */
    public const int ENTRY_POINT_REOPEN_WINDOW_SECONDS = 21600;

    /** @var int How many of the newest matching conversations the reopen look-up loads before the environment and eval checks. */
    public const int ENTRY_POINT_REOPEN_CANDIDATE_LIMIT = 5;

    /** @var int How long a start waits for another start with the same entry-point key to finish. */
    public const int ENTRY_POINT_START_LOCK_TIMEOUT_SECONDS = 10;

    /**
     * The conversation a product entry point (`$entryPointKey`, {@see AIConversation::$entryPointKey}) opened for
     * `$account` in `$agentSpace` and that is still recent: active within {@see self::ENTRY_POINT_REOPEN_WINDOW_SECONDS}
     * before `$now` (its last inbound, or its creation when it has none). Only a customer dialog qualifies: a root
     * conversation (no delegation child, no supervisor tandem), no purpose, no initiator, not closed and not FAILED (a
     * failed run handed back would not answer the click — a new conversation starts instead), not an eval
     * conversation, of THIS environment ({@see keepConversationIdsOfCurrentEnvironment()}). The account filter is the
     * account's OWN conversations — stricter than the read rights, which apply on top. The newest match wins.
     *
     * WITHOUT A SPACE (`$agentSpace` null) the read selects the ACCOUNT-LEVEL conversations of `$account`
     * (`agentSpaceId IS NULL` together with the account filter in the same statement) — never the conversations of
     * all its spaces, and never the account-level conversations of another account.
     *
     * One QueryBuilder read on the scalar columns (the composite `(agentSpaceId, accountId, entryPointKey)` index once it
     * exists, the index of `agentSpaceId` until then), uncached: a start that waited for the lock of another start with the same key
     * must see the conversation that start just stored. The environment and eval checks read JSON columns and run on the
     * at most {@see self::ENTRY_POINT_REOPEN_CANDIDATE_LIMIT} rows it returns.
     *
     * @param Account $account
     * @param AgentSpace|null $agentSpace null = the account-level conversations of `$account`
     * @param string $entryPointKey
     * @param DateTime $now
     * @return AIConversation|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws Exception
     */
    public function findReopenableConversationForEntryPoint(Account $account, ?AgentSpace $agentSpace, string $entryPointKey, DateTime $now): ?AIConversation
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $activeSince = (clone $now)->modify('-' . self::ENTRY_POINT_REOPEN_WINDOW_SECONDS . ' seconds');
        // the account and the partition in one step: without a space the account filter is what confines the read
        $repoClass::restrictToConversationsOfAccountInAgentSpacePartition(
            $queryBuilder,
            (int)$account->id,
            AgentSpaceSqlCondition::forAgentSpace($agentSpace, 'entryPointAgentSpaceId'),
            'entryPointAccountId'
        );
        $queryBuilder
            ->andWhere("{$baseModelAlias}.entryPointKey = :entryPointKey")
            ->andWhere("{$baseModelAlias}.rootConversationId IS NULL")
            ->andWhere("{$baseModelAlias}.delegatedFromMessageId IS NULL")
            ->andWhere("{$baseModelAlias}.supervisedConversationId IS NULL")
            ->andWhere("{$baseModelAlias}.purposeName IS NULL")
            ->andWhere("{$baseModelAlias}.initiatorType IS NULL")
            ->andWhere("{$baseModelAlias}.runState NOT IN (:entryPointClosedRunStates)")
            ->andWhere("COALESCE({$baseModelAlias}.lastInboundMessageAt, {$baseModelAlias}.created) >= :entryPointActiveSince")
            ->setParameter('entryPointKey', $entryPointKey)
            ->setParameter('entryPointClosedRunStates', [...AIConversationRunState::CLOSED, AIConversationRunState::FAILED])
            ->setParameter('entryPointActiveSince', $activeSince->format('Y-m-d H:i:s'))
            ->orderBy("{$baseModelAlias}.id", 'DESC')
            ->setMaxResults(self::ENTRY_POINT_REOPEN_CANDIDATE_LIMIT);
        $candidates = $repoClass->find($queryBuilder, false);
        if (!$candidates instanceof AIConversations || $candidates->isEmpty()) {
            return null;
        }
        $currentEnvironmentConversationIds = $this->keepConversationIdsOfCurrentEnvironment($candidates->getEntityIds());
        foreach ($candidates->getElements() as $candidate) {
            if (in_array((int)$candidate->id, $currentEnvironmentConversationIds, true) && !$candidate->isEvalRun()) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Take the start lock of an entry point ({@see AIConversationEntryPointStartLock}) — waits up to
     * {@see self::ENTRY_POINT_START_LOCK_TIMEOUT_SECONDS} for another start with the same key to finish. A lock that is not
     * free in time is logged and the start runs unserialised: a second conversation is the lesser harm than a start the
     * owner clicked and that did nothing.
     *
     * The lock is per (account, space, key); a start without a space locks at the account level of `$account`.
     *
     * @param Account $account
     * @param AgentSpace|null $agentSpace null = a start at account level
     * @param string $entryPointKey
     * @return AIConversationEntryPointStartLock
     * @throws InternalErrorException when the space is not stored
     * @throws Exception
     */
    public function lockEntryPointStart(Account $account, ?AgentSpace $agentSpace, string $entryPointKey): AIConversationEntryPointStartLock
    {
        // a space that is not stored throws, it never locks as "no space"
        $agentSpaceId = AgentSpace::getIdOfStoredAgentSpace($agentSpace);
        $lockName = AIConversationEntryPointStartLock::buildLockName((int)$account->id, $agentSpaceId, $entryPointKey);
        if ($this->acquireNamedLock($lockName, self::ENTRY_POINT_START_LOCK_TIMEOUT_SECONDS)) {
            return AIConversationEntryPointStartLock::taken($lockName);
        }
        \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->warning(
            "AIConversationsService: the entry-point start lock '{$lockName}' ("
            . ($agentSpaceId !== null ? "space {$agentSpaceId}" : 'account level') . ') was not free within '
            . self::ENTRY_POINT_START_LOCK_TIMEOUT_SECONDS . ' s — the start runs unserialised.'
        );
        return AIConversationEntryPointStartLock::notTaken($lockName);
    }

    /**
     * Give the start lock of an entry point back; nothing when this start does not hold it.
     *
     * @throws Exception
     */
    public function unlockEntryPointStart(AIConversationEntryPointStartLock $aIConversationEntryPointStartLock): void
    {
        if (!$aIConversationEntryPointStartLock->isReleaseOwed()) {
            return;
        }
        $this->releaseNamedLock($aIConversationEntryPointStartLock->lockName);
        $aIConversationEntryPointStartLock->markReleased();
    }

    /**
     * @var LockService[] Lock name → the lock service that took the lock. The same instance gives it back:
     *      it counts its own holds, and MariaDB hands out a new instance per call.
     */
    protected array $lockServicesByHeldLockName = [];

    /** @var array<string, int> Lock name → how many times this process holds the lock. */
    protected array $holdCountsByHeldLockName = [];

    /**
     * One attempt to take a named lock with the given wait ({@see \DDD\Domain\Base\Repo\DB\Dialect\SqlDialect::locks()}:
     * MariaDB on the default connection, PostgreSQL on the framework's own direct connection for named locks) — true
     * when this process now holds the lock.
     *
     * @throws Exception
     */
    protected function acquireNamedLock(string $lockName, int $timeoutSeconds): bool
    {
        $lockService = $this->lockServicesByHeldLockName[$lockName]
            ?? EntityManagerFactory::getDialect()->locks(EntityManagerFactory::getInstance()->getConnection());
        if (!$lockService->acquire($lockName, $timeoutSeconds)) {
            return false;
        }
        $this->lockServicesByHeldLockName[$lockName] = $lockService;
        $this->holdCountsByHeldLockName[$lockName] = ($this->holdCountsByHeldLockName[$lockName] ?? 0) + 1;
        return true;
    }

    /** Release the named lock; a failure is swallowed (it runs in a `finally`) — the lock expires with the connection. */
    protected function releaseNamedLock(string $lockName): void
    {
        $lockService = $this->lockServicesByHeldLockName[$lockName] ?? null;
        if ($lockService === null) {
            return;
        }
        try {
            $lockService->release($lockName);
        } catch (\Throwable) {
            // the lock expires with the connection
        }
        $remainingHoldCount = ($this->holdCountsByHeldLockName[$lockName] ?? 1) - 1;
        if ($remainingHoldCount > 0) {
            $this->holdCountsByHeldLockName[$lockName] = $remainingHoldCount;
            return;
        }
        unset($this->lockServicesByHeldLockName[$lockName], $this->holdCountsByHeldLockName[$lockName]);
    }

    /**
     * The tandem SUPERVISOR conversation for a supervised conversation, or null when the
     * supervised conversation has never been checkpointed. 1:1 by `supervisedConversationId`.
     */
    public function findSupervisorConversationFor(int $supervisedConversationId): ?AIConversation
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.supervisedConversationId = :supervisedConversationId")
            ->setParameter('supervisedConversationId', $supervisedConversationId)
            ->orderBy("{$baseModelAlias}.id", 'DESC')
            ->setMaxResults(1);
        return $repoClass->find($queryBuilder, false);
    }

    /**
     * The RUN-HISTORY projection of a schedule (customer + admin surfaces): one row per run
     * conversation — id, created, runState, the persisted summary's title/outcome, and the run's message-cost sum —
     * newest first, keyset-paged via `beforeConversationId`. Raw SQL: the projection reads 5 scalar columns + one
     * JSON extract per row; hydrating full conversation entities (and risking the summary lazy-load) for a list
     * would be waste. AUTHORIZATION IS THE CALLER'S: load the schedule under read-rights first — the rows here are
     * scoped only by `scheduleId`.
     *
     * @param int $scheduleId
     * @param int $limit At most 100 runs per read
     * @param int|null $beforeConversationId Only runs older than this conversation
     * @return AIConversationScheduleRunSummary[] The runs, newest first
     * @throws \Doctrine\DBAL\Exception
     */
    public function findScheduleRunSummaries(int $scheduleId, int $limit = 20, ?int $beforeConversationId = null): array
    {
        $conversationsTable = DBAIConversationModel::getTableName();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $sqlDialect = EntityManagerFactory::getDialect();
        $parameters = ['scheduleId' => $scheduleId];
        $keysetPredicate = '';
        if ($beforeConversationId !== null) {
            $keysetPredicate = ' AND `id` < :beforeConversationId';
            $parameters['beforeConversationId'] = $beforeConversationId;
        }
        $runRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT `id` AS `conversationId`, `created`, `runState`,'
            . ' ' . $sqlDialect->jsonFieldText('`summary`', 'title') . ' AS `title`,'
            . ' ' . $sqlDialect->jsonFieldText('`summary`', 'outcome') . ' AS `outcome`,'
            . ' (SELECT COALESCE(SUM(' . DBAIConversationMessage::buildCostInUSDIncludingToolCallExecutionSql('messages') . "), 0) FROM `{$messagesTable}` `messages`"
            . "  WHERE `messages`.`aiConversationId` = `{$conversationsTable}`.`id`)"
            // what the run booked outside its messages and tool calls (decision calls, summary, compaction, …)
            . " + COALESCE(`{$conversationsTable}`.`overheadCostsInUSD`, 0) AS `costInUSD`"
            . " FROM `{$conversationsTable}`"
            // ROOT runs only: a delegation child inherits its parent's scheduleId and is not a run of its own.
            . " WHERE `scheduleId` = :scheduleId AND `delegatedFromMessageId` IS NULL{$keysetPredicate}"
            . ' ORDER BY `id` DESC LIMIT ' . max(1, min(100, $limit)),
            $parameters
        );
        $scheduleRunSummaries = [];
        foreach ($runRows as $runRow) {
            $scheduleRunSummaries[] = AIConversationScheduleRunSummary::fromRunSummaryRow($runRow);
        }
        return $scheduleRunSummaries;
    }

    /**
     * The NEWEST run per schedule for a SET of schedule ids in ONE query (the "last result" line
     * of a schedule list must not become an N+1). Per schedule id: conversationId, runState, outcome
     * (truncated server-side to {@see AIConversationScheduleNewestRun::OUTCOME_EXCERPT_CHARACTERS} characters — a
     * line of the list, not the report). AUTHORIZATION IS THE CALLER'S — the caller passes ids it already read
     * rights-scoped.
     *
     * @param int[] $scheduleIds
     * @return AIConversationScheduleNewestRun[] Keyed by schedule id; a schedule without a run has no element
     * @throws \Doctrine\DBAL\Exception
     */
    public function findNewestScheduleRunSummaryPerSchedule(array $scheduleIds): array
    {
        $scheduleIds = array_values(array_filter(array_map(intval(...), $scheduleIds)));
        if ($scheduleIds === []) {
            return [];
        }
        $conversationsTable = DBAIConversationModel::getTableName();
        // Newest row per group via "no newer sibling exists" — served by the (scheduleId, id) access path.
        $newestRunRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT `conversation`.`scheduleId` AS `scheduleId`, `conversation`.`id` AS `conversationId`, `conversation`.`runState`,'
            . ' LEFT(' . EntityManagerFactory::getDialect()->jsonFieldText('`conversation`.`summary`', 'outcome') . ', ' . AIConversationScheduleNewestRun::OUTCOME_EXCERPT_CHARACTERS . ') AS `outcome`'
            . " FROM `{$conversationsTable}` `conversation`"
            // ROOT runs on BOTH sides: a delegation child inherits the scheduleId — without the predicate a newer
            // child replaces its own parent as the schedule's newest run.
            . ' WHERE `conversation`.`scheduleId` IN (:scheduleIds) AND `conversation`.`delegatedFromMessageId` IS NULL'
            . " AND NOT EXISTS (SELECT 1 FROM `{$conversationsTable}` `newerRun`"
            . '  WHERE `newerRun`.`scheduleId` = `conversation`.`scheduleId` AND `newerRun`.`delegatedFromMessageId` IS NULL'
            . '  AND `newerRun`.`id` > `conversation`.`id`)',
            ['scheduleIds' => $scheduleIds],
            ['scheduleIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $newestRunByScheduleId = [];
        foreach ($newestRunRows as $newestRunRow) {
            $scheduleNewestRun = AIConversationScheduleNewestRun::fromNewestRunRow($newestRunRow);
            $newestRunByScheduleId[$scheduleNewestRun->scheduleId] = $scheduleNewestRun;
        }
        return $newestRunByScheduleId;
    }

    /**
     * LLM cost + token totals per conversation TREE (the root plus all its delegation children — children
     * carry `rootConversationId`), for a SET of root conversation ids in ONE query. Costs are recorded per
     * message ({@see AIConversationMessage::$costInUSD}) plus per tool call ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::$executionCosts} — the
     * search fees and page readings a tool booked), so the sum over the tree's messages IS the tree's spend. The
     * admin list of runs reads this for each run's total cost column.
     * AUTHORIZATION IS THE CALLER'S (admin surface) — the rows are scoped only by the given ids.
     *
     * @param int[] $rootConversationIds
     */
    public function sumConversationTreeCostsByRootConversationId(array $rootConversationIds): AIConversationTreeCostSummaries
    {
        $aIConversationTreeCostSummaries = new AIConversationTreeCostSummaries();
        $rootConversationIds = array_values(array_filter(array_map('intval', $rootConversationIds)));
        if ($rootConversationIds === []) {
            return $aIConversationTreeCostSummaries;
        }
        $conversationsTable = DBAIConversationModel::getTableName();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        // a tree = the root, its delegation children and its supervisor conversation (supervisedConversationId)
        $treeTotalRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT COALESCE(`c`.`rootConversationId`, `c`.`supervisedConversationId`, `c`.`id`) AS `rootConversationId`,'
            . ' COALESCE(SUM(' . DBAIConversationMessage::buildCostInUSDIncludingToolCallExecutionSql('m') . '), 0) AS `costInUSD`,'
            . ' COALESCE(SUM(`m`.`inputTokens`), 0) AS `inputTokens`,'
            . ' COALESCE(SUM(`m`.`outputTokens`), 0) AS `outputTokens`,'
            . ' COUNT(`m`.`id`) AS `messageCount`,'
            . ' COUNT(DISTINCT `c`.`id`) AS `conversationCount`'
            . " FROM `{$conversationsTable}` `c`"
            . " LEFT JOIN `{$messagesTable}` `m` ON `m`.`aiConversationId` = `c`.`id`"
            . ' WHERE `c`.`id` IN (:rootConversationIds) OR `c`.`rootConversationId` IN (:rootConversationIds)'
            . ' OR `c`.`supervisedConversationId` IN (:rootConversationIds)'
            . ' GROUP BY COALESCE(`c`.`rootConversationId`, `c`.`supervisedConversationId`, `c`.`id`)',
            ['rootConversationIds' => $rootConversationIds],
            ['rootConversationIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $treeOverheadCostsInUSDByRootConversationId = $this->findTreeOverheadCostsInUSDByRootConversationId($rootConversationIds);
        foreach ($treeTotalRows as $treeTotalRow) {
            $treeTotalRow['overheadCostsInUSD'] = $treeOverheadCostsInUSDByRootConversationId[(int)$treeTotalRow['rootConversationId']] ?? 0.0;
            $treeTotalRow['costInUSD'] = (float)$treeTotalRow['costInUSD'] + $treeTotalRow['overheadCostsInUSD'];
            $aIConversationTreeCostSummary = AIConversationTreeCostSummary::fromRow($treeTotalRow);
            $aIConversationTreeCostSummaries->add($aIConversationTreeCostSummary);
        }
        return $aIConversationTreeCostSummaries;
    }

    /**
     * The overhead costs ({@see AIConversation::$overheadCostsInUSD}) of whole conversation trees — the root, its
     * delegation children and its supervisor conversation — per root id, in ONE query. Read apart from the message
     * sums: a join over the messages would repeat a conversation's overhead once per message row.
     *
     * @param int[] $rootConversationIds
     * @return float[] root conversation id => USD
     * @throws \Doctrine\DBAL\Exception
     */
    public function findTreeOverheadCostsInUSDByRootConversationId(array $rootConversationIds): array
    {
        $rootConversationIds = array_values(array_filter(array_map('intval', $rootConversationIds)));
        if ($rootConversationIds === []) {
            return [];
        }
        $treeOverheadRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT COALESCE(`c`.`rootConversationId`, `c`.`supervisedConversationId`, `c`.`id`) AS `rootConversationId`,'
            . ' COALESCE(SUM(`c`.`overheadCostsInUSD`), 0) AS `overheadCostsInUSD`'
            . ' FROM `' . DBAIConversationModel::getTableName() . '` `c`'
            . ' WHERE `c`.`id` IN (:rootConversationIds) OR `c`.`rootConversationId` IN (:rootConversationIds)'
            . ' OR `c`.`supervisedConversationId` IN (:rootConversationIds)'
            . ' GROUP BY COALESCE(`c`.`rootConversationId`, `c`.`supervisedConversationId`, `c`.`id`)',
            ['rootConversationIds' => $rootConversationIds],
            ['rootConversationIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $treeOverheadCostsInUSDByRootConversationId = [];
        foreach ($treeOverheadRows as $treeOverheadRow) {
            $treeOverheadCostsInUSDByRootConversationId[(int)$treeOverheadRow['rootConversationId']] = (float)$treeOverheadRow['overheadCostsInUSD'];
        }
        return $treeOverheadCostsInUSDByRootConversationId;
    }

    /**
     * The per-conversation cost rows of ONE conversation tree — the root first, then every delegation
     * child — for a cost BREAKDOWN view (admin build-run detail): each row carries the conversation's
     * summary title (when a summary exists), its message-cost sum and token totals.
     * AUTHORIZATION IS THE CALLER'S (admin surface).
     */
    public function findConversationTreeCostRows(int $rootConversationId): AIConversationCostRows
    {
        $conversationsTable = DBAIConversationModel::getTableName();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $sqlDialect = EntityManagerFactory::getDialect();
        $conversationCostRowValues = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT `c`.`id` AS `conversationId`,'
            . ' (`c`.`delegatedFromMessageId` IS NOT NULL) AS `isDelegationChild`,'
            . ' (`c`.`supervisedConversationId` IS NOT NULL) AS `isSupervisorConversation`,'
            // MAX: one value per conversation — the statement groups by the conversation
            . ' MAX(' . $sqlDialect->jsonFieldText('`c`.`summary`', 'title') . ') AS `title`,'
            // MAX: the join repeats the conversation's overhead once per message row; it is one value per conversation
            . ' COALESCE(SUM(' . DBAIConversationMessage::buildCostInUSDIncludingToolCallExecutionSql('m') . '), 0) + COALESCE(MAX(`c`.`overheadCostsInUSD`), 0) AS `costInUSD`,'
            . ' COALESCE(MAX(`c`.`overheadCostsInUSD`), 0) AS `overheadCostsInUSD`,'
            . ' COALESCE(SUM(`m`.`inputTokens`), 0) AS `inputTokens`,'
            . ' COALESCE(SUM(`m`.`cachedInputTokens`), 0) AS `cachedInputTokens`,'
            . ' COALESCE(SUM(`m`.`outputTokens`), 0) AS `outputTokens`,'
            . ' COUNT(`m`.`id`) AS `messageCount`,'
            . ' MIN(`m`.`created`) AS `firstMessageAt`,'
            . ' MAX(`m`.`created`) AS `lastMessageAt`,'
            // the model names in alphabetical order
            . ' ' . $sqlDialect->stringAggDistinct('`m`.`aiModelName`', ', ') . ' AS `modelNames`'
            . " FROM `{$conversationsTable}` `c`"
            . " LEFT JOIN `{$messagesTable}` `m` ON `m`.`aiConversationId` = `c`.`id`"
            . ' WHERE `c`.`id` = :rootConversationId OR `c`.`rootConversationId` = :rootConversationId'
            . ' OR `c`.`supervisedConversationId` = :rootConversationId'
            . ' GROUP BY `c`.`id`, `c`.`delegatedFromMessageId`, `c`.`supervisedConversationId`'
            . ' ORDER BY (`c`.`delegatedFromMessageId` IS NOT NULL), (`c`.`supervisedConversationId` IS NOT NULL), `c`.`id`',
            ['rootConversationId' => $rootConversationId]
        );
        $aIConversationCostRows = new AIConversationCostRows();
        foreach ($conversationCostRowValues as $conversationCostRowValue) {
            $aIConversationCostRow = AIConversationCostRow::fromRow($conversationCostRowValue);
            $aIConversationCostRows->add($aIConversationCostRow);
        }
        return $aIConversationCostRows;
    }

    /**
     * The ordered TOOL-CALL TRACE of one conversation tree — the root plus every delegation child, each
     * conversation's tool calls in append order — for the admin trace of a run ("what the model did", so
     * latency/spend/coverage are visible per work-unit). Reads the tool-call messages of the tree (one
     * grouped query) and projects a compact argument + result summary from the stored ToolCall VO.
     * AUTHORIZATION IS THE CALLER'S (admin surface).
     *
     * @throws \Throwable
     */
    public function findToolCallTraceForConversationTree(int $rootConversationId): AIConversationToolCallTraceEntries
    {
        $aIConversationToolCallTraceEntries = new AIConversationToolCallTraceEntries();
        $conversationsTable = DBAIConversationModel::getTableName();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $traceRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT `m`.`aiConversationId`, `m`.`indexInConversation`, `m`.`virtualToolName`, `m`.`toolCall`,'
            . ' ' . DBAIConversationMessage::buildCostInUSDIncludingToolCallExecutionSql('m') . ' AS `costInUSD`, `m`.`created`,'
            . ' (`c`.`delegatedFromMessageId` IS NOT NULL) AS `isDelegationChild`'
            . " FROM `{$messagesTable}` `m`"
            . " JOIN `{$conversationsTable}` `c` ON `c`.`id` = `m`.`aiConversationId`"
            . ' WHERE (`c`.`id` = :rootConversationId OR `c`.`rootConversationId` = :rootConversationId)'
            . ' AND `m`.`messageType` = :toolCallType'
            . ' ORDER BY (`c`.`delegatedFromMessageId` IS NOT NULL), `m`.`aiConversationId`, `m`.`indexInConversation`',
            ['rootConversationId' => $rootConversationId, 'toolCallType' => AIConversationMessage::MESSAGE_TOOL_CALL]
        );
        foreach ($traceRows as $traceRow) {
            $aIConversationToolCallTraceEntry = AIConversationToolCallTraceEntry::fromRow($traceRow);
            $aIConversationToolCallTraceEntries->add($aIConversationToolCallTraceEntry);
        }
        return $aIConversationToolCallTraceEntries;
    }

    /**
     * The automation eligibility of what a conversation runs for: its space when it works for one
     * ({@see AgentAutomationEligibilityService::findEligibilityForAgentSpace()}), its account otherwise — an
     * account-level conversation; a conversation with neither has nothing to gate and reads as eligible.
     */
    public function findAutomationEligibilityForConversation(AIConversation $conversation, bool $useCache = true): AgentAutomationEligibility
    {
        $agentAutomationEligibilityService = $this->getAgentAutomationEligibilityService();
        // the id decides whether there is a space to ask (no load for an account-level conversation)
        $agentSpace = isset($conversation->agentSpaceId) ? ($conversation->agentSpace ?? null) : null;
        if ($agentSpace !== null) {
            return $agentAutomationEligibilityService->findEligibilityForAgentSpace($agentSpace, $useCache);
        }
        if (($conversation->accountId ?? null) !== null) {
            return $agentAutomationEligibilityService->findEligibilityForAccountId((int)$conversation->accountId, $useCache);
        }
        return AgentAutomationEligibility::eligible();
    }

    /**
     * The entry check of the independently queued conversation workers: compaction,
     * summarization, memory curation, embeddings, finalize — each runs AFTER the turn that scheduled it, in its own
     * process, and the finalize handler even lifts the budget unconditionally, so only this check (and the model-call
     * gate behind it) stops them on a deleted / locked scope. True = the handler ends with its suppressed outcome.
     */
    public function refusesAutomationForConversation(AIConversation $conversation, string $operation): bool
    {
        $eligibility = $this->findAutomationEligibilityForConversation($conversation);
        return $this->getAgentAutomationEligibilityService()->refusesAutomation($eligibility, $operation, AgentEligibilityLogContext::forConversation(isset($conversation->id) ? (int)$conversation->id : null));
    }

    /**
     * Stamps why automated work of the conversation stopped (the reason of its ineligible scope) — kept on the first
     * stamp, like the schedule close does ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService::cancelRunsAndCloseReportObligationsOfSchedule()}).
     */
    public function recordSuppressionOfConversation(AIConversation $conversation, string $suppressionReason): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `suppressionReason` = COALESCE(`suppressionReason`, :reason), `suppressedAt` = COALESCE(`suppressedAt`, :now) WHERE `id` = :id',
            ['reason' => $suppressionReason, 'now' => (new DateTime())->format('Y-m-d H:i:s'), 'id' => (int)$conversation->id]
        );
    }

    /** The eligibility service, resolved from the container — the override point of the container-less unit tests. */
    protected function getAgentAutomationEligibilityService(): AgentAutomationEligibilityService
    {
        /** @var AgentAutomationEligibilityService $agentAutomationEligibilityService */
        $agentAutomationEligibilityService = DDDService::instance()->getService(AgentAutomationEligibilityService::class);
        return $agentAutomationEligibilityService;
    }

    /** The run claims of the conversation rows this service writes. */
    protected function runClaimsService(): AIConversationRunClaimsService
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService;
    }

    /**
     * Push a FAILED owed-turn candidate out of the recovery window — same starvation guard as
     * {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService::deferOwedReport()} for pass 2: advancing `agentTurnOwedAt` to NOW means the quiet window
     * (`--quiet-seconds`) must elapse again before the next re-fire attempt, so a permanently-failing
     * conversation cannot occupy the batch every 5 minutes.
     */
    public function deferOwedTurn(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->agentTurnOwedAt = new DateTime();
        $conversation->updatePartialIgnoringRights('agentTurnOwedAt');
    }

    /** Persist ONLY `finalizeCompactedAt` (the finalize worker's completion stamp). */
    public function persistFinalizeCompactedAt(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->updatePartialIgnoringRights('finalizeCompactedAt');
    }

    /** Persist ONLY the `episodicHistory` narrative snapshot (written once at creation). */
    protected function persistEpisodicHistory(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->updatePartialIgnoringRights('episodicHistory');
    }

    /**
     * The recent conversations of account `$accountId` in `$agentSpace` WITH a persisted summary, newest activity
     * first — the input of the episodic-history narrative. FINALIZE-INDEPENDENT by design: the per-settle summary refresh
     * keeps summaries fresh within seconds of an answered turn, so a session that ended ten minutes ago is already
     * narratable — the finalize window only governs the completion tree, never the narrative. Roots only; the fetch
     * is deliberately WIDER than the recent window (default 25) so the renderer can pick importance survivors from
     * the remainder.
     *
     * WITHOUT A SPACE (`$agentSpace` null) the read selects the ACCOUNT-LEVEL conversations of the account
     * (`agentSpaceId IS NULL`), never those of all spaces of the account — that read is
     * {@see findRecentConversationsWithSummariesOfAccountIdAcrossAgentSpaces()}. The account is filtered in the same
     * statement in both cases.
     *
     * @param int $accountId
     * @param AgentSpace|null $agentSpace null = the account-level conversations of `$accountId`
     * @param int|null $excludeConversationId
     * @param int $limit
     * @return AIConversations
     */
    public function findRecentConversationsWithSummariesOfAgentSpace(
        int $accountId,
        ?AgentSpace $agentSpace,
        ?int $excludeConversationId = null,
        int $limit = 25
    ): AIConversations {
        return $this->findRecentConversationsWithSummariesInAgentSpacePartition(
            $accountId,
            AgentSpaceSqlCondition::forAgentSpace($agentSpace, 'episodicAgentSpaceId'),
            $excludeConversationId,
            $limit
        );
    }

    /**
     * {@see self::findRecentConversationsWithSummariesOfAgentSpace()} by the partition condition, so that a caller
     * that holds only the id of the space ({@see AgentSpaceSqlCondition::forAgentSpaceId()}) reads the same
     * partition without loading the space.
     *
     * @param int $accountId
     * @param AgentSpaceSqlCondition $agentSpaceSqlCondition the rows OF a space, or the account-level rows
     * @param int|null $excludeConversationId
     * @param int $limit
     * @return AIConversations
     */
    protected function findRecentConversationsWithSummariesInAgentSpacePartition(
        int $accountId,
        AgentSpaceSqlCondition $agentSpaceSqlCondition,
        ?int $excludeConversationId = null,
        int $limit = 25
    ): AIConversations {
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $this->buildRecentConversationsWithSummariesQueryBuilder($excludeConversationId, $limit);
        $entitySetRepo::restrictToConversationsOfAccountInAgentSpacePartition($queryBuilder, $accountId, $agentSpaceSqlCondition, 'episodicAccountId');
        return $entitySetRepo->find($queryBuilder);
    }

    /**
     * {@see self::findRecentConversationsWithSummariesOfAgentSpace()} ACROSS the spaces of the account `$accountId`:
     * its recent root conversations with a persisted summary — those of every space and those at account level —
     * newest activity first. The input of the episodic-history narrative of an ACCOUNT-LEVEL conversation: it works
     * for the account as a whole, so the sessions the customer had in any space are its earlier sessions. The read
     * carries no condition on the space, on purpose, and filters the account alone.
     *
     * @param int $accountId
     * @param int|null $excludeConversationId
     * @param int $limit
     * @return AIConversations
     */
    public function findRecentConversationsWithSummariesOfAccountIdAcrossAgentSpaces(
        int $accountId,
        ?int $excludeConversationId = null,
        int $limit = 25
    ): AIConversations {
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $alias = $entitySetRepo::getBaseModelAlias();
        $queryBuilder = $this->buildRecentConversationsWithSummariesQueryBuilder($excludeConversationId, $limit);
        $queryBuilder
            ->andWhere("$alias.accountId = :episodicAccountId")
            ->setParameter('episodicAccountId', $accountId);
        return $entitySetRepo->find($queryBuilder);
    }

    /**
     * The query both reads of the recent conversations share: the customer-visible root conversations with a persisted
     * summary, newest activity first, at most `$limit` (at least 1). It carries NO condition on the account or the
     * space; the caller adds the one its read is named after.
     *
     * @param int|null $excludeConversationId
     * @param int $limit
     * @return DoctrineQueryBuilder
     */
    protected function buildRecentConversationsWithSummariesQueryBuilder(?int $excludeConversationId, int $limit): DoctrineQueryBuilder
    {
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $alias = $entitySetRepo::getBaseModelAlias();
        $queryBuilder = $entitySetRepo::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.delegatedFromMessageId IS NULL")
            ->andWhere("$alias.summary IS NOT NULL")
            ->orderBy("$alias.lastInboundMessageAt", 'DESC')
            ->setMaxResults(max(1, $limit));
        if ($excludeConversationId !== null) {
            $queryBuilder
                ->andWhere("$alias.id != :episodicExcludeId")
                ->setParameter('episodicExcludeId', $excludeConversationId);
        }
        // the platform's own runs are no session the customer had
        $entitySetRepo::restrictToCustomerVisibleConversations($queryBuilder);
        return $queryBuilder;
    }

    /**
     * The ROOT conversations of an account in `$agentSpace` that were active since $since and carry a persisted
     * summary, newest activity first — what a day-context look-up reads and what the chat lists when the owner asks
     * what was discussed lately. The
     * conversation the question is asked in is left out through $excludeConversationId. The platform's own runs are
     * left out; a scheduled task's run stays in (its result is news for the person) unless its purpose is named in
     * $excludedPurposeNames — the day context lists those runs in a section of their own, so that an hourly schedule
     * does not push the person's own sessions out of the limit.
     *
     * WITHOUT A SPACE (`$agentSpace` null) the read selects the ACCOUNT-LEVEL conversations of the account
     * (`agentSpaceId IS NULL`), never those of all its spaces — that read is
     * {@see findConversationsWithSummariesOfAccountAcrossAgentSpacesSince()}. The account is filtered in the same
     * statement in both cases.
     *
     * @param int $accountId
     * @param AgentSpace|null $agentSpace null = the account-level conversations of `$accountId`
     * @param \DateTimeInterface $since
     * @param int $limit
     * @param int|null $excludeConversationId
     * @param string[] $excludedPurposeNames
     * @return AIConversations
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findConversationsWithSummariesSince(
        int $accountId,
        ?AgentSpace $agentSpace,
        \DateTimeInterface $since,
        int $limit = 12,
        ?int $excludeConversationId = null,
        array $excludedPurposeNames = []
    ): AIConversations {
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $this->buildConversationsWithSummariesSinceQueryBuilder($since, $limit, $excludeConversationId, $excludedPurposeNames);
        // the account and the partition in one step: without a space the account filter is what confines the read
        $entitySetRepo::restrictToConversationsOfAccountInAgentSpacePartition(
            $queryBuilder,
            $accountId,
            AgentSpaceSqlCondition::forAgentSpace($agentSpace, 'sessionsAgentSpaceId'),
            'sessionsAccountId'
        );
        return $entitySetRepo->find($queryBuilder);
    }

    /**
     * {@see findConversationsWithSummariesSince()} ACROSS the spaces of `$account`: its root conversations with a
     * persisted summary that were active since $since — those of every space and those at account level — newest
     * activity first. What the chat lists when the owner asks what was discussed lately in all their spaces. The read
     * carries no condition on the space, on purpose, and filters the account alone.
     *
     * @param Account $account
     * @param \DateTimeInterface $since
     * @param int $limit
     * @param int|null $excludeConversationId
     * @param string[] $excludedPurposeNames
     * @return AIConversations
     * @throws BadRequestException
     * @throws InternalErrorException when the account is not stored
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findConversationsWithSummariesOfAccountAcrossAgentSpacesSince(
        Account $account,
        \DateTimeInterface $since,
        int $limit = 12,
        ?int $excludeConversationId = null,
        array $excludedPurposeNames = []
    ): AIConversations {
        if (!isset($account->id)) {
            throw new InternalErrorException('An account without an id has no conversations to read.');
        }
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $alias = $entitySetRepo::getBaseModelAlias();
        $queryBuilder = $this->buildConversationsWithSummariesSinceQueryBuilder($since, $limit, $excludeConversationId, $excludedPurposeNames);
        $queryBuilder
            ->andWhere("$alias.accountId = :sessionsAccountId")
            ->setParameter('sessionsAccountId', (int)$account->id);
        return $entitySetRepo->find($queryBuilder);
    }

    /**
     * The query both summary reads share: the customer-visible root conversations with a persisted summary that were
     * active since $since, newest activity first, at most `$limit` (1 to 25). It carries NO condition on the account
     * or the space; the caller adds the one its read is named after.
     *
     * @param \DateTimeInterface $since
     * @param int $limit
     * @param int|null $excludeConversationId
     * @param string[] $excludedPurposeNames
     * @return DoctrineQueryBuilder
     */
    protected function buildConversationsWithSummariesSinceQueryBuilder(
        \DateTimeInterface $since,
        int $limit,
        ?int $excludeConversationId,
        array $excludedPurposeNames
    ): DoctrineQueryBuilder {
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $alias = $entitySetRepo::getBaseModelAlias();
        $queryBuilder = $entitySetRepo::createQueryBuilder();
        if ($excludedPurposeNames !== []) {
            $queryBuilder
                ->andWhere("($alias.purposeName IS NULL OR $alias.purposeName NOT IN (:sessionsExcludedPurposeNames))")
                ->setParameter('sessionsExcludedPurposeNames', array_values($excludedPurposeNames));
        }
        $queryBuilder
            ->andWhere("$alias.delegatedFromMessageId IS NULL")
            ->andWhere("$alias.summary IS NOT NULL")
            ->andWhere("$alias.lastInboundMessageAt >= :sessionsSince")
            ->setParameter('sessionsSince', $since->format('Y-m-d H:i:s'))
            ->orderBy("$alias.lastInboundMessageAt", 'DESC')
            ->setMaxResults(max(1, min(25, $limit)));
        if ($excludeConversationId !== null) {
            $queryBuilder
                ->andWhere("$alias.id != :sessionsExcludeId")
                ->setParameter('sessionsExcludeId', $excludeConversationId);
        }
        $entitySetRepo::restrictToCustomerVisibleConversations($queryBuilder);
        return $queryBuilder;
    }

    /**
     * The runs of the scheduled tasks of a space created since $since, newest first — root conversations of the purpose
     * {@see AIConversationPurpose::SCHEDULE_RUN} with their schedule. What the day context lists as the agent's work:
     * a run started by an administrator often carries no inbound message, so the read keys on the
     * creation time, not on `lastInboundMessageAt`. The read filters the space, which fixes the account.
     *
     * @param AgentSpace $agentSpace
     * @param \DateTimeInterface $since
     * @param int $limit
     * @return AIConversations
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findScheduleRunsOfAgentSpaceSince(AgentSpace $agentSpace, \DateTimeInterface $since, int $limit = 10): AIConversations
    {
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $alias = $entitySetRepo::getBaseModelAlias();
        $queryBuilder = $entitySetRepo::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.purposeName = :scheduleRunsPurposeName")
            ->andWhere("$alias.delegatedFromMessageId IS NULL")
            ->andWhere("$alias.scheduleId IS NOT NULL")
            ->andWhere("$alias.created >= :scheduleRunsSince")
            ->setParameter('scheduleRunsPurposeName', AIConversationPurpose::SCHEDULE_RUN)
            ->setParameter('scheduleRunsSince', $since->format('Y-m-d H:i:s'))
            ->orderBy("$alias.created", 'DESC')
            ->setMaxResults(max(1, min(25, $limit)));
        $entitySetRepo::restrictToConversationsOfAgentSpace($queryBuilder, $agentSpace, 'scheduleRunsAgentSpaceId');
        return $entitySetRepo->find($queryBuilder) ?? AIConversations::newInstance();
    }

    /**
     * Compose + persist the EPISODIC-HISTORY narrative snapshot for a FRESHLY-CREATED conversation
     * — written exactly ONCE: the ordering key (last activity) and the summaries mutate, so a later recompute would
     * shift the story mid-conversation (prompt-cache break; the persisted snapshot also makes "what did this
     * conversation see" admin-reproducible). No-op when already set, ephemeral, account-less, or when there is
     * no narratable history (nothing is persisted — the segment is simply absent). Delegation children never get one
     * (they receive a focused task, not the customer's story).
     *
     * A conversation of a space reads the sessions of that space, by the stored `agentSpaceId` (no load of the
     * space). An ACCOUNT-LEVEL conversation works for the account as a whole and reads the sessions of the whole
     * account — those of every space and those at account level
     * ({@see findRecentConversationsWithSummariesOfAccountIdAcrossAgentSpaces()}).
     */
    public function composeAndPersistEpisodicHistory(AIConversation $conversation): void
    {
        if (!isset($conversation->id) || isset($conversation->episodicHistory)
            || !isset($conversation->accountId) || !$conversation->accountId || $conversation->isDelegation()) {
            return;
        }
        if ($conversation->findPurpose()?->loadsEarlierSessionsItself === true) {
            return; // the run loads the earlier sessions through its own look-ups
        }
        $agentSpaceIdOfConversation = $conversation->agentSpaceId ?? null;
        $recentConversations = $agentSpaceIdOfConversation === null
            ? $this->findRecentConversationsWithSummariesOfAccountIdAcrossAgentSpaces((int)$conversation->accountId, (int)$conversation->id)
            : $this->findRecentConversationsWithSummariesInAgentSpacePartition(
                (int)$conversation->accountId,
                AgentSpaceSqlCondition::forAgentSpaceId($agentSpaceIdOfConversation, 'episodicAgentSpaceId'),
                (int)$conversation->id
            );
        $episodicHistoryText = $recentConversations->buildEpisodicHistoryText((int)$conversation->id);
        if ($episodicHistoryText === '') {
            return;
        }
        $conversation->episodicHistory = $episodicHistoryText;
        $this->persistEpisodicHistory($conversation);
        $this->aiTrace('episodicHistory.persisted', ['conversationId' => $conversation->id, 'chars' => strlen($episodicHistoryText)]);
    }

    /**
     * Books AI costs that ran FOR a conversation outside its message rows and outside its tool calls — the decision
     * calls and activity lines of a turn, the history summary, the compaction, the memory extraction, the tool-result
     * screening — onto {@see AIConversation::$overheadCostsInUSD} and the per-scope split
     * {@see AIConversation::$overheadBookedCosts}. ONE atomic statement: turn workers and background workers book on
     * the same row concurrently, so the amounts are added in SQL, never read-modified-written (entity writes keep both
     * columns, see their `onUpdateAction`). Takes the id, not the entity: the callers (the turn loop's `finally`, the
     * Messenger middleware) hold only the id and the write needs nothing else. Fail-soft — a failed booking is traced
     * and dropped, it never fails the work that caused it; the amounts stay on AccountAICost and AIUsageLog.
     *
     * @param int $aiConversationId
     * @param AIUsageBookedCosts $overheadBookedCosts the recording that was open around the work
     * @return void
     */
    public function addOverheadCostsToConversation(int $aiConversationId, AIUsageBookedCosts $overheadBookedCosts): void
    {
        if (!$overheadBookedCosts->hasBookedCosts()) {
            return;
        }
        $tableName = DBAIConversationModel::getTableName();
        $sqlDialect = EntityManagerFactory::getDialect();
        $overheadBookedCostsColumnSql = '`overheadBookedCosts`';
        $totalCostInUSDPath = JsonPath::fromKeys('totalCostInUSD');
        $bookingCountPath = JsonPath::fromKeys('bookingCount');
        $costInUSDByUsageScopePath = JsonPath::fromKeys('costInUSDByUsageScope');
        // every amount is read from the STORED document (one parse of the document per read on both engines) and
        // written as the stored amount plus the booked one
        $overheadBookedCostsUpdate = $sqlDialect->jsonUpdate($overheadBookedCostsColumnSql)
            ->set(
                $totalCostInUSDPath,
                'COALESCE(' . $this->buildStoredOverheadCostSql($overheadBookedCostsColumnSql, $totalCostInUSDPath) . ', 0) + :totalCostInUSD',
                JsonValueKind::NUMBER
            )
            ->set(
                $bookingCountPath,
                'COALESCE(' . $sqlDialect->jsonInt($overheadBookedCostsColumnSql, $bookingCountPath) . ', 0) + :bookingCount',
                JsonValueKind::NUMBER
            )
            // the per-scope paths below need an OBJECT here (a missing key or an empty `[]` would drop them silently)
            ->setObjectUnlessPresent($costInUSDByUsageScopePath);
        $parameters = [
            'aiConversationId' => $aiConversationId,
            'totalCostInUSD' => $overheadBookedCosts->totalCostInUSD,
            'bookingCount' => $overheadBookedCosts->bookingCount,
        ];
        $scopeIndex = 0;
        // the column keeps the keyed form (AIUsageBookedCostsStoredForm): one JSON path per scope
        foreach ($overheadBookedCosts->scopeBookedCosts->getElements() as $aIUsageScopeBookedCost) {
            $usageScope = $aIUsageScopeBookedCost->usageScope;
            $costInUSD = $aIUsageScopeBookedCost->costInUSD;
            // a scope is an internal usage scope constant; anything else would end up inside a JSON path
            if (!preg_match('/^[A-Z0-9_.]+$/', (string)$usageScope)) {
                continue;
            }
            // the scope is ONE key of the path (the regex above keeps it to [A-Z0-9_.]), its dots included
            $usageScopeCostPath = $costInUSDByUsageScopePath->withKey((string)$usageScope);
            $overheadBookedCostsUpdate->set(
                $usageScopeCostPath,
                'COALESCE(' . $this->buildStoredOverheadCostSql($overheadBookedCostsColumnSql, $usageScopeCostPath) . ", 0) + :scopeCost{$scopeIndex}",
                JsonValueKind::NUMBER
            );
            $parameters["scopeCost{$scopeIndex}"] = (float)$costInUSD;
            $scopeIndex++;
        }
        try {
            EntityManagerFactory::getInstance()->getConnection()->executeStatement(
                "UPDATE `{$tableName}` SET `overheadCostsInUSD` = COALESCE(`overheadCostsInUSD`, 0) + :totalCostInUSD,"
                . ' `overheadBookedCosts` = ' . $overheadBookedCostsUpdate->toSql()
                . ' WHERE `id` = :aiConversationId',
                $parameters
            );
        } catch (Throwable $bookingThrowable) {
            // a warning, not only a trace (aiTrace is off unless AGENT_TRACE is set): the amounts stay on AccountAICost/AIUsageLog
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->warning(
                "AIConversationsService: overhead costs of conversation {$aiConversationId} not booked: " . $bookingThrowable->getMessage(),
                ['exception' => $bookingThrowable]
            );
        }
    }

    /**
     * The SQL that reads one amount of the stored overhead cost document as a decimal, NULL where the document
     * carries none. The increment reads every amount it writes — the total and one per booked usage scope — on each
     * booking of an overhead model call.
     *
     * @param string $overheadBookedCostsColumnSql the quoted `overheadBookedCosts` column of the caller's statement
     */
    protected function buildStoredOverheadCostSql(string $overheadBookedCostsColumnSql, JsonPath $amountPath): string
    {
        return EntityManagerFactory::getDialect()->jsonDecimal(
            $overheadBookedCostsColumnSql,
            $amountPath,
            self::OVERHEAD_COST_DECIMAL_PRECISION,
            self::OVERHEAD_COST_DECIMAL_SCALE
        );
    }

    // ── Idle-finalize sweeps — MEGA-EFFICIENT by requirement: pure indexed SQL on the conversations
    //    table, no joins, no entity hydration, no per-row PHP. The two cron commands are thin wrappers over these. ──

    /**
     * The mark-finalized sweep predicate, shared by the UPDATE and the --dry-run COUNT: roots only, no live run,
     * idle past the window, not yet finalized. Served by INDEX (finalizedAt, lastInboundMessageAt).
     */
    protected const string FINALIZE_MARK_PREDICATE = '`finalizedAt` IS NULL AND `delegatedFromMessageId` IS NULL'
        . ' AND `runState` NOT IN (:liveRunStates)'
        . ' AND `lastInboundMessageAt` IS NOT NULL AND `lastInboundMessageAt` < :idleBefore';

    /** @var int Digits an amount of the stored overhead cost document is read with ({@see addOverheadCostsToConversation()}). */
    protected const int OVERHEAD_COST_DECIMAL_PRECISION = 30;

    /** @var int Decimal places an amount of the stored overhead cost document is read with. */
    protected const int OVERHEAD_COST_DECIMAL_SCALE = 12;

    /**
     * Mark every idle conversation FINALIZED in ONE indexed UPDATE (called by a cron command of the application).
     * Returns the number of conversations marked. The stamp is the PHP clock, like the completion stamp
     * `finalizeCompactedAt` it is compared with ({@see findFinalizedConversationIdsOwingCompletionCompaction()}).
     *
     * @throws Exception
     */
    public function markIdleConversationsFinalized(int $idleMinutes): int
    {
        $tableName = DBAIConversationModel::getTableName();
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            "UPDATE `{$tableName}` SET `finalizedAt` = :finalizedAt WHERE " . self::FINALIZE_MARK_PREDICATE,
            $this->finalizeMarkParameters($idleMinutes) + ['finalizedAt' => (new DateTime())->format('Y-m-d H:i:s')],
            ['liveRunStates' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    /**
     * The --dry-run twin of {@see markIdleConversationsFinalized()}: COUNT via the identical predicate, no write.
     *
     * @throws Exception
     */
    public function countIdleConversationsToFinalize(int $idleMinutes): int
    {
        $tableName = DBAIConversationModel::getTableName();
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM `{$tableName}` WHERE " . self::FINALIZE_MARK_PREDICATE,
            $this->finalizeMarkParameters($idleMinutes),
            ['liveRunStates' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    /** @return array<string, mixed> the bound parameters of {@see self::FINALIZE_MARK_PREDICATE}. */
    protected function finalizeMarkParameters(int $idleMinutes): array
    {
        return [
            'liveRunStates' => AIConversationRunState::NON_TERMINAL,
            'idleBefore' => (new DateTime())->modify('-' . max(1, $idleMinutes) . ' minutes')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The finalized conversations still OWING a completion compaction (read by a cron command of the application):
     * `finalizedAt` set and newer than (or without) `finalizeCompactedAt`. One indexed SELECT of BARE ids — the
     * command dispatches an ids-only Messenger job per row; the worker does the hydration + LLM work.
     *
     * @return int[]
     * @throws Exception
     */
    public function findFinalizedConversationIdsOwingCompletionCompaction(int $batchSize): array
    {
        $tableName = DBAIConversationModel::getTableName();
        $rows = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            "SELECT `id` FROM `{$tableName}` WHERE `finalizedAt` IS NOT NULL"
            . ' AND (`finalizeCompactedAt` IS NULL OR `finalizeCompactedAt` < `finalizedAt`)'
            . ' ORDER BY `finalizedAt` ASC LIMIT ' . max(1, $batchSize)
        );
        return array_map('intval', $rows);
    }

    /**
     * Set + persist the per-conversation context-retention thresholds (lazy/eager tool loading + the
     * eviction cost-benefit budgets). Mirrors {@see updateCompactionSettings()} — a targeted single-column write so a
     * concurrent run-claim's runState/runToken is never clobbered. null clears the override (inherit the global default).
     */
    public function updateContextRetentionSettings(AIConversation $conversation, ?ContextRetentionSettings $contextRetentionSettings): AIConversation
    {
        $conversation->contextRetentionSettings = $this->keepStampedSkillLoadingStrategy($conversation, $contextRetentionSettings);
        $this->persistContextRetentionSettings($conversation);
        return $conversation;
    }

    /**
     * The context retention settings an UPDATE of an existing conversation may write: the stamped
     * skill-loading strategy stays — a value the update leaves unset (or a cleared row) keeps the stored strategy, and
     * a DIFFERENT strategy is refused once the conversation made a model call (the strategy shapes the stored history:
     * append-only skill rows or none). Before the first model call a different strategy is accepted.
     *
     * @throws BadRequestException a strategy change after the conversation's first model call
     */
    public function keepStampedSkillLoadingStrategy(AIConversation $conversation, ?ContextRetentionSettings $contextRetentionSettings): ?ContextRetentionSettings
    {
        $contextRetentionSettings = $this->keepStampedRebuildEpochOnSituationChange($conversation, $contextRetentionSettings);
        $contextRetentionSettings = $this->keepStampedStateChangeDelivery($conversation, $contextRetentionSettings);
        $storedSkillLoadingStrategy = isset($conversation->contextRetentionSettings)
            ? ContextRetentionSettings::canonicalSkillLoadingStrategy($conversation->contextRetentionSettings->skillLoadingStrategy)
            : null;
        if ($storedSkillLoadingStrategy === null) {
            return $contextRetentionSettings;
        }
        $updatedSettings = $contextRetentionSettings !== null ? clone $contextRetentionSettings : new ContextRetentionSettings();
        $requestedSkillLoadingStrategy = ContextRetentionSettings::canonicalSkillLoadingStrategy($updatedSettings->skillLoadingStrategy);
        if ($requestedSkillLoadingStrategy !== null && $requestedSkillLoadingStrategy !== $storedSkillLoadingStrategy
            && $this->conversationHasModelCall($conversation)
        ) {
            throw new BadRequestException(
                'skillLoadingStrategy cannot change after the conversation\'s first model call (stored: ' . $storedSkillLoadingStrategy . ')'
            );
        }
        $updatedSettings->skillLoadingStrategy = $requestedSkillLoadingStrategy ?? $storedSkillLoadingStrategy;
        return $updatedSettings;
    }

    /**
     * The same rule for {@see ContextRetentionSettings::$rebuildEpochOnSituationChange}: it
     * decides which epoch every call renders, so an update that leaves it unset keeps the stored value, and a different
     * value is refused once the conversation made a model call.
     *
     * @throws BadRequestException a change after the conversation's first model call
     */
    protected function keepStampedRebuildEpochOnSituationChange(AIConversation $conversation, ?ContextRetentionSettings $contextRetentionSettings): ?ContextRetentionSettings
    {
        $storedRebuildEpochOnSituationChange = isset($conversation->contextRetentionSettings) ? $conversation->contextRetentionSettings->rebuildEpochOnSituationChange : null;
        if ($storedRebuildEpochOnSituationChange === null) {
            return $contextRetentionSettings;
        }
        $updatedSettings = $contextRetentionSettings !== null ? clone $contextRetentionSettings : new ContextRetentionSettings();
        $requestedRebuildEpochOnSituationChange = $updatedSettings->rebuildEpochOnSituationChange;
        if ($requestedRebuildEpochOnSituationChange !== null && $requestedRebuildEpochOnSituationChange !== $storedRebuildEpochOnSituationChange
            && $this->conversationHasModelCall($conversation)
        ) {
            throw new BadRequestException(
                'rebuildEpochOnSituationChange cannot change after the conversation\'s first model call (stored: ' . ($storedRebuildEpochOnSituationChange ? 'true' : 'false') . ')'
            );
        }
        $updatedSettings->rebuildEpochOnSituationChange = $requestedRebuildEpochOnSituationChange ?? $storedRebuildEpochOnSituationChange;
        return $updatedSettings;
    }

    /**
     * The same rule for {@see ContextRetentionSettings::$stateChangeDelivery}: it decides whether the
     * calls render an epoch record and notes, so an update that leaves it unset keeps the stored value, and a different
     * value is refused once the conversation made a model call.
     *
     * @throws BadRequestException a change after the conversation's first model call
     */
    protected function keepStampedStateChangeDelivery(AIConversation $conversation, ?ContextRetentionSettings $contextRetentionSettings): ?ContextRetentionSettings
    {
        $storedStateChangeDelivery = isset($conversation->contextRetentionSettings)
            ? ContextRetentionSettings::canonicalStateChangeDelivery($conversation->contextRetentionSettings->stateChangeDelivery)
            : null;
        if ($storedStateChangeDelivery === null) {
            return $contextRetentionSettings;
        }
        $updatedSettings = $contextRetentionSettings !== null ? clone $contextRetentionSettings : new ContextRetentionSettings();
        $requestedStateChangeDelivery = ContextRetentionSettings::canonicalStateChangeDelivery($updatedSettings->stateChangeDelivery);
        if ($requestedStateChangeDelivery !== null && $requestedStateChangeDelivery !== $storedStateChangeDelivery
            && $this->conversationHasModelCall($conversation)
        ) {
            throw new BadRequestException(
                'stateChangeDelivery cannot change after the conversation\'s first model call (stored: ' . $storedStateChangeDelivery . ')'
            );
        }
        $updatedSettings->stateChangeDelivery = $requestedStateChangeDelivery ?? $storedStateChangeDelivery;
        return $updatedSettings;
    }

    /**
     * Stamp the skill-loading strategy of a stored conversation that carries none: every
     * creation path stamps it, so an unstamped conversation predates it. Without the stamp it would read
     * the global default at every run, and a later flip of the default would switch it mid-history. Stamped once, at a
     * run start: a conversation that already made a model call gets `systemPromptSwap` (the shape its history has),
     * one that made none the current default. A conversation without a stored retention column is stamped on its
     * purpose's retention map WITHOUT the purpose's agent wire profile
     * ({@see AIConversationPurpose::buildContextRetentionSettingsForConversationWithoutAgentWireProfile()}; the profile is
     * a creation stamp of new conversations only): the stamp fills the column, and the
     * purposed resume heals the purpose's knobs only into an empty column, so a stamp of
     * the strategy alone would keep an older purposed conversation on the global knobs for good. An ephemeral
     * conversation (no id) lives in one process, where the default cannot change, and is left as it is.
     *
     * The state-change delivery is stamped by the same rule: a conversation
     * created before the state-change delivery existed gets `everyCall` when it made a model call (the shape its history has), else its stored
     * value or the current default — a later flip of the default never moves a running conversation to the epoch record
     * and the notes mid-history.
     *
     * The current default of a conversation without a model call follows the wire API its model resolves to
     * (`$modelNameOverride` — the run's model — else the conversation's own, under its run settings;
     * {@see ContextRetentionSettings::resolveDefaultSkillLoadingStrategyForAgentWireApi()}): a Chat Completions model is
     * stamped `systemPromptSwap`, never the append-only default it cannot run.
     */
    public function stampCreationSettingsOfUnstampedConversation(AIConversation $conversation, ?string $modelNameOverride = null): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $contextRetentionSettings = isset($conversation->contextRetentionSettings) ? $conversation->contextRetentionSettings : null;
        $skillLoadingStrategyStamped = $contextRetentionSettings !== null
            && ContextRetentionSettings::canonicalSkillLoadingStrategy($contextRetentionSettings->skillLoadingStrategy) !== null;
        $stateChangeDeliveryStamped = $contextRetentionSettings !== null
            && ContextRetentionSettings::canonicalStateChangeDelivery($contextRetentionSettings->stateChangeDelivery) !== null;
        if ($skillLoadingStrategyStamped && $stateChangeDeliveryStamped) {
            return;
        }
        $conversationHasModelCall = $this->conversationHasModelCall($conversation);
        // the wire API is read only where the global default decides (no stamp, no model call yet)
        $agentWireApi = !$skillLoadingStrategyStamped && !$conversationHasModelCall
            ? $this->resolveAgentWireApiOfConversation($conversation, $modelNameOverride)
            : null;
        $stampedContextRetentionSettings = $contextRetentionSettings !== null
            ? clone $contextRetentionSettings
            // the purpose's eviction knobs, never its agent wire profile: an existing conversation is not switched to
            // the append-only strategy or the state notes after its creation
            : ContextRetentionSettings::buildForNewConversation($conversation->findPurpose()?->buildContextRetentionSettingsForConversationWithoutAgentWireProfile(), null, agentWireApi: $agentWireApi);
        if (!$skillLoadingStrategyStamped) {
            $stampedContextRetentionSettings->skillLoadingStrategy = $conversationHasModelCall
                ? ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP
                : (ContextRetentionSettings::canonicalSkillLoadingStrategy($stampedContextRetentionSettings->skillLoadingStrategy) ?? ContextRetentionSettings::resolveDefaultSkillLoadingStrategyForAgentWireApi($agentWireApi));
        }
        if (!$stateChangeDeliveryStamped) {
            $stampedContextRetentionSettings->stateChangeDelivery = $conversationHasModelCall
                ? ContextRetentionSettings::STATE_CHANGE_DELIVERY_EVERY_CALL
                : (ContextRetentionSettings::canonicalStateChangeDelivery($stampedContextRetentionSettings->stateChangeDelivery) ?? ContextRetentionSettings::resolveDefaultStateChangeDelivery());
        }
        $conversation->contextRetentionSettings = $stampedContextRetentionSettings;
        $this->persistContextRetentionSettings($conversation);
        $this->aiTrace('conversation.creationSettings.stamped', [
            'conversationId' => $conversation->id,
            'skillLoadingStrategy' => $stampedContextRetentionSettings->skillLoadingStrategy,
            'stateChangeDelivery' => $stampedContextRetentionSettings->stateChangeDelivery,
        ]);
    }

    /**
     * The wire API `$conversation` runs on ({@see AIConversationModelCallsService::resolveAgentWireApi()}): the run's
     * model `$modelNameOverride`, else the conversation's own, under its run settings. The override point of the
     * container-less unit tests.
     */
    protected function resolveAgentWireApiOfConversation(AIConversation $conversation, ?string $modelNameOverride): string
    {
        /** @var AIConversationModelCallsService $aIConversationModelCallsService */
        $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
        return $aIConversationModelCallsService->resolveAgentWireApi(
            $modelNameOverride ?? ($conversation->aiModelNameOverride ?? null),
            isset($conversation->runSettings) ? $conversation->runSettings : null
        );
    }

    /**
     * Whether the conversation made a model call: a stored row carries a call's usage (`inputTokens > 0`, the step
     * marker). One indexed existence read; an unsaved conversation reads its in-memory rows.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function conversationHasModelCall(AIConversation $conversation): bool
    {
        if (!isset($conversation->id)) {
            foreach (isset($conversation->messages) ? $conversation->messages->getElements() : [] as $aIConversationMessage) {
                if (($aIConversationMessage->inputTokens ?? 0) > 0) {
                    return true;
                }
            }
            return false;
        }
        $messagesTableName = DBAIConversationMessageModel::getTableName();
        return EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            "SELECT 1 FROM `{$messagesTableName}` WHERE `aiConversationId` = :conversationId AND `inputTokens` > 0 LIMIT 1",
            ['conversationId' => (int)$conversation->id]
        ) !== false;
    }

    /** Persist ONLY the `contextRetentionSettings` VO column (null clears it). */
    public function persistContextRetentionSettings(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->updatePartialIgnoringRights('contextRetentionSettings');
    }

    /**
     * Generate/REFRESH the conversation's display TITLE + SUMMARY (+ outcome/importance) and PERSIST it. STALENESS-GATED at every settle: {@see AIConversation::isDisplaySummaryStale()} — no summary yet, or
     * the newest message index grew past the summary's `coveredUpToMessageIndex` marker; a manually-edited title
     * latches the auto-refresh off. The generation is an EXPLICIT service-side Argus invocation (NOT the lazy-load —
     * a lazy-load gives no chance to thread the previous summary in): with an existing summary the summarizer runs
     * INCREMENTALLY over previous summary + the slice past the marker
     * ({@see AIConversation::buildDisplaySummaryDeltaInput}); first generation runs over the bounded full view. The
     * marker is stamped to the conversation's highest message index at generation. The `bool $async` seam: async
     * dispatches {@see AIConversationSummarizeMessage} (the HTTP/admin path); sync runs inline (worker / CLI / eval).
     * The spontaneous lazy-load path (a list read touching an unset `summary` column) keeps working unchanged — it
     * generates a full, marker-less summary that the next settle refresh stamps.
     */
    public function generateAndPersistSummary(AIConversation $conversation, bool $async = false, bool $forceFullRebuild = false): void
    {
        if (!isset($conversation->id)) {
            $this->aiTrace('summary.skipped', ['reason' => 'ephemeral']);
            return; // ephemeral (no id) — nothing to persist
        }
        if ($async) {
            (new AIConversationSummarizeMessage((int)$conversation->id))->dispatch();
            return;
        }
        // Preload the BOUNDED context (cover nodes + raw tail, registry-bypassed) BEFORE the staleness check: on the
        // async worker path the conversation arrives fresh and a bare `->messages` access would fire the #[LazyLoad]
        // default = the ~50 OLDEST rows — the staleness compare and the marker would then run against index ~49 on a
        // long conversation (refresh skipped / marker stamped wrong). Same load ArgusAIConversationSummary::
        // getUserContent() performs; doing it here makes staleness, marker AND input read the same true newest state.
        if (!(isset($conversation->compactionNodes) && isset($conversation->messages))) {
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = AIConversationMessage::getService();
            $conversation->compactionNodes = $conversation->findCompactionCoverNodes() ?? \DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes::newInstance();
            $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
        }
        // A FULL rebuild ignores staleness AND the previous summary: the repair path for summaries generated
        // before an input improvement (e.g. the conversation frame) — an incremental refresh would carry the
        // stale framing forward ("carry forward what still holds"). A manually edited title stays untouchable.
        if ($forceFullRebuild && isset($conversation->summary) && $conversation->summary->manuallyEdited) {
            $this->aiTrace('summary.skipped', ['conversationId' => $conversation->id, 'reason' => 'manually_edited']);
            return;
        }
        if (!$forceFullRebuild && !$conversation->isDisplaySummaryStale()) {
            $this->aiTrace('summary.skipped', ['conversationId' => $conversation->id, 'reason' => 'not_stale']);
            return; // covered to the newest message (or title manually edited) — nothing to refresh
        }
        // Capture the marker BEFORE the model call: the summary covers what the input contained at build time.
        // On a FULLY compacted conversation (completion compaction landed) the raw tail is empty and the tail-based
        // marker would collapse to -1 — the cover edge is the true coverage there.
        $highestCoveredMessageIndex = max(
            $conversation->messages->getNextIndex() - 1,
            $conversation->getCompactionCoveredUpToIndex() ?? -1
        );

        $summarizer = new ArgusAIConversationSummary();
        // setParent takes `?DefaultObject &$parent` BY-REF — a throwaway local keeps $conversation's type (the
        // summarizeForCompaction pattern).
        $summarizerParent = $conversation;
        $summarizer->setParent($summarizerParent);
        if (!$forceFullRebuild && isset($conversation->summary)) {
            $summarizer->previousSummary = $conversation->summary; // → incremental input over the delta slice
        }
        $summarizer->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
        $generatedSummary = $summarizer->toEntity();
        if (!isset($generatedSummary->title) || !isset($generatedSummary->text)) {
            $this->aiTrace('summary.skipped', ['conversationId' => $conversation->id, 'reason' => 'null_summary']);
            return; // the model produced nothing usable — keep the current summary; the next settle retries
        }
        $generatedSummary->coveredUpToMessageIndex = $highestCoveredMessageIndex;
        $conversation->summary = $generatedSummary;
        $this->aiTrace('summary.generated', [
            'conversationId' => $conversation->id,
            'hasTitle' => isset($conversation->summary->title),
            'coveredUpToMessageIndex' => $highestCoveredMessageIndex,
        ]);
        // targeted single-column persist of `summary` — NEVER a full-row update (which would clobber a
        // concurrent run-claim's runState/runToken; e.g. this async summary worker loaded IDLE, spent seconds in the
        // LLM, and a turn claimed RUNNING in between → a full-row write-back stole the claim).
        $this->persistSummary($conversation);
        $this->aiTrace('summary.persisted', ['conversationId' => $conversation->id]);
        // Ping the frontend over Firebase so the new title/summary shows without polling (best-effort — swallows on a
        // Firebase outage). Fires on both the async first-message path and the compaction refresh, since both land here.
        /** @var AIConversationRealtimeSignalService $realtimeSignalService */
        $realtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        $realtimeSignalService->publishSummaryUpdatedSignal($conversation);
    }

    /**
     * Record a USER title edit: set the new title AND latch {@see AIConversationSummary::$manuallyEdited} so
     * future auto-refreshes (first-message / compaction) never overwrite it again. Title-only — the summary text stays
     * system-generated. `isset()` is used (single-level, lazy-safe) so a fresh VO is built rather than firing the Argus
     * generator. Persists + returns the conversation. The endpoints (customer + admin) call this.
     */
    public function editTitle(AIConversation $conversation, string $title): AIConversation
    {
        $summary = isset($conversation->summary) ? $conversation->summary : new AIConversationSummary();
        $summary->editTitleManually($title);
        $conversation->summary = $summary;
        // targeted single-column persist of `summary` — never a full-row update (run-state clobber).
        $this->persistSummary($conversation);
        $this->aiTrace('title.edited', ['conversationId' => $conversation->id]);
        return $conversation;
    }

    /**
     * An AWAITING_DELEGATES parent quiet for this long is treated as a DROPPED fan-in fold (foldable now — no worker
     * is computing) and recovered by the reaper on this SHORT threshold, vs the long {@see AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS} for a
     * crashed RUNNING worker. Also the staleness bar for recover-on-read.
     */
    public const int FOLD_OWED_STALE_AFTER_SECONDS = 30;

    /**
     * The reaper's REVIVAL GRACE WINDOW: a stuck non-terminal conversation whose heartbeat is older than this is NOT
     * revived anymore — it is EXPIRED to FAILED instead. The reaper is a SHORT-TERM instrument: it heals TRANSIENT
     * failures (crashed worker, dropped message), which recover on the first re-dispatch after the outage — at the
     * ~5-min cron cadence this window buys ~6 revival attempts, plenty for any transient cause. What is still stuck
     * after that is STRUCTURALLY broken (budget exception, poisoned state, bug); further pokes burn tokens forever
     * (revive → wedge again → stale again → re-poke). UX-wise an interactive answer arriving 30+ min after the
     * question is stale anyway — after a longer outage the right behaviour is to NOT belatedly answer old turns.
     * NOTE: the window counts 30 min of SILENCE, not of work — a legitimately long turn heartbeats every step and
     * never enters the stuck set. A user message into an expired conversation still starts a normal fresh run —
     * expiry only cancels the OWED autonomous re-drive, not the conversation.
     */
    public const int RUN_REVIVE_MAX_AGE_SECONDS = 1800;

    /** @var string The environment name of a production deployment ({@see currentEnvironment()}). */
    public const string ENVIRONMENT_PRODUCTION = AgentEnvironmentNamesService::ENVIRONMENT_PRODUCTION;

    /**
     * The name of the ENVIRONMENT this process runs in — a dev workspace's name (`/var/www/dev-workspaces/<name>/app`
     * → `<name>`), `prod` on a production deployment, `local` on a developer's machine. Every won run claim stamps it
     * onto the conversation ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState::$environment});
     * the reaper wakes and expires only the conversations of its own environment: production and every dev workspace
     * share ONE database and ONE cron tick, so without the stamp the production reaper continued lost dev turns with
     * production code (a dev run refused by a gate of production's older code).
     * The name comes from {@see AgentEnvironmentNamesService} (a single-environment application answers production).
     */
    public static function currentEnvironment(): string
    {
        /** @var AgentEnvironmentNamesService $agentEnvironmentNamesService */
        $agentEnvironmentNamesService = DDDService::instance()->getService(AgentEnvironmentNamesService::class);
        return $agentEnvironmentNamesService->getCurrentEnvironmentName();
    }

    /**
     * Every conversation is stamped with its environment the moment it is FIRST persisted — not only when a worker
     * claims it ({@see AIConversationRunClaimsService::claimRun()}). A first turn whose resume was lost before any claim would otherwise carry no
     * stamp, and {@see DBAIConversations::buildEnvironmentPredicate()} lets production adopt unstamped rows: production's owed-turns cron
     * would re-drive a lost dev or test turn with production code. This is the one chokepoint every creation path
     * passes (HTTP start, the chat console, delegation children); the stamp is the name of the broker vhost the
     * conversation's messages travel through, so both always agree.
     */
    public function update(
        DefaultObject $entity,
        int $depth = DatabaseRepoEntity::UPDATE_DEFAULT_RECURSIVE_DEPTH
    ): DefaultObject {
        if ($entity instanceof AIConversation && !isset($entity->id)) {
            $this->stampEnvironmentOnNewConversation($entity);
        }
        return parent::update($entity, $depth);
    }

    /** The stamp of a conversation that has never been persisted; never overwrites a stamp already present. */
    protected function stampEnvironmentOnNewConversation(AIConversation $conversation): void
    {
        $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
        if ($agentWorkingState->environment === null) {
            $agentWorkingState->environment = self::currentEnvironment();
        }
    }

    /**
     * Of the given conversation ids, the ones THIS environment owns ({@see DBAIConversations::buildEnvironmentPredicate()}) — for a sweep
     * over rows that only reference a conversation (a phone call) and cannot carry the predicate in their own query.
     *
     * @param int[] $conversationIds
     * @return int[]
     */
    public function keepConversationIdsOfCurrentEnvironment(array $conversationIds): array
    {
        $conversationIds = array_values(array_unique(array_map('intval', $conversationIds)));
        if ($conversationIds === []) {
            return [];
        }
        $tableName = DBAIConversationModel::getTableName();
        return array_map('intval', EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT `id` FROM `' . $tableName . '` WHERE `id` IN (:ids) AND ' . DBAIConversations::buildEnvironmentPredicate($tableName, self::currentEnvironment()),
            ['ids' => $conversationIds, 'environment' => self::currentEnvironment()],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        ));
    }

    /** How many conversations carry no environment stamp — the dry-run figure of the backfill. */
    public function countConversationsWithoutEnvironment(): int
    {
        $tableName = DBAIConversationModel::getTableName();
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `' . $tableName . '`'
            . ' WHERE (' . EntityManagerFactory::getDialect()->jsonFieldText('`agentWorkingState`', 'environment') . ') IS NULL'
        );
    }

    /**
     * One-off: stamps every conversation that carries no environment with `$environment` (production, which drove
     * every unstamped row before the stamp existed), in batches, so that the production-adopts-null clause of
     * {@see DBAIConversations::buildEnvironmentPredicate()} can be dropped. Returns the number of rows stamped.
     */
    public function backfillEnvironmentOnUnstampedConversations(string $environment, int $batchSize = 1000): int
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $sqlDialect = EntityManagerFactory::getDialect();
        $tableName = DBAIConversationModel::getTableName();
        $noEnvironmentStampSql = '(' . $sqlDialect->jsonFieldText('`agentWorkingState`', 'environment') . ') IS NULL';
        $environmentStampWriteSql = $sqlDialect->jsonUpdate('`agentWorkingState`')
            ->set(JsonPath::fromKeys('environment'), ':environment', JsonValueKind::TEXT)
            ->toSql();
        $stampedRows = 0;
        $highestConversationIdOfEarlierBatches = 0;
        // an UPDATE with a row limit exists on MariaDB only: each batch is read by id first, the write repeats the
        // condition; walking upwards by id ends the run even over a row the write cannot stamp
        while (true) {
            $unstampedConversationIds = array_map('intval', $connection->fetchFirstColumn(
                'SELECT `id` FROM `' . $tableName . '` WHERE `id` > :highestConversationIdOfEarlierBatches AND ' . $noEnvironmentStampSql
                . ' ORDER BY `id` ASC LIMIT ' . max(1, $batchSize),
                ['highestConversationIdOfEarlierBatches' => $highestConversationIdOfEarlierBatches]
            ));
            if ($unstampedConversationIds === []) {
                return $stampedRows;
            }
            $stampedRows += (int)$connection->executeStatement(
                'UPDATE `' . $tableName . '` SET `agentWorkingState` = ' . $environmentStampWriteSql
                . ' WHERE `id` IN (:unstampedConversationIds) AND ' . $noEnvironmentStampSql,
                ['environment' => $environment, 'unstampedConversationIds' => $unstampedConversationIds],
                ['unstampedConversationIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
            );
            $highestConversationIdOfEarlierBatches = max($unstampedConversationIds);
        }
    }

    /** The conversation's stored delegation anchor by a raw read (no registry, no rights) — null when the row is gone. */
    public function findDelegationAnchorById(int $conversationId): ?AgentDelegationAnchor
    {
        $row = EntityManagerFactory::getInstance()->getConnection()->fetchAssociative(
            'SELECT `delegatedFromMessageId`, `delegationBatchId`, `rootConversationId` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` = :id',
            ['id' => $conversationId]
        );
        if (!is_array($row)) {
            return null;
        }
        return AgentDelegationAnchor::fromRow($row);
    }

    /**
     * IDLE purposed ROOT conversations that have been quiet for at least $quietSeconds — the reaper's settle sweep
     * feeds them through the settled-turn close path ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::settlePurposeConversationIfGoalReached()}):
     * a conversation whose goal was reached but whose finalize failed, or whose open offers were never deferred,
     * gets its close retried without waiting for an owner turn that will not come. Newest first, bounded.
     *
     * @return int[]
     * @throws Exception
     */
    public function findQuietIdlePurposedConversationIds(string $purposeName, int $quietSeconds, int $limit = self::OWED_CONVERSATIONS_BATCH_LIMIT): array
    {
        $quietSince = (new DateTime())->modify('-' . max(1, $quietSeconds) . ' seconds')->format('Y-m-d H:i:s');
        $rows = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT `id` FROM `' . DBAIConversationModel::getTableName() . '`'
            . ' WHERE `purposeName` = :purposeName AND `runState` = :idleState AND `delegatedFromMessageId` IS NULL'
            . ' AND `agentWorkingState` IS NOT NULL AND (`runHeartbeatAt` IS NULL OR `runHeartbeatAt` <= :quietSince)'
            . ' AND ' . DBAIConversations::buildEnvironmentPredicate(DBAIConversationModel::getTableName(), self::currentEnvironment())
            . ' ORDER BY `id` DESC LIMIT ' . max(1, $limit),
            ['purposeName' => $purposeName, 'idleState' => AIConversationRunState::IDLE, 'quietSince' => $quietSince, 'environment' => self::currentEnvironment()]
        );
        return array_map('intval', $rows);
    }

    /**
     * The settled-turn twin of {@see persistTaskPlanSlice()}: writes the plan slice ONLY while the conversation is
     * IDLE (`WHERE runState = IDLE`) — the reconciliation that runs at the settled release point holds no run
     * claim, so the fence is the run state itself: affected rows 0 means a new run has taken the conversation
     * over (or the plan is byte-identical) and NOTHING is written; the next settled turn re-evaluates. Never
     * throws for that case. Same merge-patch statement and marker columns as the fenced write.
     */
    public function persistTaskPlanSliceWhileIdle(AIConversation $conversation, TaskPlan $taskPlan): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $sliceJson = (string)json_encode(
            ['taskPlan' => $taskPlan->mapToRepository()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $taskPlanOpenAt = $taskPlan->getOpenMarkerAt(new DateTime());
        $taskPlanRetryableAt = $taskPlan->getRetryableMarkerAt(new DateTime());
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `agentWorkingState` = ' . self::buildAgentWorkingStateMergePatchSql() . ','
            . ' `taskPlanOpenAt` = :taskPlanOpenAt, `taskPlanRetryableAt` = :taskPlanRetryableAt'
            . ' WHERE `id` = :id AND `runState` = :idleState',
            [
                'slice' => $sliceJson,
                'id' => $conversation->id,
                'taskPlanOpenAt' => $taskPlanOpenAt,
                'taskPlanRetryableAt' => $taskPlanRetryableAt,
                'idleState' => AIConversationRunState::IDLE,
            ]
        );
        if ($affectedRows === 1) {
            return true;
        }
        // 0 rows = not IDLE any more (a new run owns the conversation) OR a byte-identical slice; only the run
        // state tells the two apart — an identical slice on a still-IDLE row IS persisted state.
        $currentRunState = (string)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `runState` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` = :id',
            ['id' => $conversation->id]
        );
        return $currentRunState === AIConversationRunState::IDLE;
    }

    /**
     * persist the conversation's CURRENT task plan as a BARE `{taskPlan: …}` slice onto the
     * `agentWorkingState` MERGE column, TOKEN-FENCED when a run claim is held: the atomic
     * `UPDATE … JSON_MERGE_PATCH … WHERE id AND runToken` (the releaseRun pattern) closes the check-then-write
     * window a column write has no UNIQUE backstop for — affectedRows 0 under a held
     * claim = the claim was stolen → {@see AgentRunClaimLostException}, so a reaper-outrun zombie can never write a
     * stale plan back. `$runToken` null (admin sync drive / CLI — no claim held) writes unfenced: the documented
     * pre-existing claimless window, self-healing (the next fenced write overwrites). NEVER writes the loaded
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState} VO back — a full-VO write would merge
     * stale sibling slices over a concurrent writer's newer ones. `$taskPlan` null clears the plan (RFC 7396:
     * an explicit null deletes the key).
     */
    public function persistTaskPlanSlice(AIConversation $conversation, ?TaskPlan $taskPlan, ?string $runToken): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $sliceJson = (string)json_encode(
            ['taskPlan' => $taskPlan?->mapToRepository()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        // the observability/policy markers ride the SAME statement as the slice — one writer, one
        // statement, no divergence window. Derived from the passed plan (the explicit-null clear NULLs both).
        $taskPlanOpenAt = $taskPlan?->getOpenMarkerAt(new DateTime());
        $taskPlanRetryableAt = $taskPlan?->getRetryableMarkerAt(new DateTime());
        $parameters = [
            'slice' => $sliceJson,
            'id' => $conversation->id,
            'taskPlanOpenAt' => $taskPlanOpenAt,
            'taskPlanRetryableAt' => $taskPlanRetryableAt,
        ];
        $fenceClause = '';
        if ($runToken !== null) {
            $fenceClause = ' AND `runToken` = :runToken';
            $parameters['runToken'] = $runToken;
        }
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `agentWorkingState` = ' . self::buildAgentWorkingStateMergePatchSql() . ','
            . ' `taskPlanOpenAt` = :taskPlanOpenAt, `taskPlanRetryableAt` = :taskPlanRetryableAt'
            . ' WHERE `id` = :id' . $fenceClause,
            $parameters
        );
        // affectedRows counts MATCHED-and-changed rows; an unchanged re-write of the identical plan also returns 0.
        // Under a held claim, disambiguate via the claim probe — only a genuinely lost claim throws.
        if ($runToken !== null && $affectedRows === 0 && !$this->runClaimsService()->holdsRunClaim($conversation, $runToken)) {
            throw new AgentRunClaimLostException('Run-claim lost before the task-plan write — another worker owns this conversation.');
        }
    }

    /**
     * Persist the RETIREMENT of a finished plan in ONE statement: the active `taskPlan` cleared (an
     * explicit null — RFC 7396 deletes the key), the `completedTaskPlans` set replaced wholesale (arrays replace under
     * JSON_MERGE_PATCH), both plan markers NULLed, token-fenced under a held run claim exactly like
     * {@see persistTaskPlanSlice()} (a stolen claim throws {@see AgentRunClaimLostException}). Reads the LOADED working
     * state — {@see AgentWorkingState::retireFinishedTaskPlan()} mutated it first.
     */
    public function persistTaskPlanRetirementSlice(AIConversation $conversation, ?string $runToken): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $agentWorkingState = $conversation->getAgentWorkingState();
        if ($agentWorkingState === null) {
            return;
        }
        $sliceJson = (string)json_encode(
            [
                'taskPlan' => null,
                'completedTaskPlans' => $agentWorkingState->completedTaskPlans?->toRepositoryArray() ?? [],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $parameters = ['slice' => $sliceJson, 'id' => $conversation->id];
        $fenceClause = '';
        if ($runToken !== null) {
            $fenceClause = ' AND `runToken` = :runToken';
            $parameters['runToken'] = $runToken;
        }
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `agentWorkingState` = ' . self::buildAgentWorkingStateMergePatchSql() . ','
            . ' `taskPlanOpenAt` = NULL, `taskPlanRetryableAt` = NULL'
            . ' WHERE `id` = :id' . $fenceClause,
            $parameters
        );
        if ($runToken !== null && $affectedRows === 0 && !$this->runClaimsService()->holdsRunClaim($conversation, $runToken)) {
            throw new AgentRunClaimLostException('Run-claim lost before the task-plan retirement write — another worker owns this conversation.');
        }
    }

    /**
     * @var array<int, array<string, true>> Conversation object id → the slice keys persisted while a collected stretch
     *      runs ({@see collectAgentWorkingStateSliceWrites()}); emptied by {@see writeCollectedAgentWorkingStateSlices()}.
     */
    protected array $collectedAgentWorkingStateSliceKeysByConversation = [];

    /**
     * From now until {@see writeCollectedAgentWorkingStateSlices()}, the slices persisted for this conversation are
     * collected instead of written one UPDATE each: the turn-start application stamps up to seven slices (one per
     * decision site plus the decision log after each) that land in ONE statement at its end. The in-memory working
     * state is the truth in between; the end writes each key's final value, which is what the single writes left.
     */
    public function collectAgentWorkingStateSliceWrites(AIConversation $conversation): void
    {
        $this->collectedAgentWorkingStateSliceKeysByConversation[spl_object_id($conversation)] ??= [];
    }

    /**
     * End the collected stretch of {@see collectAgentWorkingStateSliceWrites()} and write the collected keys in ONE
     * fenced statement; nothing when none was persisted. `$writeCollectedKeys = false` drops them (the run claim is
     * already lost — the write would only throw again).
     *
     * @throws AgentRunClaimLostException when this process's claim on the conversation is no longer held
     */
    public function writeCollectedAgentWorkingStateSlices(AIConversation $conversation, bool $writeCollectedKeys = true): void
    {
        $conversationObjectId = spl_object_id($conversation);
        $collectedSliceKeys = array_keys($this->collectedAgentWorkingStateSliceKeysByConversation[$conversationObjectId] ?? []);
        unset($this->collectedAgentWorkingStateSliceKeysByConversation[$conversationObjectId]);
        if ($writeCollectedKeys && $collectedSliceKeys !== []) {
            $this->persistAgentWorkingStateSlice($conversation, ...$collectedSliceKeys);
        }
    }

    /**
     * THE writer of the working-state slices: JSON_MERGE_PATCH the named TOP-LEVEL keys of the
     * conversation's LOADED working state ({@see AgentWorkingState::buildSlice()}) onto the `agentWorkingState` merge
     * column — one writer per key, the column's concurrency contract ({@see AgentWorkingState}); a JSON array value
     * (`variants`, `decisions`, the owner-row index lists) is replaced as a whole, a null deletes its key. Nothing is
     * written while the conversation has no working state. The run token is the one this process holds for the
     * conversation ({@see AIConversationRunClaimsService::getRunTokenClaimedInThisProcess()}): a worker's write lands only while its claim holds, a
     * process without a claim (CLI / eval / admin drive, the supervisor) writes unfenced.
     *
     * @throws AgentRunClaimLostException when this process's claim on the conversation is no longer held
     */
    public function persistAgentWorkingStateSlice(AIConversation $conversation, string ...$sliceKeys): void
    {
        $agentWorkingState = $conversation->getAgentWorkingState();
        if ($agentWorkingState === null) {
            return;
        }
        $conversationObjectId = spl_object_id($conversation);
        if (isset($this->collectedAgentWorkingStateSliceKeysByConversation[$conversationObjectId])) {
            // a collected stretch (the turn-start application): the keys are written together at its end
            foreach ($sliceKeys as $sliceKey) {
                $this->collectedAgentWorkingStateSliceKeysByConversation[$conversationObjectId][$sliceKey] = true;
            }
            return;
        }
        $this->writeAgentWorkingStateSliceUnderRunToken($conversation, $agentWorkingState->buildSlice(...$sliceKeys), $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation));
    }

    /**
     * {@see persistAgentWorkingStateSlice()} for the step stamps of a turn (`turnMinimumSteps` + its turn stamp,
     * `budgetClosingStepTurnInitiatorMessageIndex`): a conversation without a loaded working state writes the keys'
     * empty values (null) instead of writing nothing — a chunked (worker) turn re-reads these between chunks, and an
     * older turn's value must not survive.
     *
     * @throws AgentRunClaimLostException when this process's claim on the conversation is no longer held
     */
    public function persistAgentWorkingStateSliceEvenWithoutAWorkingState(AIConversation $conversation, string ...$sliceKeys): void
    {
        $agentWorkingState = $conversation->getAgentWorkingState() ?? new AgentWorkingState();
        $this->writeAgentWorkingStateSliceUnderRunToken($conversation, $agentWorkingState->buildSlice(...$sliceKeys), $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation));
    }

    /**
     * The ONE writer of the per-conversation skill-preload switch: an ADMIN decision — the console's
     * start / continue / PATCH run-settings, or the impersonation panel in the client view — never a customer's. A
     * value outside `inherit` / `on` / `off` is refused; `inherit` clears the row back to the site's mode. The audit
     * fields name who set it and when; a partial write of the run-settings column only. Not inherited by children.
     * A non-admin caller is ignored silently (the customer endpoints pass the query param straight through and let this
     * gate decide) — the switch never leaks a 4xx that would tell a customer it exists.
     */
    public function applyAdminRunSettingsOverride(AIConversation $conversation, ?RunSettingsOverride $override, bool $adminIsOperatingSession): void
    {
        if ($override === null || $override->isEmpty() || !$adminIsOperatingSession || !isset($conversation->id)) {
            return;
        }
        $runSettings = isset($conversation->runSettings) ? $conversation->runSettings : RunSettings::newInstance();
        $setByAccountId = (int) (AuthService::instance()->getAccount()?->id ?? 0) ?: null;
        // in place: a row that throws leaves the rows before it applied on the loaded run settings, and nothing is written
        $runSettings->applyOverride($override, $setByAccountId, new DateTime());
        // a wire-API or carry-over row is validated with the conversation's model before it persists
        if ($override->agentWireApi !== null || $override->previousReasoningCarryOver !== null) {
            /** @var AIConversationModelCallsService $aIConversationModelCallsService */
            $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
            $conversation->runSettings = $runSettings;
            $agentWireConfiguration = $aIConversationModelCallsService->resolveAgentWireConfiguration($conversation, $conversation->aiModelNameOverride ?? null);
            if ($agentWireConfiguration->isRejected()) {
                throw new BadRequestException((string)$agentWireConfiguration->rejectionReason);
            }
        }
        $conversation->runSettings = $runSettings;
        $conversation->updatePartialIgnoringRights('runSettings');
    }

    /**
     * Of the given conversations, the ids a CUSTOMER never sees: conversations of a platform-internal purpose
     * ({@see AIConversationPurpose::CUSTOMER_HIDDEN_PURPOSE_NAMES}) and the conversations of their delegation trees.
     * One id lookup, no entity hydration — the filter of search and recall candidates.
     *
     * @param int[] $conversationIds
     * @return int[]
     * @throws Exception
     */
    public function findCustomerHiddenConversationIds(array $conversationIds): array
    {
        $conversationIds = array_values(array_unique(array_filter(array_map('intval', $conversationIds))));
        if ($conversationIds === []) {
            return [];
        }
        $tableName = DBAIConversationModel::getTableName();
        return array_map('intval', EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            "SELECT `conversation`.`id` FROM `{$tableName}` `conversation`"
            . " LEFT JOIN `{$tableName}` `rootConversation` ON `rootConversation`.`id` = `conversation`.`rootConversationId`"
            . ' WHERE `conversation`.`id` IN (:conversationIds)'
            . ' AND (`conversation`.`purposeName` IN (:hiddenPurposeNames) OR `rootConversation`.`purposeName` IN (:hiddenPurposeNames))',
            ['conversationIds' => $conversationIds, 'hiddenPurposeNames' => AIConversationPurpose::getCustomerHiddenPurposeNames()],
            [
                'conversationIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
                'hiddenPurposeNames' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ]
        ));
    }

    /**
     * The conversation a CUSTOMER path works on: found under the read rights AND visible to a customer. A
     * platform-internal conversation answers like one that does not exist.
     *
     * @throws NotFoundException when the conversation does not exist, may not be read or is hidden from customers
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findVisibleToCustomer(int $aiConversationId): AIConversation
    {
        $conversation = $this->find($aiConversationId);
        if (!$conversation instanceof AIConversation || $conversation->isHiddenFromCustomer()) {
            throw new NotFoundException("AIConversation {$aiConversationId} not found.");
        }
        return $conversation;
    }

    /**
     * Every conversation the authenticated account may read and a customer may see (the default query options apply).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAllVisibleToCustomer(): AIConversations
    {
        return $this->getEntitySetRepoClassInstance()->findAllVisibleToCustomer();
    }

    /**
     * The ROOT conversation of the delegation tree a tool call runs in — the calling conversation itself when it is
     * the root. A tool that belongs to one kind of run authorises through the root: a look-up child carries no
     * purpose of its own. Null on the HTTP-MCP path, where no conversation exists.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findRootConversationForToolCall(AgentToolCallProvenance $agentToolCallProvenance): ?AIConversation
    {
        $rootConversationId = $agentToolCallProvenance->getTreeRootConversationIdForToolCall();
        if ($rootConversationId === null) {
            return null;
        }
        return $agentToolCallProvenance->getCallingConversationWhenItIsTheTreeRoot() ?? $this->find($rootConversationId, false);
    }

    /**
     * Compose + persist the DAY CONTEXT snapshot of a FRESHLY-CREATED conversation: the application's text for
     * the day and what the agent recorded for the day. Written ONCE, like the episodic history — a later
     * text must not change the prefix of a running conversation. No-op for every conversation the day context is
     * not meant for (see {@see AgentSituationalContextService::composeDayContextTextForNewConversation()}; the host: the
     * day-context service).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function composeAndPersistDayContext(AIConversation $conversation): void
    {
        /** @var AgentSituationalContextService $agentSituationalContextService */
        $agentSituationalContextService = DDDService::instance()->getService(AgentSituationalContextService::class);
        $dayContextText = $agentSituationalContextService->composeDayContextTextForNewConversation($conversation);
        if ($dayContextText === null) {
            return;
        }
        $this->persistDayContextTextSlice($conversation, $dayContextText);
        $this->aiTrace('dayContext.persisted', ['conversationId' => $conversation->id, 'chars' => strlen($dayContextText)]);
    }

    /**
     * Persist the DAY CONTEXT snapshot of a freshly created conversation — its own top-level key on the
     * `agentWorkingState` merge column, written ONCE: the statement applies only while the key is still absent or
     * JSON null, so a second call can never change what the conversation's context segment was built from.
     */
    public function persistDayContextTextSlice(AIConversation $conversation, string $dayContextText): void
    {
        $dayContextText = trim($dayContextText);
        if (!isset($conversation->id) || $dayContextText === '') {
            return;
        }
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `agentWorkingState` = ' . self::buildAgentWorkingStateMergePatchSql()
            // absent OR stored as JSON null: the creation writes every key of the working state, null ones included
            . ' WHERE `id` = :id AND ' . self::buildAgentWorkingStateKeyIsUnsetSql('dayContextText'),
            [
                'slice' => (string)json_encode(['dayContextText' => $dayContextText], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'id' => $conversation->id,
            ]
        );
        if ($affectedRows === 0) {
            return;
        }
        $agentWorkingState = $conversation->getAgentWorkingState() ?? new AgentWorkingState();
        $agentWorkingState->dayContextText = $dayContextText;
        $conversation->agentWorkingState = $agentWorkingState;
    }

    /**
     * Compose + persist the LOCAL SITUATION snapshot of a FRESHLY-CREATED conversation: the application's text about
     * what goes on around the space the conversation works for. Written ONCE, like the day context. No-op for every conversation it is not meant for (see
     * {@see AgentSituationalContextService::composeLocalSituationText()}) and when nothing is stored.
     */
    public function composeAndPersistLocalSituation(AIConversation $conversation): void
    {
        /** @var AgentSituationalContextService $agentSituationalContextService */
        $agentSituationalContextService = DDDService::instance()->getService(AgentSituationalContextService::class);
        $localSituationText = $agentSituationalContextService->composeLocalSituationText($conversation);
        // the first contact of the day starts what is not researched yet; this conversation reads it with the application's tool
        $agentSituationalContextService->startLocalSituationResearchOnFirstContact($conversation);
        if ($localSituationText === null) {
            return;
        }
        $this->persistLocalSituationTextSlice($conversation, $localSituationText);
        $this->aiTrace('localSituation.persisted', ['conversationId' => $conversation->id, 'chars' => strlen($localSituationText)]);
    }

    /**
     * Bring the LOCAL SITUATION of an existing conversation up to now (the situation is read by every
     * conversation as it stands, not as it stood when the conversation began): composed again, and written over the
     * stored text only when it changed — so the cached context prefix breaks only when the situation does (a new day,
     * a research that finished, a new picture). Never throws: a failed read keeps the text the conversation has.
     */
    public function refreshLocalSituation(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        try {
            /** @var AgentSituationalContextService $agentSituationalContextService */
            $agentSituationalContextService = DDDService::instance()->getService(AgentSituationalContextService::class);
            $localSituationText = trim((string)$agentSituationalContextService->composeLocalSituationText($conversation));
            $storedText = trim((string)($conversation->getAgentWorkingState()?->localSituationText ?? ''));
            if ($localSituationText === '' || $localSituationText === $storedText) {
                return;
            }
            EntityManagerFactory::getInstance()->getConnection()->executeStatement(
                'UPDATE `' . DBAIConversationModel::getTableName() . '`'
                . ' SET `agentWorkingState` = ' . self::buildAgentWorkingStateMergePatchSql() . ' WHERE `id` = :id',
                [
                    'slice' => (string)json_encode(['localSituationText' => $localSituationText], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'id' => $conversation->id,
                ]
            );
            $agentWorkingState = $conversation->getAgentWorkingState() ?? new AgentWorkingState();
            $agentWorkingState->localSituationText = $localSituationText;
            $conversation->agentWorkingState = $agentWorkingState;
            $this->aiTrace('localSituation.refreshed', ['conversationId' => $conversation->id, 'chars' => strlen($localSituationText)]);
        } catch (Throwable $refreshError) {
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->warning(
                'Local situation could not be refreshed: ' . $refreshError->getMessage(),
                ['conversationId' => $conversation->id ?? null]
            );
        }
    }

    /**
     * Persist the LOCAL SITUATION snapshot of a freshly created conversation — its own top-level key on the
     * `agentWorkingState` merge column, written ONCE: the statement applies only while the key is still absent or
     * JSON null.
     */
    public function persistLocalSituationTextSlice(AIConversation $conversation, string $localSituationText): void
    {
        $localSituationText = trim($localSituationText);
        if (!isset($conversation->id) || $localSituationText === '') {
            return;
        }
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `agentWorkingState` = ' . self::buildAgentWorkingStateMergePatchSql()
            // absent OR stored as JSON null: the creation writes every key of the working state, null ones included
            . ' WHERE `id` = :id AND ' . self::buildAgentWorkingStateKeyIsUnsetSql('localSituationText'),
            [
                'slice' => (string)json_encode(['localSituationText' => $localSituationText], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'id' => $conversation->id,
            ]
        );
        if ($affectedRows === 0) {
            return;
        }
        $agentWorkingState = $conversation->getAgentWorkingState() ?? new AgentWorkingState();
        $agentWorkingState->localSituationText = $localSituationText;
        $conversation->agentWorkingState = $agentWorkingState;
    }

    /**
     * The SQL that writes the bound slice `:slice` over the stored working state (RFC 7396: a key of the slice replaces
     * the stored key, an explicit null deletes it, every other stored key stays) — the right-hand side of
     * `agentWorkingState = …` in every slice write of this service.
     */
    protected static function buildAgentWorkingStateMergePatchSql(): string
    {
        return EntityManagerFactory::getDialect()->jsonUpdate('`agentWorkingState`')->mergePatch(':slice')->toSql();
    }

    /** The SQL condition "the working state carries no value under this top-level key": the key is absent or JSON null. */
    protected static function buildAgentWorkingStateKeyIsUnsetSql(string $workingStateKey): string
    {
        return 'COALESCE(' . EntityManagerFactory::getDialect()->jsonType('`agentWorkingState`', JsonPath::fromKeys($workingStateKey)) . ", 'null') = 'null'";
    }

    /**
     * JSON_MERGE_PATCH one or more TOP-LEVEL keys of the agent working state — the one-writer-per-key contract of
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState}: a bare carrier holding only the touched
     * keys, so a concurrent writer's sibling slices are never merged over. No-op without a conversation id (the
     * CLI's ephemeral run keeps the in-memory state only).
     *
     * TOKEN-FENCED exactly like {@see persistTaskPlanSlice()}: the slice writers pass the token this process claimed
     * the conversation with ({@see AIConversationRunClaimsService::getRunTokenClaimedInThisProcess()}) and the write lands only while that claim holds — a worker that
     * lost its claim (reaper steal, redelivery) can never replace the successor's slice with its stale one; an
     * unchanged rewrite also matches 0 rows, so the claim probe tells the two apart and only a lost claim throws. A
     * null token (no claim held: the CLI/eval drive, the supervisor writing onto the conversation it supervises,
     * admin tools) writes unconditionally.
     *
     * @param array<string, mixed> $slice top-level key → repository shape (null clears the key)
     * @throws AgentRunClaimLostException when the claim behind $runToken is no longer held
     */
    protected function writeAgentWorkingStateSliceUnderRunToken(AIConversation $conversation, array $slice, ?string $runToken): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $parameters = [
            'slice' => (string)json_encode($slice, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'id' => $conversation->id,
        ];
        $fenceClause = '';
        if ($runToken !== null) {
            $fenceClause = ' AND `runToken` = :runToken';
            $parameters['runToken'] = $runToken;
        }
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `agentWorkingState` = ' . self::buildAgentWorkingStateMergePatchSql()
            . ' WHERE `id` = :id' . $fenceClause,
            $parameters
        );
        if ($runToken !== null && $affectedRows === 0 && !$this->runClaimsService()->holdsRunClaim($conversation, $runToken)) {
            throw new AgentRunClaimLostException('Run-claim lost before a working-state write (' . implode(', ', array_keys($slice)) . ') — another worker owns this conversation.');
        }
    }

    /**
     * The reaper query: ids of conversations stuck NON-TERMINAL (RUNNING / AWAITING_DELEGATES) whose heartbeat
     * is older than the hung threshold — a crashed worker or a dropped resume message. TREE-WIDE (delegates too, not
     * just parents): a delegate whose worker died mid-loop is the common crash. Uses the (runState, runHeartbeatAt)
     * index. The reaper wakes each via a fresh resume; the claim's staleness clause lets that resume reclaim the row.
     * BOUNDED by the revival grace window: a conversation stuck for longer than $reviveMaxAgeSeconds is NOT returned
     * (not revived) — {@see expireStuckConversationsPastMaxAge} fails it terminally instead.
     *
     * @return int[]
     */
    public function findStuckConversationIds(
        int $hungAfterSeconds = AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS,
        int $reviveMaxAgeSeconds = self::RUN_REVIVE_MAX_AGE_SECONDS
    ): array {
        $runningHungBefore = (new DateTime())->modify('-' . $hungAfterSeconds . ' seconds');
        // Threshold SPLIT: a RUNNING worker may legitimately run minutes (a real multi-step turn,
        // heartbeated each step) → only crash-reclaim it after the long hung threshold. But an AWAITING_DELEGATES
        // parent whose fan-in fold-resume was dropped (the claim race) is foldable NOW — nobody is computing — so
        // recover it on a SHORT threshold instead of wasting up to 300s. A spurious re-poke of a still-waiting parent
        // is a clean no-op (claimRun → re-derive → re-park) and refreshes its heartbeat, so the short sweep is
        // self-limiting (it won't re-poke the same parent again for FOLD_OWED_STALE_AFTER_SECONDS).
        $awaitingStaleBefore = (new DateTime())->modify('-' . self::FOLD_OWED_STALE_AFTER_SECONDS . ' seconds');
        $oldestReviveableHeartbeatAt = (new DateTime())->modify('-' . $reviveMaxAgeSeconds . ' seconds');
        $environment = self::currentEnvironment();
        $ids = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT `c`.`id` FROM `' . DBAIConversationModel::getTableName() . '` `c`'
            . ' WHERE `c`.`runHeartbeatAt` IS NOT NULL AND `c`.`runHeartbeatAt` >= :oldestReviveableHeartbeatAt AND ('
            . '(`c`.`runState` = :running AND `c`.`runHeartbeatAt` < :runningHungBefore)'
            . ' OR (`c`.`runState` = :awaiting AND `c`.`runHeartbeatAt` < :awaitingStaleBefore))'
            // only this environment's conversations — production shares the database with every dev workspace
            . ' AND ' . DBAIConversations::buildEnvironmentPredicate('c', $environment),
            [
                'running' => AIConversationRunState::RUNNING,
                'awaiting' => AIConversationRunState::AWAITING_DELEGATES,
                'runningHungBefore' => $runningHungBefore->format('Y-m-d H:i:s'),
                'awaitingStaleBefore' => $awaitingStaleBefore->format('Y-m-d H:i:s'),
                'oldestReviveableHeartbeatAt' => $oldestReviveableHeartbeatAt->format('Y-m-d H:i:s'),
                'environment' => $environment,
            ]
        );
        return array_map('intval', $ids);
    }

    /**
     * Expire conversations stuck non-terminal PAST the revival grace window ({@see RUN_REVIVE_MAX_AGE_SECONDS}) —
     * flip them to FAILED instead of reviving (their task is assumed irrelevant by now; see the const docblock).
     * Same two states as the revival set, so revival + expiry exactly partition the reaper's stuck set. Race-safe
     * without a run-token: the UPDATE re-checks state + staleness per row, so a row a live worker just reclaimed
     * (fresh heartbeat) is skipped. QUEUED rows are untouched — they hold no heartbeat and nothing re-pokes them.
     *
     * @return int[] The ids actually expired.
     */
    public function expireStuckConversationsPastMaxAge(int $reviveMaxAgeSeconds = self::RUN_REVIVE_MAX_AGE_SECONDS): array
    {
        $oldestReviveableHeartbeatAt = (new DateTime())->modify('-' . $reviveMaxAgeSeconds . ' seconds');
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $environment = self::currentEnvironment();
        $tableName = DBAIConversationModel::getTableName();
        // only this environment's conversations — production shares the database with every dev workspace
        $statePredicate = "(`{$tableName}`.`runState` = :running OR `{$tableName}`.`runState` = :awaiting)"
            . " AND `{$tableName}`.`runHeartbeatAt` IS NOT NULL AND `{$tableName}`.`runHeartbeatAt` < :oldestReviveableHeartbeatAt"
            . ' AND ' . DBAIConversations::buildEnvironmentPredicate($tableName, $environment);
        $stateParameters = [
            'running' => AIConversationRunState::RUNNING,
            'awaiting' => AIConversationRunState::AWAITING_DELEGATES,
            'oldestReviveableHeartbeatAt' => $oldestReviveableHeartbeatAt->format('Y-m-d H:i:s'),
            'environment' => $environment,
        ];
        $expiredConversationIds = array_map(
            'intval',
            $connection->fetchFirstColumn(
                'SELECT `id` FROM `' . $tableName . '` WHERE ' . $statePredicate,
                $stateParameters
            )
        );
        if ($expiredConversationIds === []) {
            return [];
        }
        $connection->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            // reportOwedAt: the expiry is a terminal that can deliver NOTHING itself (set-based, no
            // hydration) — the stamp hands the obligation to the owed-reports recovery pass, which composes + delivers late.
            // The stamp is a PHP timestamp, never SQL NOW(): every other writer of reportOwedAt and every message
            // createdTime use the PHP clock, and the per-run report boundary compares the two — the DB session clock
            // runs in another timezone, so a NOW() stamp would sit hours ahead of the report it is compared against.
            . ' SET `runState` = :failed, `runToken` = NULL, `reportOwedAt` = COALESCE(`reportOwedAt`, :owedAt)'
            . ' WHERE `id` IN (' . implode(',', $expiredConversationIds) . ') AND ' . $statePredicate,
            ['failed' => AIConversationRunState::FAILED, 'owedAt' => (new DateTime())->format('Y-m-d H:i:s')] + $stateParameters
        );
        return $expiredConversationIds;
    }

    /**
     * The delegation children spawned by the given delegate-call messages, oldest first (the order the tasks were
     * declared), under the read rights of the caller. Empty for messages that spawned none.
     *
     * @param int[] $delegateCallMessageIds
     */
    public function findDelegationChildrenOfMessages(array $delegateCallMessageIds): AIConversations
    {
        $delegateCallMessageIds = array_values(array_filter(array_map('intval', $delegateCallMessageIds)));
        if ($delegateCallMessageIds === []) {
            return AIConversations::newInstance();
        }
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $alias = $entitySetRepo::getBaseModelAlias();
        $queryBuilder = $entitySetRepo::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.delegatedFromMessageId IN (:delegateCallMessageIds)")
            ->setParameter('delegateCallMessageIds', $delegateCallMessageIds)
            ->orderBy("$alias.id", 'ASC');
        return $entitySetRepo->find($queryBuilder) ?? AIConversations::newInstance();
    }

    /**
     * The conversations of a space, newest first, optionally narrowed by a search term — under the read rights of the
     * caller. The read filters the space, which fixes the account.
     *
     * @param AgentSpace $agentSpace
     * @param string|null $searchTerm
     * @param bool $includeUnsummarized
     *
     * @return AIConversations|null
     * @throws BadRequestException
     * @throws Exception
     * @throws ForbiddenException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws MappingException
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws JsonException
     */
    public function findByAgentSpaceAndSearchTerm(
        AgentSpace $agentSpace,
        ?string $searchTerm = null,
        bool $includeUnsummarized = false,
    ): ?AIConversations
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $agentSpacesService->applyTranslationSettingsOfAgentSpace($agentSpace);
        return $this->getEntitySetRepoClassInstance()->findByAgentSpaceAndSearchTerm(
            $agentSpace,
            $searchTerm ?? null,
            $includeUnsummarized
        );
    }

    /**
     * The persisted rows of a conversation TREE for the recorded-run comparison — a
     * historical-record read, no rights, no entities: the root and every conversation whose `rootConversationId` is
     * the root (root first, then by id), their messages (truncated rows excluded; root first, then per conversation by
     * index), the delegation batches of the tree (by id) and the compaction nodes of the tree (the conversation memory;
     * per conversation by level and covered range, the embedding left out). The comparison normalizes them
     * ({@see \DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison::buildTreeSnapshot()}).
     *
     * @return array<string, array<int, array<string, mixed>>> keyed by the comparison's tree sections
     * @throws \Doctrine\DBAL\Exception
     */
    public function findTreeRowsForRecordedRunComparison(AIConversation $rootConversation): array
    {
        if (!isset($rootConversation->id)) {
            return [];
        }
        $rootConversationId = (int)$rootConversation->id;
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $conversationRows = $connection->fetchAllAssociative(
            'SELECT * FROM `' . DBAIConversationModel::getTableName() . '`'
            . ' WHERE `id` = :rootConversationId OR `rootConversationId` = :rootConversationId ORDER BY (`id` = :rootConversationId) DESC, `id`',
            ['rootConversationId' => $rootConversationId]
        );
        $treeConversationIdList = implode(',', array_map(static fn (array $conversationRow): int => (int)$conversationRow['id'], $conversationRows));
        if ($treeConversationIdList === '') {
            return [];
        }
        $rootFirst = '(`aiConversationId` = ' . $rootConversationId . ') DESC, `aiConversationId`';
        return [
            'conversations' => $conversationRows,
            'messages' => $connection->fetchAllAssociative(
                'SELECT `id`, `aiConversationId`, `indexInConversation`, `role`, `authorType`, `messageType`, `signalSource`, `status`, `message`, `toolCall`, `loadedCapabilities` FROM `'
                . DBAIConversationMessageModel::getTableName() . '`'
                . " WHERE `aiConversationId` IN ({$treeConversationIdList}) AND `truncatedAt` IS NULL ORDER BY {$rootFirst}, `indexInConversation`"
            ),
            'delegationBatches' => $connection->fetchAllAssociative(
                'SELECT * FROM `' . DBAIConversationDelegationBatchModel::getTableName() . '`'
                . " WHERE `parentConversationId` IN ({$treeConversationIdList}) ORDER BY `id`"
            ),
            'compactionNodes' => $connection->fetchAllAssociative(
                'SELECT `id`, `aiConversationId`, `level`, `coveredFromMessageIndex`, `coveredToMessageIndex`, `fromRawMessages`, `coveredMessageCount`, `coveredTokens`, `summaryTokens`, `summaryText`, `preservedFacts`, `aiModelName` FROM `'
                . DBAIConversationCompactionNodeModel::getTableName() . '`'
                . " WHERE `aiConversationId` IN ({$treeConversationIdList}) ORDER BY {$rootFirst}, `level`, `coveredFromMessageIndex`, `id`"
            ),
        ];
    }

    // ── Recover on read (moved from the agent loop): a read that finds a conversation stuck
    //    or its turn lost dispatches one recovery resume under the conversation's own account. ──

    /**
     * Recover-on-interaction, READ path ("a reload resolves a stuck conversation"): when the admin LOADS
     * a conversation that looks stuck — non-terminal (RUNNING/AWAITING_DELEGATES) and quiet past
     * FOLD_OWED_STALE_AFTER_SECONDS — dispatch ONE recovery resume so the reload actually nudges a dropped fan-in /
     * crashed turn back to life (until the reaper cron lands, this is the prompt manual recovery). Best-effort: a
     * recently-active turn (fresh heartbeat) is left untouched; the worker's claimRun reclaims a genuinely stale row
     * (its staleness clause) and drives the fold, a still-busy one no-ops. Self-limiting: the reclaim refreshes the
     * heartbeat, so a re-load within the window won't re-nudge. Impersonates the conversation's account around the
     * dispatch (like the reaper) so the resume carries the right accountId — the caller here is an ADMIN, not the
     * conversation's account.
     */
    public function recoverIfStuck(AIConversation $conversation): void
    {
        // ONLY the AWAITING_DELEGATES (dropped-fan-in-fold) case — that is what a reload should resolve. A stuck
        // RUNNING row is a crashed-worker case the 300s reaper owns; recover-on-read can't reclaim it anyway
        // (claimRun's RUNNING staleness is 300s), and bumping its heartbeat here would defeat that crash-reap.
        if (!isset($conversation->id) || $conversation->runState !== AIConversationRunState::AWAITING_DELEGATES) {
            return;
        }
        $heartbeat = $conversation->runHeartbeatAt ?? null;
        $staleBefore = (new DateTime())->modify('-' . AIConversationsService::FOLD_OWED_STALE_AFTER_SECONDS . ' seconds');
        if ($heartbeat !== null && $heartbeat > $staleBefore) {
            return;
        }
        $this->dispatchRecoveryResume($conversation);
    }

    /**
     * Customer-requested RETRY of a run that owes an answer: the FE shows a "Retry"
     * action on the error widget whenever the customer's own message is still unanswered after the FAILED signal. Two
     * run states qualify — FAILED (a pre-drive / turn error that exhausted MAX_RESUME_ATTEMPTS, a crashed worker the
     * reaper expired) and IDLE (a turn error UNDER the cap: the loop released the run for the automatic retry, which
     * the owed-turn pass drives only after its quiet window, while the customer already sees the widget —
     * otherwise every click answers 400 for those minutes). A RUNNING / AWAITING_DELEGATES / QUEUED run is never retried: a
     * worker is on it. The retry re-drives the PERSISTED inbound — the customer never re-sends it (a duplicate
     * message was the old workaround). Gates: a persisted id, a qualifying runState, and an unanswered inbound in
     * the loaded tail (a run whose newest wire message is already the agent's answer — the budget terminal's reply,
     * a provider error after the answer — has nothing to retry). The re-arm is ONE guarded UPDATE
     * ({@see AIConversationRunClaimsService::rearmRunForRetry}); the resume then rides the recovery dispatch under the
     * conversation's own account. Returns whether the retry is UNDERWAY — dispatched by this call, or already being
     * driven by a worker that re-claimed the row meanwhile (its turn IS the retry).
     *
     * @param AIConversation $conversation The conversation whose run to retry (loaded under the caller's rights)
     *
     * @return bool
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    public function retryUnansweredTurn(AIConversation $conversation): bool
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        if (!isset($conversation->id)
            || !$conversation->getRunState()->isAtRestOrFailed()
        ) {
            return false;
        }
        // The same bounded load the resume preamble does: with the cover nodes in place the tail load stops at the
        // compaction edge instead of paging the whole history.
        $conversation->compactionNodes = $conversation->findCompactionCoverNodes() ?? AIConversationCompactionNodes::newInstance();
        $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
        if (!$conversation->hasUnansweredInboundMessage()) {
            return false;
        }
        if (!$aIConversationRunClaimsService->rearmRunForRetry($conversation)) {
            // The guarded UPDATE lost: a worker re-claimed the row between the customer's read and this call
            // (claimRun accepts any non-RUNNING state) — that running turn is the retry, not a refusal.
            // one scalar read of the run state, never a hydration of the conversation
            return $aIConversationRunClaimsService->findRunStateById((int)$conversation->id) === AIConversationRunState::RUNNING;
        }
        $this->aiTrace('turn.retry.requested', ['conversationId' => $conversation->id]);
        $this->dispatchRecoveryResume($conversation);
        return true;
    }

    /**
     * Recover a LOST KICKOFF on read: a conversation whose kickoff resume was dropped (the
     * handler's load threw — replica lag, schema drift — or the dispatch never reached a consumer) sits IDLE with an
     * unanswered inbound message. NO sweep owns that state — {@see recoverIfStuck} covers only AWAITING_DELEGATES and
     * the reaper only non-terminal runStates — so an A2A task projected from it stays SUBMITTED forever while the
     * client polls. Called from the A2A `GetTask` projection when it sees exactly that (latest task SUBMITTED on an
     * IDLE conversation): dispatch ONE recovery resume, so the client's own polling heals the task.
     *
     * Gates (all must hold): persisted id · runState IDLE · the last inbound is older than
     * {@see AIConversationsService::FOLD_OWED_STALE_AFTER_SECONDS} (a normal kickoff starts within seconds — never
     * poke a turn that is just spinning up) · the heartbeat is equally stale (markRecoveryDispatched bumps it, so a
     * poll flurry dispatches only once per window). The caller has already established that an unanswered inbound
     * exists (the SUBMITTED projection over the loaded messages) — this method deliberately does NOT re-derive it
     * from the lazy message relation (its capped window would mis-read long conversations).
     */
    public function recoverLostKickoffIfStale(AIConversation $conversation): void
    {
        if (!isset($conversation->id) || $conversation->runState !== AIConversationRunState::IDLE) {
            return;
        }
        $staleBefore = (new DateTime())->modify('-' . AIConversationsService::FOLD_OWED_STALE_AFTER_SECONDS . ' seconds');
        $lastInboundMessageAt = $conversation->lastInboundMessageAt ?? null;
        if ($lastInboundMessageAt === null || $lastInboundMessageAt > $staleBefore) {
            return;
        }
        $heartbeat = $conversation->runHeartbeatAt ?? null;
        if ($heartbeat !== null && $heartbeat > $staleBefore) {
            return;
        }
        $this->dispatchRecoveryResume($conversation);
    }

    /**
     * The shared recovery-dispatch tail of {@see recoverIfStuck} / {@see recoverLostKickoffIfStale}: bump the
     * heartbeat FIRST (self-limiting — a flurry of reads dispatches only one recovery per staleness window), then
     * dispatch the resume under the CONVERSATION's account (the reader may be an admin or an A2A partner scope, and
     * the resume message must carry the owning accountId — the reaper's impersonation pattern).
     */
    protected function dispatchRecoveryResume(AIConversation $conversation): void
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        // Bump the heartbeat BEFORE the async dispatch so a flurry of inspector refetches doesn't dispatch a burst of
        // duplicate recovery resumes before a worker reclaims (the dispatch's real heartbeat refresh lags queue latency).
        $aIConversationRunClaimsService->markRecoveryDispatched($conversation);
        $conversationAccount = $conversation->account ?? null;
        $previousAuthAccount = AuthService::instance()->getAccount();
        if ($conversationAccount !== null) {
            AuthService::instance()->setAccount($conversationAccount);
        }
        try {
            (new AIConversationResumeMessage((int)$conversation->id))->dispatch();
        } finally {
            AuthService::instance()->setAccount($previousAuthAccount);
        }
    }

    /**
     * Get/create the persisted agent conversation the drive appends to, with what its space needs for a run attached
     * ({@see AgentSpacesService::attachAgentSpaceRelations()}).
     * A persisted id is required so the loop persists its messages and the conversation is resumable from the inspector.
     *
     * THE SPACE IS WRITTEN ONCE, here, on the NEW conversation: `$agentSpace` sets its `agentSpaceId` (null = an
     * account-level conversation). An EXISTING conversation keeps the space it was stored with — `$agentSpace` is not
     * read for it, and nothing moves a stored conversation to another space. The caller has checked that the account
     * may use the space ({@see self::findConversationToDrive()}).
     *
     * @param AIConversation|null $existing the stored conversation to continue; null = create one
     * @param int $accountId the account the new conversation is stored for
     * @param AgentSpace|null $agentSpace the space of the NEW conversation; null = account level
     * @param AIConversationSchedule|null $spawningSchedule the schedule this conversation is a run of
     * @return AIConversation
     */
    public function findOrCreateConversationToDrive(
        ?AIConversation $existing,
        int $accountId,
        ?AgentSpace $agentSpace,
        ?AIConversationSchedule $spawningSchedule = null
    ): AIConversation {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        if ($existing instanceof AIConversation) {
            // The SAME bounded load the resume worker does: the raw tail above the compaction
            // cover, never the lazy `->messages` relation (the OLDEST 50 rows — a truncated context and a wrong next
            // index past 50 messages) and never the whole history (the cover nodes already summarise what lies below).
            $existing->messages = $aIConversationMessagesService->findContextTailMessages($existing);
            $agentSpacesService->attachAgentSpaceRelations($existing, false);
            return $existing;
        }

        $conversation = AIConversation::newInstance();
        $conversation->accountId = $accountId;
        // a space that is not stored throws instead of being stored as "no space"
        $conversation->agentSpaceId = AgentSpace::getIdOfStoredAgentSpace($agentSpace);
        $conversation->messages = AIConversationMessages::newInstance();
        // a schedule run is SCHEDULE-BOUND — it thinks in its schedule's zone (cron digits and the run's
        // clock must match), frozen BEFORE the first persist; a legacy schedule (no zone) runs in UTC like its cron,
        // flagged unverified. Every other conversation owns no zone and reads that of its space per model request.
        if ($spawningSchedule !== null) {
            $conversation->freezeTimezone(
                $spawningSchedule->timezone ?? $spawningSchedule->getCronTimezoneIdentifier(),
                ($spawningSchedule->timezone ?? null) === null ? true : ($spawningSchedule->timezoneUnverified ?? false)
            );
        }
        // update(0) — messages set is empty here (cascade is a no-op); persist → id, then the loop persists
        // each message individually via persistMessage. Bounded so a future non-empty set can't re-stamp child FKs.
        // a ROOT keeps rootConversationId = NULL (NULL means "I am the root" — a conversation never references
        // itself as its own root). Delegation children get the root's id threaded down in spawnDelegationChild(); every
        // read derives the tree root as `rootConversationId ?? id`. No post-insert self-stamp.
        $conversation->update(0); // persist → id, so the loop persists its messages + the conversation is resumable
        // the space and what it needs for a run are attached AFTER the persist: update(0) never sees them. The object the
        // caller holds is set as it is (null included), so the fresh conversation never loads its space a second time
        $conversation->agentSpace = $agentSpace;
        $agentSpacesService->attachAgentSpaceRelations($conversation, false);
        return $conversation;
    }

    /**
     * The two CREATION SNAPSHOTS of a fresh conversation, composed ONCE after its identity (purpose, initiator) is
     * stamped — both feed a context segment that must stay byte-stable for the conversation's whole life:
     *  - the episodic history: "what was recently discussed in which sessions", from the per-settle-fresh
     *    summaries of the conversation's space (or of its account level) — skipped for a purpose that loads the
     *    earlier sessions itself;
     *  - the day context: the application's text for the day and what the agent judged relevant, for an
     *    ORDINARY conversation — no purpose, a person present.
     * Best-effort: a compose failure must never break the conversation's creation.
     */
    public function composeCreationSnapshots(AIConversation $conversation): void
    {
        try {
            $this->composeAndPersistEpisodicHistory($conversation);
        } catch (Throwable $episodicHistoryError) {
            $this->aiTrace('episodicHistory.failed', ['conversationId' => $conversation->id ?? null, 'message' => $episodicHistoryError->getMessage()]);
            // Real log too — aiTrace is a no-op without AGENT_TRACE, and a silently absent narrative is invisible
            // feature degradation (the iteration-1 summary-catch lesson).
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                "AIConversationsService: episodic-history snapshot failed for conversation {$conversation->id}: "
                . $episodicHistoryError->getMessage()
            );
        }
        try {
            $this->composeAndPersistDayContext($conversation);
        } catch (Throwable $dayContextError) {
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                "AIConversationsService: day-context snapshot failed for conversation {$conversation->id}: "
                . $dayContextError->getMessage()
            );
        }
        try {
            $this->composeAndPersistLocalSituation($conversation);
        } catch (Throwable $localSituationError) {
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                "AIConversationsService: local-situation snapshot failed for conversation {$conversation->id}: "
                . $localSituationError->getMessage()
            );
        }
    }
}
