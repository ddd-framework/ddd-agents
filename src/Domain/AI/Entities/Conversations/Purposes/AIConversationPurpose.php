<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorPolicy;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariantDimensions;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Services\DDDService;

/**
 * A CONVERSATION PURPOSE — file-based config, mirrored from {@see \DDD\Domain\AI\Entities\Skills\AISkill}.
 * A purpose starts a conversation FOR a declared goal (for example a guided
 * intake): it contributes DATA only — a directive segment, a set of preloaded (+optionally pinned) skills,
 * RunSettings caps, a seeded task plan, a kickoff signal, doneCriteria — stamped once at creation, the
 * conversation self-contained afterwards. It is NOT a skill (not in the `load_skills` catalog): a purpose OWNS
 * the conversation and cannot be loaded mid-chat.
 *
 * One purpose = one `.md` under `config/app/AI/Purposes/<Domain>/<Feature>.md` (frontmatter + body) + one
 * dotted-name constant (registered in {@see REGISTERED_PURPOSE_NAMES}): here for the purposes the module runs itself,
 * in the application's purpose class at the same path for the application's own. Loaded via
 * {@see AIConversationPurposesService::getPurposeByName()}. The dotted name IS the identifier. The file's REQUIRED
 * `purposeClass:` line names the class it loads as — this base or a subclass carrying the purpose's individual logic,
 * which lives in the purpose's own domain (`<Domain>/Entities/AI/Conversations/Purposes/`).
 *
 * A purpose has no entity set and no repository, so the entity shorthand `getService()` resolves nothing for it:
 * fetch {@see AIConversationPurposesService} from the container.
 */
class AIConversationPurpose extends Entity
{
    // ===== Background (system-spawned, never startable via the API) =====
    public const string BACKGROUND_MECHANICAL_OPERATION = 'Background.MechanicalOperation';

    // ===== Supervisor (the tandem oversight conversation; system-spawned only) =====
    public const string SUPERVISOR_CHECKPOINT = 'Supervisor.Checkpoint';

    // ===== Schedule (one firing of a task schedule — spawned by the fire path only) =====
    /**
     * The RUN of an {@see \DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule}: the unattended
     * conversation that executes the owner's mission once, reports over the schedule's report channel and closes.
     * Instant (`startBackgroundTask`), timed and recurring firings alike. Started by
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService::spawnRunConversationForClaimedSchedule()} only.
     */
    public const string SCHEDULE_RUN = 'Schedule.Run';

    /**
     * @var string[] The closed registry of purpose names (bijection-tested against the `.md` files). The module
     * registers the three purposes it runs itself; an application declares the names of its own purposes as constants
     * of its purpose class at the same path and redeclares this list with all of them. Read through
     * {@see getAllPurposeNames()} and {@see isRegisteredPurposeName()}, which ask the purpose class of the application
     * ({@see getPurposeClassOfTheApplication()}) — never through the constant of a class a caller happens to name.
     */
    protected const array REGISTERED_PURPOSE_NAMES = [
        self::BACKGROUND_MECHANICAL_OPERATION,
        self::SUPERVISOR_CHECKPOINT,
        self::SCHEDULE_RUN,
    ];

    /**
     * @var string[] The purposes whose conversations are the PLATFORM's own work and never appear in a customer's
     * conversation history, search or recall — they stay fully visible in the admin console. ONE list for every
     * customer-facing reader; a conversation is internal when its own purpose OR its root's purpose is listed. An
     * application redeclares the list with its own hidden purposes added; read through
     * {@see getCustomerHiddenPurposeNames()} and {@see isCustomerHiddenPurposeName()}.
     */
    protected const array CUSTOMER_HIDDEN_PURPOSE_NAMES = [
        self::SUPERVISOR_CHECKPOINT,
        self::BACKGROUND_MECHANICAL_OPERATION,
    ];

    /**
     * @var string[] The purposes whose reached goal is completed and closed without waiting for an owner turn: the
     * recovery reads their idle conversations after a quiet period and runs the close of a reached goal again
     * ({@see shouldCloseConversationAfterSettledTurn()}, {@see completePurposeGoalAndConfirmItIsDurable()}). The
     * module has none; an application lists the purposes whose last act is the owner's last turn. Read through
     * {@see getPurposeNamesClosedWithoutAnOwnerTurn()}.
     */
    protected const array PURPOSE_NAMES_CLOSED_WITHOUT_AN_OWNER_TURN = [];

    /**
     * @var string[] The purposes whose conversation IS a phone call with the owner when nothing else initiated it:
     * the owner is present on the phone for the whole conversation and no live call is read for it. The module has
     * none; read through {@see isPurposeNameHeldAsAPhoneCall()}.
     */
    protected const array PURPOSE_NAMES_HELD_AS_A_PHONE_CALL = [];

    /**
     * @var string|null The purpose this config INHERITS (frontmatter `inheritsFrom`) — the parent
     * whose plan, rail and knobs this purpose runs with; null for a purpose of its own. Traceability only: the
     * admin inspector and the context composition name it next to the purpose.
     */
    public ?string $inheritsFromPurposeName = null;

    /**
     * The progress step key of this purpose's CLOSING act — the only act whose self-contained packages may be
     * delegated in a seeded plan. Null = the purpose has no closing act (nothing of its plan is delegated).
     */
    public function getClosingProgressStepKey(): ?string
    {
        return null;
    }

    /**
     * Whether this purpose SEEDS a task plan of its own (an intake, a staged build) — such a plan is found and worked,
     * never rewritten, and never questioned by the plan-dynamics site. Read from the seed the loop seeds
     * ({@see buildSeedTaskPlanDefinition()}); a purpose's override changes gates, never the item count.
     */
    public function seedsTaskPlan(): bool
    {
        return $this->buildSeedTaskPlanDefinition() !== [];
    }

    /**
     * The seed definition the task plan is built from at creation — the config's items as they are, by default.
     * A purpose that INHERITS another's plan may reshape it here (an intake by phone drops the widget
     * gates of the chat intake's items, keeping everything else) — config data stays single-sourced, the surface
     * difference is code.
     *
     * @return array<int, mixed>
     */
    public function buildSeedTaskPlanDefinition(): array
    {
        return $this->seedTaskPlanItems;
    }

