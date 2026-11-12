<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics\AgentDecisionStatistics;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics\AgentDecisionStatisticsRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics\AgentDecisionStatisticsSection;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics\AgentDecisionWilsonInterval;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventModel;
use DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The decision statistics over the turn-events table — READ-ONLY. For a site and a window: decisions per
 * mode × arm × day, applied / skipped by reason / fallbacks, the request latency, and for the skill-preload site the
 * TEACHER AGREEMENT on SHADOW decisions (the loads the model made afterwards in the same turn: pick ∈ first load =
 * agreement; pick loaded later = "needed later"; pick made and NO load at all = miss — split by whether a resident
 * skill's tool answered or no tool ran), the per-skill precision and the top confusions, the floor sweep over the
 * recorded probabilities, and the on-arm's `preloadedSkillToolUsed` from the preload outcomes. Eval traffic is excluded
 * twice: the persisted `evalRun` marker on the event and the configured eval-account list. The reconciliation
 * (population = eligible + excluded; eligible = terminal + pending + missing) comes first. Observations are bounded to
 * the decision's TURN (initiator → next initiator) and to the cutoff; a turn without a terminal row younger than that
 * is censored, an older one is `missing`. Read by an application's console
 * command and its admin tools.
 */
class AgentDecisionStatisticsService extends Service
{
    /** Builds the statistics of `$request` — the sections in the order the command prints them. */
    public function buildStatistics(AgentDecisionStatisticsRequest $request): AgentDecisionStatistics
    {
        $evalAccountIds = [];
        if (!$request->includeEval) {
            /** @var AgentEvalAccountsService $agentEvalAccountsService */
            $agentEvalAccountsService = DDDService::instance()->getService(AgentEvalAccountsService::class);
            $evalAccountIds = $agentEvalAccountsService->getEvalAccountIds();
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $eventsTable = DBAIConversationTurnEventModel::getTableName();
        $conversationsTable = DBAIConversationModel::getTableName();
        $from = $request->from;
        $to = $request->to;
        $site = $request->site;
        $evalClause = $request->includeEval ? '' : ' AND COALESCE(`e`.`evalRun`, FALSE) = FALSE' . ($evalAccountIds !== [] ? ' AND `c`.`accountId` NOT IN (' . implode(',', array_map('intval', $evalAccountIds)) . ')' : '');

        $statistics = new AgentDecisionStatistics();
        $statistics->title = "Decision statistics — site {$site}, {$from} → {$to}, cutoff {$request->cutoff}"
            . ($request->includeEval ? ' (eval included)' : ' (eval excluded' . ($evalAccountIds !== [] ? ', ' . count($evalAccountIds) . ' eval accounts' : '') . ')');

        // --- 1. reconciliation over the turnEnd rows ---
        // every GROUP BY names select-list positions: a bare name resolves to a table column before a select alias on
        // both engines (`arm` would group by `e`.`arm`), and MariaDB's ONLY_FULL_GROUP_BY does not match a repeated
        // JSON expression with the selected one
        $outcomeSql = $this->buildPayloadTextSql('outcome');
        $terminalRows = $connection->fetchAllAssociative(
            "SELECT {$outcomeSql} AS `outcome`, `e`.`arm`, COUNT(*) AS `n`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause}
             GROUP BY 1, 2",
            ['kind' => AIConversationTurnEvent::KIND_TURN_END, 'from' => $from, 'to' => $to]
        );
        $statistics->addSection(AgentDecisionStatisticsSection::withTable('Terminal records (turnEnd) by outcome × arm', ['outcome', 'arm', 'n'], $terminalRows));

        // --- 2. decisions per mode × arm × day, applied / skips / fallbacks, latency ---
        $daySql = EntityManagerFactory::getDialect()->dateBucket('`e`.`created`', 'day');
        $modeSql = $this->buildPayloadTextSql('mode');
        $decisionRows = $connection->fetchAllAssociative(
            "SELECT {$daySql} AS `day`, {$modeSql} AS `mode`, `e`.`arm`,
                    COUNT(*) AS `decisions`,
                    {$this->buildPayloadTrueCountSql('applied')} AS `applied`,
                    {$this->buildPayloadTrueCountSql('wouldPreload')} AS `wouldPreload`,
                    {$this->buildPayloadTrueCountSql('wouldApply')} AS `wouldApply`,
                    SUM(CASE WHEN {$this->buildPayloadTextSql('decidedBy')} LIKE 'fallback:%' THEN 1 ELSE 0 END) AS `fallbacks`,
                    ROUND(AVG(`e`.`elapsedMs`)) AS `avgMs`, MAX(`e`.`elapsedMs`) AS `maxMs`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`site` = :site AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause}
             " . ($request->mode !== null ? " AND {$modeSql} = :mode" : '') . "
             GROUP BY 1, 2, 3 ORDER BY `day`, `mode`, `e`.`arm`",
            array_filter(['kind' => AIConversationTurnEvent::KIND_DECISION, 'site' => $site, 'from' => $from, 'to' => $to, 'mode' => $request->mode], static fn ($v) => $v !== null)
        );
        $statistics->addSection(AgentDecisionStatisticsSection::withTable(
            'Decisions per day × mode × arm',
            ['day', 'mode', 'arm', 'decisions', 'applied', 'wouldPreload', 'wouldApply', 'fallbacks', 'avgMs', 'maxMs'],
            $decisionRows
        ));

        $skipReasonSql = 'COALESCE(' . $this->buildPayloadTextSql('skipReason') . ", '(preloaded)')";
        $skipRows = $connection->fetchAllAssociative(
            "SELECT {$skipReasonSql} AS `skipReason`, COUNT(*) AS `n`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`site` = :site AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause}
             GROUP BY 1 ORDER BY `n` DESC",
            ['kind' => AIConversationTurnEvent::KIND_DECISION, 'site' => $site, 'from' => $from, 'to' => $to]
        );
        $statistics->addSection(AgentDecisionStatisticsSection::withTable('Skip reasons', ['skipReason', 'n'], $skipRows));

        // Follow-up passes (a root with sub-skills got admitted → the decision ran again over its sub-skills); the
        // teacher agreement below is bounded to the roots pass (`subject IS NULL`) so a turn counts once.
        $passSql = $this->buildPayloadTextSql('pass');
        $rootSql = $this->buildPayloadTextSql('unlockedByRootSkillName');
        $followUpRows = $connection->fetchAllAssociative(
            "SELECT {$passSql} AS `pass`, {$rootSql} AS `root`, COUNT(*) AS `n`,
                    {$this->buildPayloadTrueCountSql('wouldPreload')} AS `wouldPreload`, {$this->buildPayloadTrueCountSql('applied')} AS `applied`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`site` = :site AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause} AND `e`.`subject` IS NOT NULL
             GROUP BY 1, 2 ORDER BY `n` DESC",
            ['kind' => AIConversationTurnEvent::KIND_DECISION, 'site' => $site, 'from' => $from, 'to' => $to]
        );
        if ($followUpRows !== []) {
            $statistics->addSection(AgentDecisionStatisticsSection::withTable(
                'Follow-up passes (sub-skills unlocked by an admitted root)',
                ['pass', 'root', 'n', 'wouldPreload', 'applied'],
                $followUpRows
            ));
        }

        $latencies = array_map('intval', array_column($connection->fetchAllAssociative(
            "SELECT `e`.`elapsedMs` FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`created` >= :from AND `e`.`created` < :to AND `e`.`elapsedMs` IS NOT NULL{$evalClause}",
            ['kind' => AIConversationTurnEvent::KIND_REQUEST, 'from' => $from, 'to' => $to]
        ), 'elapsedMs'));
        if ($latencies !== []) {
            sort($latencies);
            $statistics->addSection(AgentDecisionStatisticsSection::withTextLines('', [
                sprintf('turn-start request latency: n=%d p50=%d ms p95=%d ms', count($latencies), $this->percentile($latencies, 50), $this->percentile($latencies, 95)),
            ]));
        }

        match ($site) {
            AgentDecisionModelSettings::SITE_TURN_CLOSING => $this->addTurnClosingSections($statistics, $connection, $from, $to, $evalClause),
            AgentDecisionModelSettings::SITE_REASONING_EFFORT => $this->addReasoningEffortSections($statistics, $connection, $from, $to, $evalClause),
            AgentDecisionModelSettings::SITE_STALL_RECOVERY => $this->addStallRecoverySections($statistics, $connection, $from, $to, $evalClause),
            AgentDecisionModelSettings::SITE_SKILL_PRELOAD => $this->addSkillPreloadSections($statistics, $connection, $request, $evalClause),
            default => null,
        };
        return $statistics;
    }

    /** The skill-preload site: the teacher agreement on shadow picks, per-skill precision, confusions, floor sweep, preload outcomes, accrual. */
    protected function addSkillPreloadSections(AgentDecisionStatistics $statistics, Connection $connection, AgentDecisionStatisticsRequest $request, string $evalClause): void
    {
        $eventsTable = DBAIConversationTurnEventModel::getTableName();
        $conversationsTable = DBAIConversationModel::getTableName();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $sqlDialect = EntityManagerFactory::getDialect();
        $from = $request->from;
        $to = $request->to;
        $cutoff = $request->cutoff;

        // --- 3. teacher agreement on SHADOW decisions with a pick: the loads the model made afterwards in the turn ---
        // a decision without a pick stores `pickedSkillName` as JSON null: the text read answers NULL for it on both engines
        $pickSql = $this->buildPayloadTextSql('pickedSkillName');
        $shadowPicks = $connection->fetchAllAssociative(
            "SELECT `e`.`id`, `e`.`aiConversationId`, `e`.`initiatorMessageIndex`, `e`.`evaluationMessageIndex`, `e`.`created`,
                    {$pickSql} AS `pick`,
                    {$this->buildPayloadTextSql('pickConfidence')} AS `conf`,
                    {$this->buildPayloadTextSql('pickMargin')} AS `margin`,
                    {$this->buildPayloadTextSql('wouldPreload')} AS `wouldPreload`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`site` = :site AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause}
               AND `e`.`subject` IS NULL
               AND {$this->buildPayloadTextSql('mode')} = 'shadow'
               AND {$pickSql} IS NOT NULL
             ORDER BY `e`.`aiConversationId`, `e`.`initiatorMessageIndex`",
            ['kind' => AIConversationTurnEvent::KIND_DECISION, 'site' => $request->site, 'from' => $from, 'to' => $to]
        );
        $agreement = ['agree' => 0, 'neededLater' => 0, 'missNoTool' => 0, 'missResidentTool' => 0, 'otherRoot' => 0, 'censored' => 0];
        $perSkill = [];
        $confusions = [];
        $sweepCandidates = [];
        // the rows that start a turn beside the owner's own: the signal sources every registered enum answers
        // startsATurn() for — the same list the event's initiator index was written from
        $initiatorSignalSources = ConversationSignalSources::getValuesThatStartATurn();
        foreach ($shadowPicks as $pickRow) {
            $conversationId = (int)$pickRow['aiConversationId'];
            $initiatorIndex = (int)$pickRow['initiatorMessageIndex'];
            $evaluationIndex = (int)($pickRow['evaluationMessageIndex'] ?? $initiatorIndex);
            // the observation window: the turn = up to the next initiator (or the end), bounded by the cutoff
            $nextInitiatorIndex = $connection->fetchOne(
                "SELECT MIN(`indexInConversation`) FROM `$messagesTable` WHERE `aiConversationId` = :c AND `indexInConversation` > :i AND `truncatedAt` IS NULL
                   AND ((`role` = 'USER' AND (`authorType` = 'USER' OR `authorType` IS NULL))
                        OR (`messageType` = 'SIGNAL' AND `signalSource` IN (:initiatorSignalSources)))",
                ['c' => $conversationId, 'i' => $initiatorIndex, 'initiatorSignalSources' => $initiatorSignalSources],
                ['initiatorSignalSources' => ArrayParameterType::STRING]
            );
            $hasTerminal = (int)$connection->fetchOne(
                "SELECT COUNT(*) FROM `$eventsTable` WHERE `aiConversationId` = :c AND `kind` = :k AND `initiatorMessageIndex` = :i",
                ['c' => $conversationId, 'k' => AIConversationTurnEvent::KIND_TURN_END, 'i' => $initiatorIndex]
            ) > 0;
            if (!$hasTerminal && $nextInitiatorIndex === null && (string)$pickRow['created'] >= $cutoff) {
                $agreement['censored']++;
                continue;
            }
            $windowClause = $nextInitiatorIndex !== null ? ' AND `indexInConversation` < ' . (int)$nextInitiatorIndex : '';
            $loadRows = $connection->fetchAllAssociative(
                'SELECT `indexInConversation`, ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('arguments', 'skill_names')) . ' AS `skills`,'
                . ' ' . $sqlDialect->jsonFieldText('`toolCall`', 'name') . ' AS `tool`, ' . $sqlDialect->jsonFieldText('`toolCall`', 'status') . ' AS `status`,'
                . ' ' . $sqlDialect->jsonFieldText('`toolCall`', 'result') . ' AS `result`'
                . " FROM `$messagesTable` WHERE `aiConversationId` = :c AND `indexInConversation` > :i AND `truncatedAt` IS NULL AND `messageType` = 'TOOL_CALL' AND `created` < :cutoff{$windowClause}"
                . ' ORDER BY `indexInConversation`',
                ['c' => $conversationId, 'i' => $evaluationIndex, 'cutoff' => $cutoff]
            );
            $pick = (string)$pickRow['pick'];
            $firstLoadSkills = null;
            $laterLoadSkills = [];
            $anyTool = false;
            foreach ($loadRows as $loadRow) {
                if (($loadRow['tool'] ?? '') === 'load_skills') {
                    if (($loadRow['status'] ?? '') !== 'COMPLETED' || !str_starts_with((string)($loadRow['result'] ?? ''), 'Skills loaded')) {
                        continue;
                    }
                    $skills = json_decode((string)($loadRow['skills'] ?? '[]'), true) ?: [];
                    if ($firstLoadSkills === null) {
                        $firstLoadSkills = $skills;
                    } else {
                        $laterLoadSkills = array_merge($laterLoadSkills, $skills);
                    }
                } else {
                    $anyTool = true;
                }
            }
            $perSkill[$pick] ??= ['picks' => 0, 'agree' => 0];
            $perSkill[$pick]['picks']++;
            if ($firstLoadSkills !== null && in_array($pick, $firstLoadSkills, true)) {
                $agreement['agree']++;
                $perSkill[$pick]['agree']++;
                $verdict = 'agree';
            } elseif (in_array($pick, $laterLoadSkills, true)) {
                $agreement['neededLater']++;
                $verdict = 'later';
            } elseif ($firstLoadSkills !== null) {
                $agreement['otherRoot']++;
                $confusions[$pick . ' → ' . implode(',', $firstLoadSkills)] = ($confusions[$pick . ' → ' . implode(',', $firstLoadSkills)] ?? 0) + 1;
                $verdict = 'other';
            } elseif ($anyTool) {
                $agreement['missResidentTool']++;
                $verdict = 'missResident';
            } else {
                $agreement['missNoTool']++;
                $verdict = 'missNoTool';
            }
            $sweepCandidates[] = ['conf' => (float)$pickRow['conf'], 'margin' => (float)$pickRow['margin'], 'agree' => $verdict === 'agree'];
        }
        $scored = $agreement['agree'] + $agreement['neededLater'] + $agreement['otherRoot'] + $agreement['missNoTool'] + $agreement['missResidentTool'];
        $agreementLines = [];
        foreach ($agreement as $key => $count) {
            $agreementLines[] = sprintf('%-18s %d', $key, $count);
        }
        if ($scored > 0) {
            $wilsonInterval = AgentDecisionWilsonInterval::forSuccessesOfCases($agreement['agree'], $scored);
            $agreementLines[] = sprintf('precision (agree / scored) = %.3f  Wilson 95%% [%.3f, %.3f]  n=%d', $agreement['agree'] / $scored, $wilsonInterval->lowerBound, $wilsonInterval->upperBound, $scored);
        }
        $statistics->addSection(AgentDecisionStatisticsSection::withTextLines('Teacher agreement (shadow picks)', $agreementLines));
        if ($perSkill !== []) {
            $perSkillRows = [];
            foreach ($perSkill as $skillName => $counts) {
                $perSkillRows[] = ['skill' => $skillName, 'picks' => $counts['picks'], 'agree' => $counts['agree'], 'precision' => $counts['picks'] > 0 ? round($counts['agree'] / $counts['picks'], 3) : null];
            }
            $statistics->addSection(AgentDecisionStatisticsSection::withTable('Per-skill precision', ['skill', 'picks', 'agree', 'precision'], $perSkillRows));
        }
        if ($confusions !== []) {
            arsort($confusions);
            $confusionLines = [];
            foreach (array_slice($confusions, 0, 10, true) as $label => $count) {
                $confusionLines[] = sprintf('%4d  %s', $count, $label);
            }
            $statistics->addSection(AgentDecisionStatisticsSection::withTextLines('Top confusions (pick → first load)', $confusionLines));
        }
        if ($sweepCandidates !== []) {
            $sweepRows = [];
            foreach ([0.70, 0.80, 0.90, 0.95] as $confFloor) {
                foreach ([0.30, 0.50, 0.70] as $marginFloor) {
                    $passing = array_filter($sweepCandidates, static fn (array $c): bool => $c['conf'] >= $confFloor && $c['margin'] >= $marginFloor);
                    $agree = count(array_filter($passing, static fn (array $c): bool => $c['agree']));
                    $sweepRows[] = ['conf≥' => $confFloor, 'margin≥' => $marginFloor, 'passing' => count($passing), 'precision' => count($passing) > 0 ? round($agree / count($passing), 3) : null];
                }
            }
            $statistics->addSection(AgentDecisionStatisticsSection::withTable(
                'Floor sweep (conf × margin) — picks passing / precision among them',
                ['conf≥', 'margin≥', 'passing', 'precision'],
                $sweepRows
            ));
        }

        // --- 4. the on-arm's preload outcomes ---
        $outcomeSql = $this->buildPayloadTextSql('outcome');
        $outcomeRows = $connection->fetchAllAssociative(
            "SELECT {$outcomeSql} AS `outcome`, COUNT(*) AS `n`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause} GROUP BY 1",
            ['kind' => AIConversationTurnEvent::KIND_PRELOAD_OUTCOME, 'from' => $from, 'to' => $to]
        );
        if ($outcomeRows !== []) {
            $statistics->addSection(AgentDecisionStatisticsSection::withTable('Preload outcomes (on)', ['outcome', 'n'], $outcomeRows));
        }

        if ($request->withAccrualArithmetic) {
            $days = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400));
            $shadowPerWeek = count($shadowPicks) / $days * 7;
            $agreeRate = $scored > 0 ? $agreement['agree'] / $scored : 0.0;
            $accrualSection = AgentDecisionStatisticsSection::withTextLines('Accrual arithmetic (from the observed rates)', [
                sprintf(
                    'shadow picks/week ≈ %.1f · agreement %.3f · weeks to 650 picks ≈ %.1f · weeks to 400 applied (on-arm, precision-adjusted) ≈ %.1f',
                    $shadowPerWeek,
                    $agreeRate,
                    $shadowPerWeek > 0 ? 650 / $shadowPerWeek : INF,
                    $shadowPerWeek > 0 ? 400 / ($shadowPerWeek * max(0.05, $agreeRate)) : INF
                ),
            ]);
            $accrualSection->note = 'The turn-level margins of gate 6(a) need the account-clustered null bound: run the clustered bootstrap over the turnEnd rows per arm once the cohort has ≥ 60 accounts per arm (not computed here).';
            $statistics->addSection($accrualSection);
        }
    }

    /**
     * The two-arm view of the checked stop: decisions by turn-closing mode × who decided × fallback reason
     * (with the fallback COST: count × average elapsed), the bounce signals per run split by trigger, and the per-turn
     * wall clock (initiator `created` → the LAST assistant head of the turn) — all read from the ledger, per arm. The
     * arm is the conversation's row switch (`runSettings.decisionModelTurnClosing`), which is how the eval selects it;
     * `inherit` = the environment's turn-closing mode.
     */
    protected function addTurnClosingSections(AgentDecisionStatistics $statistics, Connection $connection, string $from, string $to, string $evalClause): void
    {
        $eventsTable = DBAIConversationTurnEventModel::getTableName();
        $conversationsTable = DBAIConversationModel::getTableName();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $armExpression = $this->buildConversationArmSql('decisionModelTurnClosing');
        $conversationEvalClause = $this->buildConversationEvalClause($evalClause);
        $turnClosingModeSql = $this->buildPayloadTextSql('turnClosingMode');
        $decidedBySql = $this->buildPayloadTextSql('decidedBy');
        $fallbackReasonSql = $this->buildPayloadTextSql('fallbackReason');
        // grouped by position 1, the conversation's arm: a bare `arm` would name the event's own column `e`.`arm`
        $decisionRows = $connection->fetchAllAssociative(
            "SELECT $armExpression AS `arm`, {$turnClosingModeSql} AS `turnClosingMode`,
                    {$decidedBySql} AS `decidedBy`,
                    {$fallbackReasonSql} AS `fallbackReason`,
                    COUNT(*) AS `n`, {$this->buildPayloadTrueCountSql('wouldBounce')} AS `wouldBounce`,
                    ROUND(AVG(`e`.`elapsedMs`)) AS `avgMs`, SUM(COALESCE(`e`.`elapsedMs`, 0)) AS `totalMs`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`site` = :site AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause}
             GROUP BY 1, 2, 3, 4 ORDER BY `arm`, `turnClosingMode`, `n` DESC",
            ['kind' => AIConversationTurnEvent::KIND_DECISION, 'site' => AgentDecisionModelSettings::SITE_TURN_CLOSING, 'from' => $from, 'to' => $to]
        );
        $statistics->addSection(AgentDecisionStatisticsSection::withTable(
            'Closing decisions per arm × turn-closing mode × decidedBy × fallback (fallback cost = n × avgMs)',
            ['arm', 'turnClosingMode', 'decidedBy', 'fallbackReason', 'n', 'wouldBounce', 'avgMs', 'totalMs'],
            $decisionRows
        ));

        $bounceRows = $connection->fetchAllAssociative(
            "SELECT $armExpression AS `arm`, `m`.`signalSource`, COUNT(*) AS `n`, COUNT(DISTINCT `m`.`aiConversationId`) AS `runs`,
                    {$this->buildPerRunSql()} AS `perRun`
             FROM `$messagesTable` `m` JOIN `$conversationsTable` `c` ON `c`.`id` = `m`.`aiConversationId`
             WHERE `m`.`messageType` = 'SIGNAL' AND `m`.`signalSource` IN (:bounceSources)
               AND `m`.`created` >= :from AND `m`.`created` < :to AND `m`.`truncatedAt` IS NULL{$conversationEvalClause}
             GROUP BY 1, 2 ORDER BY `arm`, `m`.`signalSource`",
            ['from' => $from, 'to' => $to, 'bounceSources' => ConversationSignalSources::getValuesOfTaskPlanOpenBounces()],
            ['bounceSources' => ArrayParameterType::STRING]
        );
        $statistics->addSection(AgentDecisionStatisticsSection::withTable('Checked-stop bounces per arm × trigger (per run)', ['arm', 'signalSource', 'n', 'runs', 'perRun'], $bounceRows));

        // per-turn wall clock: owner TEXT initiator → the last assistant head (inputTokens > 0) before the next initiator
        $turnRows = $connection->fetchAllAssociative(
            "SELECT `m`.`aiConversationId`, $armExpression AS `arm`, `m`.`indexInConversation` AS `idx`, `m`.`role`, `m`.`messageType`, `m`.`authorType`, `m`.`inputTokens`, `m`.`created`
             FROM `$messagesTable` `m` JOIN `$conversationsTable` `c` ON `c`.`id` = `m`.`aiConversationId`
             WHERE `m`.`created` >= :from AND `m`.`created` < :to AND `m`.`truncatedAt` IS NULL{$conversationEvalClause}
               AND `c`.`id` IN (SELECT DISTINCT `e`.`aiConversationId` FROM `$eventsTable` `e` WHERE `e`.`kind` = :kind AND `e`.`created` >= :from AND `e`.`created` < :to)
             ORDER BY `m`.`aiConversationId`, `m`.`indexInConversation`",
            ['from' => $from, 'to' => $to, 'kind' => AIConversationTurnEvent::KIND_TURN_END]
        );
        $turnsByArm = [];
        $openTurn = null;
        foreach ($turnRows as $row) {
            $isInitiator = ($row['role'] ?? '') === 'USER' && ($row['messageType'] ?? '') === 'TEXT' && in_array($row['authorType'] ?? null, [null, '', 'USER'], true);
            if ($isInitiator || ($openTurn !== null && $openTurn['conversationId'] !== $row['aiConversationId'])) {
                if ($openTurn !== null && $openTurn['lastHeadAt'] !== null) {
                    $turnsByArm[$openTurn['arm']][] = strtotime($openTurn['lastHeadAt']) - strtotime($openTurn['startedAt']);
                }
                $openTurn = $isInitiator ? ['conversationId' => $row['aiConversationId'], 'arm' => $row['arm'], 'startedAt' => $row['created'], 'lastHeadAt' => null] : null;
                continue;
            }
            if ($openTurn !== null && (int)($row['inputTokens'] ?? 0) > 0) {
                $openTurn['lastHeadAt'] = $row['created'];
            }
        }
        if ($openTurn !== null && $openTurn['lastHeadAt'] !== null) {
            $turnsByArm[$openTurn['arm']][] = strtotime($openTurn['lastHeadAt']) - strtotime($openTurn['startedAt']);
        }
        $wallRows = [];
        foreach ($turnsByArm as $arm => $seconds) {
            sort($seconds);
            $wallRows[] = ['arm' => $arm, 'turns' => count($seconds), 'p50s' => $this->percentile($seconds, 50), 'p95s' => $this->percentile($seconds, 95), 'avgS' => round(array_sum($seconds) / max(1, count($seconds)), 1)];
        }
        $statistics->addSection(AgentDecisionStatisticsSection::withTable(
            'Per-turn wall clock per arm (owner text initiator → last assistant head)',
            ['arm', 'turns', 'p50s', 'p95s', 'avgS'],
            $wallRows
        ));
    }

    /**
     * The effort site: decisions per arm (the conversation's effort row) × precondition reason ×
     * decidedBy × effective effort (the raise share and where the raises come from), and the per-request wall clock
     * of the model calls per effective effort read from the request snapshots' `reasoningEffort`.
     */
    protected function addReasoningEffortSections(AgentDecisionStatistics $statistics, Connection $connection, string $from, string $to, string $evalClause): void
    {
        $eventsTable = DBAIConversationTurnEventModel::getTableName();
        $conversationsTable = DBAIConversationModel::getTableName();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $armExpression = $this->buildConversationArmSql('decisionModelReasoningEffort');
        $preconditionReasonSql = $this->buildPayloadTextSql('preconditionReason');
        $decidedBySql = $this->buildPayloadTextSql('decidedBy');
        $effectiveEffortSql = $this->buildPayloadTextSql('effectiveEffort');
        $decisionRows = $connection->fetchAllAssociative(
            "SELECT $armExpression AS `arm`, {$preconditionReasonSql} AS `preconditionReason`,
                    {$decidedBySql} AS `decidedBy`,
                    {$effectiveEffortSql} AS `effectiveEffort`,
                    COUNT(*) AS `n`, {$this->buildPayloadTrueCountSql('applied')} AS `applied`, ROUND(AVG(`e`.`elapsedMs`)) AS `avgMs`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`site` = :site AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause}
             GROUP BY 1, 2, 3, 4 ORDER BY `arm`, `n` DESC",
            ['kind' => AIConversationTurnEvent::KIND_DECISION, 'site' => AgentDecisionModelSettings::SITE_REASONING_EFFORT, 'from' => $from, 'to' => $to]
        );
        $statistics->addSection(AgentDecisionStatisticsSection::withTable(
            'Effort decisions per arm × precondition × decidedBy × effective effort',
            ['arm', 'preconditionReason', 'decidedBy', 'effectiveEffort', 'n', 'applied', 'avgMs'],
            $decisionRows
        ));

        $raiseRows = $connection->fetchAllAssociative(
            "SELECT $armExpression AS `arm`, COUNT(*) AS `turns`,
                    SUM(CASE WHEN {$preconditionReasonSql} <> 'none' THEN 1 ELSE 0 END) AS `asked`,
                    SUM(CASE WHEN {$this->buildPayloadTextSql('resolvedEffort')} = 'medium' THEN 1 ELSE 0 END) AS `raisedMedium`,
                    SUM(CASE WHEN {$this->buildPayloadTextSql('resolvedEffort')} = 'high' THEN 1 ELSE 0 END) AS `raisedHigh`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`site` = :site AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause}
             GROUP BY 1 ORDER BY `arm`",
            ['kind' => AIConversationTurnEvent::KIND_DECISION, 'site' => AgentDecisionModelSettings::SITE_REASONING_EFFORT, 'from' => $from, 'to' => $to]
        );
        foreach ($raiseRows as $rowPosition => $raiseRow) {
            $turns = max(1, (int)$raiseRow['turns']);
            $raiseRows[$rowPosition]['askedShare'] = round((int)$raiseRow['asked'] / $turns, 3);
            $raiseRows[$rowPosition]['raiseShare'] = round(((int)$raiseRow['raisedMedium'] + (int)$raiseRow['raisedHigh']) / $turns, 3);
        }
        $statistics->addSection(AgentDecisionStatisticsSection::withTable(
            'Raise share per arm (owner turns → asked → raised)',
            ['arm', 'turns', 'asked', 'askedShare', 'raisedMedium', 'raisedHigh', 'raiseShare'],
            $raiseRows
        ));

        // the model calls' wall clock per effort actually SENT (the assistant heads carry the request snapshot)
        $sentEffortSql = 'COALESCE(' . EntityManagerFactory::getDialect()->jsonText('`m`.`requestSnapshot`', JsonPath::fromKeys('reasoningEffort')) . ", '(none)')";
        // the cost column is a floating-point number: PostgreSQL rounds to decimal places only a fixed-point one
        $callRows = $connection->fetchAllAssociative(
            "SELECT $armExpression AS `arm`, {$sentEffortSql} AS `sentEffort`,
                    COUNT(*) AS `calls`, ROUND(AVG(`m`.`inputTokens`)) AS `avgInputTokens`, ROUND(AVG(`m`.`outputTokens`)) AS `avgOutputTokens`,
                    ROUND(CAST(SUM(`m`.`costInUSD`) AS DECIMAL(20, 10)), 4) AS `costUsd`
             FROM `$messagesTable` `m` JOIN `$conversationsTable` `c` ON `c`.`id` = `m`.`aiConversationId`
             WHERE `m`.`created` >= :from AND `m`.`created` < :to AND `m`.`truncatedAt` IS NULL AND `m`.`inputTokens` > 0 AND `m`.`requestSnapshot` IS NOT NULL
               AND `c`.`id` IN (SELECT DISTINCT `e`.`aiConversationId` FROM `$eventsTable` `e` WHERE `e`.`kind` = :kind AND `e`.`site` = :site AND `e`.`created` >= :from AND `e`.`created` < :to)
             GROUP BY 1, 2 ORDER BY `arm`, `sentEffort`",
            ['from' => $from, 'to' => $to, 'kind' => AIConversationTurnEvent::KIND_DECISION, 'site' => AgentDecisionModelSettings::SITE_REASONING_EFFORT]
        );
        $statistics->addSection(AgentDecisionStatisticsSection::withTable(
            'Model calls per arm × effort sent (tokens, cost)',
            ['arm', 'sentEffort', 'calls', 'avgInputTokens', 'avgOutputTokens', 'costUsd'],
            $callRows
        ));
    }

    /**
     * The stall site: decisions per arm (the conversation's stall row) × shape × move × applied, the
     * forced re-drives by who forced them, and the LOOP_NUDGE rows per run per arm (the stall count the site should
     * lower).
     */
    protected function addStallRecoverySections(AgentDecisionStatistics $statistics, Connection $connection, string $from, string $to, string $evalClause): void
    {
        $eventsTable = DBAIConversationTurnEventModel::getTableName();
        $conversationsTable = DBAIConversationModel::getTableName();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $armExpression = $this->buildConversationArmSql('decisionModelStallRecovery');
        $conversationEvalClause = $this->buildConversationEvalClause($evalClause);
        $stallShapeSql = $this->buildPayloadTextSql('stallShape');
        $decidedBySql = $this->buildPayloadTextSql('decidedBy');
        $moveSql = $this->buildPayloadTextSql('move');
        $decisionRows = $connection->fetchAllAssociative(
            "SELECT $armExpression AS `arm`, {$stallShapeSql} AS `stallShape`,
                    {$decidedBySql} AS `decidedBy`,
                    {$moveSql} AS `move`,
                    COUNT(*) AS `n`, {$this->buildPayloadTrueCountSql('applied')} AS `applied`, ROUND(AVG(`e`.`elapsedMs`)) AS `avgMs`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`site` = :site AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause}
             GROUP BY 1, 2, 3, 4 ORDER BY `arm`, `n` DESC",
            ['kind' => AIConversationTurnEvent::KIND_DECISION, 'site' => AgentDecisionModelSettings::SITE_STALL_RECOVERY, 'from' => $from, 'to' => $to]
        );
        $statistics->addSection(AgentDecisionStatisticsSection::withTable(
            'Stall decisions per arm × shape × decidedBy × move',
            ['arm', 'stallShape', 'decidedBy', 'move', 'n', 'applied', 'avgMs'],
            $decisionRows
        ));

        $forcedBySql = 'COALESCE(' . $this->buildPayloadTextSql('forcedBy') . ", 'ladder')";
        $forcedRows = $connection->fetchAllAssociative(
            "SELECT $armExpression AS `arm`, {$forcedBySql} AS `forcedBy`,
                    COUNT(*) AS `n`, {$this->buildPayloadTrueCountSql('succeeded')} AS `succeeded`
             FROM `$eventsTable` `e` JOIN `$conversationsTable` `c` ON `c`.`id` = `e`.`aiConversationId`
             WHERE `e`.`kind` = :kind AND `e`.`created` >= :from AND `e`.`created` < :to{$evalClause}
             GROUP BY 1, 2 ORDER BY `arm`, `forcedBy`",
            ['kind' => AIConversationTurnEvent::KIND_FORCED_RE_DRIVE, 'from' => $from, 'to' => $to]
        );
        $statistics->addSection(AgentDecisionStatisticsSection::withTable(
            'Forced re-drives per arm × who forced (succeeded = a tool call came back)',
            ['arm', 'forcedBy', 'n', 'succeeded'],
            $forcedRows
        ));

        $nudgeRows = $connection->fetchAllAssociative(
            "SELECT $armExpression AS `arm`, COUNT(*) AS `nudges`, COUNT(DISTINCT `m`.`aiConversationId`) AS `runs`, {$this->buildPerRunSql()} AS `perRun`
             FROM `$messagesTable` `m` JOIN `$conversationsTable` `c` ON `c`.`id` = `m`.`aiConversationId`
             WHERE `m`.`messageType` = 'SIGNAL' AND `m`.`signalSource` = :nudgeSource AND `m`.`created` >= :from AND `m`.`created` < :to AND `m`.`truncatedAt` IS NULL{$conversationEvalClause}
             GROUP BY 1 ORDER BY `arm`",
            ['from' => $from, 'to' => $to, 'nudgeSource' => AIConversationSignalSource::LOOP_NUDGE->value]
        );
        $statistics->addSection(AgentDecisionStatisticsSection::withTable('Stall nudges per arm (per run)', ['arm', 'nudges', 'runs', 'perRun'], $nudgeRows));
    }

    /** The message and turn queries have no event row to carry `evalRun` — the same exclusion off the conversation's row. */
    protected function buildConversationEvalClause(string $evalClause): string
    {
        if (!str_contains($evalClause, 'evalRun')) {
            return '';
        }
        return ' AND ' . DBAIConversations::buildNotAnEvalRunPredicate('c')
            . (str_contains($evalClause, '`accountId` NOT IN') ? substr($evalClause, strpos($evalClause, ' AND `c`.`accountId`')) : '');
    }

    /** The text at a top-level key of the event's payload (`e`); NULL for a missing key and for JSON null on both engines. */
    protected function buildPayloadTextSql(string $key): string
    {
        return EntityManagerFactory::getDialect()->jsonText('`e`.`payload`', JsonPath::fromKeys($key));
    }

    /** How many events of the group hold JSON true at a top-level key of their payload (`e`); 0, never NULL, for none. */
    protected function buildPayloadTrueCountSql(string $key): string
    {
        return 'SUM(CASE WHEN ' . EntityManagerFactory::getDialect()->jsonFieldText('`e`.`payload`', $key) . " = 'true' THEN 1 ELSE 0 END)";
    }

    /** The arm of the conversation row (`c`): its run-settings switch `$runSettingsKey`, `inherit` without one. */
    protected function buildConversationArmSql(string $runSettingsKey): string
    {
        return 'COALESCE(' . EntityManagerFactory::getDialect()->jsonText('`c`.`runSettings`', JsonPath::fromKeys($runSettingsKey)) . ", 'inherit')";
    }

    /**
     * Message rows per conversation of the group (`m`), two decimals. The count is made fixed-point first: PostgreSQL
     * divides two whole numbers without a remainder.
     */
    protected function buildPerRunSql(): string
    {
        return 'ROUND(CAST(COUNT(*) AS DECIMAL(20, 4)) / COUNT(DISTINCT `m`.`aiConversationId`), 2)';
    }

    /** @param int[] $sorted */
    protected function percentile(array $sorted, int $p): int
    {
        if ($sorted === []) {
            return 0;
        }
        $index = (int)max(0, min(count($sorted) - 1, (int)ceil($p / 100 * count($sorted)) - 1));
        return $sorted[$index];
    }
}
