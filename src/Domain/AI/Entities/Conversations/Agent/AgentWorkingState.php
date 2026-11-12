<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentUses;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionRecord;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartLineDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentStallRecoveryDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnClosingDecision;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The agent's WORKING-STATE container: ONE JSON merge column on the conversation
 * ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$agentWorkingState}) holding every run-scoped state
 * kind as a typed property — initially only the {@see TaskPlan}. Future kinds (supervisor intentions, approval
 * state) become new typed properties: no new columns, one render seam.
 *
 * CONCURRENCY CONTRACT: the column is `isMergableJSONColumn` — every persist writes
 * ONLY the touched property slice via a BARE carrier (JSON_MERGE_PATCH unions top-level keys, so different
 * properties tolerate different writers), and each property has exactly ONE writer: `taskPlan` is written only by
 * the turn holding the run claim, through the token-fenced
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistTaskPlanSlice()} — NEVER by writing
 * this loaded VO back (that would merge stale sibling slices over a concurrent writer's newer ones).
 */
class AgentWorkingState extends ValueObject
{
    /** @var TaskPlan|null The ACTIVE plan (at most one) — the model's per-step view, the checked stop's gate. */
    public ?TaskPlan $taskPlan = null;

    /**
     * @var TaskPlans|null The FINISHED plans of this conversation: every plan
     *      retired by {@see retireFinishedTaskPlan()} — oldest first, capped by {@see TaskPlans::MAX_FINISHED_PLANS}.
     *      Read by the `get_task_plans` meta-tool and the inspector; never compacted away (it lives here, not in the
     *      messages). Written by the retirement, persisted together with the cleared active plan via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistTaskPlanRetirementSlice()}, and by
     *      the reopening of the newest plan at the next owner turn ({@see reopenNewestFinishedTaskPlan()}), persisted
     *      together with the active plan in one slice write — both by the turn holding the run claim.
     */
    public ?TaskPlans $completedTaskPlans = null;

    /**
     * @var AgentPlanDynamicsDecision|null The plan-dynamics decision of the CURRENT owner turn: the band the
     *      turn-start question resolved, the mid-turn re-ask, the reopening and the retirement record. ONE writer: the loop's decision
     *      steps, persisted via {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()};
     *      the context build reads it only while its `ownerMessageIndex` is the newest initiator.
     */
    public ?AgentPlanDynamicsDecision $planDynamicsDecision = null;

    /**
     * Retire the active plan as FINISHED into the finished set: stamps the retirement on the plan,
     * appends it (capped) and clears the active slot. Returns the retired plan, or null when there is none or it is
     * not finished. Pure — the caller persists the retirement slice.
     */
    public function retireFinishedTaskPlan(int $atMessageIndex, string $completedAt, ?string $title = null): ?TaskPlan
    {
        $activePlan = $this->taskPlan;
        if ($activePlan === null || !$activePlan->isFinished()) {
            return null;
        }
        $activePlan->retire($atMessageIndex, $completedAt, $title);
        $this->completedTaskPlans ??= new TaskPlans();
        $this->completedTaskPlans->addFinishedPlan($activePlan);
        $this->taskPlan = null;
        return $activePlan;
    }

    /**
     * REOPEN the newest finished plan as the active one: it leaves the
     * finished set, its retirement stamps are taken back ({@see TaskPlan::reopen()}) and it takes the active slot, so
     * the parts the owner adds join it as new items. Whether it is due is the plan's own rule
     * ({@see TaskPlan::reopensForOwnerTurn()}). Returns the reopened plan, or null when a plan is active or none is
     * finished. Pure — the caller persists `taskPlan` and `completedTaskPlans` together.
     */
    public function reopenNewestFinishedTaskPlan(): ?TaskPlan
    {
        $newestFinishedPlan = $this->completedTaskPlans?->last();
        if ($this->taskPlan !== null || !$newestFinishedPlan instanceof TaskPlan) {
            return null;
        }
        // removed BEFORE the stamps change: the set finds its element by the key the stamps form (remove() takes a reference)
        $finishedPlanToRemove = $newestFinishedPlan;
        $this->completedTaskPlans->remove($finishedPlanToRemove);
        if ($this->completedTaskPlans->count() === 0) {
            $this->completedTaskPlans = null;
        }
        $newestFinishedPlan->reopen();
        $this->taskPlan = $newestFinishedPlan;
        return $newestFinishedPlan;
    }

    /**
     * The plan this conversation worked on LAST: the active one, else the newest finished one. A finished plan is
     * retired at the accepted final answer, so a read after the turn finds the active slot empty although the turn
     * planned and completed its work.
     */
    public function getNewestTaskPlan(): ?TaskPlan
    {
        if ($this->taskPlan !== null) {
            return $this->taskPlan;
        }
        $finishedPlans = $this->completedTaskPlans?->getElements() ?? [];
        $newestFinishedPlan = $finishedPlans === [] ? null : end($finishedPlans);
        return $newestFinishedPlan instanceof TaskPlan ? $newestFinishedPlan : null;
    }

    /**
     * The message indexes at which the live loop retired a finished plan: each plan of the finished set
     * carries {@see TaskPlan::$completedAtMessageIndex}, the newest message index when the accepted final answer retired
     * it. Live, a plan retires only there; where the site is off nothing is recorded and no plan ever retires.
     *
     * @return int[]
     */
    public function getTaskPlanRetirementMessageIndexes(): array
    {
        $retirementMessageIndexes = [];
        foreach ($this->completedTaskPlans?->getElements() ?? [] as $retiredTaskPlan) {
            if ($retiredTaskPlan->completedAtMessageIndex !== null) {
                $retirementMessageIndexes[] = $retiredTaskPlan->completedAtMessageIndex;
            }
        }
        return $retirementMessageIndexes;
    }

    /** How many finished plans this conversation keeps. */
    public function countFinishedTaskPlans(): int
    {
        return $this->completedTaskPlans?->count() ?? 0;
    }

    /**
     * @var string|null When the interface-language question of a purposed conversation was DECIDED — the ONE-SHOT
     * stamp of the automatic confirmation (run by the purpose's plan consequence hook,
     * {@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::applyConsequencesOfPersistedTaskPlan()}):
     * set on EVERY terminal outcome (language switched, default confirmed, existing row found, explicit tool call
     * seen, attempt cap reached), never cleared — once set the automatic path stays retired for good (only the
     * explicit `updateInterfaceLanguage` tool changes the language afterwards). `Y-m-d H:i:s`; ONE writer: the
     * purpose's consequence hook, persisted via
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}.
     */
    public ?string $interfaceLanguageConfirmedAt = null;

    /**
     * @var string|null The ENVIRONMENT whose worker last drove this conversation — a dev workspace's name,
     *      `prod`, or `local` ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::currentEnvironment()}).
     *      Stamped by the run claim ({@see \DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService::claimRun()})
     *      as a JSON slice, so no schema change. Production and every dev workspace share one database and one
     *      cron tick: the stuck-conversation reaper reads this stamp and wakes ONLY the conversations of its own
     *      environment — a lost dev turn is no longer continued by a production worker with production code.
     *      Null = never driven since the stamp exists; only the production reaper treats such rows as its own.
     */
    public ?string $environment = null;

    /**
     * @var string|null The interface-language CODE the confirmation settled on (lowercase ISO), stamped together with
     *      {@see $interfaceLanguageConfirmedAt} whenever the outcome KNOWS a language: the detected one (switched or
     *      equal to the default), or an existing persisted row's. Null when the outcome knew none ('other', cap,
     *      no active languages). Read by {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::resolveUserLanguageCode()}
     *      between the persisted row and the country fallback — without it a confirmation that matches the default
     *      wrote no row, and a later change of the space's country flipped the fallback to that country's language
     *      for an owner who writes in another one.
     */
    public ?string $interfaceLanguageConfirmedCode = null;

    /**
     * @var int How often the interface-language DETECTION model call ran without a parseable verdict — the retry
     * cap's counter (a failed call must not retry unbounded on every later plan write; at the cap the hook stamps
     * {@see $interfaceLanguageConfirmedAt} and gives up). Same single writer + persist slice as the stamp.
     */
    public int $interfaceLanguageDetectionAttemptCount = 0;

    /**
     * @var int|null The step FLOOR a TOOL declared for the current turn (`turnMinimumSteps` on its result — a tool that
     *      knows how long its own flow is, e.g. a multi-page website edit); read by the loop's budget derivation only
     *      while {@see $turnMinimumStepsTurnInitiatorMessageIndex} matches the current turn (a leftover from an
     *      earlier turn never raises a plain chat turn).
     */
    public ?int $turnMinimumSteps = null;

    /**
     * @var AgentReplyLanguageDecision|null The reply-language decision of the CURRENT owner turn: the
     *      language the context build names in the reply-language cue, which source won, and who decided. ONE
     *      writer: the loop's turn-start decision step, persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()};
     *      read by {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::resolveReplyLanguageCode()} only while
     *      its `ownerMessageIndex` is the newest owner message (a stale stamp never names a language), and as the
     *      sticky ANCHOR of the next turn's chain.
     */
    public ?AgentReplyLanguageDecision $replyLanguageDecision = null;

    /**
     * @var AgentTurnClosingDecision|null The turn-closing decision of the NEWEST judged final answer:
     *      whether that text announced the open work instead of delivering it, and who decided. A record
     *      for the admin trace and the gate measurement; the loop acts on it at the moment it is made, never from
     *      the persisted slice. ONE writer: the checked-stop step, persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}.
     */
    public ?AgentTurnClosingDecision $turnClosingDecision = null;

    /**
     * @var AgentSkillPreloadDecision|null The skill-preload decision of the NEWEST owner-text turn (site
     *      B): what the decision model picked, whether the loop applied it and why not. A record for the inspector;
     *      the loop acts on it at the moment it is made, the turn-events table is the durable log. ONE writer:
     *      the turn-start orchestrator, persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}.
     */
    public ?AgentSkillPreloadDecision $skillPreloadDecision = null;

    /**
     * @var AgentReasoningEffortDecision|null The reasoning-effort decision of the CURRENT owner turn:
     *      the effort every model call of the turn is sent with, and who decided. ONE writer: the turn-start decision
     *      step, persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()};
     *      read by the loop's model call only while its `ownerMessageIndex` is the newest initiator (a stale stamp
     *      never sets an effort) — so a chunk resume of the same turn keeps the decided effort.
     */
    public ?AgentReasoningEffortDecision $reasoningEffortDecision = null;

    /**
     * @var AgentStallRecoveryDecision|null The stall-recovery decision of the NEWEST judged second stall:
     *      the move the decision model chose and whether the loop applied it. A record for the inspector
     *      and the "already judged this turn" memory across a chunk resume; ONE writer: the stall branch, persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}.
     */
    public ?AgentStallRecoveryDecision $stallRecoveryDecision = null;

    /**
     * @var AgentDirectWidgetDecision|null The direct-widget decision of the CURRENT owner turn:
     *      the widget the turn's answer is written as, when the decision model named one and the skill preload abstained.
     *      ONE writer: the turn-start decision step, persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()};
     *      read only while its `ownerMessageIndex` is the newest initiator ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::getDecidedDirectWidgetTypeOfCurrentTurn()}).
     */
    public ?AgentDirectWidgetDecision $directWidgetDecision = null;

    /**
     * @var AgentTurnStartLineDecision|null The turn-start line decision of the CURRENT owner turn:
     *      whether the customer's activity stack opens with one generated line on what the assistant does now. ONE
     *      writer: the turn-start decision step; read by the activity endpoint while its `ownerMessageIndex` is the turn's
     *      initiator ({@see \DDD\Domain\AI\Services\Conversations\AgentActivityLinesService::buildTurnStartElement()}).
     */
    public ?AgentTurnStartLineDecision $turnStartLineDecision = null;

    /** The log keeps the newest N decision records (a trace for the inspector, not the loop's working value). */
    public const int MAX_DECISION_RECORDS = 40;

    /**
     * @var AgentDecisionRecord[] Every decision-model call of this conversation, newest LAST, capped at
     *      {@see MAX_DECISION_RECORDS} — inputs (state), outputs (answers) and the resolved consequence, for the
     *      conversation inspector. ONE writer: the loop's decision step, persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}.
     */
    public array $decisions = [];

    /**
     * Append one record; past {@see MAX_DECISION_RECORDS} the oldest records leave — except the NEWEST owner-message
     * injection record, which stays as the oldest kept record: the turn start reads it to know that the newest owner
     * message was screened already ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerMessageScreeningService::findOwnerMessageForInjectionCheck()}),
     * and a long turn's records would otherwise push it out and screen the same message again on a chunk resume.
     */
    public function appendDecisionRecord(AgentDecisionRecord $agentDecisionRecord): void
    {
        $this->decisions[] = $agentDecisionRecord;
        if (count($this->decisions) <= self::MAX_DECISION_RECORDS) {
            return;
        }
        $newestInjectionDecisionRecord = null;
        foreach ($this->decisions as $agentDecisionRecord) {
            if (($agentDecisionRecord->site ?? null) === AgentDecisionModelSettings::SITE_OWNER_MESSAGE_INJECTION) {
                $newestInjectionDecisionRecord = $agentDecisionRecord;
            }
        }
        $newestDecisionRecords = array_slice($this->decisions, -self::MAX_DECISION_RECORDS);
        if ($newestInjectionDecisionRecord !== null && !in_array($newestInjectionDecisionRecord, $newestDecisionRecords, true)) {
            $newestDecisionRecords = array_merge([$newestInjectionDecisionRecord], array_slice($this->decisions, -(self::MAX_DECISION_RECORDS - 1)));
        }
        $this->decisions = $newestDecisionRecords;
    }

    /**
     * @var string|null The DAY CONTEXT as it stood when this conversation was created: the text the application
     *      composed for the day. Written ONCE at creation by
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistDayContextTextSlice()} and never
     *      changed — the context segment built from it stays byte-stable for the whole conversation.
     */
    public ?string $dayContextText = null;

    /**
     * @var string|null The LOCAL SITUATION as it stood when this conversation was created: the text the
     *      application composed about what goes on around the conversation's space. Written ONCE at creation by
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistLocalSituationTextSlice()} and
     *      never changed.
     */
    public ?string $localSituationText = null;

    /** @var int|null The turn stamp of {@see $turnMinimumSteps} — the initiator message index of the turn that declared it. */
    public ?int $turnMinimumStepsTurnInitiatorMessageIndex = null;

    /**
     * @var int|null The initiator message index of the owner turn whose BUDGET CLOSING STEP was already granted — the
     *      reserved last model call that writes the owner's message when the step budget is one call from spent. One
     *      per owner turn, across worker chunks: a chunk resume re-reads this and never grants a second one.
     */
    public ?int $budgetClosingStepTurnInitiatorMessageIndex = null;

    /**
     * @var AIConversationVariants|null The conversation's SITUATION as the purpose's variant dimensions resolve it:
     *      one value per declared dimension with its provenance. Read by
     *      the context build to select the skill sections the model sees, stamped onto every request snapshot. ONE
     *      writer: the loop's resolve step (turn start and after a record-changing tool call), persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}.
     */
    public ?AIConversationVariants $variants = null;

    /**
     * @var int[] The conversation indexes of the owner messages the injection check BLOCKED. The rows keep
     *      the owner's text (the chat shows it); the wire renders each as a filter notice, so the model never reads it.
     *      Written by the turn-start decision, persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}.
     */
    public array $securityFilteredOwnerMessageIndexes = [];

    public function isOwnerMessageSecurityFiltered(?int $messageIndex): bool
    {
        return $messageIndex !== null && in_array($messageIndex, $this->securityFilteredOwnerMessageIndexes, true);
    }

    public function markOwnerMessageSecurityFiltered(int $messageIndex): void
    {
        if (!in_array($messageIndex, $this->securityFilteredOwnerMessageIndexes, true)) {
            $this->securityFilteredOwnerMessageIndexes[] = $messageIndex;
        }
    }

    /**
     * @var int[] The conversation indexes of the owner-role rows the PLATFORM wrote — an admin typing through the admin
     *      console ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::driveAdminConversation()}), the
     *      voice model relaying a caller's task
     *      — not the owner's own words. Read by an application's intake of owner input
     *      (such rows never count as the owner's instruction; the caller's own
     *      transcript rows do); written at the row's persist, persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}.
     */
    public array $platformWrittenOwnerMessageIndexes = [];

    public function markOwnerMessageWrittenByPlatform(int $messageIndex): void
    {
        if (!in_array($messageIndex, $this->platformWrittenOwnerMessageIndexes, true)) {
            $this->platformWrittenOwnerMessageIndexes[] = $messageIndex;
        }
    }

    /**
     * @var int[] The conversation indexes of the owner rows the VOICE SESSION persisted — sentences the caller SPOKE
     *      (the application's voice channel writes them), written outside
     *      the agent loop and never screened by the turn-start injection check. An application's intake of owner input
     *      releases such a row once the assistant answered it — a row
     *      the owner TYPED during a call is not marked and waits for its verdict. Persisted via
     *      {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}.
     */
    public array $voiceTranscriptOwnerMessageIndexes = [];

    public function markOwnerMessageAsVoiceTranscript(int $messageIndex): void
    {
        if (!in_array($messageIndex, $this->voiceTranscriptOwnerMessageIndexes, true)) {
            $this->voiceTranscriptOwnerMessageIndexes[] = $messageIndex;
        }
    }

    /**
     * The owner-consent use record: one entry per tool and owner row. READ here, never written by
     * {@see mapToRepository()} — a write of the whole state would merge a stale copy over a concurrent reservation; its
     * only writers are the guarded statements of
     * {@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::recordOwnerConsentReservation()} and its siblings, which
     * keep this loaded copy in step. The tool's window (`ownerConsentWindows`) is read from the row, never from here.
     */
    public ?OwnerConsentUses $ownerConsentUses = null;

    public function getOrCreateOwnerConsentUses(): OwnerConsentUses
    {
        return $this->ownerConsentUses ??= new OwnerConsentUses();
    }

    /**
     * The top-level keys of the stored working state, in the order {@see mapToRepository()} writes them.
     * `ownerConsentUses` is not among them: its paths are written by their own statements and only read back here.
     *
     * @var string[]
     */
    public const array REPOSITORY_KEYS = [
        'taskPlan',
        'completedTaskPlans',
        'planDynamicsDecision',
        'interfaceLanguageConfirmedAt',
        'interfaceLanguageConfirmedCode',
        'interfaceLanguageDetectionAttemptCount',
        'turnMinimumSteps',
        'turnMinimumStepsTurnInitiatorMessageIndex',
        'budgetClosingStepTurnInitiatorMessageIndex',
        'replyLanguageDecision',
        'turnClosingDecision',
        'skillPreloadDecision',
        'reasoningEffortDecision',
        'stallRecoveryDecision',
        'directWidgetDecision',
        'turnStartLineDecision',
        'decisions',
        'dayContextText',
        'localSituationText',
        'variants',
        'securityFilteredOwnerMessageIndexes',
        'platformWrittenOwnerMessageIndexes',
        'voiceTranscriptOwnerMessageIndexes',
    ];

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return $this->buildSlice(...self::REPOSITORY_KEYS);
    }

    /**
     * The repository shape of the named top-level keys only, in the order given — the bare carrier one slice write
     * merges onto the stored column (the concurrency contract above: a write touches only its own keys, a null value
     * deletes its key).
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException for a key that is not one of {@see REPOSITORY_KEYS}
     */
    public function buildSlice(string ...$keys): array
    {
        $slice = [];
        foreach ($keys as $key) {
            $slice[$key] = match ($key) {
                'taskPlan' => $this->taskPlan?->mapToRepository(),
                'completedTaskPlans' => $this->completedTaskPlans?->toRepositoryArray() ?? [],
                'planDynamicsDecision' => $this->planDynamicsDecision?->mapToRepository(),
                'interfaceLanguageConfirmedAt' => $this->interfaceLanguageConfirmedAt,
                'interfaceLanguageConfirmedCode' => $this->interfaceLanguageConfirmedCode,
                'interfaceLanguageDetectionAttemptCount' => $this->interfaceLanguageDetectionAttemptCount,
                'turnMinimumSteps' => $this->turnMinimumSteps,
                'turnMinimumStepsTurnInitiatorMessageIndex' => $this->turnMinimumStepsTurnInitiatorMessageIndex,
                'budgetClosingStepTurnInitiatorMessageIndex' => $this->budgetClosingStepTurnInitiatorMessageIndex,
                'replyLanguageDecision' => $this->replyLanguageDecision?->mapToRepository(),
                'turnClosingDecision' => $this->turnClosingDecision?->mapToRepository(),
                'skillPreloadDecision' => $this->skillPreloadDecision?->mapToRepository(),
                'reasoningEffortDecision' => $this->reasoningEffortDecision?->mapToRepository(),
                'stallRecoveryDecision' => $this->stallRecoveryDecision?->mapToRepository(),
                'directWidgetDecision' => $this->directWidgetDecision?->mapToRepository(),
                'turnStartLineDecision' => $this->turnStartLineDecision?->mapToRepository(),
                'decisions' => array_map(static fn (AgentDecisionRecord $record): array => $record->mapToRepository(), $this->decisions),
                'dayContextText' => $this->dayContextText,
                'localSituationText' => $this->localSituationText,
                'variants' => $this->variants?->toRepositoryArray(),
                'securityFilteredOwnerMessageIndexes' => $this->securityFilteredOwnerMessageIndexes,
                'platformWrittenOwnerMessageIndexes' => $this->platformWrittenOwnerMessageIndexes,
                'voiceTranscriptOwnerMessageIndexes' => $this->voiceTranscriptOwnerMessageIndexes,
                default => throw new \InvalidArgumentException('Not a top-level key of the agent working state: ' . $key),
            };
        }
        return $slice;
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $repoArray = is_array($repoObject) ? $repoObject : [];
        $this->taskPlan = null;
        if (is_array($repoArray['taskPlan'] ?? null)) {
            $taskPlan = new TaskPlan();
            $taskPlan->mapFromRepository($repoArray['taskPlan']);
            $this->taskPlan = $taskPlan;
        }
        $this->completedTaskPlans = is_array($repoArray['completedTaskPlans'] ?? null) && $repoArray['completedTaskPlans'] !== []
            ? TaskPlans::fromRepositoryArray($repoArray['completedTaskPlans'])
            : null;
        $this->planDynamicsDecision = null;
        if (is_array($repoArray['planDynamicsDecision'] ?? null)) {
            $planDynamicsDecision = new AgentPlanDynamicsDecision();
            $planDynamicsDecision->mapFromRepository($repoArray['planDynamicsDecision']);
            $this->planDynamicsDecision = $planDynamicsDecision;
        }
        $interfaceLanguageConfirmedAt = trim((string)($repoArray['interfaceLanguageConfirmedAt'] ?? ''));
        $this->interfaceLanguageConfirmedAt = $interfaceLanguageConfirmedAt !== '' ? $interfaceLanguageConfirmedAt : null;
        $interfaceLanguageConfirmedCode = strtolower(trim((string)($repoArray['interfaceLanguageConfirmedCode'] ?? '')));
        $this->interfaceLanguageConfirmedCode = $interfaceLanguageConfirmedCode !== '' ? $interfaceLanguageConfirmedCode : null;
        $this->interfaceLanguageDetectionAttemptCount = (int)($repoArray['interfaceLanguageDetectionAttemptCount'] ?? 0);
        $this->turnMinimumSteps = isset($repoArray['turnMinimumSteps']) ? (int)$repoArray['turnMinimumSteps'] : null;
        $this->replyLanguageDecision = null;
        if (is_array($repoArray['replyLanguageDecision'] ?? null)) {
            $replyLanguageDecision = new AgentReplyLanguageDecision();
            $replyLanguageDecision->mapFromRepository($repoArray['replyLanguageDecision']);
            $this->replyLanguageDecision = $replyLanguageDecision;
        }
        $this->turnClosingDecision = null;
        if (is_array($repoArray['turnClosingDecision'] ?? null)) {
            $turnClosingDecision = new AgentTurnClosingDecision();
            $turnClosingDecision->mapFromRepository($repoArray['turnClosingDecision']);
            $this->turnClosingDecision = $turnClosingDecision;
        }
        $this->skillPreloadDecision = null;
        if (is_array($repoArray['skillPreloadDecision'] ?? null)) {
            $skillPreloadDecision = new AgentSkillPreloadDecision();
            $skillPreloadDecision->mapFromRepository($repoArray['skillPreloadDecision']);
            $this->skillPreloadDecision = $skillPreloadDecision;
        }
        $this->reasoningEffortDecision = null;
        if (is_array($repoArray['reasoningEffortDecision'] ?? null)) {
            $reasoningEffortDecision = new AgentReasoningEffortDecision();
            $reasoningEffortDecision->mapFromRepository($repoArray['reasoningEffortDecision']);
            $this->reasoningEffortDecision = $reasoningEffortDecision;
        }
        $this->stallRecoveryDecision = null;
        if (is_array($repoArray['stallRecoveryDecision'] ?? null)) {
            $stallRecoveryDecision = new AgentStallRecoveryDecision();
            $stallRecoveryDecision->mapFromRepository($repoArray['stallRecoveryDecision']);
            $this->stallRecoveryDecision = $stallRecoveryDecision;
        }
        $this->directWidgetDecision = null;
        if (is_array($repoArray['directWidgetDecision'] ?? null)) {
            $directWidgetDecision = new AgentDirectWidgetDecision();
            $directWidgetDecision->mapFromRepository($repoArray['directWidgetDecision']);
            $this->directWidgetDecision = $directWidgetDecision;
        }
        $this->turnStartLineDecision = null;
        if (is_array($repoArray['turnStartLineDecision'] ?? null)) {
            $turnStartLineDecision = new AgentTurnStartLineDecision();
            $turnStartLineDecision->mapFromRepository($repoArray['turnStartLineDecision']);
            $this->turnStartLineDecision = $turnStartLineDecision;
        }
        $dayContextText = trim((string)($repoArray['dayContextText'] ?? ''));
        $this->dayContextText = $dayContextText !== '' ? $dayContextText : null;
        $localSituationText = trim((string)($repoArray['localSituationText'] ?? ''));
        $this->localSituationText = $localSituationText !== '' ? $localSituationText : null;
        $this->decisions = [];
        foreach (is_array($repoArray['decisions'] ?? null) ? $repoArray['decisions'] : [] as $decisionRow) {
            if (!is_array($decisionRow)) {
                continue;
            }
            $agentDecisionRecord = new AgentDecisionRecord();
            $agentDecisionRecord->mapFromRepository($decisionRow);
            $this->decisions[] = $agentDecisionRecord;
        }
        $this->turnMinimumStepsTurnInitiatorMessageIndex = isset($repoArray['turnMinimumStepsTurnInitiatorMessageIndex'])
            ? (int)$repoArray['turnMinimumStepsTurnInitiatorMessageIndex']
            : null;
        $this->budgetClosingStepTurnInitiatorMessageIndex = isset($repoArray['budgetClosingStepTurnInitiatorMessageIndex'])
            ? (int)$repoArray['budgetClosingStepTurnInitiatorMessageIndex']
            : null;
        $this->variants = is_array($repoArray['variants'] ?? null) && $repoArray['variants'] !== []
            ? AIConversationVariants::fromRepositoryArray($repoArray['variants'])
            : null;
        $this->securityFilteredOwnerMessageIndexes = array_values(array_map(
            'intval',
            array_filter((array)($repoArray['securityFilteredOwnerMessageIndexes'] ?? []), 'is_numeric')
        ));
        $this->platformWrittenOwnerMessageIndexes = array_values(array_map(
            'intval',
            array_filter((array)($repoArray['platformWrittenOwnerMessageIndexes'] ?? []), 'is_numeric')
        ));
        $this->voiceTranscriptOwnerMessageIndexes = array_values(array_map(
            'intval',
            array_filter((array)($repoArray['voiceTranscriptOwnerMessageIndexes'] ?? []), 'is_numeric')
        ));
        $this->ownerConsentUses = is_array($repoArray['ownerConsentUses'] ?? null) && $repoArray['ownerConsentUses'] !== []
            ? OwnerConsentUses::fromRepositoryObject($repoArray['ownerConsentUses'])
            : null;
    }
}