    /**
     * What THIS purpose does when a purposed conversation of `$agentSpace` (null: a conversation without a space,
     * account level) is started or resumed — called by the purposed-conversation start path on BOTH branches (fresh start AND resume) and by an
     * application's own launch paths. The base purpose does nothing; an application's purpose stamps its own records
     * here (for example a STARTED milestone of the account). Implementations are FAIL-SOFT: a hiccup must never take
     * the conversation's spawn down.
     */
    public function afterPurposedStart(?AgentSpace $agentSpace): void
    {
    }

    /**
     * Applies THIS purpose's consequences of a freshly persisted task plan — called by the agent loop after
     * every plan-slice persist. The base purpose has none; a purpose whose seeded items declare consequences
     * overrides this (a purpose class of the application whose item class declares a field of its own: an item that
     * names a step of the application done → mark that step complete in the application's own records).
     * Implementations must be FAIL-SOFT — a consequence failure
     * never kills the turn.
     *
     * @return string|null A NOTICE the loop must surface to the MODEL as a SIGNAL message
     *         ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::SIGNAL_SOURCE_PURPOSE_CONSEQUENCE})
     *         — e.g. "the interface language was just switched, mention it in one line". Null = nothing to surface.
     */
    /**
     * A NOTICE for the model at the START of an owner turn, surfaced like a plan consequence (a PURPOSE_CONSEQUENCE
     * SIGNAL before the first model call). The base purpose has none; a purpose that reads its scope's record loads it
     * itself (over the conversation's `agentSpaceId`). Fail-soft: a failure returns null.
     */
    public function buildTurnStartNotice(AIConversation $conversation): ?string
    {
        return null;
    }

    /**
     * @var int The shortest final text (without its widget blocks) that can carry a draft: a combined draft is several
     * lines; a one-line question or confirmation request is not a draft. An application whose draft gate defines its own
     * length overrides {@see isFreeQuestionUnderDraftApproval()} with it.
     */
    public const int DRAFT_APPROVAL_MIN_LINE_CHARS = 300;

    /**
     * Whether an owner question with the draft-approval catalogue key under `$lineWithoutBlocks` (the final text without
     * its widget blocks, trimmed) is a FREE question: the line showed no draft, so the key is dropped — a nod under it
     * must not unlock what the draft gate gates on the presented draft. Asked of the conversation's purpose, of the base
     * purpose for a conversation without one: the line is shorter than {@see DRAFT_APPROVAL_MIN_LINE_CHARS}.
     */
    public function isFreeQuestionUnderDraftApproval(string $lineWithoutBlocks): bool
    {
        return mb_strlen($lineWithoutBlocks) < static::DRAFT_APPROVAL_MIN_LINE_CHARS;
    }

    /**
     * The widget the platform writes itself when the turn opens, with the signal that tells the model it is in the
     * chat — asked once per turn start by the turn-signal service, which writes it through the one platform-widget
     * writer (that decides the delivery) and appends the signal only for a written widget. The base purpose writes none.
     */
    public function buildWidgetAtTurnOpen(AIConversation $conversation): ?AIConversationPurposeTurnOpenWidget
    {
        return null;
    }

    /**
     * Whether the COMPLETED, non-error result `$resultContent` of the tool `$toolName` ends this purpose: the only
     * correct next move is then ONE closing line, and the loop forces the tool-less step. The base purpose has no such
     * tool.
     */
    public function doesToolResultEndThePurpose(string $toolName, string $resultContent): bool
    {
        return false;
    }

    /**
     * The delegation-result VERDICT of a child conversation of THIS purpose (the text its parent reads when the child's
     * run ends); null = the default verdict (the child's last assistant text). The base purpose has none.
     */
    public function buildDelegationResultVerdict(AIConversation $childConversation): ?string
    {
        return null;
    }

    /**
     * The delegation-result TASK line of a child conversation of THIS purpose; null = the default (the child's
     * delegation task text). The base purpose has none.
     */
    public function buildDelegationResultTaskText(AIConversation $childConversation): ?string
    {
        return null;
    }

    public function applyConsequencesOfPersistedTaskPlan(AIConversation $conversation, ?TaskPlan $taskPlan): ?string
    {
        return null;
    }

    /**
     * The widget types whose block CLOSES this purpose: a final text or a tool row of the turn carrying
     * one hands the turn to the owner like a question does — the checked stop, the delivers-only class and the pause
     * footer read it through {@see AIConversation::hasWidgetTypeAwaitingTheOwnerOrEndingThePurpose()}. A property of
     * the purpose, not of the widget: the same widget may end one purpose and not another. The BASE purpose names none.
     *
     * @return string[] canonical wire names (a widget class's `WIDGET_TYPE`)
     */
    public function getWidgetTypesThatEndThisPurpose(): array
    {
        return [];
    }

    /**
     * The spoken TASK a running voice call is re-briefed with when its call row is re-pointed at a conversation of
     * this purpose (the live voice worker appends it to the session instructions). The BASE purpose has none (null =
     * the call is not re-briefed); only a purpose held as a phone call (an `Intake.Call`) returns its call task.
     */
    public function getSpokenCallTask(): ?string
    {
        return null;
    }

    /**
     * RUN BOOKKEEPING hooks: a purpose that keeps a run record of its own (one row per run of its
     * work) reacts to the loop's run events; the BASE purpose keeps none and does nothing. The loop calls the stop and
     * resumption hooks only for CLAIMED turns (a run token) — the sync CLI / eval path is not a customer run.
     */
    public function recordDelegationBatchOfRun(AIConversation $conversation, AIConversationDelegationBatch $delegationBatch): void
    {
    }

    /** A delegation child of the run's conversation exists (spawned, not dispatched yet); `$taskText` is its task. */
    public function recordDelegationChildOfRun(AIConversation $parentConversation, AIConversation $childConversation, string $taskText): void
    {
    }

    /** The loop stopped without finishing (`$agentStopReason`: the AgentRunReport vocabulary or the monthly-budget code). */
    public function recordStopOfRun(AIConversation $conversation, string $agentStopReason, string $runToken): void
    {
    }

    /** The turn's plan closed BLOCKED without an owner question and without a retryable step. */
    public function recordPlanBlockedStopOfRun(AIConversation $conversation, string $runToken): void
    {
    }

    /** A claimed turn's first model call — a standing stop is over. */
    public function clearStopOfRunOnResumption(AIConversation $conversation, string $runToken): void
    {
    }

