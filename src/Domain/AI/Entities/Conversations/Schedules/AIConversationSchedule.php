<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationStateReason;
use Cron\CronExpression;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;
use Throwable;

/**
 * A SCHEDULED agent task (the outer loop of the proactivity runtime), in one of two
 * kinds: RECURRING ("run this task on this cron schedule until done/expired", {@see $schedule}) or ONE-TIME
 * ("run this once at this moment" — "ruf mich heute um 15:30 an", {@see $oneTimeAt}; fires once, then DONE).
 * Every firing spawns ONE NEW {@see \DDD\Domain\AI\Entities\Conversations\AIConversation}
 * (seed message = {@see $task}; the run carries {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$scheduleId}
 * + `initiatorType = SCHEDULE`) — **a run IS a regular conversation**: the customer
 * opens it in the normal chat view, the admin in the conversation inspector; nothing schedule-specific exists at
 * the run level. Everything the agent loop provides applies to each run unchanged (task plan, adaptive budget,
 * checked stop, chunking, owner report, owed-turn recovery).
 *
 * Fired by the recovery-cron family (a console command of the application): claim-by-UPDATE on
 * `status = ACTIVE AND nextRunAt <= NOW()` (advancing {@see $nextRunAt} in the same statement — no double fire),
 * then guardrails (previous run still live? budget? expiry?) → spawn → normal async run → bookkeeping.
 *
 * A schedule works for an {@see AgentSpace} ({@see $agentSpaceId}) or, without one, for its account as a whole; its
 * runs are conversations of the same space, or conversations without a space. What a space is in an application is
 * told by the application's {@see AgentSpace} subclass alone — this entity carries no column of an application. An
 * application that puts a same-path subclass of THIS entity under its own root namespace for another reason (its
 * account class, see {@see $account}) keeps the SAME table, must redeclare every class attribute below (PHP class
 * attributes are not inherited and DDD reads them off the concrete class) and creates instances only through
 * {@see newInstance()}.
 *
 * @method static AIConversationSchedulesService getService()
 * @method static DBAIConversationSchedule getRepoClassInstance(string $repoType = null)
 */