    /** An owner message arrived (drive entry) — a run paused on the owner's input resumes. */
    public function resumeRunAfterOwnerInput(AIConversation $conversation): void
    {
    }

    /**
     * Whether this purposed conversation should be CLOSED (runState → COMPLETED, no further customer input)
     * once a turn fully settles with the purpose's goal reached. The BASE purpose
     * never closes; a purpose class of the application closes when the closing item of its seeded plan is
     * done and no open items remain. The agent loop calls this at the fully-settled release point and
     * additionally waits for the supervisor's milestone checkpoint (a pending checkpoint or an unconsumed
     * steer defers the close so the close-review's correction can still run).
     */
    public function shouldCloseConversationAfterSettledTurn(AIConversation $conversation): bool
    {
        return false;
    }

    /**
     * RECONCILE the conversation's live task plan at the fully-settled release point, BEFORE the close is
     * evaluated — the one moment every tick and the turn's answer are durable (a plan-write hook would run
     * between the turn's own tool calls and even on unpersisted candidate text). Mutates the plan on the loaded
     * working state IN PLACE and returns true when it changed something; the loop then persists the slice
     * IDLE-guarded ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistTaskPlanSliceWhileIdle()})
     * and re-reads the close predicate. The BASE purpose reconciles nothing.
     */
    public function reconcileTaskPlanAtSettledTurn(AIConversation $conversation): bool
    {
        return false;
    }

    /**
     * COMPLETE the purpose's goal beyond the conversation and CONFIRM that state is durable: called once
     * {@see shouldCloseConversationAfterSettledTurn()} holds, BEFORE the close and independent of the supervisor
     * (the durable state is what the owner's next click reads). A purpose whose goal has a side effect outside
     * the conversation (a completion record in the application's own data) runs it here — idempotent, fail-soft —
     * and answers whether the durable state now exists; the conversation closes ONLY on true, so a COMPLETED
     * conversation can never sit on unfinished work. False defers both to the next settled turn / the
     * reaper's settle sweep. The BASE purpose has nothing beyond the conversation to complete.
     */
    public function completePurposeGoalAndConfirmItIsDurable(AIConversation $conversation): bool
    {
        return true;
    }

    /** @var string The dotted purpose name = identifier = config path (e.g. 'Schedule.Run'). */
    public string $name = '';

    /** @var string One-line description (frontmatter) — what this purposed conversation is for. */
    public string $description = '';

    /** @var string The purpose directive body — the conversation-specific mission + prohibitions (system segment). */
    public string $directive = '';

    /** @var string[] Skills preloaded at creation (their tools available from turn 1). */
    public array $preloadSkillNames = [];

    /**
     * @var string[] When non-empty, the load_skills / delegate catalog for THIS purpose is RESTRICTED to exactly these
     * skill names — the agent structurally cannot load anything else. A rail-bounded purpose lists only its own act
     * skills so the agent cannot drift onto the general roots (a general skill instead of the purpose's own one), which
     * derailed the run before the gates were reached. Empty = the full entitled catalog (the default for open chat).
     */
    public array $allowedLoadSkillNames = [];

    /** @var bool Pin the preloaded skills non-evictable for the whole conversation (default true). */
    public bool $pinPreloadedSkills = true;

    /**
     * @var array The initial HIERARCHICAL task-plan seed from the purpose config (the single seed source — no
     * code-side seed trees). Each item is either a plain STRING (a criterion-less leaf) or a MAP with `text`
     * (required), `doneCriterion` (optional machine criterion key — lets the seed pre-tick already-satisfied
     * leaves) and `subtasks` (optional nested list of the same shape). Built into the plan via
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems::fromPurposeSeedDefinition()}.
     */
    public array $seedTaskPlanItems = [];

    /**
     * @var array<int, array<string, string>> The customer-facing progress STEPPER declaration: the ordered list of steps the customer UI renders for this purpose's conversations — each
     * entry a map with `key` (stable identifier, e.g. 'identify') and `customerLabel` (the English source
     * string of a JS-scope translation key). Membership of the seeded plan items is declared per item via
     * `customerProgressStepKey` in `seedTaskPlanItems`. Empty = this purpose's conversations render NO stepper
     * (the presence-based frontend contract — see
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\Progress\CustomerProgressSteps}).
     */
    public array $customerProgressSteps = [];

    /**
     * @var string[] Known recurring FAILURE MODES of this purpose's runs — the mistakes an agent tends to make that
     * a later steer cannot undo once they have happened (an already-said wrong offer, an over-promise, a fabricated
     * fact). Surfaced to the SUPERVISOR as a "pitfalls to PRE-EMPT" briefing layer so it can warn
     * the agent AHEAD of the mistake (prevention, not post-hoc correction), and mirrored into the driver skill so the
     * agent avoids them at source. Empty = none declared.
     */
    public array $commonPitfalls = [];

    /** @var string|null The user-INVISIBLE kickoff signal text that drives turn 1 (the agent greets first); null = none. */
    public ?string $kickoffSignalText = null;

    /** @var string[] FeatureFlag values gating whether this purpose may be started for an agent space (empty = ungated). */
    public array $requiredFeatureFlags = [];

    /**
     * @var bool TRUE when this purpose's runs work WITHOUT owner input after the kickoff (a staged build: the
     * owner watches, the agent drives every stage itself). Consequence in the checked-stop bounce: a final
     * answer from a turn that resolved a plan item is normally exempt from bouncing (the resolved-turn answer
     * is typically the question the NEXT item needs — the interview pattern of an intake), but under an autonomous
     * purpose there is no interview — so the exemption is skipped while open items still owe TOOL done-evidence
     * and nothing is blocked awaiting the owner. FALSE (default) keeps the interview-friendly exemption.
     */
    public bool $worksAutonomouslyAfterKickoff = false;

    /** @var int|null Per-turn step cap copied into RunSettings; null = the model/global default. */
    public ?int $maxStepsPerTurn = null;

    /**
     * @var string[] Tool names the executor REFUSES for every conversation of this purpose AND its whole delegation
     * tree (frontmatter `blockedToolNames`) — a structural guard, not a prompt: a build must never change the space's
     * record to make its output fit. The refusal text names the requirement that owns the wish instead.
     */
    public array $blockedToolNames = [];

    /**
     * @var string|null What the model is told when it calls a blocked tool, after the sentence that the tool is not
     * available in this conversation (frontmatter `blockedToolsRefusalText`): why the purpose blocks it and what to do
     * instead. Null = the plain sentence alone.
     */
    public ?string $blockedToolsRefusalText = null;

    /**
     * @var ContextRetentionSettings|null Per-purpose override of the context-retention / capability-eviction knobs,
     * applied to every conversation started for this purpose (null = the global defaults). A SHORT, bounded purpose
     * declares lenient thresholds here so its act SUB-SKILLS are NOT evicted mid-flow: the default
     * `unloadSkillAfterUnusedTokens = 5000` evicts the active act's skill the moment a big tool result (a fetched web
     * page, an import) balloons the context past 5k — which strands the very tools the act still needs. Feeds the
     * eviction sweep as data; the sweep logic itself is untouched.
     */
    public ?ContextRetentionSettings $contextRetentionSettings = null;

    /**
     * @var int|null The WORKING-CONTEXT budget in tokens for every conversation of this purpose (frontmatter
     * `contextBudgetTokens`) — the absolute size the live prompt may grow to before compaction fires,
     * applied as the conversation's {@see CompactionSettings::$triggerTokens} at the purposed start (and healed onto
     * an existing singleton on resume when it carries none). It replaces the global 50k latency ceiling
     * ({@see AIConversationCompactionService::DEFAULT_EFFECTIVE_CONTEXT_TOKENS}) for THIS purpose only: a staged
     * build carries a brief, a plan and several stage contracts at once, which the 50k window compacted away
     * mid-stage. The start path clamps it to 80 % of the conversation model's hard window
     * ({@see AIConversationCompactionService::clampContextBudgetToModelWindow()}) — the context-rot wall stays
     * absolute. It ALSO scales the token-denominated eviction knobs of {@see buildContextRetentionSettingsForConversation()}
     * so the capability layer grows with the history layer. Null = the model/global threshold applies.
     */
    public ?int $contextBudgetTokens = null;

    /**
     * The compaction settings a conversation of this purpose starts with: the {@see $contextBudgetTokens} as the
     * absolute `triggerTokens` (already clamped by the caller to the model's window), everything else inherited.
     * Null when the purpose declares no budget — the conversation then runs on the model/global threshold. Pure.
     */
    public function buildCompactionSettingsForContextBudget(int $effectiveContextBudgetTokens): ?CompactionSettings
    {
        if ($effectiveContextBudgetTokens <= 0) {
            return null;
        }
        $compactionSettings = new CompactionSettings();
        $compactionSettings->triggerTokens = $effectiveContextBudgetTokens;
        return $compactionSettings;
    }

    /**
     * The context-retention settings a conversation of this purpose starts with: the explicit `contextRetention:`
     * map ({@see $contextRetentionSettings}) with its still-unset TOKEN knobs scaled to {@see $contextBudgetTokens}
     * against the 50k reference ({@see ContextRetentionSettings::withTokenKnobsScaledToContextBudget()}). Without a
     * budget the explicit map is returned as-is; without either, null (the global defaults apply). Pure.
     */
    public function buildContextRetentionSettingsForConversation(): ?ContextRetentionSettings
    {
        if ($this->contextBudgetTokens === null || $this->contextBudgetTokens <= 0) {
            return $this->contextRetentionSettings;
        }
        // the calibration reference is the retention layer's OWN constant, decoupled from the compaction
        // ceiling — raising that ceiling must not silently set a 100k purpose's scale factor to 1.
        return ($this->contextRetentionSettings ?? new ContextRetentionSettings())->withTokenKnobsScaledToContextBudget(
            $this->contextBudgetTokens,
            ContextRetentionSettings::RETENTION_REFERENCE_CONTEXT_TOKENS
        );
    }

    /**
     * @var RunSettings|null The run settings every NEW conversation of this purpose starts with (frontmatter
     * `runSettings:`): the wire API (`agentWireApi`), the reasoning
     * carry-over (`previousReasoningCarryOver`, `previousReasoningCapTokens`) and a fixed reasoning effort
     * (`fixedReasoningEffort`). Stamped at the creation into the fields the start's own run settings leave unset — an
     * eval arm or a caller's value wins, an admin override after the start wins, an existing conversation keeps what it
     * was stamped with. Together with the three creation choices of {@see $contextRetentionSettings} it forms the
     * purpose's AGENT WIRE PROFILE, which applies as a whole or not at all
     * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeRunsService::buildStartSettingsForNewPurposedConversation()}).
     * Null = the purpose declares none (the model catalog and the global defaults apply).
     */
    public ?RunSettings $runSettings = null;

    /**
     * The run settings a NEW conversation of this purpose is created with: `$requestedRunSettings` (the start's own —
     * an eval arm, the eval marker; null = none) with every field it leaves unset filled from {@see $runSettings}. Null
     * when neither exists. Pure; the argument is not changed.
     */
    public function buildRunSettingsForNewConversation(?RunSettings $requestedRunSettings): ?RunSettings
    {
        if ($this->runSettings === null) {
            return $requestedRunSettings;
        }
        $runSettingsForNewConversation = $requestedRunSettings !== null ? clone $requestedRunSettings : RunSettings::newInstance();
        $runSettingsForNewConversation->agentWireApi ??= $this->runSettings->agentWireApi;
        $runSettingsForNewConversation->previousReasoningCarryOver ??= $this->runSettings->previousReasoningCarryOver;
        $runSettingsForNewConversation->previousReasoningCapTokens ??= $this->runSettings->previousReasoningCapTokens;
        $runSettingsForNewConversation->fixedReasoningEffort ??= $this->runSettings->fixedReasoningEffort;
        return $runSettingsForNewConversation;
    }

    /**
     * Whether this purpose declares an AGENT WIRE PROFILE: a `runSettings:` map ({@see $runSettings}) or a creation
     * choice in its `contextRetention:` map (skill-loading strategy, epoch rebuild on a situation change, state-change
     * delivery).
     */
    public function declaresAgentWireProfile(): bool
    {
        return $this->runSettings !== null
            || ($this->contextRetentionSettings !== null && $this->contextRetentionSettings->namesSkillLoadingOrStateChangeDeliveryChoice());
    }