// The fire-claim scan: WHERE status = 'ACTIVE' AND nextRunAt <= :now, oldest first.
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['status', 'nextRunAt'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationSchedule::class)]
class AIConversationSchedule extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var string Created by the customer (via chat/UI — stage 5 exposes the creating tool). */
    public const string INITIATOR_TYPE_USER = 'USER';

    /** @var string Created in the admin console. */
    public const string INITIATOR_TYPE_ADMIN = 'ADMIN';

    /** @var string Created by the agent itself (`scheduleFollowUpTask`, stage 5 — anti-runaway limits apply). */
    public const string INITIATOR_TYPE_AGENT = 'AGENT';

    /** @var string Created by the goal tree / strategist layer (series 14 — reserved, not built). */
    public const string INITIATOR_TYPE_GOAL = 'GOAL';

    /**
     * @var string The SCHEDULED_RUN_DIRECTIVE system segment of a run conversation WITHOUT a purpose — the runs
     *      spawned before the `Schedule.Run` purpose (whose directive now carries this text); kept for their
     *      historical rebuilds in the admin inspector. The segment of every run conversation
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput::$scheduledRunDirective}): tells the model
     * each round that it IS the execution of one firing — never the customer setting the task up — so scheduling
     * words inside the {@see $task} text are read as metadata the system already handles, and the schedule-admin
     * tools are never called for the task itself. A static const on purpose: byte-stable across rounds, so it
     * stays in the cacheable prefix (the seed user message is compactable; this is not). The structural half of
     * the same rule is the provenance guard in the schedule-admin tools, which refuses those four tools for ANY
     * call made from inside a run — the text below states exactly that scope.
     */
    public const string RUN_DIRECTIVE = "# Scheduled run\n\n"
        . 'This conversation IS one firing of a scheduled task: you are the run that EXECUTES the mission in the '
        . 'first user message — you are not the customer setting the task up. Any time-of-day or recurrence wording '
        . 'inside the mission ("every day at 12:00", "each week") is schedule metadata the system already handles: '
        . 'treat it as context, never as an instruction to create, update or delete a schedule. Do the work now, '
        . 'once, exactly as stated. The schedule-admin tools (scheduleOneTimeTask, createRecurringTaskSchedule, '
        . 'updateTaskSchedule, deleteTaskSchedule) are unavailable inside a run and will be refused — never call them '
        . 'here at all; the next firing is handled by the system. If the mission literally asks for a schedule change, '
        . 'report that to the owner in your final answer instead. State carried across firings arrives as the '
        . '"Last run (…) result:" line of the first user message; continue from it. Report what you did in your '
        . 'final answer.';

    /** @var int|null The owning account — the read-rights anchor AND the budget attribution target. */
    #[NotNull]
    public ?int $accountId;

    /**
     * @var Account|null The owning account (the budget attribution target). NO explicit repoType — the account's own
     * default repo is used. An application whose account class lives elsewhere (e.g. in a legacy table)
     * redeclares the property in its same-path subclass with `entityClassName:` naming its account class, because
     * lazy loading resolves the repo through the DECLARED type. Mirrors
     * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$account}.
     */
    #[LazyLoad(addAsParent: true)]
    public ?Account $account;

    /**
     * @var int|null Id of the {@see AgentSpace} the task works for. Empty = ACCOUNT LEVEL: the task works for the
     * account as a whole, and its runs are conversations without a space. Written when the schedule is created and
     * never changed afterwards (an update keeps the stored value). A read "of the same space" filters on it through
     * {@see AgentSpaceSqlCondition::forAgentSpace()}: an empty reference selects the account-level schedules, never
     * those of all spaces of the account.
     */
    #[DatabaseColumn(onUpdateAction: '`agentSpaceId`')]
    public ?int $agentSpaceId;

    /**
     * @var AgentSpace|null The space the task works for; deleting the space deletes its schedules. The repo type is
     * named: a space is a database entity of this package, whatever repo type the application uses by default.
     */
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AgentSpace $agentSpace;

    /** @var string Who created this schedule — one of the INITIATOR_TYPE_* constants. */
    #[Choice(choices: [self::INITIATOR_TYPE_USER, self::INITIATOR_TYPE_ADMIN, self::INITIATOR_TYPE_AGENT, self::INITIATOR_TYPE_GOAL])]
    #[Length(max: 16)]
    public string $initiatorType = self::INITIATOR_TYPE_ADMIN;

    /** @var int|null The conversation from which this schedule was created (stage-5 provenance); NULL for admin/UI creation. */
    public ?int $createdByConversationId;

    /**
     * @var string|null The 3-6-word label the owner sees in their task list (the entry's headline); NULL = the UI falls
     * back to the truncated {@see $task}.
     */
    #[Length(max: 60)]
    public ?string $title;

    /**
     * @var string|null The task text — the SEED user message of every firing, written as a complete instruction. No
     * index: the text is read with its row, never searched.
     */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[NotNull]
    public ?string $task;

    /**
     * @var string|null When the RECURRING goal counts as fulfilled ("the profile reaches 100% completeness") — the
     * agent judges it at each run terminal; fulfilled → {@see AIConversationScheduleStatus::DONE}. NULL = runs
     * until expiry/cancel. No index: the text is read with its row, never searched.
     */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $doneCriteria;

    /** @var string|null The cron expression of a RECURRING schedule (same grammar as the EntityCrons runner, e.g. "0 7 * * 1"); NULL on a ONE-TIME schedule ({@see $oneTimeAt} set instead — exactly one of the two is set). */
    #[Length(max: 100)]
    public ?string $schedule;

    /**
     * @var DateTime|null The single UTC fire moment of a ONE-TIME schedule ("ruf mich heute um 15:30 an") —
     * set ⇒ this schedule fires exactly once and closes as DONE at its run terminal. The creating tool/UI
     * converts the user's LOCAL time (the time zone of the schedule) to UTC before setting this; NULL ⇒ recurring
     * ({@see $schedule} carries the cron). The fire claim clears {@see $nextRunAt} instead of advancing it.
     */
    public ?DateTime $oneTimeAt;

    /** @var string One of the {@see AIConversationScheduleStatus} constants — the fire-claim scans ACTIVE only. */
    #[Choice(choices: [
        AIConversationScheduleStatus::ACTIVE,
        AIConversationScheduleStatus::PAUSED,
        AIConversationScheduleStatus::EXPIRED,
        AIConversationScheduleStatus::DONE,
        AIConversationScheduleStatus::CANCELLED,
        AIConversationScheduleStatus::ARCHIVED,
    ])]
    #[Length(max: 16)]
    public string $status = AIConversationScheduleStatus::ACTIVE;

    /** @var DateTime|null The next fire anchor — advanced by the claim UPDATE itself (claim-by-UPDATE, no double fire). */
    public ?DateTime $nextRunAt;

    /** @var DateTime|null When the last firing was claimed (bookkeeping/inspection). */
    public ?DateTime $lastFiredAt;

    /**
     * @var DateTime|null When the owner was last told that the SCHEDULE BUDGET POT is exhausted and autonomous
     * runs pause — the once-per-month anti-spam marker: before notifying, the fire
     * path checks the newest marker across ALL the account's schedules for the current month
     * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService::notifyOwnerOncePerMonthThatScheduleBudgetPotIsExhausted()}).
     */
    public ?DateTime $scheduleBudgetPotExhaustedNotifiedAt;

    /** @var int Firings so far — {@see $maxIterations} caps it. */
    public int $iterationsUsed = 0;

    /** @var int|null Maximum firings before the schedule EXPIRES; NULL = unlimited (the ttl still bounds it). */
    public ?int $maxIterations;

    /** @var DateTime|null Absolute expiry — past it the schedule EXPIRES regardless of iterations; NULL = no ttl. */
    public ?DateTime $ttlAt;

    /**
     * @var int Consecutive FAILED runs — the fire path delays the next occurrence by 2^streak intervals (capped)
     * so a persistently failing task does not burn budget every occurrence; a COMPLETED run resets it to 0.
     */
    public int $failureStreak = 0;

    /** @var DateTime|null When the last run FAILED (backoff bookkeeping). */
    public ?DateTime $lastFailedAt;

    /**
     * @var DateTime|null The moment up to which the OWNER has seen this schedule's finished runs (the tab badge
     * counts runs newer than this); NULL = never marked seen — the schedule's own `created` stands in as the floor.
     * Stamped to NOW on ALL schedules of a space by the customer `markRunsSeen` action (opening the
     * Scheduled-Tasks tab).
     */
    public ?DateTime $ownerSawRunsUntilAt;

    /**
     * @var string|null WHY the PLATFORM transitioned this schedule — one of the
     * {@see AgentAutomationStateReason} values: AGENT_SPACE_SUSPENDED / ACCOUNT_SUSPENDED on a row the sweep PAUSED
     * for a locked space or account (automatic resumption re-arms ONLY these and clears the reason),
     * AGENT_SPACE_ENDED / ACCOUNT_ENDED / ACCOUNT_ERASED on a row it CANCELLED. NULL = the owner's or the platform's own
     * transition (a customer pause, a normal expiry) — the sweep never touches such rows in either direction, and
     * there is no other owner-pause marker on the entity, which is exactly why this column exists.
     */
    #[Choice(callback: [AgentAutomationStateReason::class, 'getAllOfTheApplication'])]
    #[Length(max: 32)]
    public ?string $stateReason;

    /** @var string Report delivery INSIDE the run conversation only (chat message; the FE/task log surface it). */
    public const string REPORT_CHANNEL_CHAT = 'CHAT';

    /** @var string Report delivery by EMAIL in addition to the chat message — the run conversation is snapshot-stamped with this channel at spawn and its delivery obligation is armed at spawn. */
    public const string REPORT_CHANNEL_EMAIL = 'EMAIL';

    /**
     * @var string Where the run report is delivered — snapshot-copied onto every spawned run conversation
     * ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$reportChannel}); a later edit here never
     * changes an already-running conversation.
     */
    #[Length(max: 16)]
    #[Choice(choices: [self::REPORT_CHANNEL_CHAT, self::REPORT_CHANNEL_EMAIL])]
    public string $reportChannel = self::REPORT_CHANNEL_CHAT;

    /**
     * @var RunSettings|null Per-firing run settings (maxStepsPerTurn / maxTurnCostUsd) — copied onto every spawned
     * run conversation; NULL = the plan-adaptive defaults.
     */
    public ?RunSettings $runSettings;

    /**
     * @var float|null Hard cost cap per firing in USD — the v1 stand-in for the account-level autonomy pot
     * (schedule runs must never drain the customer's chat budget unboundedly). NULL = the plan-adaptive default
     * cap applies per turn.
     */
    public ?float $perRunCostCapUsd;

    /**
     * @var string|null The IANA time zone the cron digits are written in: "0 6 * * 1-5" fires at 06:00 in
     * THIS zone, DST included — a cron is a pattern, not an instant, and a pattern cannot be translated between zones
     * losslessly (12:00 UTC is 14:00 Berlin in summer and 13:00 in winter), so the digits themselves are local and the
     * evaluator applies the zone. Copied from the creating conversation (agent) or the space of the schedule (REST
     * editor) and copied verbatim onto every spawned run. NULL = no zone was known when the schedule was created (a
     * schedule without a space whose creator named none, or a legacy schedule): the cron is evaluated in UTC.
     */
    public ?string $timezone = null;

    /**
     * @var bool|null Whether that zone was only a country default when the schedule was created — copied onto every
     * spawned run with the zone ({@see AIConversation::$timezoneUnverified}). NULL for a legacy schedule.
     */
    public ?bool $timezoneUnverified = null;

    /** The identifier the cron evaluator uses — the frozen zone, UTC for a legacy schedule. */
    public function getCronTimezoneIdentifier(): string
    {
        return $this->timezone ?? 'UTC';
    }

    /**
     * Whether `$occurrence` is the SECOND instance of a local wall-clock slot that exists twice — the repeated hour
     * of a fall-back transition. A property of the occurrence itself (not of the previous call): the instant one
     * DST delta earlier renders to the same local digits. Never true outside a transition day, never true in a zone
     * without DST, never true for a distinct local minute (every-30-minutes and hourly crons keep every other firing).
     */
    protected function isRepeatedLocalOccurrence(\DateTimeInterface $occurrence, \DateTimeZone $timezone): bool
    {
        $timestamp = $occurrence->getTimestamp();
        $transitions = $timezone->getTransitions($timestamp - 86400, $timestamp);
        if (count($transitions) < 2) {
            return false;                                             // element 0 is the state at the range start
        }
        $lastTransition = $transitions[count($transitions) - 1];
        $offsetBeforeTransition = $transitions[count($transitions) - 2]['offset'];
        $offsetDeltaSeconds = $offsetBeforeTransition - $lastTransition['offset'];
        if ($offsetDeltaSeconds <= 0) {
            return false;                                             // a spring-forward transition creates a gap, not a repeat
        }
        $earlierInstance = (new \DateTimeImmutable('@' . ($timestamp - $offsetDeltaSeconds)))->setTimezone($timezone);
        $thisInstance = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        return $earlierInstance->format('Y-m-d H:i') === $thisInstance->format('Y-m-d H:i');
    }

    /**
     * THE occurrence policy shared by next-run, backoff, count and agenda: the cron occurrences strictly after
     * `$from`, evaluated in the schedule's zone, taken by TIMESTAMP (never by re-parsing digits — that would lose
     * the instant), with the repeated fall-back hour fired ONCE; a spring-gap occurrence is taken where the library
     * places it (the next existing minute). `$skip` = accepted occurrences to drop before the returned one.
     */
    /** @var CronExpression[] parsed expressions by their text — the agenda calls this per occurrence */
    protected static array $parsedCronExpressions = [];

    protected function nextCronOccurrenceAfter(\DateTimeInterface $from, int $skip = 0): DateTime
    {
        $cronExpression = self::$parsedCronExpressions[(string)$this->schedule] ??= new CronExpression((string)$this->schedule);
        $cronTimezoneIdentifier = $this->getCronTimezoneIdentifier();
        $cronTimezone = new \DateTimeZone($cronTimezoneIdentifier);
        $cursor = $from;
        $accepted = -1;
        while (true) {
            $occurrence = $cronExpression->getNextRunDate($cursor, 0, false, $cronTimezoneIdentifier);
            $cursor = $occurrence;
            if ($this->isRepeatedLocalOccurrence($occurrence, $cronTimezone)) {
                continue;
            }
            if (++$accepted === $skip) {
                return DateTime::fromTimestamp($occurrence->getTimestamp());
            }
        }
    }

    /**
     * The run settings every firing copies onto its spawned run conversation — the entity's own
     * {@see $runSettings} plus {@see $perRunCostCapUsd} folded into {@see RunSettings::$maxTurnCostUsd} when no
     * explicit turn cost cap is set (a schedule run is ONE task turn, so per-run and per-turn coincide).
     * Returns a NEW composite (never the stored VO — the caller stamps it onto a different entity); null when the
     * schedule sets nothing (the run then uses the plan-adaptive defaults).
     *
     * `$isCreatedByAnEvalRun`: the schedule was created inside an eval run (its creating conversation's tree carries
     * {@see RunSettings::$evalRun}, or the stored settings carry it) — the run then carries {@see RunSettings::$evalRun}
     * too, so its tool calls stay in eval context and every outbound send it makes reaches only the test sinks
     * ({@see \DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService}).
     */
    public function buildRunSettingsForRun(bool $isCreatedByAnEvalRun = false): ?RunSettings
    {
        $isEvalRun = $isCreatedByAnEvalRun || (isset($this->runSettings) && $this->runSettings->evalRun === true);
        if (!isset($this->runSettings) && !isset($this->perRunCostCapUsd) && !$isEvalRun) {
            return null;
        }
        $runSettingsForRun = RunSettings::newInstance();
        if (isset($this->runSettings)) {
            $runSettingsForRun->maxStepsPerTurn = $this->runSettings->maxStepsPerTurn;
            $runSettingsForRun->maxTurnCostUsd = $this->runSettings->maxTurnCostUsd;
        }
        if ($runSettingsForRun->maxTurnCostUsd === null && isset($this->perRunCostCapUsd)) {
            $runSettingsForRun->maxTurnCostUsd = $this->perRunCostCapUsd;
        }
        if ($isEvalRun) {
            $runSettingsForRun->evalRun = true;
        }
        return $runSettingsForRun;
    }

    /**
     * The seed USER message of a run: the owner's task text (the mission) plus, when a previous run left an outcome,
     * the `Last run (date) result: …` line the run continues from. Nothing else — the run's role and its delivery
     * contract per report channel are the `Schedule.Run` purpose's directive
     * ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\ScheduleRunPurpose}), stamped on the run at spawn.
     */
    public function composeSeedUserMessageText(?string $previousRunOutcome, ?DateTime $previousRunCreated): string
    {
        $seedUserMessageText = trim((string)$this->task);
        if ($previousRunOutcome !== null && trim($previousRunOutcome) !== '') {
            $previousRunDate = $previousRunCreated !== null ? $previousRunCreated->format('Y-m-d') : 'earlier';
            $seedUserMessageText .= "\n\nLast run ({$previousRunDate}) result: " . trim($previousRunOutcome);
        }
        return $seedUserMessageText;
    }

    /**
     * Whether a run's seed (its first user message, {@see composeSeedUserMessageText()}) was written for the task this
     * schedule carries NOW: the seed starts with the trimmed task. A run before the owner changed the task fails it —
     * its report's structure answers a task that no longer exists.
     */
    public function isCurrentTaskOfRunSeed(string $runSeedText): bool
    {
        $currentTask = trim((string)($this->task ?? ''));
        return $currentTask !== '' && str_starts_with(trim($runSeedText), $currentTask);
    }

    /** Whether this is a ONE-TIME schedule (fires exactly once at {@see $oneTimeAt}) vs a recurring cron. */
    public function isOneTime(): bool
    {
        return isset($this->oneTimeAt);
    }

    /** @var int Character cap of {@see getOwnerFacingLabel()} — mirrors the {@see $title} column length. */
    public const int OWNER_FACING_LABEL_MAX_CHARS = 60;

    /** @var int Character cap of the run outcome inside {@see composeRunCompletedOwnerNoticeText()} — a chat line, not the report. */
    public const int OWNER_NOTICE_OUTCOME_MAX_CHARS = 200;

    /**
     * The subject of a run's report mail when the run gave none (it never stated its outcome, or the budget closing step
     * wrote the report): the schedule's owner-facing label and the run's day, so the owner's inbox tells the runs of one
     * schedule apart — never the generic "Report from your AI assistant".
     */
    public function composeReportEmailSubjectForRun(?DateTime $runStartedAt): string
    {
        $label = $this->getOwnerFacingLabel();
        if ($label === '' || $runStartedAt === null) {
            return $label;
        }
        return $label . ' – ' . $runStartedAt->format('Y-m-d');
    }

    /**
     * The label the owner-facing surfaces call this schedule by: the curated {@see $title}, or the {@see $task}
     * truncated to {@see OWNER_FACING_LABEL_MAX_CHARS} when no title was set (same fallback the frontend uses).
     */
    public function getOwnerFacingLabel(): string
    {
        $title = trim((string)($this->title ?? ''));
        if ($title !== '') {
            return $title;
        }
        $task = trim((string)($this->task ?? ''));
        return mb_strlen($task) > self::OWNER_FACING_LABEL_MAX_CHARS
            ? mb_substr($task, 0, self::OWNER_FACING_LABEL_MAX_CHARS - 3) . '…'
            : $task;
    }

    /** @var string The `(UTC)`-suffixed next-attempt moment in the owner notice — locale-neutral on purpose, see {@see translateOwnerNotice()}. */
    public const string OWNER_NOTICE_DATE_FORMAT = 'Y-m-d H:i';

    /**
     * The chat notice a COMPLETED run appends into the conversation this schedule was created in
     * ({@see $createdByConversationId}) — pure text composition; the service fetches
     * `$runOutcome` (the run's summary outcome or last assistant text) and appends the message. Written in the
     * owner's language via {@see translateOwnerNotice()}; the outcome/with-outcome variants are two WHOLE-sentence
     * keys rather than a translated fragment appended to a translated fragment (word order is not portable).
     */
    public function composeRunCompletedOwnerNoticeText(
        AIConversation $creatingConversation,
        ?string $runOutcome
    ): string {
        $ownerFacingLabel = $this->getOwnerFacingLabel();
        $trimmedRunOutcome = trim((string)$runOutcome);
        if ($trimmedRunOutcome === '') {
            return $this->translateOwnerNotice(
                $creatingConversation,
                '✅ Your task "%task%" has run.',
                ['task' => $ownerFacingLabel]
            );
        }
        if (mb_strlen($trimmedRunOutcome) > self::OWNER_NOTICE_OUTCOME_MAX_CHARS) {
            $trimmedRunOutcome = mb_substr($trimmedRunOutcome, 0, self::OWNER_NOTICE_OUTCOME_MAX_CHARS - 3) . '…';
        }
        return $this->translateOwnerNotice(
            $creatingConversation,
            '✅ Your task "%task%" has run. Result: %outcome%',
            ['task' => $ownerFacingLabel, 'outcome' => $trimmedRunOutcome]
        );
    }

    /**
     * The chat notice a REPEATEDLY FAILED run ({@see $failureStreak} ≥ 2) appends into the conversation this
     * schedule was created in — names the streak and, when the fire anchor is armed, the (backoff-delayed) next
     * attempt. Pure text composition over own state, in the owner's language.
     */
    public function composeRunFailedOwnerNoticeText(AIConversation $creatingConversation): string
    {
        $ownerFacingLabel = $this->getOwnerFacingLabel();
        if (!isset($this->nextRunAt)) {
            return $this->translateOwnerNotice(
                $creatingConversation,
                '⚠️ Your task "%task%" failed %streak% times in a row.',
                ['task' => $ownerFacingLabel, 'streak' => (string)$this->failureStreak]
            );
        }
        return $this->translateOwnerNotice(
            $creatingConversation,
            '⚠️ Your task "%task%" failed %streak% times in a row. Next attempt: %nextAttempt% (UTC).',
            [
                'task' => $ownerFacingLabel,
                'streak' => (string)$this->failureStreak,
                'nextAttempt' => $this->nextRunAt->format(self::OWNER_NOTICE_DATE_FORMAT),
            ]
        );
    }

    /**
     * The once-per-month owner notice when the SCHEDULE BUDGET POT is exhausted —
     * pure composition, deliberately WITHOUT figures (the account may be under a fair-use policy; the chat
     * surface never quotes budget numbers here). For a ONE-TIME task the notice names the task, says it could
     * not run because it EXPIRED with the pot, and asks the owner to request it again after the 1st.
     */
    public function composeBudgetPausedOwnerNoticeText(AIConversation $creatingConversation): string
    {
        if ($this->isOneTime()) {
            return $this->translateOwnerNotice(
                $creatingConversation,
                '⏸️ Your task "%task%" could not run: this month\'s automation budget is used up. Ask for it again'
                . ' after the 1st — the chat keeps working without restriction.',
                ['task' => $this->getOwnerFacingLabel()]
            );
        }
        return $this->translateOwnerNotice(
            $creatingConversation,
            '⏸️ This month\'s automation budget is used up — your scheduled and background tasks pause until the'
            . ' 1st of next month. The chat keeps working without restriction.'
        );
    }

    /**
     * One owner-notice string through the app translation layer — key = the English source string, exactly the
     * convention {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport::translate()} uses for the
     * template-composed run report. The language is the creating conversation's
     * ({@see AIConversation::resolveUserLanguageCode()}, the same chain that fills `{%USER_LANGUAGE%}` in the
     * agent prompt); when neither the conversation nor its space names a language that chain lands on the translation
     * store's own default, which is the intended degradation here.
     *
     * The date in the next-attempt variant stays `Y-m-d H:i` ({@see OWNER_NOTICE_DATE_FORMAT}): the only
     * locale-aware date rendering in the codebase
     * ({@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIConversation::getAIPromptWithParametersApplied()})
     * needs a COUNTRY code on top of the language, and this path cannot guarantee a space that names one — a format
     * that silently alternates between 12.17 and 17.12 reads worse than one nobody can misread.
     *
     * @param array<string, string> $placeholders
     */
    protected function translateOwnerNotice(
        AIConversation $creatingConversation,
        string $englishSourceKey,
        array $placeholders = []
    ): string {
        $placeholderSearchAndReplace = [];
        foreach ($placeholders as $placeholderName => $placeholderValue) {
            $placeholderSearchAndReplace['%' . $placeholderName . '%'] = $placeholderValue;
        }
        try {
            return ___(
                $englishSourceKey,
                languageCode: $creatingConversation->resolveUserLanguageCode(),
                placeholders: $placeholders,
                defaultString: $englishSourceKey
            );
        } catch (Throwable $translationError) {
            // The notice rides the run TERMINAL (including the failure and budget-pause paths) — an unreachable
            // translation store must never take that terminal down. The English source string with its
            // placeholders filled is the honest degradation; logged in its own guard so a permanently broken
            // store stays visible instead of silently serving English forever.
            try {
                DDDService::instance()->getLogger()->error(
                    'AIConversationSchedule: owner-notice translation failed, degrading to English: '
                    . $translationError->getMessage()
                );
            } catch (Throwable) {
            }
            return strtr($englishSourceKey, $placeholderSearchAndReplace);
        }
    }

    /** @var string Delete outcome: the row is removed for good (zero executions so far — nothing to keep). */
    public const string DELETE_ACTION_HARD_DELETE = 'HARD_DELETE';

    /** @var string Delete outcome: a recurring schedule that already ran → status ARCHIVED (hidden by default, filterable). */
    public const string DELETE_ACTION_ARCHIVE = 'ARCHIVE';

    /** @var string Delete outcome: a one-time schedule that already ran → nothing changes (it IS history). */
    public const string DELETE_ACTION_KEEP_HISTORY = 'KEEP_HISTORY';

    /**
     * What a DELETE request does to THIS schedule (every caller including the admin
     * console): never ran (zero executions) → hard delete,
     * one-time or recurring alike ("please do not call today after all"); recurring with executions → ARCHIVED ("I no
     * longer want the daily report"); one-time already executed → keep as history, nothing happens. The rule is
     * {@see AIConversationScheduleTransition::OPERATION_DELETE}.
     */
    public function getDeleteAction(): string
    {
        return (string)$this->buildTransitionTo(AIConversationScheduleTransition::OPERATION_DELETE, new DateTime())->deleteAction;
    }

    /**
     * The NEXT fire anchor strictly after `$from`: a ONE-TIME schedule fires at its {@see $oneTimeAt} (fixed —
     * there is no "next after that"); a recurring one at the next cron occurrence — pure schedule math on the
     * entity's own expression (dragonmantank/cron-expression, the parser of the host's cron runner). The fire path
     * writes the result into {@see $nextRunAt} within the claim UPDATE.
     */
    public function computeNextRunAt(DateTime $from): DateTime
    {
        if ($this->isOneTime()) {
            return DateTime::fromTimestamp($this->oneTimeAt->getTimestamp());
        }
        return $this->nextCronOccurrenceAfter($from);
    }

    /**
     * The next occurrence AFTER failure backoff: skips 2^{@see $failureStreak} occurrences (capped at 5 doublings
     * = 32 intervals) so a persistently failing task decays instead of burning budget on every occurrence.
     */
    public function computeNextRunAtWithFailureBackoff(DateTime $from): DateTime
    {
        if ($this->isOneTime()) {
            // A one-time schedule has no later occurrence to back off to — its single anchor stands.
            return DateTime::fromTimestamp($this->oneTimeAt->getTimestamp());
        }
        $occurrencesToSkip = 2 ** min(5, max(0, $this->failureStreak));
        return $this->nextCronOccurrenceAfter($from, max(0, $occurrencesToSkip - 1));
    }

    /**
     * How often the cron fires within a window of days from `$from` — pure iteration over the cron's
     * occurrences (no repo contact), capped at `$countCap` so a runaway expression cannot loop unbounded.
     * 0 for a one-time schedule (no cron to iterate).
     */
    public function countCronFiringsWithinDays(DateTime $from, int $days, int $countCap = 500): int
    {
        if ($this->isOneTime() || trim((string)($this->schedule ?? '')) === '') {
            return 0;
        }
        // The window is N × 24 h of ELAPSED time from $from (not local calendar days) — unchanged by the zone.
        $windowEndTimestamp = $from->getTimestamp() + $days * 86400;
        $firingCount = 0;
        $occurrenceCursor = $from;
        while ($firingCount < $countCap) {
            $occurrenceCursor = $this->nextCronOccurrenceAfter($occurrenceCursor);
            if ($occurrenceCursor->getTimestamp() > $windowEndTimestamp) {
                break;
            }
            $firingCount++;
        }
        return $firingCount;
    }

    /**
     * The upcoming fire moments within a window of `$days` from `$from` — pure schedule math (no repo contact),
     * the datetime-returning sibling of {@see countCronFiringsWithinDays()} (the agenda strip's
     * source). A RECURRING schedule iterates its cron, capped at `$countCap` so a runaway expression
     * cannot loop unbounded; a ONE-TIME schedule contributes its single {@see $oneTimeAt} — only while still
     * PENDING ({@see $iterationsUsed} = 0) and only when the moment falls inside the window.
     *
     * @return DateTime[] fire moments in ascending order; empty when nothing falls inside the window
     */
    public function computeCronOccurrencesWithinDays(DateTime $from, int $days, int $countCap = 50): array
    {
        // The window is N × 24 h of ELAPSED time from $from (not local calendar days) — unchanged by the zone.
        $windowEndTimestamp = $from->getTimestamp() + $days * 86400;
        if ($this->isOneTime()) {
            if ($this->iterationsUsed > 0) {
                // Already fired — its single occurrence is history, not an upcoming one.
                return [];
            }
            $oneTimeTimestamp = $this->oneTimeAt->getTimestamp();
            if ($oneTimeTimestamp < $from->getTimestamp() || $oneTimeTimestamp > $windowEndTimestamp) {
                return [];
            }
            // A NEW instance, never the stored property — the caller sorts/serializes freely.
            return [DateTime::fromTimestamp($oneTimeTimestamp)];
        }
        if (trim((string)($this->schedule ?? '')) === '') {
            return [];
        }
        $upcomingOccurrences = [];
        $occurrenceCursor = $from;
        while (count($upcomingOccurrences) < $countCap) {
            $occurrenceCursor = $this->nextCronOccurrenceAfter($occurrenceCursor);
            if ($occurrenceCursor->getTimestamp() > $windowEndTimestamp) {
                break;
            }
            $upcomingOccurrences[] = $occurrenceCursor;
        }
        return $upcomingOccurrences;
    }

    /** Whether this schedule fires on its anchor (status ACTIVE). */
    public function isActive(): bool
    {
        return $this->status === AIConversationScheduleStatus::ACTIVE;
    }

    /**
     * Whether `$operation` is allowed on this schedule as it is now, and what it leads to — THE legality question of
     * every schedule operation ({@see AIConversationScheduleTransition}), the same answer for every caller;
     * `$eligibility` is the scope's freshly read automation state where the operation depends on it (resume, state
     * sweep).
     */
    public function buildTransitionTo(
        string $operation,
        DateTime $now,
        ?AgentAutomationEligibility $eligibility = null
    ): AIConversationScheduleTransition {
        return AIConversationScheduleTransition::decide($this, $operation, $now, $eligibility);
    }

    /** Whether the customer/admin may PAUSE this schedule (only a running recurrence can pause). */
    public function canBePaused(): bool
    {
        return $this->buildTransitionTo(AIConversationScheduleTransition::OPERATION_PAUSE, new DateTime())->isAllowed;
    }

    /**
     * Whether the customer/admin may RESUME this schedule: only a PAUSED row, only one the OWNER paused — a row the
     * platform paused for a locked agent space / account ({@see $stateReason} set) is re-armed by the sweep when the state
     * clears, never by hand (resuming it from the automations tab would fire it on a locked agent space at the
     * next due scan) — and a one-time row only while its moment lies ahead. The scope's eligibility is not part of
     * this answer.
     */
    public function canBeResumed(): bool
    {
        return $this->buildTransitionTo(AIConversationScheduleTransition::OPERATION_RESUME, new DateTime())->isAllowed;
    }

    /** Whether the platform itself transitioned this row for a state of the account or the agent space ({@see $stateReason}). */
    public function isClosedByPlatformState(): bool
    {
        return ($this->stateReason ?? null) !== null;
    }

    /**
     * The state sweep's transition for the scope's eligibility (the four-row table of
     * {@see AIConversationScheduleTransition::OPERATION_STATE_SWEEP}), or null when the row stays exactly as it is.
     */
    public function decideTransitionForAutomationState(AgentAutomationEligibility $eligibility): ?AIConversationScheduleTransition
    {
        $stateSweepTransition = $this->buildTransitionTo(AIConversationScheduleTransition::OPERATION_STATE_SWEEP, new DateTime(), $eligibility);
        return $stateSweepTransition->isAllowed ? $stateSweepTransition : null;
    }

    /** Whether the customer/admin may CANCEL this schedule (any non-terminal status; terminal stays terminal). */
    public function canBeCancelled(): bool
    {
        return $this->buildTransitionTo(AIConversationScheduleTransition::OPERATION_CANCEL, new DateTime())->isAllowed;
    }

    /** Whether the schedule has run out of iterations or lifetime — the fire path transitions it to EXPIRED. */
    public function isExpired(DateTime $now): bool
    {
        if (isset($this->ttlAt) && $this->ttlAt->getTimestamp() <= $now->getTimestamp()) {
            return true;
        }
        return isset($this->maxIterations) && $this->iterationsUsed >= $this->maxIterations;
    }
}