    /**
     * {@see buildContextRetentionSettingsForConversation()} without the three creation choices of the agent wire
     * profile — the retention a conversation gets when the profile does not apply (a model that cannot run it): the
     * purpose's eviction knobs stay, the strategy and the state-change delivery fall to the global defaults. Pure.
     */
    public function buildContextRetentionSettingsForConversationWithoutAgentWireProfile(): ?ContextRetentionSettings
    {
        return $this->buildContextRetentionSettingsForConversation()?->withoutSkillLoadingAndStateChangeDeliveryChoices();
    }

    /** @var float|null Per-turn cost cap (USD) copied into RunSettings; null = default. */
    public ?float $maxTurnCostUsd = null;

    /**
     * @var bool The purpose's USER messages are written by the PLATFORM, not by an owner (frontmatter
     * `usesSynthesizedOwnerText`): the supervisor's checkpoint briefing is seeded as USER text. A
     * decision that reads "what the owner wrote" (the skill preload) must never run on such a conversation.
     */
    public bool $usesSynthesizedOwnerText = false;

    /**
     * @var bool The run loads what happened in earlier sessions ITSELF, through its own look-ups (frontmatter
     * `loadsEarlierSessionsItself`): a conversation of this purpose gets no episodic-history snapshot at
     * creation — the snapshot would hand the run a second, older copy of what it is told to load.
     */
    public bool $loadsEarlierSessionsItself = false;

    /**
     * @var bool A conversation of this purpose reads the local situation of its space at creation (frontmatter
     * `readsLocalSituation`). True by default — every conversation with a space; false for a purpose whose work
     * must not carry what goes on around the space this week (e.g. a run that describes the space for the first
     * time). A customer-hidden purpose never reads it.
     */
    public bool $readsLocalSituation = true;

    /**
     * @var string|null The model this purpose PREFERS (frontmatter `preferredModelName`, e.g. `OPENAI.GPT6_LUNA`) —
     * applied as the conversation's model when the caller did not pass an explicit one. The per-purpose half of the
     * model-per-role config: a build with visual output prefers a cheap MULTIMODAL model so its quality child can SEE
     * the result. Null = the global agent default.
     */
    public ?string $preferredModelName = null;

    /**
     * @var string|null The MINIMUM reasoning effort every model call of this purpose's owner turns runs at
     * (frontmatter `minReasoningEffort`: `low` | `medium` | `high` — e.g. a setup purpose pins `medium`).
     * The loop sends the maximum of the turn's decided effort (the decision model, default `low`), this
     * minimum and the next open plan item's `minReasoningEffort`, clamped to the model's ceiling — it raises, never
     * lowers, and a model without a reasoning setting ignores it. Null = no purpose minimum (the turn's effort).
     */
    public ?string $minReasoningEffort = null;

    /**
     * @var string|null The BASE reasoning effort of this purpose's model calls (frontmatter `reasoningEffort`: `low` |
     * `medium` | `high`): what a call
     * is sent with when nothing raises it — in place of the model catalog's agentic effort (`models.php`, the agent
     * default: the catalog is the ONE default, there is no second one in the decision layer).
     * Everything that raises still raises: the decision model's verdict, the row pin, this purpose's
     * `minReasoningEffort`, the next open plan item's `minReasoningEffort`; the model's ceiling still clamps. Null =
     * the catalog value.
     */
    public ?string $reasoningEffort = null;

    /**
     * @var string|null The prompt that ANALYZES a finished conversation of this purpose (frontmatter
     * `analysisPromptName`); null = the default of the application's conversation-analysis config. Every
     * analysis prompt embeds the application's shared verdict core; its own ITEMS are declared in code
     * ({@see getAnalysisItems()}).
     */
    public ?string $analysisPromptName = null;

    /** @var string|null The model of that analysis (frontmatter `analysisModelName`); null = the configured default. */
    public ?string $analysisModelName = null;

    /** @var string The data-defined completion definition (doneCriteria) — for the admin view + the handover gate. */
    public string $doneCriteria = '';

    /**
     * @var bool false = reserved for SYSTEM-spawned conversations (background delegation children): the HTTP
     * get-or-start endpoint rejects the purpose regardless of scope. Default true (a normal, startable purpose).
     */
    public bool $startableViaApi = true;

    /**
     * @var bool true = a COMPLETED or CANCELLED conversation of this purpose still IS the space's singleton
     * (frontmatter `completedConversationStaysSingleton`): the get-or-start path
     * returns it instead of starting a fresh one, so the owner can reopen a finished intake and read it — the
     * chat refuses input on a closed conversation. Default false: a closed conversation makes room for the
     * next one (a build, a nightly digest, a scheduled run).
     */
    public bool $completedConversationStaysSingleton = false;

    /**
     * @var int|null Supervisor: a value above zero marks this purpose as supervised; null or 0 =
     * not supervised ({@see isSupervisedByDeclaration()}). The number itself is not read: the step and settled beats
     * decide when a checkpoint runs.
     */
    public ?int $supervisorCadence = null;

    /**
     * @var int Absolute cap on supervisor runs per conversation; beyond it only top-severity
     * event triggers fire. 0 = supervision fully OFF (no trigger class fires — same semantics as the
     * per-conversation {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$orchestratorRunCapOverride}).
     * Admin-overridable per conversation. Default 8.
     */
    public int $supervisorRunCap = 8;

    /** Whether this purpose is supervised: it declares a {@see $supervisorCadence} above zero (null = not supervised). */
    public function isSupervisedByDeclaration(): bool
    {
        return $this->supervisorCadence !== null && $this->supervisorCadence > 0;
    }

    /**
     * What the supervisor knows about this purpose: the briefing's tool lists
     * and FACTS, the space-state reads and the purpose's rules in the acceptance chain. The base knows none — a
     * supervised purpose returns its own {@see SupervisorPolicy} subclass from its own domain.
     */
    public function buildSupervisorPolicy(): SupervisorPolicy
    {
        return new SupervisorPolicy();
    }

    /**
     * @var bool The purpose asks for the model's FAST service tier on every call (frontmatter `fastMode`): the egress sends `service_tier: "priority"` when the selected model declares
     *      {@see \DDD\Domain\AI\Entities\Models\AIModel::$supportsFastMode}; a model without it is served as before.
     *      Every run with the customer present is fast regardless of this flag
     *      ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::runsWithCustomerPresent()}); the flag keeps
     *      a purpose fast whose runs are not customer-present (an eval harness run, an admin task).
     */
    public bool $fastMode = false;

    /**
     * @var array<int, class-string<TaskPlanDoneCriterion>> The done criteria of this purpose's seeded plan, one class
     * per `doneCriterion` key — {@see getDoneCriteria()} builds the registry from it. A stored key
     * without a class is NO gate. Empty on the base: a purpose without criteria decides nothing.
     */
    public const array DONE_CRITERION_CLASSES = [];

    /**
     * @var string[] The tools this purpose KNOWS to change none of its criteria: the post-write re-sweep after them
     * reads nothing (`[]`), where a tool outside both this list and every criterion's `CHANGED_BY_TOOLS` re-reads every
     * open criterion leaf (`null`).
     */
    public const array TOOLS_CHANGING_NO_DONE_CRITERION = [];

    /**
     * The registry of this purpose's done criteria, built from {@see DONE_CRITERION_CLASSES} for this purpose: every
     * verdict it answers goes through {@see resolveVerdictWithPurposeRules()}.
     */
    public function getDoneCriteria(): TaskPlanDoneCriteria
    {
        return TaskPlanDoneCriteria::forPurpose($this);
    }

    /**
     * The keys of this purpose's criteria whose verdict an ENDED owner turn can change without a tool
     * ({@see TaskPlanDoneCriterion::RESOLVES_ONCE_AN_OWNER_TURN_ENDS}) — re-read when the owner's next message opens a turn.
     *
     * @return string[]
     */
    public function getCriterionKeysResolvedOnceAnOwnerTurnEnds(): array
    {
        $criterionKeys = [];
        foreach (static::DONE_CRITERION_CLASSES as $doneCriterionClass) {
            if ($doneCriterionClass::RESOLVES_ONCE_AN_OWNER_TURN_ENDS) {
                $criterionKeys[] = $doneCriterionClass::KEY;
            }
        }
        return $criterionKeys;
    }

    /**
     * The verdict of one registered criterion under this purpose's own rules — the one step a purpose overrides to apply
     * a rule BEFORE its criteria (one fact of the record that closes several keys at once). The base applies none: the
     * criterion's verdict. Public because the registry ({@see TaskPlanDoneCriteria::forPurpose()}) asks it per verdict.
     */
    public function resolveVerdictWithPurposeRules(TaskPlanDoneCriterion $taskPlanDoneCriterion, TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    {
        return $taskPlanDoneCriterion->resolve($taskPlanDoneCriterionInputs);
    }

    /**
     * What this purpose's criteria read, built ONCE per seed walk, re-sweep or `set_task_status` call, and once per
     * {@see resolveDoneCriterion()} call. The base returns the domain-free inputs (conversation, messages, the LIVE plan); a purpose
     * whose criteria read more returns its own subclass of {@see TaskPlanDoneCriterionInputs}. A service-backed fact is
     * not read here: it sits behind an accessor of that subclass, read at a criterion's first ask
     * ({@see TaskPlanDoneCriterion::GATHERED_FACTS}).
     * The inputs are built over the conversation's space ({@see AIConversation::$agentSpace}; a purpose that reads the
     * record of the space loads it itself); a conversation without a space is judged at account level.
     */
    public function buildDoneCriterionInputs(AIConversation $conversation, ?TaskPlanItems $taskPlanItems): TaskPlanDoneCriterionInputs
    {
        return TaskPlanDoneCriterionInputs::forConversation($conversation, isset($conversation->messages) ? $conversation->messages : null, $taskPlanItems);
    }

    /**
     * The conversation the inputs are built for when the caller passes only its pieces (its id, its space and its
     * messages — a direct call of {@see preResolveSeedTaskPlanItems()} / {@see resolveDoneCriterion()} without the
     * conversation): an unsaved conversation carrying exactly those pieces, never persisted. A null id is a conversation
     * without an id (a transient console drive), a null space a conversation at account level. Public: an application
     * purpose that loads the record of the space itself builds the same conversation.
     */
    public function buildConversationOfTheRecordPieces(?int $conversationId, ?AgentSpace $agentSpace, ?AIConversationMessages $conversationMessages): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = $conversationId;
        $conversation->agentSpaceId = $agentSpace !== null && isset($agentSpace->id) ? (int)$agentSpace->id : null;
        $conversation->agentSpace = $agentSpace;
        $conversation->messages = $conversationMessages;
        return $conversation;
    }

    /**
     * PRE-RESOLVE the freshly-seeded task-plan items against the live state of the space (the account's, without one) — and RE-resolve the
     * still-open ones whenever the conversation's work changed that state (the post-write re-sweep). Generic over
     * the purpose's registered criteria ({@see getDoneCriteria()}): each criterion leaf asks its key LAZILY when the
     * walk reaches it; the base purpose registers none, so all items seed open. The keys are purpose vocabulary,
     * never a global registry. Returns the number of leaves resolved. The conversation's messages (when the
     * sweep runs mid-conversation) stamp the platform's provenance on a resolved leaf.
     * The inputs come from {@see buildDoneCriterionInputs()} ONCE per walk, over `$conversation` when the caller holds
     * it (the record service does), else over a conversation of the passed pieces
     * ({@see buildConversationOfTheRecordPieces()}).
     */
    public function preResolveSeedTaskPlanItems(TaskPlanItems $taskPlanItems, ?AgentSpace $agentSpace, ?AIConversationMessages $conversationMessages = null, ?\DateTimeZone $timezone = null, ?int $conversationId = null, ?array $onlyDoneCriterionKeys = null, ?AIConversation $conversation = null): int
    {
        return $taskPlanItems->applyPreResolutions(
            $this->getDoneCriteria(),
            $this->buildDoneCriterionInputs($conversation ?? $this->buildConversationOfTheRecordPieces($conversationId, $agentSpace, $conversationMessages), $taskPlanItems),
            $conversationMessages,
            $onlyDoneCriterionKeys
        );
    }

    /**
     * The done-criterion keys a COMPLETED call of `$toolName` can have changed — what the post-write re-sweep
     * re-resolves after that tool: `null` = unknown tool, every open criterion leaf is re-read (the safe default);
     * `[]` = a write that touches no criterion, nothing is re-read; otherwise exactly those keys. Generic over the
     * registered criteria' `CHANGED_BY_TOOLS` and {@see TOOLS_CHANGING_NO_DONE_CRITERION}; a purpose without criteria
     * answers null.
     *
     * @return string[]|null
     */
    public function criterionKeysChangedByTool(string $toolName): ?array
    {
        if (static::DONE_CRITERION_CLASSES === []) {
            return null;
        }
        return $this->getDoneCriteria()->getKeysChangedByTool($toolName, static::TOOLS_CHANGING_NO_DONE_CRITERION);
    }

    /**
     * The BACKGROUND WARM-UPS a purpose wants after a loop step whose calls `$completedToolNames` COMPLETED: fire-and-forget mechanical children, one {@see AIConversationPurposeWarmOperation} each
     * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeBackgroundWorkService::buildPurposeWarmSubAgentRequestsAfterStep()}),
     * typically `executeTool` with `noCache: true` so the child recomputes and WRITES the entity cache the model's
     * later plain call hits. The loop asks ONCE per step after every call of the step completed (the warm then sees
     * the whole draft batch) and dedups per conversation, tool name, url and the optional `cacheIdentity` within a
     * turn — an operation names its `cacheIdentity` when the warmed entry's identity follows the record (a hash of
     * the record's fields the entry is built from), so a later write step of the same turn re-warms exactly when the
     * record changed.
     * `$agentSpace` is the conversation's space, null at account level (a purpose that reads the record of the space
     * loads it FRESH, after the step's writes); `$conversation` lets a purpose read its plan (a warm whose plan item is already done serves
     * nobody). The base purpose warms nothing.
     *
     * @param string[] $completedToolNames
     * @param AgentSpace|null $agentSpace
     * @param AIConversation|null $conversation
     * @return AIConversationPurposeWarmOperation[]
     */
    public function backgroundWarmOperationsAfterStep(array $completedToolNames, ?AgentSpace $agentSpace, ?AIConversation $conversation = null): array
    {
        return [];
    }

    /**
     * Whether a model `done` tick on a seeded leaf with a `doneCriterion` is GATED on that criterion (the item accepts
     * `done` only when the criterion resolves satisfied against the live state). The base purpose says no — its
     * criteria are advisory pre-ticks whose evaluation may be UNKNOWN. A purpose whose criteria read a machine record
     * deterministically (a staged build reads its run record) says yes: there a null resolution IS "not satisfied", and
     * a tick by word ("the build is finished") on an empty run is exactly the failure this gate closes
     * (items ticked done while nothing was built).
     */
    public function enforcesDoneCriterionOnTicks(): bool
    {
        return false;
    }

    /**
     * Resolve ONE done criterion against the live state of the space (the account's, without one) — the per-tick twin of
     * {@see preResolveSeedTaskPlanItems()}; null = not satisfied (or unknown; a key without a class is no gate). Generic
     * over the registered criteria; the base purpose registers none. $conversationId lets a purpose read the record
     * BOUND TO THIS CONVERSATION before any fallback over the whole space. The inputs are built as in
     * {@see preResolveSeedTaskPlanItems()}: over `$conversation` when the caller holds it, else over its pieces.
     */
    public function resolveDoneCriterion(string $doneCriterionKey, ?AgentSpace $agentSpace, ?int $conversationId = null, ?AIConversationMessages $conversationMessages = null, ?TaskPlanItems $taskPlanItems = null, ?AIConversation $conversation = null): ?TaskPlanDoneCriterionVerdict
    {
        return $this->getDoneCriteria()->resolveKey(
            $doneCriterionKey,
            $this->buildDoneCriterionInputs($conversation ?? $this->buildConversationOfTheRecordPieces($conversationId, $agentSpace, $conversationMessages), $taskPlanItems)
        );
    }

    /**
     * The live connection state of a channel ({@see TaskPlanItem::CONNECTED_CHANNEL_LABELS}) of `$agentSpace`, read now —
     * the reader behind the `doneRequiresConnectedChannel` tick gate and the `update_task_plan` check: true = connected,
     * false = readable and not connected, null = the state could not be read (never treated as connected). The base
     * purpose has no channels; a purpose with channels asks
     * {@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::findChannelConnectionState()}. A conversation
     * without a space has no channels to read: the caller does not ask.
     */
    public function findChannelConnectionStateOfAgentSpace(AgentSpace $agentSpace, string $connectedChannel): ?bool
    {
        return null;
    }

    /**
     * The VARIANT DIMENSIONS this purpose declares: the lanes of the
     * conversation's situation whose resolved values select the skill sections the model sees. The base purpose
     * declares none — its skills render whole.
     */
    public function getVariantDimensions(): AIConversationVariantDimensions
    {
        return new AIConversationVariantDimensions();
    }

    /**
     * Resolve the declared dimensions from the record — the conversation's messages (completed, grounded tool calls),
     * its space ({@see AIConversation::$agentSpace}; a purpose that reads the record of the space loads it itself), and `$previous` (the values resolved before: an owner-decided value survives a record re-read).
     * `$onlyDimensions` narrows a post-write re-resolve to the dimensions the tool can have changed. The base purpose
     * resolves nothing and returns an empty set.
     *
     * @param string[]|null $onlyDimensions
     */
    public function resolveVariants(AIConversation $conversation, ?AIConversationVariants $previous = null, ?array $onlyDimensions = null): AIConversationVariants
    {
        return new AIConversationVariants();
    }

    /**
     * The variant dimensions a COMPLETED call of `$toolName` can have changed — what the post-write re-resolve
     * re-reads after that tool: `[]` = nothing (the default; no re-resolve), otherwise exactly those dimensions.
     *
     * @return string[]
     */
    public function variantDimensionsChangedByTool(string $toolName): array
    {
        return [];
    }

    /**
     * The SEGMENT values of a conversation of this purpose — the scorecard's segment columns, keyed by
     * the segment keys of the host's conversation measurement; other keys are
     * ignored. The base purpose files nothing. Pure over the conversation's working state.
     *
     * @return array<string, string>
     */
    public function buildRunSegmentValues(AIConversation $conversation): array
    {
        return [];
    }

    /**
     * The ITEMS this purpose's analysis prompt asks: item key => hard (a violated hard item caps the score and
     * fails the conversation). The keys are the closed set the analysis may answer — any other key is dropped. The
     * base purpose asks none.
     *
     * @return array<string, bool>
     */
    public function getAnalysisItems(): array
    {
        return [];
    }

    /**
     * The score from which a conversation of this purpose passes its analysis; null = the configured default. A purpose
     * with an eval suite uses the suite's threshold, so both judges pass and fail on the same line.
     */
    public function getAnalysisPassScore(): ?int
    {
        return null;
    }

    /**
     * The tools whose COMPLETION the analysis receives as a platform fact (what code can decide is not
     * left to the model) — rendered as "completed at #n" / "never completed". The base purpose names none.
     *
     * @return string[]
     */
    public function getAnalysisFactToolNames(): array
    {
        return [];
    }

    /**
     * What the analysis should know about a conversation of this purpose beyond its transcript — one short
     * English block. The base purpose names itself.
     */
    public function buildAnalysisContext(AIConversation $conversation): string
    {
        return "Purpose: {$this->name} — {$this->description}";
    }

    /**
     * The purpose directive as a system segment block: a stable header + the directive body + the tools this purpose
     * blocks ({@see $blockedToolNames}), named to the model with the refusal reason. The executor refuses a blocked
     * call anyway; naming the tools here keeps the model from trying — a refused call is what it would otherwise
     * relay to the owner as "I could not do X". Rendered from the list, so the text can never drift from it.
     */
    public function getDirectiveBlock(): string
    {
        $directiveBlock = "# Conversation purpose: {$this->name}\n\n" . trim($this->directive);
        if ($this->blockedToolNames !== []) {
            $directiveBlock .= "\n\n## Tools not available in this conversation\n\n"
                . implode(', ', array_map(static fn (string $toolName): string => '`' . $toolName . '`', $this->blockedToolNames))
                . ' — never call them here; a call is refused'
                . ($this->blockedToolsRefusalText !== null ? ': ' . rtrim($this->blockedToolsRefusalText, '.') . '.' : '.');
        }
        return $directiveBlock;
    }

    /**
     * The skill names the agent MAY load via load_skills under this purpose's rail — the declared
     * `allowedLoadSkillNames` PLUS the `preloadSkillNames`. The preloaded skills are unioned in so a preloaded skill
     * that has DROPPED from loadedCapabilities (e.g. an evicted, un-pinned root) can be RE-LOADED to self-heal: without
     * this a rail-bounded purpose would offer + accept only its act sub-skills, and once the pinned conductor
     * skill dropped, every sub-skill was unrooted AND the conductor itself was un-reloadable — a deadlock
     * (load a sub-skill -> "root not loaded"; load the root -> "not in this skill set"). Callers that enforce the rail
     * (the load_skills OFFER catalog in `buildToolSchemas` and the LOAD-path dispatch filter) use THIS list, guarded by
     * `allowedLoadSkillNames !== []` (an empty rail = the full entitled catalog, unchanged). A preloaded root is
     * normally pinned + resident, so LoadSkillsTool (which offers only UNLOADED skills) surfaces it ONLY after a drop —
     * exactly the self-heal moment.
     *
     * @return string[]
     */
    public function getLoadableSkillNames(): array
    {
        return array_values(array_unique(array_merge($this->allowedLoadSkillNames, $this->preloadSkillNames)));
    }

    /**
     * Whether this purpose may be started for an agent space with the given available feature-flag values. Pure over
     * the purpose's own requirement list — an empty requirement = always offered.
     *
     * @param string[] $availableFeatureFlagValues
     */
    /**
     * A deterministic APPENDIX for the turn's ACCEPTED FINAL ANSWER, or null. Called for a purposed
     * conversation right before the final answer is stored — the purpose guarantees a MACHINE FACT reaches the
     * owner even when the model omitted it (a staged build appends the link to its result when the turn built
     * successfully but the answer text does not carry it — runs ended on a bare
     * acknowledgment while the result existed). Purely ADDITIVE: an implementation never rewrites the answer,
     * it returns the missing lines (or null when nothing is missing).
     */
    public function buildFinalAnswerAppendix(AIConversation $conversation, string $finalAnswerText): ?string
    {
        return null;
    }

    public function isOfferedWithFeatureFlagValues(array $availableFeatureFlagValues): bool
    {
        foreach ($this->requiredFeatureFlags as $requiredFeatureFlag) {
            if (!in_array($requiredFeatureFlag, $availableFeatureFlagValues, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The purpose class whose lists apply: the application's class at the same path when it has one (it declares the
     * names of the application's purposes and redeclares the lists), else this class.
     *
     * @return class-string<AIConversationPurpose>
     */
    public static function getPurposeClassOfTheApplication(): string
    {
        /** @var class-string<AIConversationPurpose> $purposeClassOfTheApplication */
        $purposeClassOfTheApplication = DDDService::instance()->getContainerServiceClassNameForClass(self::class) ?? self::class;
        return $purposeClassOfTheApplication;
    }

    /** @return string[] The closed registry of registered purpose names — the module's and the application's. */
    public static function getAllPurposeNames(): array
    {
        return (self::getPurposeClassOfTheApplication())::REGISTERED_PURPOSE_NAMES;
    }

    /** @return string[] The purposes whose conversations are never shown to a customer. */
    public static function getCustomerHiddenPurposeNames(): array
    {
        return (self::getPurposeClassOfTheApplication())::CUSTOMER_HIDDEN_PURPOSE_NAMES;
    }

    /** @return string[] The purposes whose reached goal the recovery closes without waiting for an owner turn. */
    public static function getPurposeNamesClosedWithoutAnOwnerTurn(): array
    {
        return (self::getPurposeClassOfTheApplication())::PURPOSE_NAMES_CLOSED_WITHOUT_AN_OWNER_TURN;
    }

    /** True when conversations of $purposeName are the platform's own work and never shown to a customer. */
    public static function isCustomerHiddenPurposeName(?string $purposeName): bool
    {
        return $purposeName !== null && in_array($purposeName, self::getCustomerHiddenPurposeNames(), true);
    }

    /** True when $purposeName is a registered purpose (the closed-list guard for the start paths). */
    public static function isRegisteredPurposeName(string $purposeName): bool
    {
        return in_array($purposeName, self::getAllPurposeNames(), true);
    }

    /** True when a conversation of $purposeName is itself a phone call with the owner. */
    public static function isPurposeNameHeldAsAPhoneCall(?string $purposeName): bool
    {
        return $purposeName !== null
            && in_array($purposeName, (self::getPurposeClassOfTheApplication())::PURPOSE_NAMES_HELD_AS_A_PHONE_CALL, true);
    }
}
