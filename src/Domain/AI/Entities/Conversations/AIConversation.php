<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionRollUpGroup;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionRoundSettings;
use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionModelLimits;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\CompactionThresholdBreakdown;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationMessageFootprints;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionLeafRanges;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionLeafRange;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPrefixEpoch;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentConversationState;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDelivery;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDeliveryBaseValues;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDelta;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateEpoch;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendedSituation;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendOnlyToolSet;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentModelInputHistory;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentProductAwareness;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentTurn;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanReplay;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariant;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\ShownConversationWidgets;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatches;
use DDD\Domain\AI\Entities\Conversations\Agent\Progress\CustomerProgressSteps;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionCover;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionPreservedFacts;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationMessageCompactionContext;
use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionBudgets;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationOwnerTurns;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationToolCallHistory;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationResultSlice;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolResultRetention;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilityOrigin;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedSkillToolDefinitions;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;
use DDD\Domain\AI\Entities\Conversations\Agent\LoadableSubSkillTools;
use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use DDD\Domain\AI\Entities\Conversations\Agent\SubSkillToolDiscovery;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedToolsInputs;
use DDD\Domain\AI\Entities\Skills\AISkillOfferRestrictions;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Entities\Conversations\Reports\AIConversationOwnerReport;
use DDD\Domain\AI\Entities\Conversations\Reports\AIConversationReportObligation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointBriefingHeader;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\AI\Entities\AgentSpaces\AgentOwnerAddressingArguments;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceTimezone;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Services\SystemPrompts\AgentBaseSystemPromptCacheService;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationStateReason;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use DDD\Infrastructure\Traits\Serializer\Attributes\DontPersistProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\HidePropertyOnSystemSerialization;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use RuntimeException;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadToolTool;

/**
 * @method static AIConversationsService getService()
 */
// Reaper index: the tree-wide reaper scans `WHERE runState IN (RUNNING, AWAITING_DELEGATES) AND
// runHeartbeatAt < :hungBefore`, so index (runState, runHeartbeatAt) to keep it off an all-rows scan.
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['runState', 'runHeartbeatAt'])]
// Delegation-batch settle: the fork-join's `COUNT(*) WHERE delegationBatchId = :id AND runState = 'COMPLETED'`.
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['delegationBatchId', 'runState'])]
// Idle-finalize sweeps: mark-finalized's single UPDATE range-scans (finalizedAt IS NULL, lastInboundMessageAt
// < cutoff); compact-finalized's SELECT scans (finalizedAt IS NOT NULL, finalizeCompactedAt < finalizedAt / IS NULL).
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['finalizedAt', 'lastInboundMessageAt'])]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['finalizedAt', 'finalizeCompactedAt'])]
// Owed-turns recovery pass: `runState='IDLE' AND agentTurnOwedAt IS NOT NULL AND < :quietBefore` —
// the self-clearing handover set stays tiny, the index keeps the 5-min scan off all rows.
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['runState', 'agentTurnOwedAt'])]
// Owed-reports recovery pass: `reportDeliveredAt IS NULL AND reportOwedAt IS NOT NULL` — leading NULL
// lookup, then only the undelivered (self-clearing) rows are range-scanned.
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['reportDeliveredAt', 'reportOwedAt'])]
// Start-or-reopen by entry point: `agentSpaceId = :s AND accountId = :a AND entryPointKey = :k` (the conversation an
// entry point opened recently), or `agentSpaceId IS NULL` for the account-level conversations. The lookup does not
// depend on it: until it exists the read runs on `idx_agentSpaceId`.
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['agentSpaceId', 'accountId', 'entryPointKey'])]
// Purposed-run population reads of the admin (`findPurposedRuns` / `reviewPurposeCriteria`):
// `purposeName IN (:names) AND created BETWEEN :from AND :to ORDER BY created DESC`. Until it exists the reads run on
// `idx_purposeName` and filter the window row by row.
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['purposeName', 'created'])]
#[LazyLoadRepo(LazyLoadRepo::DB, repoClass: DBAIConversation::class)]
class AIConversation extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var int|null Id of the Account's who initiated the conversation */
    public ?int $accountId;

    /** @var Account|null Account's who initiated the conversation */
    #[LazyLoad(addAsParent: true)]
    public ?Account $account;

    /**
     * @var int|null Id of the {@see AgentSpace} this conversation works for. Empty = ACCOUNT LEVEL: the conversation
     * works for the account as a whole — the only case in an application without spaces, a regular one next to the
     * spaces in an application that has them. Written when the conversation is created and never changed afterwards
     * (an update keeps the stored value). A read "of the same space" filters on it through
     * {@see AgentSpaceSqlCondition::forAgentSpace()}: an empty reference selects the account-level conversations,
     * never those of all spaces of the account.
     */
    #[DatabaseColumn(onUpdateAction: '`agentSpaceId`')]
    public ?int $agentSpaceId;

    /**
     * @var AgentSpace|null The space this conversation works for; deleting the space deletes its conversations. The
     * core asks about it only through {@see AgentSpacesService}. The repo type is named: a space is a database entity
     * of this package, whatever repo type the application uses by default for a relation without one.
     */
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AgentSpace $agentSpace;

    /** @var AIConversationSummary|null Brief summary of the conversation */
    #[LazyLoad(repoType: LazyLoadRepo::ARGUS)]
    public ?AIConversationSummary $summary;

    /** @var AIConversationMessages|null The messages exchanged in the conversation */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversationMessages $messages;

    /**
     * @var AIConversationCompactionNodes|null The hierarchical compaction TREE — immutable,
     * append-only summary nodes over contiguous message-index ranges. The live + point-in-time context cover is
     * derived in-memory from this set ({@see self::buildCompactionCover()}); the roll-up appends to it.
     */
    // real-value NOT-NULL columns (runState=IDLE, resumeAttempts=0, budgetBypass=false) carry an inline default
    // so `new` and DB-load both have a sane value. This is SAFE for the targeted single-column persists because
    // {@see DBEntity::updatePartialIgnoringRights()} maps ONLY the named columns onto a fresh ORM model — an inline
    // default on any OTHER column never reaches that write set, so it can't clobber a concurrent run-state write.
    // #[LazyLoad] RELATION properties and genuinely-nullable scalars get NO default → they stay uninitialized so
    // their lazy-load __get fires (a `= null` default would permanently break lazy-load) / they load as unset.
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversationCompactionNodes $compactionNodes;

    /** @var int|null Attached AIPromptExtension's id */
    public ?int $aiPromptExtensionId;

    /**
     * @var bool Admin-debug flag: lift the AI-budget CHECK for EVERY turn of this conversation. Set when an
     * admin starts/continues it from the admin console with "Bypass AI budget" on — persisted on the conversation
     * so every appended message inherits it (a budget-spent test account could otherwise not be continued, and there
     * was no per-message place to re-check it). DECIDED only by
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::driveConversation()} (an admin operating
     * the session, the console's --ignore-budget, or the lift of the turn that starts the drive) — a plain customer
     * drive resets it to false. HONOURED as an {@see \DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant} on the AI
     * usage envelope of the work done for this conversation (the turn, a mechanical child's operation, the supervisor
     * tandem, a forked tool call, the background jobs): the grant ends with that work, never a process-wide lift.
     */
    public bool $budgetBypass = false;

    /**
     * @var string|null The model this conversation runs on (the agent default when null) — the admin's "Model" pick in
     * the admin console, OR a per-turn override promoted to conversation-global. Persisted so the ASYNC drive honours it:
     * the resume worker / fan-in / reaper read it (the {@see AIConversationResumeMessage} carries only the id), and the
     * loop applies it as the egress `aiModelNameOverride`. Without this, a background drive dropped the model pick and
     * silently ran the agent default. A delegation child inherits it ({@see inheritDelegationScopeFrom}).
     */
    public ?string $aiModelNameOverride;

    /**
     * @var CompactionSettings|null Per-conversation OVERRIDE of the compaction knobs (threshold in %/tokens, model,
     * keep-recent-turns, summary budget, re-summarize cadence, on/off) — set on ONE conversation to TEST compaction
     * without touching global config (mirrors {@see $aiModelNameOverride}). Null = inherit all global defaults.
     * Persisted as a JSON column (same mechanism as {@see $contextRetentionSettings}). DDL owner-applied.
     */
    public ?CompactionSettings $compactionSettings;

    /**
     * @var ContextRetentionSettings|null Per-conversation OVERRIDE of the SYSTEM-BLOCK footprint knobs (the
     * non-compaction half of context engineering) — tool-result clearing now, skill-body disclosure + deferred tools
     * later. Null = inherit all global defaults ({@see self::CONTEXT_RETENTION_DEFAULT_KEEP_TOOL_RESULTS_RECENT_TURNS} /
     * {@see self::CONTEXT_RETENTION_DEFAULT_TOOL_RESULT_TOKEN_BUDGET}). Sibling of {@see $compactionSettings}; same JSON
     * column mechanism. DDL owner-applied — until the column exists the property stays unset (reads as null → defaults),
     * so the gate is LIVE on defaults and the per-conversation override activates once the column ships.
     */
    public ?ContextRetentionSettings $contextRetentionSettings;

    /**
     * @var RunSettings|null Per-conversation OVERRIDE of the agent-run budget knobs: total steps one
     * user turn may consume across its chunked worker invocations + a USD cost ceiling for the turn. Null = inherit
     * the global defaults (`MAX_STEPS`, no cost cap) — behaviour-identical to the unchunked loop. Sibling of
     * {@see $compactionSettings} / {@see $contextRetentionSettings}; same JSON column mechanism. DDL owner-applied —
     * until the column exists the property stays unset (reads as null → defaults).
     */
    public ?RunSettings $runSettings;

    /**
     * @var AgentWorkingState|null The agent's run-scoped WORKING STATE — initially only the
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan} the model maintains via `update_task_plan`.
     * MERGE column: every persist writes only the touched property slice via a bare carrier
     * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistTaskPlanSlice()}, token-fenced —
     * never a write of this loaded VO). Uninitialized until the DDL ships / nothing was ever written.
     */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_JSON, isMergableJSONColumn: true)]
    public ?AgentWorkingState $agentWorkingState;

    /** The working state, or null while none was written (`isset` — never a lazy load). */
    public function getAgentWorkingState(): ?AgentWorkingState
    {
        return isset($this->agentWorkingState) ? $this->agentWorkingState : null;
    }

    /** The working state; a new, empty one is attached first when none exists (in memory only — the callers persist slices). */
    public function getOrCreateAgentWorkingState(): AgentWorkingState
    {
        $agentWorkingState = $this->getAgentWorkingState();
        if ($agentWorkingState === null) {
            $agentWorkingState = new AgentWorkingState();
            $this->agentWorkingState = $agentWorkingState;
        }
        return $agentWorkingState;
    }

    /** The ACTIVE task plan of the working state, or null. */
    public function getTaskPlan(): ?TaskPlan
    {
        return $this->getAgentWorkingState()?->taskPlan;
    }

    /**
     * Point-in-time task plan as of `$asOfMessageIndex` (the supervisor's plan replay): the purpose SEED as the live loop
     * seeds it ({@see AIConversationPurpose::buildSeedTaskPlanDefinition()} — a purpose by phone strips screen gates)
     * replayed through every loaded plan call up to the index by the outcome the live loop recorded, with the recorded
     * plan retirements ({@see TaskPlanReplay}). Null when there is neither a seed nor a plan call.
     *
     * A COMPACTED conversation whose loaded rows are only the raw tail past the cover edge
     * answers with a copy of the PERSISTED plan instead: it carries every op ever applied — it IS the state as of the
     * newest index — and the tail's ops are not re-applied on top (a replayed tick the live gate rejected could otherwise
     * be accepted against the tail's evidence). A historical replay (evals) loads the full history (first index 0).
     */
    public function buildTaskPlanAsOfMessageIndex(int $asOfMessageIndex): ?TaskPlan
    {
        $firstLoadedMessageIndex = $this->messages->getLowestMessageIndex() ?? PHP_INT_MAX;
        if ($firstLoadedMessageIndex > 0 && $this->getCompactionCoveredUpToIndex() !== null) {
            $liveTaskPlan = $this->getTaskPlan();
            if ($liveTaskPlan !== null) {
                // A deep copy — the briefing renders a snapshot, never the live object.
                return $liveTaskPlan->buildDeepCopy();
            }
        }
        $seedTaskPlan = null;
        $seedTaskPlanDefinition = $this->findPurpose()?->buildSeedTaskPlanDefinition() ?? [];
        if ($seedTaskPlanDefinition !== []) {
            $seedTaskPlan = new TaskPlan();
            $seedTaskPlan->items = TaskPlanItems::fromPurposeSeedDefinition($seedTaskPlanDefinition);
        }
        return TaskPlanReplay::replayUpTo(
            $seedTaskPlan,
            $this->messages,
            $asOfMessageIndex,
            $this->getAgentWorkingState()?->getTaskPlanRetirementMessageIndexes() ?? []
        );
    }

    /**
     * A copy of the PERSISTED task plan when `$asOfMessageIndex` stands at (or past) the newest loaded row, else null — a
     * briefing at the newest row shows the stored plan with the leaves the platform closed from the record, which a
     * replay of the agent's own ticks never closes.
     */
    public function buildTaskPlanCopyWhenAtNewestMessageIndex(int $asOfMessageIndex): ?TaskPlan
    {
        $liveTaskPlan = $this->getTaskPlan();
        if ($liveTaskPlan === null || $liveTaskPlan->items === null) {
            return null;
        }
        if ($asOfMessageIndex < ($this->messages->getNewestMessageIndex() ?? -1)) {
            return null;
        }
        return $liveTaskPlan->buildDeepCopy();
    }

    /**
     * @var CustomerProgressSteps|null The customer-facing progress STEPPER — the
     * derived, wire-only view of the purposed conversation's task-plan progress ({@see buildCustomerProgressSteps()}).
     * NEVER persisted (ignoreProperty — the plan itself is the state); filled by the CLIENT controller on the
     * conversation GET, where it replaces the raw {@see $agentWorkingState} (hidden from customers — its item
     * texts are English agent instructions). Presence-based frontend contract: non-empty = render the stepper.
     */
    #[DatabaseColumn(ignoreProperty: true)]
    public ?CustomerProgressSteps $customerProgressSteps = null;

    /**
     * @var string|null The key of the memoized memory-directives block ({@see findMemoryDirectivesBlock()}): the scope
     *      plus the number of COMPLETED memory-tool rows (remember / updateMemory / forget) of this conversation object —
     *      it moves exactly when a mutation landed. Null = nothing memoized yet. Never persisted; a new worker job holds
     *      a new object.
     */
    #[DatabaseColumn(ignoreProperty: true)]
    protected ?string $memoryDirectivesMemoKey = null;

    /** @var string|null The memoized memory-directives block for {@see $memoryDirectivesMemoKey} (null = no directives). */
    #[DatabaseColumn(ignoreProperty: true)]
    protected ?string $memoryDirectivesMemoBlock = null;

    // ── Async run-state (parallel delegations / self-healing). All default-safe so a row that
    //    predates these columns (or a fully-synchronous CLI run) behaves as IDLE/root with no claim. ──

    /**
     * @var string The async run-state ({@see AIConversationRunState}). The run-claim flips IDLE/stale → RUNNING; a
     * parent parks at AWAITING_DELEGATES while children run; FAILED = retry cap exhausted. Drives the reaper.
     */
    public string $runState = AIConversationRunState::IDLE;

    /**
     * @var string|null Per-run fencing token written by the run-claim UPDATE and re-checked before EVERY append
     * — a redelivered stale worker that lost the claim cannot write. Null when IDLE.
     */
    public ?string $runToken;

    /**
     * @var int|null Id of the ROOT conversation of this delegation tree: for a CHILD it is the root's id,
     * threaded down in {@see spawnDelegationChild()} at spawn. For a ROOT it is NULL — a conversation NEVER references
     * itself as its own root (root-on-self is a design defect). The tree root is therefore always derived as
     * `rootConversationId ?? id` (NULL ⇒ "I am the root"); the Firebase ping and subtree queries use that form.
     */
    public ?int $rootConversationId;

    /** The id of the delegation tree's root: the stored root of a child, the own id of a root (0 while transient). */
    public function getTreeRootConversationId(): int
    {
        return (int)($this->rootConversationId ?? $this->id);
    }

    /**
     * @var int Retry counter: incremented ONLY on reaper/redelivery re-claims, reset on ANY successful step
     * progress. Over the cap → runState = FAILED.
     */
    public int $resumeAttempts = 0;

    /**
     * @var DateTime|null Liveness heartbeat: touched around every blocking external call (model,
     * MCP tool, Argus) AND at every step boundary. "Stale" = no progress past the reaper threshold. Decoupled from
     * message persistence so a long call (no message for minutes) does NOT read as hung.
     */
    public ?DateTime $runHeartbeatAt;

    // ── Idle-finalize state: the two mega-efficient cron sweeps operate on EXACTLY these columns — no
    //    joins, no hydration. All nullable, NO inline defaults (stay uninitialized so targeted persists skip them). ──

    /**
     * @var DateTime|null When the last TURN-OWING inbound (a USER text message OR an injected SIGNAL — both demand a
     * turn, {@see hasUnansweredInboundMessage()}) was persisted. Stamped by
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::recordInboundMessageActivity()} (which also
     * NULLs {@see $finalizedAt} — a resume re-arms the finalize cycle). The mark-finalized sweep's idle criterion.
     */
    public ?DateTime $lastInboundMessageAt;

    /**
     * @var DateTime|null Set by the application's mark-finalized sweep once the conversation has been idle past the
     * configured window (no live run, no new inbound). NULLed on any new inbound. "Finalized" gates the completion
     * compaction (and membership in episode roll-ups).
     */
    public ?DateTime $finalizedAt;

    /**
     * @var DateTime|null When the completion compaction last ran for the CURRENT {@see $finalizedAt} — stamped by the
     * finalize worker after {@see \DDD\Domain\AI\Services\Conversations\AIConversationCompactionService::finalizeConversationCompaction()}.
     * `finalizeCompactedAt < finalizedAt` (or NULL) = completion work owed; a resume + re-finalize re-arms the pair.
     */
    public ?DateTime $finalizeCompactedAt;

    /** @var string An admin-created USER-ABSENT task conversation ({@see $initiatorType}) — runs autonomously through the worker chain. */
    public const string INITIATOR_TYPE_ADMIN_TASK = 'ADMIN_TASK';

    /** @var string One firing of an {@see \DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule} — a user-absent run on the async worker chain. */
    public const string INITIATOR_TYPE_SCHEDULE = 'SCHEDULE';

    /** @var string A conversation created by an authenticated phone call — holds the post-auth voice transcript; the application's call row links to it via aiConversationId. */
    public const string INITIATOR_TYPE_PHONE_CALL = 'PHONE_CALL';

    /** @var string A conversation an external software agent created through the A2A endpoint: no person reads the chat live — no memory curation, no fast tier, no reply-language measurement from the task text. */
    public const string INITIATOR_TYPE_EXTERNAL_AGENT = 'EXTERNAL_AGENT';

    /**
     * @var string[] The initiator types of the runs the platform starts without the owner — never the owner talking.
     * An application that starts further such runs declares their types as `INITIATOR_TYPE_*` constants of its
     * conversation class at the same path and redeclares this list with them added; read through
     * {@see getOwnerAbsentInitiatorTypes()}. An external agent's conversation is not in the list: its caller wrote
     * the task, the platform did not start it.
     */
    protected const array OWNER_ABSENT_INITIATOR_TYPES = [
        self::INITIATOR_TYPE_SCHEDULE,
        self::INITIATOR_TYPE_ADMIN_TASK,
    ];

    /**
     * @var string[] The initiator types of the runs the platform runs for itself: it reads what they write, no person
     * does ({@see AIConversationOrigin::isPlatformOwnRun()}). The module starts no such run by an initiator type (its
     * own are known by their purpose); an application redeclares the list with the types of its own; read through
     * {@see getPlatformOwnRunInitiatorTypes()}.
     */
    protected const array PLATFORM_OWN_RUN_INITIATOR_TYPES = [];

    /**
     * @var string|null WHO initiated this conversation as an autonomous TASK (a forward-
     * compatible discriminator): NULL = a normal customer/user dialog; {@see self::INITIATOR_TYPE_ADMIN_TASK} = an
     * admin-created user-absent task (runs the async worker chain end-to-end — chunking, checked stop, report);
     * {@see self::INITIATOR_TYPE_SCHEDULE} = one firing of an {@see \DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule};
     * later stages add AGENT / GOAL. Drives the admin list filter ("show autonomous tasks") and the delivery
     * rule for normal ends of user-absent runs. Persisted at creation, never changed.
     */
    public ?string $initiatorType;

    /** @var string The characters an entry-point key may hold: letters, digits and `.`, `_`, `:`, `-`. */
    public const string ENTRY_POINT_KEY_PATTERN = '/^[A-Za-z0-9._:-]+$/';

    /**
     * @var string|null The product entry point that opened this conversation, e.g. `hub.starter.reviews` — an opaque
     * key the client sends with the start; the platform never reads a meaning into it. A later start with the same key
     * for the same space by the same account reopens this conversation while it is recent
     * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::startOrReopenConversationAtEntryPoint()}).
     * NOT an initiator: a conversation an entry point opened is a normal customer dialog ({@see $initiatorType} stays
     * NULL). Persisted at creation, never changed. No index of its own — the composite (agentSpaceId, accountId,
     * entryPointKey) on the class serves the lookup.
     */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[Length(max: 255)]
    public ?string $entryPointKey;

    /**
     * @var int|null The {@see \DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule} whose
     * firing spawned this conversation — **a run IS a regular conversation**:
     * this FK is the ONLY schedule-specific thing on a run; the customer chat view and the admin
     * inspector open it like any other conversation. A schedule's run history = `WHERE scheduleId = :id ORDER BY
     * created DESC` (the auto-indexed FK column serves it). NULL on every non-schedule conversation. Persisted at
     * creation, never changed. ONE exception, never persisted: the eval harness stamps
     * {@see self::EVAL_SCHEDULED_RUN_SCHEDULE_ID} (a non-positive id with no row) on a TRANSIENT conversation via
     * {@see markAsEvalScheduledRun()} to replay one firing of a schedule — the run directive and the schedule-admin
     * guard key on this property being set.
     */
    public ?int $scheduleId;

    /**
     * @var string|null The IANA time zone a SCHEDULE-BOUND conversation thinks in: a schedule run copies
     * its schedule's zone at creation (cron digits and the run's clock must match) and its delegation children and
     * supervisor tandem inherit it. Every OTHER conversation does not own a zone — it reads its space's zone at
     * every model request and every tool call ({@see getTimezone()}), so this column stays NULL for it.
     */
    public ?string $timezone = null;

    /**
     * @var bool|null Whether a schedule-bound conversation's zone is unconfirmed (the schedule was stamped from a
     * country default, or a legacy schedule runs in UTC). Rendered as "the owner has not confirmed it" in the time
     * rule. NULL when the conversation is not schedule-bound.
     */
    public ?bool $timezoneUnverified = null;

    /**
     * @var AgentSpaceTimezone|null The zone of the conversation's space as read for the CURRENT model request / tool
     * call — in-memory only, refreshed by {@see refreshAgentSpaceTimezone()}; PROTECTED like
     * {@see $transientToolCallImages} so the schema generator and the serializer never see it.
     */
    #[DontPersistProperty]
    protected ?AgentSpaceTimezone $agentSpaceTimezoneSnapshot = null;

    /** Freeze the zone and its provenance verbatim — schedule-bound conversations only (a run copies its schedule's values). */
    public function freezeTimezone(?string $timezoneIdentifier, ?bool $timezoneUnverified): void
    {
        $this->timezone = $timezoneIdentifier;
        $this->timezoneUnverified = $timezoneUnverified;
    }

    /**
     * Whether this conversation thinks in its OWN frozen columns: a schedule run, a delegation child
     * of one, or the supervisor tandem of one — recognisable by a stored zone together with the schedule provenance.
     * Everything else reads its space.
     */
    public function isScheduleBound(): bool
    {
        return $this->getOrigin()->isScheduleBound();
    }

    /**
     * Re-read the zone of the conversation's space; called at every model request (performTurn) and before every
     * tool call, so a zone written a step earlier is what the next request and the next call see. No-op for a
     * schedule-bound conversation. The read is {@see AgentSpacesService::findTimezoneOfConversation()}: no zone for
     * a conversation without a space, unless the application answers one for the account.
     */
    public function refreshAgentSpaceTimezone(): void
    {
        $this->agentSpaceTimezoneSnapshot = null;
        if ($this->isScheduleBound()) {
            return;
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $this->agentSpaceTimezoneSnapshot = $agentSpacesService->findTimezoneOfConversation($this);
    }

    /**
     * The zone this conversation thinks in RIGHT NOW: the frozen column for a schedule-bound
     * conversation, else the zone of its space as of the last {@see refreshAgentSpaceTimezone()} (read lazily on first
     * use), null when none is known (no conversion).
     */
    public function getTimezone(): ?\DateTimeZone
    {
        if ($this->isScheduleBound()) {
            return new \DateTimeZone($this->timezone);
        }
        if ($this->agentSpaceTimezoneSnapshot === null) {
            $this->refreshAgentSpaceTimezone();
        }
        return $this->agentSpaceTimezoneSnapshot?->getTimezone();
    }

    /** Whether the zone of {@see getTimezone()} is a country default the owner has not confirmed. */
    public function isTimezoneUnverified(): bool
    {
        if ($this->isScheduleBound()) {
            return $this->timezoneUnverified ?? false;
        }
        if ($this->agentSpaceTimezoneSnapshot === null) {
            $this->refreshAgentSpaceTimezone();
        }
        return $this->agentSpaceTimezoneSnapshot?->unverified ?? false;
    }

    /** The schedule id an eval replay of a schedule firing stamps — no such row exists, see {@see markAsEvalScheduledRun()}. */
    public const int EVAL_SCHEDULED_RUN_SCHEDULE_ID = -1;

    /**
     * Mark a TRANSIENT conversation as ONE FIRING OF A TASK SCHEDULE for an eval replay: the same two facts the
     * fire path stamps on a spawned run — the schedule id and the SCHEDULE initiator type — so everything that keys
     * on them behaves as in production: the SCHEDULED_RUN_DIRECTIVE context segment (the run EXECUTES the mission,
     * never administers schedules — {@see \DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule::RUN_DIRECTIVE}),
     * the provenance guard refusing the schedule-admin tools, and the unattended-run discriminator. No schedule row
     * exists for the id and `scheduleId` is a real foreign key, so this is refused on a conversation that has an id:
     * a persisted write would violate the constraint. Same in-memory pattern as `reportChannel` / `runSettings`.
     *
     * @throws RuntimeException on a persisted conversation
     */
    public function markAsEvalScheduledRun(): void
    {
        if (isset($this->id)) {
            throw new RuntimeException('An eval schedule-run replay is stamped on TRANSIENT conversations only — the eval schedule id has no row and must never be persisted.');
        }
        $this->scheduleId = self::EVAL_SCHEDULED_RUN_SCHEDULE_ID;
        $this->initiatorType = self::INITIATOR_TYPE_SCHEDULE;
        // a schedule run is SCHEDULE-BOUND — the replay seeds the transient zone columns from the
        // space (what a schedule created today would be stamped with), so the run thinks in a frozen zone like
        // production. Seeded here from the read the loop would make; a caller may re-freeze a legacy `UTC` case.
        $this->refreshAgentSpaceTimezone();
        if ($this->agentSpaceTimezoneSnapshot !== null) {
            $this->freezeTimezone($this->agentSpaceTimezoneSnapshot->identifier, $this->agentSpaceTimezoneSnapshot->unverified);
        }
    }

    /**
     * @var AIConversationSchedule|null The spawning schedule as a RELATION — enables
     * `expand=schedule` + relation filters on the admin list (e.g. `schedule.oneTimeAt ne 'NULL'` = one-time
     * task runs vs recurring). Lazy — never loaded unless expanded; NULL/unset on non-schedule conversations.
     */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversationSchedule $schedule;

    /**
     * @var string|null The {@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose} this
     * conversation was started FOR (e.g. `Setup.General`), or NULL for a normal chat — the
     * `initiatorType`/`scheduleId` compatible-discriminator pattern. Stamped ONCE at creation; drives exactly
     * three things and nothing else: the seed (preloaded+pinned skills, task plan, kickoff signal), the
     * `PURPOSE_DIRECTIVE` system segment, and the RunSettings copy. A later purpose-file edit never changes a
     * running conversation (reproducible, forensically auditable).
     */
    public ?string $purposeName;

    /**
     * @var int|null Supervisor: on a SUPERVISOR conversation (purpose Supervisor.Checkpoint)
     * this points at the conversation it oversees — the 1:1 tandem link. Indexed: the supervisor-run list for a
     * supervised conversation is one query. NULL on every non-supervisor conversation.
     */
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_CASCADE)]
    public ?int $supervisedConversationId = null;

    /**
     * @var string|null Supervisor: admin per-conversation override of the ORCHESTRATOR
     * model for THIS conversation's supervision (mirrors {@see $aiModelNameOverride} for the turn model). NULL =
     * the ORCHESTRATOR-scope default. Set on the SUPERVISED conversation; the supervisor worker reads it.
     */
    public ?string $orchestratorModelNameOverride = null;

    /**
     * @var int|null Supervisor: admin per-conversation override of the supervisor RUN CAP.
     * NULL = the purpose's declared `supervisorRunCap`. **0 = supervision fully OFF for this conversation** —
     * under a zero cap NO trigger class fires (milestone/event included; you cannot run one checkpoint under a
     * cap of zero) and no existing steer is injected. Used by the supervisor A/B eval (its `--no-supervisor` arm)
     * and by an admin turning supervision off for one conversation. Set on the SUPERVISED conversation.
     */
    public ?int $orchestratorRunCapOverride = null;

    /**
     * @var string|null The REPORT CHANNEL snapshot of an unattended run — where this run's
     * report is delivered beyond the chat message: NULL/`CHAT` = chat only (today's behaviour),
     * {@see \DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule::REPORT_CHANNEL_EMAIL} = email
     * in addition. Snapshot-stamped ONCE at creation from the spawning schedule (the `purposeName` pattern — a
     * later schedule edit never changes a running conversation; schedule-less unattended runs can carry the
     * channel too). With EMAIL the delivery obligation ({@see $reportOwedAt}) is armed AT SPAWN, making the
     * owed-reports recovery pass the universal delivery floor for every failure mode.
     */
    public ?string $reportChannel;

    /**
     * @var DateTime|null The HANDOVER MARKER of the chunked agent run: "an agent turn is OWED to this
     * conversation, and its handover to the next worker is still in flight". Non-null ONLY between a chunk YIELD
     * and the next worker's claim — the relay-baton window.
     *
     * SET (to NOW) in exactly one place: the yield's targeted activity persist
     * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::recordInboundMessageActivity()}, called
     * when the loop appends the TURN_CONTINUATION signal and re-fires — roots and yielded delegation children
     * alike). CLEARED (to NULL) in exactly one place:
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService::claimRun()}'s atomic conditional UPDATE
     * — a WON claim means a worker drives this conversation NOW, so liveness responsibility moves to the
     * claim/heartbeat/reaper machinery. No other writer exists; the admin truncate path clears it when it
     * abandons a branch.
     *
     * SEMANTICS OF A SURVIVING STAMP: the handover message was LOST (the one failure mode IDLE hides from the
     * reaper — RUNNING/AWAITING are heartbeat-watched, IDLE is not). The owed-turns recovery pass
     * (a cron command of the application) therefore selects
     * `runState = 'IDLE' AND agentTurnOwedAt IS NOT NULL AND agentTurnOwedAt < :quietBefore`: every healthy
     * handover clears the stamp within seconds, so anything older than the quiet window IS a lost re-fire →
     * re-dispatch via resumeConversationIfReady(async).
     *
     * SELF-CLEARING BY CONSTRUCTION: every run that ENDS — final answer, accepted checked-stop, step/cost/budget
     * terminal, FAILED — ends through a claimed drive, which already cleared the stamp; a finished conversation
     * can therefore never linger in the recovery pass's candidate set. PLAN-INDEPENDENT on purpose: the stamp tracks the
     * RUN's handover, not the task plan — a run that never created a plan is covered
     * identically.
     *
     * NOT covered here (pre-existing classes with their own recovery): a crashed CLAIMED worker (stale heartbeat →
     * reaper), a lost delegation-child KICKOFF (QUEUED promotion + reaper), a user message whose resume was lost
     * (recover-on-interaction + presence recovery). NULL = nothing owed / pre-feature row.
     */
    public ?DateTime $agentTurnOwedAt;

    /**
     * @var int|null COMPUTED, never persisted: the id of the newest stored message of this conversation (null before the
     * first). Filled only by the run-state read ({@see \DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationReadsTrait}),
     * where a polling client compares it to see a new message while the run state stays the same; uninitialized
     * elsewhere, so no other read serializes it.
     */
    #[DatabaseColumn(ignoreProperty: true)]
    #[DontPersistProperty]
    public ?int $lastMessageId;

    /**
     * @var DateTime|null OBSERVABILITY/policy marker (deliberately NO recovery duty): when the persisted
     * task plan last had ≥1 OPEN item (tree-aware {@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems::hasOpenItems()}).
     * NULL = plan fulfilled (all done/blocked), cleared, or none. Maintained in the SAME statement as every plan
     * slice write ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistTaskPlanSlice()}) —
     * one writer, no divergence. Consumers: admin lists, the derived on-read `taskPlanFulfilled` field.
     */
    public ?DateTime $taskPlanOpenAt;

    /**
     * @var DateTime|null OBSERVABILITY/policy marker: when the persisted task plan last had ≥1
     * blocked item with `retryable: true` (a transient external failure the system may retry later). NULL =
     * none. Maintained with {@see $taskPlanOpenAt} in the same slice statement. Consumer: the retry cron
     * (shipped now so that cron is a pure consumer; it brings its own index when it ships).
     */
    public ?DateTime $taskPlanRetryableAt;

    /**
     * @var DateTime|null The DELIVERY OBLIGATION stamp: a terminal of an unattended/abnormal run
     * owes the customer a report — stamped by EVERY such terminal (graceful step/cost cap, budget FAILED,
     * provider-error FAILED, repeated-call abort, stall give-up, the reaper's max-age expiry UPDATE). The
     * obligation is DATA-carried: whoever dies after stamping, the owed-reports recovery pass finds
     * `reportDeliveredAt IS NULL AND reportOwedAt IS NOT NULL`, composes the template-only {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport}
     * and delivers. NULL = nothing owed (interactive normal end — the answer IS the delivery).
     */
    public ?DateTime $reportOwedAt;

    /**
     * @var DateTime|null When the owed report was DELIVERED (chat message + push + A2A where configured) — stamped
     * targeted BEFORE the external sends, so a crash-after-send redelivery skips re-sending (webhook idempotency)
     * and the phase-R candidate set self-clears. NULL while owed-but-undelivered.
     */
    public ?DateTime $reportDeliveredAt;

    /**
     * @var string|null WHY the platform SUPPRESSED this run's work: the model call,
     * the owed report or its mail was refused because the agent space / account is not eligible for automated AI work
     * — one of the {@see AgentAutomationStateReason} values (the same vocabulary as a schedule's `stateReason`).
     * Stamped together with {@see $suppressedAt}; a suppressed report obligation is also CLOSED (`reportDeliveredAt`
     * stamped) so the owed-reports sweep never re-selects it. NULL = nothing was suppressed.
     */
    #[Choice(callback: [AgentAutomationStateReason::class, 'getAllOfTheApplication'])]
    #[Length(max: 32)]
    public ?string $suppressionReason;

    /** @var DateTime|null When {@see $suppressionReason} was stamped. */
    public ?DateTime $suppressedAt;

    /**
     * @var float The AI costs booked FOR this conversation outside its message rows and outside its tool calls, in
     * USD: the decision calls inside a turn, the activity lines, the history summary, the compaction, the memory
     * extraction, the tool-result injection screening and the user-language detection. Message rows carry the model
     * steps ({@see AIConversationMessage::$costInUSD}), tool calls what a tool books
     * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::$executionCosts}); this column carries the rest,
     * so the conversation total = rows + tool calls + this. Written only by an atomic `+=` (turn workers and
     * background workers book concurrently), on the conversation the work ran for (a sub-agent's turn books on the
     * sub-agent). `onUpdateAction` keeps the stored value on every entity write, so a stale in-memory copy never
     * overwrites a concurrent `+=` ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::addOverheadCostsToConversation()}).
     * No index — it is only read along.
     */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(onUpdateAction: '`overheadCostsInUSD`')]
    public float $overheadCostsInUSD = 0.0;

    /**
     * @var AIUsageBookedCosts|null The same amounts as {@see $overheadCostsInUSD}, split by usage scope
     * ({@see AIUsageBookedCosts::$scopeBookedCosts}) for the admin view. Written by the same atomic statement;
     * entity writes keep the stored value. The column keeps the per-scope amounts keyed by scope; the repository maps
     * between that form and this object ({@see \DDD\Domain\AI\Repo\DB\Conversations\DBAIConversation::mapToEntity()}).
     */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_JSON, onUpdateAction: '`overheadBookedCosts`')]
    public ?AIUsageBookedCosts $overheadBookedCosts = null;

    /**
     * @var string|null The EPISODIC-HISTORY narrative snapshot: "what was recently discussed
     * with this customer, in which sessions" — the rendered recent-window + importance-survivor lines
     * ({@see AIConversations::buildEpisodicHistoryText()}), composed ONCE at conversation creation
     * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::composeAndPersistEpisodicHistory()}) and
     * never rewritten: the ordering key (last activity) and the summaries themselves mutate, so a per-turn recompute
     * would shift the narrative mid-conversation (prompt-cache break + a story that moves under the agent). Rendered
     * into the system content as the `EPISODIC_HISTORY` segment. Null = no history existed at creation / pre-feature
     * conversation / delegation child (children never get one). No index: the text is read with its row, never
     * searched.
     */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_MEDIUMTEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $episodicHistory;

    /**
     * @var int|null FK to the parent's `delegate` tool-call message that this conversation was delegated FROM (the
     * FK-on-the-CHILD model). NULL = a root dialog with a human. NOT unique — ONE `delegate` call carries `tasks[]` and
     * spawns N children that all anchor back to the SAME message. This replaces the old UNIQUE message-side
     * `delegatedConversationId`: the edge now lives on the child, so a message → N children is a plain INVERSE relation
     * ({@see AIConversationMessage::$delegatedConversations}).
     */
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_SET_NULL)]
    public ?int $delegatedFromMessageId;

    /**
     * @var AIConversationMessage|null The parent's `delegate` tool-call message this conversation was delegated FROM —
     * the delegation anchor, a FORWARD FK lazyload via {@see $delegatedFromMessageId} (no addAsParent: the message is in
     * the PARENT conversation; the ancestry climb resolves it via the FK). Null = a root dialog.
     */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversationMessage $delegatedFromMessage;

    /**
     * @var int|null The delegation BATCH this conversation is a MEMBER of (NULL = not a batch member / a root
     * dialog). The fork-join settle counts members by this FK; on the parent's results-reading turn the verdicts are read off them.
     */
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_SET_NULL)]
    public ?int $delegationBatchId;

    /** @var AIConversationDelegationBatch|null The batch this conversation belongs to — the fork-join it settles into. */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversationDelegationBatch $delegationBatch;

    /** @var AIConversationDelegationBatches|null The batches THIS conversation forked (inverse of batch.parentConversationId; admin inspector $expand). */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversationDelegationBatches $forkedDelegationBatches;

    /** Wire the delegated-from anchor in memory AND its FK id (delegate() / batch hydration); keeps object + id in sync. */
    public function setDelegatedFromMessage(?AIConversationMessage $delegatedFromMessage): void
    {
        $this->delegatedFromMessage = $delegatedFromMessage;
        $this->delegatedFromMessageId = $delegatedFromMessage?->id;
    }

    /**
     * Configure THIS (freshly-created, blank) conversation as a delegation child of $parentConversation, anchored on
     * the parent's $delegationCallMessage. Pure in-memory wiring — the service constructs the child
     * shell and persists it; this only copies scope: the account id and the space id always, the account and space
     * OBJECTS only when present on the parent (`isset()` does not trigger a lazy-load). `rootConversationId` threads the delegation-tree ROOT down: the
     * parent's root, or — when the parent IS the root — the parent's own id (a root carries `rootConversationId = NULL`,
     * so `?? id` resolves it). Records the in-memory inverse anchor (child ← delegate message).
     */
    public function inheritDelegationScopeFrom(self $parentConversation, AIConversationMessage $delegationCallMessage): void
    {
        $this->accountId = $parentConversation->accountId ?? null;
        $this->agentSpaceId = $parentConversation->agentSpaceId ?? null;
        $this->rootConversationId = $parentConversation->rootConversationId ?? $parentConversation->id;
        // Inherit the parent's budget-bypass: a delegation child runs in its OWN worker via resumeConversationIfReady,
        // which gates the model call on THIS conversation's budgetBypass — without inheriting it, every child on a
        // budget-exhausted account (e.g. the admin console's budgetBypass turns) dies with AiBudgetExceededException
        // while the parent (which had the bypass) succeeded → the parent then hangs in AWAITING_DELEGATES forever.
        $this->budgetBypass = $parentConversation->budgetBypass ?? false;
        // Inherit the SCHEDULE PROVENANCE for the same reason accountId and budgetBypass are inherited: the schedule
        // budget pot sums a run's message costs INCLUDING its delegation children, and only the run ROOT carries
        // `scheduleId`. Without it on the child, that sum needs a self-join back to the root over
        // `COALESCE(rootConversationId, id)` purely to recover one column the child can simply carry — two of the
        // three filters it applies (accountId, budgetBypass) already sit here. A NULL parent value stays NULL, so a
        // normal chat's children are unaffected.
        $this->scheduleId = $parentConversation->scheduleId ?? null;
        // Inherit the INITIATOR TYPE for the same reason: the fast service tier follows the customer's presence
        // ({@see runsWithCustomerPresent()}), and a delegation forked from a live chat runs for that customer while
        // one forked from a scheduled or background run does not — the child answers like its root without a load.
        $this->initiatorType = $parentConversation->initiatorType ?? null;
        // a SCHEDULE-BOUND parent's frozen zone is inherited (the run's whole tree thinks in the run's
        // zone); a child of a normal chat inherits nothing and reads its space like its parent.
        $this->freezeTimezone(
            $parentConversation->isScheduleBound() ? $parentConversation->timezone : null,
            $parentConversation->isScheduleBound() ? $parentConversation->timezoneUnverified : null
        );
        // Inherit the model: a delegation child runs on the same model the admin picked for the tree (else children
        // would silently fall back to the agent default while the orchestrator ran on the chosen model).
        $this->aiModelNameOverride = $parentConversation->aiModelNameOverride ?? null;
        // NOTE: $compactionSettings is deliberately NOT inherited — its thresholds are about the model-context
        // footprint of ONE conversation's own message tree; a child has an independent tree (per-conversation test
        // scope). Unlike the model override, which children MUST share, there is no compaction analogue.
        // Inherit the STEP budget only: a long run's sub-tasks are equally long, so the child's own loop
        // gets the parent's maxStepsPerTurn. maxTurnCostUsd stays PARENT-ONLY — the parent's turn Σ already counts the
        // children's spend via their settle slices; a per-child copy would double-cap it.
        // A FIXED reasoning effort is inherited as well: it is a measurement setting for the whole run tree — children
        // on another effort would make two runs differ in more than the effort.
        // the wire API, the reasoning carry-over and its cap are inherited too — an A/B arm measures the whole tree.
        if (isset($parentConversation->runSettings)
            && ($parentConversation->runSettings->maxStepsPerTurn !== null || $parentConversation->runSettings->fixedReasoningEffort !== null
                || $parentConversation->runSettings->agentWireApi !== null || $parentConversation->runSettings->previousReasoningCarryOver !== null
                || $parentConversation->runSettings->previousReasoningCapTokens !== null)
        ) {
            $childRunSettings = RunSettings::newInstance();
            $childRunSettings->maxStepsPerTurn = $parentConversation->runSettings->maxStepsPerTurn;
            $childRunSettings->fixedReasoningEffort = $parentConversation->runSettings->fixedReasoningEffort;
            $childRunSettings->agentWireApi = $parentConversation->runSettings->agentWireApi;
            $childRunSettings->previousReasoningCarryOver = $parentConversation->runSettings->previousReasoningCarryOver;
            $childRunSettings->previousReasoningCapTokens = $parentConversation->runSettings->previousReasoningCapTokens;
            $this->runSettings = $childRunSettings;
        }
        // a child loads skills the way its parent does (its own prefix epoch, seeded with its skills) —
        // the parent's stamped strategy, never the global default of the moment
        if (!isset($this->contextRetentionSettings)) {
            $childContextRetentionSettings = new ContextRetentionSettings();
            $childContextRetentionSettings->skillLoadingStrategy = $parentConversation->getSkillLoadingStrategy();
            $childContextRetentionSettings->rebuildEpochOnSituationChange = $parentConversation->rebuildsEpochOnSituationChange();
            // a child delivers volatile changes the way its parent does
            $childContextRetentionSettings->stateChangeDelivery = $parentConversation->getStateChangeDelivery();
            $this->contextRetentionSettings = $childContextRetentionSettings;
        } else {
            $this->contextRetentionSettings->skillLoadingStrategy ??= $parentConversation->getSkillLoadingStrategy();
            $this->contextRetentionSettings->rebuildEpochOnSituationChange ??= $parentConversation->rebuildsEpochOnSituationChange();
            $this->contextRetentionSettings->stateChangeDelivery ??= $parentConversation->getStateChangeDelivery();
        }
        $this->copyLoadedAccountAndAgentSpaceFrom($parentConversation);
        $this->setDelegatedFromMessage($delegationCallMessage);
    }

    /**
     * Attaches what the turn's prompt build reads of the conversation's space
     * ({@see AgentSpacesService::attachAgentSpaceRelations()}; the application decides which relations and loads only
     * those that are missing — a space that is already on the conversation may still lack what the application hangs
     * on it). No-op for a conversation without a space.
     */
    public function loadAgentSpaceRelationsIfMissing(): void
    {
        if (!isset($this->agentSpaceId)) {
            return;
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $agentSpacesService->attachAgentSpaceRelations($this, false);
    }

    /**
     * What the model reads in front of the tool arguments the executor fills from this conversation's space
     * ({@see AgentSpacesService::getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()}); none for a
     * conversation without a space — nothing would fill them, so the tool schema keeps them as declared.
     *
     * @return string[] description prefix by argument name
     */
    public function getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace(): array
    {
        if (!isset($this->agentSpaceId)) {
            return [];
        }
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace();
    }

    /**
     * Copies the account and the space OBJECTS of `$sourceConversation` onto this conversation — only those already
     * loaded or assigned on the source (`isset()` never triggers a lazy load); the scalar ids are copied by the
     * callers.
     */
    protected function copyLoadedAccountAndAgentSpaceFrom(self $sourceConversation): void
    {
        if (isset($sourceConversation->account)) {
            $this->account = $sourceConversation->account;
        }
        if (isset($sourceConversation->agentSpace)) {
            $this->agentSpace = $sourceConversation->agentSpace;
        }
    }

    /**
     * The step budget a fan-out tool declares for its children (edit-flow design ED3b) — set on THIS child's own run
     * settings, so everything the child inherited from its parent (a fixed reasoning effort) stays, and no two
     * siblings share one settings object.
     */
    public function applyToolDeclaredStepBudget(int $maxStepsPerTurn): void
    {
        $runSettings = isset($this->runSettings) ? $this->runSettings : RunSettings::newInstance();
        $runSettings->maxStepsPerTurn = $maxStepsPerTurn;
        $this->runSettings = $runSettings;
    }

    /**
     * Stamp the TANDEM supervision scope onto a fresh conversation so it becomes THE supervisor for
     * `$supervisedConversation`: 1:1 by `supervisedConversationId`, sharing the supervised account +
     * space (usage attribution + read-rights) and its budget posture. NOT a delegation — a supervisor conversation
     * has no `delegatedFromMessageId`, forks no batch, and lives its OWN long-lived tandem history (memory +
     * prompt-cache across checkpoints). The orchestrator model / run-cap overrides carry over so the supervisor is
     * self-contained (the loop never re-reads them off the supervised conversation).
     */
    public function inheritSupervisionScopeFrom(self $supervisedConversation): void
    {
        $this->accountId = $supervisedConversation->accountId ?? null;
        $this->agentSpaceId = $supervisedConversation->agentSpaceId ?? null;
        $this->purposeName = AIConversationPurpose::SUPERVISOR_CHECKPOINT;
        $this->supervisedConversationId = $supervisedConversation->id ?? null;
        // Respect the supervised conversation's budget exemption: a checkpoint must still run when the
        // supervised run bypasses the budget (e.g. an admin-drive), yet its spend is counted like any other turn.
        $this->budgetBypass = $supervisedConversation->budgetBypass;
        $this->orchestratorModelNameOverride = $supervisedConversation->orchestratorModelNameOverride ?? null;
        $this->orchestratorRunCapOverride = $supervisedConversation->orchestratorRunCapOverride ?? null;
        // the tandem of a SCHEDULE-BOUND run judges the run's firing times on the run's frozen zone; the
        // supervisor of a normal chat reads its space like the chat.
        $this->freezeTimezone(
            $supervisedConversation->isScheduleBound() ? $supervisedConversation->timezone : null,
            $supervisedConversation->isScheduleBound() ? $supervisedConversation->timezoneUnverified : null
        );
        $this->copyLoadedAccountAndAgentSpaceFrom($supervisedConversation);
    }

    /** {@see AIConversationMessages::getFirstMessageText()} over this conversation's loaded messages. */
    public function getDelegationTaskText(): ?string
    {
        return ($this->messages ?? null)?->getFirstMessageText();
    }

    /**
     * This delegation CHILD's result slice for the parent's `delegationResults` map — its TASK (seed message)
     * paired with its VERDICT (last assistant text). Each child writes its own one-key slice onto the shared delegate
     * message; the slices MERGE in the DB. Pure read over this child's own messages.
     *
     * @return DelegationResultSlice
     */
    public function buildDelegationResultSlice(): DelegationResultSlice
    {
        // A child of a purpose that writes its own result (the entry the run wrote, never its closing `done`) answers
        // through the purpose's hooks; a purposeless child asks no purpose.
        $childPurpose = ($this->purposeName ?? null) !== null ? $this->findPurpose() : null;
        $purposeVerdict = $childPurpose?->buildDelegationResultVerdict($this);
        if ($purposeVerdict !== null) {
            return DelegationResultSlice::forTaskAndVerdict(
                (string)($childPurpose->buildDelegationResultTaskText($this) ?? $this->getDelegationTaskText() ?? ''),
                $purposeVerdict,
                isset($this->messages) ? $this->messages->getTotalCostIncludingDelegationSlicesUsd() : 0.0
            );
        }
        return DelegationResultSlice::forTaskAndVerdict(
            (string)($this->getDelegationTaskText() ?? ''),
            // The parent's model reads the verdict: resolved ComposedView snapshots fold back to their bindings.
            WidgetBlocks::buildModelFacingText((string)($this->getLastAssistantText() ?? '')),
            // the child's WHOLE-SUBTREE spend rides to the parent on the settle slice — the ONLY path
            // child cost reaches the parent's turn-Σ (each child runs in its own worker; the parent never hydrates
            // the child's messages). Own messages PLUS this child's own delegate slices
            // ({@see AIConversationMessages::getTotalCostIncludingDelegationSlicesUsd()}) — so a grandchild's spend,
            // already merged into this child's delegate slices at ITS fold, attributes upward by induction.
            isset($this->messages) ? $this->messages->getTotalCostIncludingDelegationSlicesUsd() : 0.0
        );
    }

    /**
     * Whether this conversation is the PLATFORM's own work and never shown to a customer: its own purpose is one of
     * {@see AIConversationPurpose::CUSTOMER_HIDDEN_PURPOSE_NAMES}, or it belongs to the delegation tree of such a
     * conversation (a look-up child carries no purpose of its own). The admin console shows it in full.
     */
    public function isHiddenFromCustomer(): bool
    {
        if (AIConversationPurpose::isCustomerHiddenPurposeName($this->purposeName ?? null)) {
            return true;
        }
        $rootConversationId = $this->rootConversationId ?? null;
        if ($rootConversationId === null || (int)$rootConversationId === (int)($this->id ?? 0)) {
            return false;
        }
        return self::getService()->findCustomerHiddenConversationIds([(int)$rootConversationId]) !== [];
    }

    /** Whether the eval runner started this conversation (`runSettings.evalRun`); reads nothing. */
    public function isEvalRun(): bool
    {
        return isset($this->runSettings) && $this->runSettings->evalRun === true;
    }

    /** The run state of this conversation's stored `runState` column, with the run-state questions; reads nothing. */
    public function getRunState(): AIConversationRunState
    {
        return AIConversationRunState::of($this->runState);
    }

    /**
     * Whether this conversation is CLOSED — a terminal `runState` (COMPLETED / CANCELLED) that no customer
     * message may reopen (a finished purposed conversation accepts no further input;
     * new concerns start a new conversation). The ADMIN drive path deliberately ignores this (debugging).
     */
    public function isClosed(): bool
    {
        return $this->getRunState()->isClosed();
    }

    /**
     * The facts about the conversation's space PHP already holds for a decision-model state — read from the LOADED
     * space only ({@see AgentSpacesService::buildStoredFactLines()}, never a lazy load). Shared by the effort site and the preload site (the decision model must
     * see what the chat model sees — the context text of the space is part of it).
     *
     * @return string[]
     */
    public function buildStoredFactLinesForDecisions(): array
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->buildStoredFactLines($this);
    }

    /** Whether the plan's `← next` node belongs to this conversation's purpose's CLOSING act ({@see AIConversationPurpose::getClosingProgressStepKey()}). */
    public function isNextPlanItemInThePurposesClosingAct(): bool
    {
        $closingProgressStepKey = $this->findPurpose()?->getClosingProgressStepKey();
        return $closingProgressStepKey !== null && ($this->getTaskPlan()?->items?->nextMarkedItemHasProgressStepKey($closingProgressStepKey) ?? false);
    }

    /** The purpose this conversation was started for (its stamped `purposeName`), or null for a plain conversation. */
    public function findPurpose(): ?AIConversationPurpose
    {
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        return $aIConversationPurposesService->getPurposeOfConversation($this);
    }

    /**
     * Whether one of `$widgetTypes` hands the turn to the owner: its widget class AWAITS the owner's
     * input ({@see ConversationWidget::AWAITS_OWNER_INPUT}), or it ends this conversation's purpose
     * ({@see AIConversationPurpose::getWidgetTypesThatEndThisPurpose()}). The types are matched EXACTLY as canonical
     * wire names: a caller reading a text passes the canonical types of its closed blocks
     * ({@see AIConversationMessages::findClosedWidgetTypesOfText()}); a tool-written `…Directive` type stays as written
     * and matches nothing, as the name list it replaces did. An unknown type matches nothing.
     *
     * @param string[] $widgetTypes
     */
    public function hasWidgetTypeAwaitingTheOwnerOrEndingThePurpose(array $widgetTypes): bool
    {
        if ($widgetTypes === []) {
            return false;
        }
        $widgetClassesByWidgetType = ConversationWidgetClasses::getWidgetClassesByWidgetType();
        foreach ($widgetTypes as $widgetType) {
            $widgetClass = $widgetClassesByWidgetType[$widgetType] ?? null;
            if ($widgetClass !== null && $widgetClass::AWAITS_OWNER_INPUT) {
                return true;
            }
        }
        return array_intersect($widgetTypes, $this->findPurpose()?->getWidgetTypesThatEndThisPurpose() ?? []) !== [];
    }

    /**
     * Whether the budget closing step's text gets the pause footer (moved from the agent loop): autonomous work remains (a plan with owed open items, or no plan at all — the budget cut mid-work) AND the
     * text does not hand the turn to the owner (no owner question, no asking or closing widget in the text or among the
     * turn's tool-written widgets). A text asking the owner is complete without "continue"; the owner's answer resumes
     * the work.
     */
    public function closingTextNeedsPauseFooter(string $closingText): bool
    {
        $taskPlanItems = $this->getTaskPlan()?->items;
        if ($taskPlanItems !== null && !$taskPlanItems->hasOwedOpenItems()) {
            return false;
        }
        return !AIConversationMessages::textEndsWithOwnerQuestion($closingText)
            && !$this->hasWidgetTypeAwaitingTheOwnerOrEndingThePurpose(AIConversationMessages::findClosedWidgetTypesOfText($closingText))
            // cut at the owner's click: a picker the owner already submitted is answered, the footer stays
            && !$this->hasWidgetTypeAwaitingTheOwnerOrEndingThePurpose($this->messages->findToolEmittedWidgetTypesSinceOwnerInbound())
            // a widget that awaits the owner by its content (a ComposedView with a Form)
            && !AIConversationMessages::textCarriesWidgetWhoseContentAwaitsOwnerInput($closingText)
            && !$this->messages->buildCurrentTurn()->hasToolWrittenWidgetWhoseContentAwaitsOwnerInputSinceOwnerInbound();
    }

    /**
     * Whether the CURRENT turn's own widget hands the turn to the owner — the question the
     * two stall predicates of the agent loop ask. The turn's own widgets are:
     * - the CLOSED, VALID blocks of the model's candidate text `$modelResponseText`
     *   ({@see AIConversationMessages::findClosedWidgetTypesOfText()});
     * - the widgets of the tool and platform rows since the newest owner-authored inbound
     *   ({@see AIConversationTurnMessages::findToolWrittenWidgetTypesSinceOwnerInbound()}) — a widget click starts no
     *   turn, so the widget the owner just answered is cut off there; the turn's recorded `askOwner` question is
     *   the caller's first operand.
     *
     * The origin is decided by the HOST ROW, never by the block's `emittedBy`: only a `MESSAGE_TOOL_MESSAGE` row
     * authored `AUTHOR_TOOL` counts as a tool or platform row. A block inside model text is
     * model-written whatever its `emittedBy` says (the model can write the field into its own block), and it counts
     * here by its type exactly as the same block without the field does.
     */
    public function hasCurrentTurnWidgetAwaitingTheOwnerOrEndingThePurpose(string $modelResponseText): bool
    {
        return $this->hasWidgetTypeAwaitingTheOwnerOrEndingThePurpose(array_values(array_unique(array_merge(
            AIConversationMessages::findClosedWidgetTypesOfText($modelResponseText),
            $this->messages->buildCurrentTurn()->findToolWrittenWidgetTypesSinceOwnerInbound()
        ))))
            // a widget that awaits the owner by its content (a ComposedView with a Form)
            || AIConversationMessages::textCarriesWidgetWhoseContentAwaitsOwnerInput($modelResponseText)
            || $this->messages->buildCurrentTurn()->hasToolWrittenWidgetWhoseContentAwaitsOwnerInputSinceOwnerInbound();
    }

    /**
     * The tools the purpose of this conversation's tree blocks (`blockedToolNames` frontmatter; `Schedule.Run` blocks
     * the owner-messaging send tools): never on the wire, and never named to the model as callable — a "call them
     * directly NOW" naming a blocked tool sent a run into six `load_skills` calls until its budget was gone.
     *
     * @return string[]
     */
    public function findBlockedToolNamesOfConversationTree(?AgentRun $agentRun = null): array
    {
        $purposeOfConversationTree = $this->findPurposeOfConversationTree($agentRun);
        return $purposeOfConversationTree !== null ? $purposeOfConversationTree->blockedToolNames : [];
    }

    /**
     * The purpose that governs this conversation's tool surface: its own purpose, else the purpose of its delegation
     * tree's root ({@see AIConversationPurposesService::findPurposeOfConversationTree()}); inside a run the root's purpose
     * is read once per run.
     */
    public function findPurposeOfConversationTree(?AgentRun $agentRun = null): ?AIConversationPurpose
    {
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        return $aIConversationPurposesService->findPurposeOfConversationTree($this, $agentRun);
    }

    /**
     * The purpose NAME of the delegation tree's root, for the purpose-binding exclusion of a delegation child (a child
     * in one purpose's tree is never offered what another binds); null for a root dialog and while the root cannot be
     * read ({@see AIConversationPurposesService::findRootPurposeNameOfDelegationChild()}).
     */
    public function findRootPurposeNameForBinding(?AgentRun $agentRun = null): ?string
    {
        if (!$this->isDelegation()) {
            return null;
        }
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        return $aIConversationPurposesService->findRootPurposeNameOfDelegationChild($this, $agentRun);
    }

    /**
     * What decides this conversation's skill offer, read once in the order the offer applies it:
     * flags (with the budget policy they carry), rights, billing surface, the purpose binding (the tree root's purpose
     * of a delegation child), the initiator binding, the purpose rail, the effective model's vision.
     */
    public function buildAISkillOfferRestrictions(?AgentRun $agentRun = null): AISkillOfferRestrictions
    {
        $aISkillOfferRestrictions = new AISkillOfferRestrictions();
        $aISkillOfferRestrictions->entitlement = $this->getEntitlement();
        $aISkillOfferRestrictions->purposeName = $this->purposeName ?? null;
        $aISkillOfferRestrictions->rootPurposeName = $this->findRootPurposeNameForBinding($agentRun);
        $aISkillOfferRestrictions->initiatorType = $this->initiatorType ?? null;
        if (($this->purposeName ?? null) !== null) {
            $railPurpose = $this->findPurpose();
            if ($railPurpose instanceof AIConversationPurpose && $railPurpose->allowedLoadSkillNames !== []) {
                $aISkillOfferRestrictions->railLoadableSkillNames = $railPurpose->getLoadableSkillNames();
            }
        }
        $aISkillOfferRestrictions->modelHasVision = $this->findEffectiveModelHasVision();
        return $aISkillOfferRestrictions;
    }

    /**
     * The skill catalog this conversation OFFERS as of `$anchorMessage` (when null: the newest message that carries a
     * capability set — a row written without one, a voice transcript line or an owner row written from an unhydrated
     * view, must not read as "nothing loaded"; a backward search): each message carries its own cumulative loaded
     * set, so a past anchor rebuilds the offer of that turn. The world reads are gathered here; the policy is
     * {@see OfferedSkillCatalog::buildFor()}.
     *
     * @param string[] $assumeLoadedSkillNames skills treated as loaded, so their sub-skills join the offer
     * @param AgentRun|null $agentRun the run that asks (its per-run reads), null outside a run
     */
    public function buildOfferedSkillCatalog(?AIConversationMessage $anchorMessage = null, array $assumeLoadedSkillNames = [], ?AgentRun $agentRun = null): OfferedSkillCatalog
    {
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = AISkill::getService();
        $skillCatalog = $aiSkillsService->getCatalog();
        $anchorMessage ??= $this->messages->getNewestMessageWithLoadedCapabilities();
        $anchorLoadedCapabilities = $anchorMessage?->getLoadedCapabilities();
        return OfferedSkillCatalog::buildFor($skillCatalog, $this->buildAISkillOfferRestrictions($agentRun), $anchorLoadedCapabilities, $assumeLoadedSkillNames);
    }

    /**
     * The tools one model call of this conversation is offered as of `$anchorMessage`:
     * the world reads are gathered here — the offered catalog, the entitlement, the loaded skills' package
     * tools, the tree purpose's blocked tools, the phone line, the plan-dynamics site, the report channel — the offer
     * itself is {@see AgentOfferedTools::buildFor()}. The anchor is the same as the catalog's: null = the newest
     * message that CARRIES a capability set (a voice transcript line or an owner row written from an unhydrated view
     * carries none; a row carrying an EMPTY set is a carrier and stays the anchor), a past message = the point-in-time
     * rebuild of that turn's offer. `$allowThreads` = an orchestrator (`delegate` offered); `$agentRun` = the run that
     * asks (its per-run reads), null outside a run.
     */
    public function buildOfferedTools(bool $allowThreads = true, ?AIConversationMessage $anchorMessage = null, ?AgentRun $agentRun = null): AgentOfferedTools
    {
        $offeredSkillCatalog = $this->buildOfferedSkillCatalog($anchorMessage, [], $agentRun);
        // append-only loading offers the whole reachable catalog in `load_skills` / `delegate` — the
        // same for the whole epoch, so a load never changes the top-level tools
        $loadsSkillsAppendOnly = $this->usesConversationAppend();
        $enumSkillCatalog = $loadsSkillsAppendOnly ? $this->buildReachableSkillCatalog() : $offeredSkillCatalog;
        $anchorMessage ??= $this->messages->getNewestMessageWithLoadedCapabilities();
        $anchorLoadedCapabilities = $anchorMessage?->getLoadedCapabilities();
        $entitlement = $this->getEntitlement(); // the loaded skills and their tool schemas are gated too
        $agentOfferedToolsInputs = new AgentOfferedToolsInputs();
        $agentOfferedToolsInputs->allowThreads = $allowThreads;
        $agentOfferedToolsInputs->hasDelegateCall = $allowThreads && $this->buildToolCallHistory()->getDelegateCallRows() !== [];
        // Offered to a root conversation, a turn opened by sub-agent notifications only, a sub-agent with an
        // unfinished run when the run's loop started (read once by the loop) — a point-in-time rebuild without a run offers none
        $agentOfferedToolsInputs->offersWaitForSubAgents = $allowThreads && !$this->isDelegation()
            && ($agentRun?->hasUnfinishedSubAgentRunAtLoopStart ?? false)
            && $this->messages->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly();
        // While the owner is on the phone line, askOwner and the tool descriptions render their
        // `[surface=phone]` sections instead of the screen ones (the live flag — a point-in-time rebuild shows the current one)
        $ownerPresence = $this->getOwnerPresence();
        $agentOfferedToolsInputs->ownerIsOnThePhoneLine = $ownerPresence === OwnerPresence::PRESENT_ON_THE_PHONE;
        // the widget search on a screen surface only — the chat, or an in-app call with the chat open
        $agentOfferedToolsInputs->offersFindWidgets = !$this->isDelegation()
            && in_array($ownerPresence, [OwnerPresence::PRESENT_IN_CHAT, OwnerPresence::PRESENT_IN_APP_VOICE_CALL], true);
        // The plan this conversation's purpose seeded is worked, never rewritten — no update_task_plan
        $agentOfferedToolsInputs->offersUpdateTaskPlan = $this->getTaskPlan() === null || !($this->findPurpose()?->seedsTaskPlan() ?? false);
        $agentOfferedToolsInputs->finishedTaskPlanCount = $this->getAgentWorkingState()?->countFinishedTaskPlans() ?? 0;
        $agentOfferedToolsInputs->isGuidedByPlanDynamics = $agentOfferedToolsInputs->finishedTaskPlanCount > 0 && $this->isGuidedByPlanDynamics();
        $agentOfferedToolsInputs->reportChannel = $this->reportChannel ?? null;
        $agentOfferedToolsInputs->initiatorType = $this->initiatorType ?? null;
        $agentOfferedToolsInputs->reportIsDelivered = $this->getReportObligation()->isDelivered();
        // Defensive re-check — a LOADED skill may have become locked (a downgrade or a revoked right
        // mid-conversation) or sneaked in past the enum (a forced call): dropping it removes its whole tool surface. A
        // tool may be gated TIGHTER than its skill (a tool behind a flag of its own inside an open skill): a locked
        // tool is offered NEITHER resident NOR via load_tool.
        $loadedSkills = $entitlement->filterSkills($anchorMessage?->findLoadedAISkills() ?? new AISkills());
        $agentOfferedToolsInputs->loadedSkillToolDefinitions = $entitlement->filterToolDefinitions(ToolDefinitions::forPackages($loadedSkills->getPackages()));
        $agentOfferedToolsInputs->residentToolNames = $anchorLoadedCapabilities?->getLoadedToolNames() ?? [];
        $purposeOfConversationTree = $this->findPurposeOfConversationTree($agentRun);
        $agentOfferedToolsInputs->purposeBlockedToolNames = $purposeOfConversationTree !== null ? $purposeOfConversationTree->blockedToolNames : [];
        // only a conversation WITH a space has arguments the executor fills from it — a conversation at account
        // level keeps every argument as declared, the model must name the target
        $agentOfferedToolsInputs->descriptionPrefixesOfToolArgumentsFilledFromAgentSpace = $this->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace();
        $agentOfferedToolsInputs->loadableSubSkillTools = $this->buildLoadableSubSkillTools($offeredSkillCatalog, $anchorLoadedCapabilities);
        $agentOfferedToolsInputs->listsSubSkillToolsByNameOnly = SubSkillToolDiscovery::listsSubSkillToolsByNameOnly();
        $agentOfferedToolsInputs->loadsSkillsAppendOnly = $loadsSkillsAppendOnly;
        // an append-only epoch whose purpose's whole reachable tool set fits the ceiling offers that set
        // and every conditional meta tool from the start — the top-level `tools` never change inside the epoch; a call of
        // a tool whose skill is not loaded yet is refused by the dispatch with the next move
        if ($loadsSkillsAppendOnly) {
            $appendOnlyToolSet = $this->buildAppendOnlyToolSet($agentRun);
            if ($appendOnlyToolSet->offersReachableToolsTopLevel()) {
                $agentOfferedToolsInputs->appendOnlyReachableToolDefinitions = $appendOnlyToolSet->reachableToolDefinitions;
                $agentOfferedToolsInputs->offersConditionalMetaToolsFromTheStart = true;
                $agentOfferedToolsInputs->offersWaitForSubAgents = $agentOfferedToolsInputs->offersWaitForSubAgents || ($allowThreads && !$this->isDelegation());
            }
        }
        return AgentOfferedTools::buildFor($enumSkillCatalog, $agentOfferedToolsInputs);
    }

    /**
     * Which tools this append-only conversation's epoch carries top-level: the tools of the reachable
     * skill catalog ({@see buildReachableSkillCatalog()} — the purpose rail, the sub-skills), gated by the entitlement,
     * without the tree purpose's blocked tools, decided against the configured ceiling
     * ({@see ContextRetentionSettings::resolveAppendOnlyReachableToolSetCeilingTokens()}). Memoized on the run.
     */
    public function buildAppendOnlyToolSet(?AgentRun $agentRun = null): AgentAppendOnlyToolSet
    {
        $memoKey = (string)($this->id ?? spl_object_id($this));
        if ($agentRun !== null && isset($agentRun->appendOnlyToolSetsByConversationKey[$memoKey])) {
            return $agentRun->appendOnlyToolSetsByConversationKey[$memoKey];
        }
        $entitlement = $this->getEntitlement();
        $reachableSkills = $entitlement->filterSkills($this->buildReachableSkillCatalog()->skills);
        $reachableToolDefinitions = $entitlement->filterToolDefinitions(ToolDefinitions::forPackages($reachableSkills->getPackages()));
        $purposeOfConversationTree = $this->findPurposeOfConversationTree($agentRun);
        $purposeBlockedToolNames = $purposeOfConversationTree !== null ? $purposeOfConversationTree->blockedToolNames : [];
        if ($purposeBlockedToolNames !== []) {
            $reachableToolDefinitions = $reachableToolDefinitions->withoutNames($purposeBlockedToolNames);
        }
        $appendOnlyToolSet = AgentAppendOnlyToolSet::decideFor(
            $reachableToolDefinitions,
            $this->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace(),
            ContextRetentionSettings::resolveAppendOnlyReachableToolSetCeilingTokens()
        );
        if ($agentRun !== null) {
            $agentRun->appendOnlyToolSetsByConversationKey[$memoKey] = $appendOnlyToolSet;
        }
        return $appendOnlyToolSet;
    }

    /**
     * The tools of the offered, not yet loaded sub-skills of `$loadedCapabilities`' roots
     * ({@see OfferedSkillCatalog::buildLoadableSubSkillTools()}); the two world reads — the sub-skill discovery switch
     * and the loaded skills' registry entries — are made here. Empty without a loaded root or with the surface off.
     *
     * @return LoadableSubSkillTools every such tool with its sub-skill and one-line summary
     */
    public function buildLoadableSubSkillTools(OfferedSkillCatalog $offeredSkillCatalog, ?LoadedCapabilities $loadedCapabilities): LoadableSubSkillTools
    {
        if ($loadedCapabilities === null || $loadedCapabilities->getLoadedSkillNames() === [] || !SubSkillToolDiscovery::offersToolsThroughLoadTool()) {
            return new LoadableSubSkillTools();
        }
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = AISkill::getService();
        return $offeredSkillCatalog->buildLoadableSubSkillTools($loadedCapabilities, $aiSkillsService->findLoadedSkillsOf($loadedCapabilities), $this->getEntitlement());
    }

    /** Whether the plan-dynamics site GUIDES this conversation (switch 2 on, resolved through every layer). */
    public function isGuidedByPlanDynamics(): bool
    {
        return AgentDecisionModelSettings::current()->siteModeForConversation(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $this) === AgentDecisionModelSettings::MODE_ON;
    }

    /**
     * The names this conversation's plan and acting user permit (feature flags AND account rights), request order —
     * the seeding paths' entitlement gate (purpose and structural preloads, delegation and background child seeds, the
     * plan-gate lock). A conversation with neither flags nor rights is ungated: the names come back as given.
     *
     * @param string[] $skillNames
     * @return string[]
     */
    public function findPermittedSkillNames(array $skillNames): array
    {
        $entitlement = $this->getEntitlement();
        if ($entitlement->isUngatedByFlagsAndRights()) {
            return $skillNames;
        }
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = AISkill::getService();
        return $entitlement->filterPermittedSkillNames($aiSkillsService->resolveRequestedSkills($skillNames, []));
    }

    /**
     * The names this conversation's effective model can use ({@see findEffectiveModelHasVision()}) — a
     * delegation spawn asks the CHILD, whose model a fan-out tool may pin.
     *
     * @param string[] $skillNames
     * @return string[]
     */
    public function findVisionCapableSkillNames(array $skillNames): array
    {
        if ($skillNames === [] || $this->findEffectiveModelHasVision()) {
            return array_values($skillNames);
        }
        $aISkillOfferRestrictions = new AISkillOfferRestrictions();
        $aISkillOfferRestrictions->modelHasVision = false;
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = AISkill::getService();
        return $aISkillOfferRestrictions->filterVisionCapableSkillNames($aiSkillsService->resolveRequestedSkills($skillNames, []));
    }

    /**
     * The names this conversation's purpose and initiator bind in ({@see AISkillOfferRestrictions::filterBoundSkillNames()})
     * — a child seed is judged on its PARENT's purpose.
     *
     * @param string[] $skillNames
     * @param AgentRun|null $agentRun the run that asks (its per-run reads), null outside a run
     * @return string[]
     */
    public function findBoundSkillNames(array $skillNames, ?AgentRun $agentRun = null): array
    {
        if ($skillNames === []) {
            return [];
        }
        $aISkillOfferRestrictions = new AISkillOfferRestrictions();
        $aISkillOfferRestrictions->purposeName = $this->purposeName ?? null;
        $aISkillOfferRestrictions->rootPurposeName = $this->findRootPurposeNameForBinding($agentRun);
        $aISkillOfferRestrictions->initiatorType = $this->initiatorType ?? null;
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = AISkill::getService();
        return $aISkillOfferRestrictions->filterBoundSkillNames($aiSkillsService->resolveRequestedSkills($skillNames, []));
    }

    /**
     * The names inside this conversation's purpose rail — a purpose declaring `allowedLoadSkillNames`
     * bounds every load, a structural preload included; without a rail the names come back as given.
     *
     * @param string[] $skillNames
     * @return string[]
     */
    public function findRailedSkillNames(array $skillNames): array
    {
        if (($this->purposeName ?? null) === null || $skillNames === []) {
            return $skillNames;
        }
        $railPurpose = $this->findPurpose();
        if (!$railPurpose instanceof AIConversationPurpose || $railPurpose->allowedLoadSkillNames === []) {
            return $skillNames;
        }
        $aISkillOfferRestrictions = new AISkillOfferRestrictions();
        $aISkillOfferRestrictions->railLoadableSkillNames = $railPurpose->getLoadableSkillNames();
        return $aISkillOfferRestrictions->filterRailedSkillNames($skillNames);
    }

    /**
     * Everything this conversation COULD be offered once its roots are loaded ({@see OfferedSkillCatalog::buildReachableFor()})
     * — for a reader that asks whether a skill is available to the conversation's space, purpose and user at all.
     */
    public function buildReachableSkillCatalog(): OfferedSkillCatalog
    {
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = AISkill::getService();
        $anchorMessage = $this->messages->last();
        $anchorLoadedCapabilities = $anchorMessage?->getLoadedCapabilities();
        return OfferedSkillCatalog::buildReachableFor($aiSkillsService->getCatalog(), $this->buildAISkillOfferRestrictions(), $anchorLoadedCapabilities);
    }

    /**
     * The catalog model of this conversation's {@see $aiModelNameOverride}, or null without an override
     * (an empty name counts as none — never a catalog read) or for a name the catalog does not know. `find`: crosses
     * into the model catalog ({@see AIModelsService::getAIModelByName()}).
     */
    public function findModelOverride(): ?AIModel
    {
        $modelName = $this->aiModelNameOverride ?? null;
        if ($modelName === null || $modelName === '') {
            return null;
        }
        /** @var AIModelsService $aiModelsService */
        $aiModelsService = DDDService::instance()->getService(AIModelsService::class);
        return $aiModelsService->getAIModelByName($modelName);
    }

    /** What this conversation's model allows its compaction: {@see CompactionModelLimits::forModel()} of {@see findModelOverride()}. */
    public function findCompactionModelLimits(): CompactionModelLimits
    {
        return CompactionModelLimits::forModel($this->findModelOverride());
    }

    /**
     * Whether this conversation's effective model can see images — FAIL-OPEN: only a per-conversation
     * {@see $aiModelNameOverride} the model catalog positively knows as blind answers false. Without an override the
     * egress resolves the model elsewhere (eval / scope defaults), and the model-level wire gate degrades any picture on
     * a genuinely blind model anyway.
     */
    public function findEffectiveModelHasVision(): bool
    {
        $overrideModelName = $this->aiModelNameOverride ?? '';
        if ($overrideModelName === '') {
            return true;
        }
        /** @var AIModelsService $aiModelsService */
        $aiModelsService = DDDService::instance()->getService(AIModelsService::class);
        return $aiModelsService->getAIModelByName($overrideModelName)?->hasVisionCapabilities !== false;
    }

    /**
     * A delegation child that runs ONE tool call without a model: a delegation whose purpose is
     * the tool-run purpose (formerly `Background.MechanicalOperation`). The loop asks this —
     * no purpose name in the loop.
     */
    public function isToolRunDelegationChild(): bool
    {
        return $this->isDelegation() && ($this->purposeName ?? null) === AIConversationPurpose::BACKGROUND_MECHANICAL_OPERATION;
    }

    /** Whether this conversation is a sub-agent delegation (vs a root dialog with a human). */
    public function isDelegation(): bool
    {
        return $this->getOrigin()->isDelegation();
    }

    /**
     * Whether this conversation sits BELOW the root of its delegation tree — the conversation form of
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance::isInDelegationChild()} (a stored root
     * that is not this conversation's own id). The widget writer's DELEGATION_CHILD test for the platform's widgets:
     * nobody reads a child's transcript.
     */
    public function isDelegationChild(): bool
    {
        return $this->getOrigin()->isDelegationChild();
    }

    /**
     * Whether every model call of this conversation stores its FULL sent payload and the raw response on its request
     * snapshot: the retention setting ({@see ContextRetentionSettings::shouldStoreRequestSnapshot()}) or the run's
     * measurement setting ({@see RunSettings::$storeRequestSnapshot}, the eval arm `--store-snapshot`).
     */
    public function storesFullRequestSnapshots(): bool
    {
        return (isset($this->contextRetentionSettings) && $this->contextRetentionSettings->shouldStoreRequestSnapshot())
            || (isset($this->runSettings) && $this->runSettings->storeRequestSnapshot === true);
    }

    /**
     * The report this conversation's run owes, from the loaded `reportOwedAt` / `reportDeliveredAt` columns — reads nothing. `?? null` is safe on an uninitialized typed column.
     */
    public function getReportObligation(): AIConversationReportObligation
    {
        $aIConversationReportObligation = new AIConversationReportObligation();
        $aIConversationReportObligation->owedAt = $this->reportOwedAt ?? null;
        $aIConversationReportObligation->deliveredAt = $this->reportDeliveredAt ?? null;
        return $aIConversationReportObligation;
    }

    /**
     * The conversation class of the running application: its same-path subclass when it has one, else this class. The
     * initiator types of the application's own runs and the two lists of them are constants of THAT class.
     *
     * @return class-string<AIConversation>
     */
    public static function getConversationClassOfTheApplication(): string
    {
        /** @var class-string<AIConversation> $conversationClassOfTheApplication */
        $conversationClassOfTheApplication = DDDService::instance()->getContainerServiceClassNameForClass(self::class) ?? self::class;
        return $conversationClassOfTheApplication;
    }

    /** @return string[] {@see OWNER_ABSENT_INITIATOR_TYPES} of the running application. */
    public static function getOwnerAbsentInitiatorTypes(): array
    {
        return (self::getConversationClassOfTheApplication())::OWNER_ABSENT_INITIATOR_TYPES;
    }

    /** @return string[] {@see PLATFORM_OWN_RUN_INITIATOR_TYPES} of the running application. */
    public static function getPlatformOwnRunInitiatorTypes(): array
    {
        return (self::getConversationClassOfTheApplication())::PLATFORM_OWN_RUN_INITIATOR_TYPES;
    }

    /**
     * Who started this conversation and who is present in it, from the loaded columns — reads nothing.
     * The delegation anchor is the FK COLUMN `delegatedFromMessageId` (always loaded with the conversation), never the
     * lazy relation `delegatedFromMessage`: on an async resume the child is loaded by id and that relation is not
     * hydrated, so a delegation child would be driven as a ROOT dialog. `?? null` is safe on an
     * uninitialized typed column (no lazy load, no "accessed before initialization").
     */
    public function getOrigin(): AIConversationOrigin
    {
        $aIConversationOrigin = new AIConversationOrigin();
        $aIConversationOrigin->initiatorType = $this->initiatorType ?? null;
        $aIConversationOrigin->purposeName = $this->purposeName ?? null;
        $aIConversationOrigin->conversationId = isset($this->id) ? (int)$this->id : null;
        $aIConversationOrigin->delegatedFromMessageId = $this->delegatedFromMessageId ?? null;
        $aIConversationOrigin->rootConversationId = $this->rootConversationId ?? null;
        $aIConversationOrigin->scheduleId = $this->scheduleId ?? null;
        $aIConversationOrigin->supervisedConversationId = $this->supervisedConversationId ?? null;
        $aIConversationOrigin->timezone = $this->timezone ?? null;
        return $aIConversationOrigin;
    }

    /**
     * Is a live OWNER present in this run — the gate for memory curation. Memory is the durable
     * RESIDUAL of what the OWNER SAID (DIRECTIVE/FACT/SOLUTION, owner-intent-derived), so a run with no owner
     * turns has nothing legitimate to harvest and must not curate (waste + fabrication risk).
     *  - NULL initiatorType = a normal chat dialog → owner present.
     *  - {@see INITIATOR_TYPE_PHONE_CALL} = the agent CALLED the owner → a live owner IS on the line stating
     *    things → owner present (the one user-facing autonomous class).
     *  - {@see INITIATOR_TYPE_SCHEDULE} / {@see INITIATOR_TYPE_ADMIN_TASK} = user-ABSENT background runs → NOT.
     *  - {@see INITIATOR_TYPE_EXTERNAL_AGENT} = a software agent's requests, no owner's own words → NOT.
     * A supervisor conversation (purpose Supervisor.Checkpoint) contains no owner content at all → also NOT,
     * handled by the purpose check at the curate gate rather than here.
     */
    public function isUserPresentRun(): bool
    {
        return $this->getOrigin()->isUserPresentRun();
    }

    /** The supervisor run cap of a purpose-less conversation (free dialog) while supervision is on globally. */
    public const int PURPOSELESS_SUPERVISOR_RUN_CAP = 8;

    /** Whether this conversation is a supervisor TANDEM (purpose `Supervisor.Checkpoint`) — it is never supervised itself. */
    public function isSupervisorTandem(): bool
    {
        return ($this->purposeName ?? null) === AIConversationPurpose::SUPERVISOR_CHECKPOINT;
    }

    /**
     * Whether this conversation can be supervised at all, from its loaded columns (no tandem read, no cap): a stored
     * conversation that is no delegation child, no tandem itself and has the owner present.
     */
    public function canBeSupervised(): bool
    {
        return isset($this->id) && !$this->isDelegation() && !$this->isSupervisorTandem() && $this->isUserPresentRun();
    }

    /**
     * Whether this conversation's purpose lets it be supervised: a purpose that declares a supervisor cadence above
     * zero ({@see AIConversationPurpose::isSupervisedByDeclaration()}); a purpose-less conversation (free dialog) always.
     */
    public function isSupervisableByPurpose(): bool
    {
        $purpose = $this->findPurpose();
        return $purpose === null || $purpose->isSupervisedByDeclaration();
    }

    /**
     * The supervisor run cap of this conversation: the admin / eval override when set (an override ABOVE zero arms the
     * conversation even while supervision is off globally — the admin console and the suite's `--supervisor` arm; zero
     * turns it off), else 0 while supervision is OFF globally, else the purpose's declared cap
     * ({@see PURPOSELESS_SUPERVISOR_RUN_CAP} without a purpose). The caller reads the global switch.
     */
    public function resolveSupervisorRunCap(bool $supervisionEnabledGlobally): int
    {
        if (($this->orchestratorRunCapOverride ?? null) !== null) {
            return (int)$this->orchestratorRunCapOverride;
        }
        if (!$supervisionEnabledGlobally) {
            return 0;
        }
        $purpose = $this->findPurpose();
        return $purpose !== null ? $purpose->supervisorRunCap : self::PURPOSELESS_SUPERVISOR_RUN_CAP;
    }

    /**
     * The beat class a supervisor checkpoint of this (supervised) conversation owes RIGHT NOW: a SETTLED beat when no
     * turn runs and the newest wire message is the agent's final text — the owner has read the answer; otherwise a
     * STEP beat (a running turn, a tool batch without its text yet).
     */
    public function getSupervisorBeat(): string
    {
        return !$this->getRunState()->holdsALiveTurn() && $this->lastWireMessageIsFinalAssistantText()
            ? SupervisorCheckpointBriefingHeader::BEAT_SETTLED
            : SupervisorCheckpointBriefingHeader::BEAT_STEP;
    }

    /**
     * Who the agent is talking to in this conversation (owner channel awareness) — derived, never
     * persisted, in this order:
     *  1. a delegation child → null (its wire is its own, the root carries the presence) — except while the ROOT has
     *     an answered call over the phone line: then the child is on the phone too (its output reaches the
     *     caller through the root's turn, so it must not write for a screen);
     *  2. a customer-hidden purpose, or an initiator type the application runs for itself → the platform's own run;
     *  3. a phone-call conversation, or ANY conversation with a live answered call on it (the owner is on the line
     *     while the agent works in a chat conversation), or a phone purpose → on the phone when the call runs over the
     *     phone line, in an in-app call when it runs in the app (the app keeps the screen); a phone-call
     *     conversation whose call has ended keeps its PHONE_CALL initiator — the newest owner
     *     text row in `$messagesInContext` then decides: a row typed from a chat device (the owner opened the call's
     *     transcript and wrote) → present in the chat; a spoken row or none → read later (the voicemail follow-up);
     *  4. an external agent's conversation → working for an external agent;
     *  5. a schedule firing, an admin task, or a purpose-less `Schedule.Run` → a scheduled run;
     *  6. no initiator (the chat, a purposed chat, the admin console) → present in the chat;
     *  7. anything else → unknown origin, nobody assumed present.
     * The live-call probe reads the call repo once per {@see LIVE_CALL_PROBE_TTL_SECONDS} per conversation object
     * (every model step of a turn builds the context anew); a transient conversation without an id never reaches it.
     *
     * @param AIConversationMessage[]|null $messagesInContext the context's loaded tail, oldest first
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getOwnerPresence(?array $messagesInContext = null): ?OwnerPresence
    {
        $aIConversationOrigin = $this->getOrigin();
        $liveCallChannelType = $aIConversationOrigin->readsTheLiveCallForOwnerPresence() ? $this->findLiveAnsweredCallChannelType() : null;
        return $aIConversationOrigin->buildOwnerPresence($liveCallChannelType, self::findNewestOwnerDeviceInMessages($messagesInContext ?? []));
    }

    /**
     * Whether this conversation's model calls are taught the `ComposedView` kit and the widgets written without a skill —
     * the turn-level twin of {@see AgentModelInput::teachesComposedViewKit()}: a root whose owner presence is one of
     * {@see AgentModelInput::OWNER_PRESENCES_TAUGHT_THE_COMPOSED_VIEW_KIT} (the phone line is its own presence), read
     * with the loaded messages as the context build reads it — the owner's device on the newest owner row tells a phone
     * conversation continued in the chat from one left after the call. The direct-widget decision and the typed
     * final-text checks read it.
     */
    public function isTaughtTheComposedViewKit(): bool
    {
        return !$this->isDelegation()
            && in_array($this->getOwnerPresence(isset($this->messages) ? $this->messages->getElements() : null), AgentModelInput::OWNER_PRESENCES_TAUGHT_THE_COMPOSED_VIEW_KIT, true);
    }

    /**
     * The widget the CURRENT owner turn's answer is written as: the applied direct-widget
     * decision of the newest initiator ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecision::getDecidedWidgetType()}),
     * null for another turn's decision, a record-only one or none. Pure over the loaded working state.
     */
    public function getDecidedDirectWidgetTypeOfCurrentTurn(): ?string
    {
        $directWidgetDecision = $this->getAgentWorkingState()?->directWidgetDecision;
        if ($directWidgetDecision === null || !isset($this->messages)
            || $directWidgetDecision->ownerMessageIndex !== $this->messages->getNewestInitiatorMessageIndex()
        ) {
            return null;
        }
        return $directWidgetDecision->getDecidedWidgetType();
    }

    /**
     * The transient `surface` dimension: the newest owner TEXT row with a device in the
     * context's messages decides it; the rendered variants are a copy so {@see AgentWorkingState::$variants} never
     * carries it. Signal rows are skipped (a click carries no words).
     */
    protected function mergeSurfaceVariantIntoContext(AgentModelInput $agentContext, bool $ownerIsOnThePhoneLine = false): void
    {
        if ($ownerIsOnThePhoneLine) {
            $this->putSurfaceVariantIntoContext($agentContext, OwnerDevice::SURFACE_PHONE, null);
            return;
        }
        for ($position = count($agentContext->messages) - 1; $position >= 0; $position--) {
            $candidate = $agentContext->messages[$position];
            if (!$candidate->isOwnerText()) {
                continue;
            }
            // `?? null`: the column is UNINITIALIZED on a load profile that does not carry it. A row without a device
            // (written before the column existed) is skipped — the newest row WITH a device decides.
            $surfaceValue = ($candidate->ownerDevice ?? null)?->toSurfaceVariantValue();
            // a spoken row never decides the surface — `surface=phone` holds only while the phone line is
            // open (the branch above); after hang-up the newest SCREEN device decides, else the screen default.
            if ($surfaceValue === null || $surfaceValue === OwnerDevice::SURFACE_PHONE) {
                continue;
            }
            $this->putSurfaceVariantIntoContext($agentContext, $surfaceValue, $candidate->indexInConversation ?? null);
            return;
        }
    }

    /** Put `surface=$surfaceValue` into a COPY of the context's rendered variants (the working state stays untouched). */
    protected function putSurfaceVariantIntoContext(AgentModelInput $agentContext, string $surfaceValue, ?int $decidedAtMessageIndex): void
    {
        $renderedVariants = $agentContext->variants === null
            ? new AIConversationVariants()
            : AIConversationVariants::fromRepositoryArray($agentContext->variants->toRepositoryArray());
        $renderedVariants->put(AIConversationVariant::decided(
            OwnerDevice::SURFACE_DIMENSION,
            $surfaceValue,
            AIConversationVariant::SOURCE_RECORD,
            $decidedAtMessageIndex
        ));
        $agentContext->variants = $renderedVariants;
    }

    /**
     * The transient `voiceAppRing` / `voicePhoneDial` dimensions: which call channel reaches the owner right now, merged
     * into a COPY of the rendered variants like the surface (the working state never carries them) — only while a
     * loaded skill keys sections on them, through {@see AgentVoiceChannelService::putCallReachVariantsInto()} (no voice
     * component: nothing is merged). A failed reading leaves the dimensions out.
     */
    protected function mergeVoiceCallReachVariantsIntoContext(AgentModelInput $agentContext): void
    {
        $loadedSkillDimensionNames = [];
        foreach ($agentContext->loadedAISkills?->getElements() ?? [] as $loadedAISkill) {
            $loadedSkillDimensionNames = array_merge($loadedSkillDimensionNames, $loadedAISkill->getSections()->getDimensionNames());
        }
        $renderedVariants = $agentContext->variants === null
            ? new AIConversationVariants()
            : AIConversationVariants::fromRepositoryArray($agentContext->variants->toRepositoryArray());
        /** @var AgentVoiceChannelService $agentVoiceChannelService */
        $agentVoiceChannelService = DDDService::instance()->getService(AgentVoiceChannelService::class);
        if ($agentVoiceChannelService->putCallReachVariantsInto($this, $loadedSkillDimensionNames, $renderedVariants)) {
            $agentContext->variants = $renderedVariants;
        }
    }

    /** How long one live-call probe answer serves this conversation object — a call starts or ends rarely, a turn's steps come fast. */
    public const int LIVE_CALL_PROBE_TTL_SECONDS = 20;

    /** @var AIConversationLiveCallProbe|null The last look-up of an answered call on this conversation. In-memory only. */
    protected ?AIConversationLiveCallProbe $liveAnsweredCallProbeMemo = null;

    /**
     * @var string|null Eval/test override of the live-call probe: a live-call channel type
     * ({@see AIConversationOrigin::LIVE_CALL_CHANNEL_PHONE} / `…_APP`) the
     * conversation behaves as if a call of that channel were answered on it (an eval conversation has no
     * call row). Never persisted.
     */
    protected ?string $simulatedLiveCallChannelType = null;

    /** Eval/test: behave as if a call of `$channelType` (`PHONE` / `APP`) were answered on this conversation. */
    public function simulateLiveAnsweredCall(?string $channelType): void
    {
        $this->simulatedLiveCallChannelType = $channelType;
        $this->liveAnsweredCallProbeMemo = null;
    }

    /** Whether the owner is on a call over the PHONE LINE right now — the one flag every phone-only rule reads. */
    public function isOwnerOnThePhoneLine(?array $messagesInContext = null): bool
    {
        return $this->getOwnerPresence($messagesInContext) === OwnerPresence::PRESENT_ON_THE_PHONE;
    }

    /**
     * The channel type (`PHONE` / `APP`) of the answered, not yet ended call attached to this conversation right now
     * (the owner is on the line; {@see AgentVoiceChannelService::findLiveAnsweredCallChannelType()}), or null. A delegation child probes its ROOT (the call hangs on the root). Read from the
     * repo for a persisted conversation only; a transient one (an eval replay, a unit test) has no call unless
     * {@see $simulatedLiveCallChannelType} says so. Memoised for {@see LIVE_CALL_PROBE_TTL_SECONDS} on this object so a
     * turn's steps cost one query, not one per step.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findLiveAnsweredCallChannelType(): ?string
    {
        if ($this->simulatedLiveCallChannelType !== null) {
            return $this->simulatedLiveCallChannelType;
        }
        $probedConversationId = $this->isDelegation() ? ($this->rootConversationId ?? null) : ($this->id ?? null);
        if (!$probedConversationId) {
            return null;
        }
        if ($this->liveAnsweredCallProbeMemo !== null && $this->liveAnsweredCallProbeMemo->isYoungerThanSeconds(self::LIVE_CALL_PROBE_TTL_SECONDS)) {
            return $this->liveAnsweredCallProbeMemo->liveCallChannelType;
        }
        /** @var AgentVoiceChannelService $agentVoiceChannelService */
        $agentVoiceChannelService = DDDService::instance()->getService(AgentVoiceChannelService::class);
        $liveCallChannelType = $agentVoiceChannelService->findLiveAnsweredCallChannelType((int)$probedConversationId);
        $this->liveAnsweredCallProbeMemo = AIConversationLiveCallProbe::forChannelTypeProbedNow($liveCallChannelType);
        return $liveCallChannelType;
    }

    /**
     * The device of the newest owner TEXT row among `$messages` (oldest first), or null when no owner row carries
     * one. Signal rows are skipped (a click carries no words); `?? null` because the column is uninitialized on a
     * load profile that does not carry it.
     *
     * @param AIConversationMessage[] $messages
     */
    protected static function findNewestOwnerDeviceInMessages(array $messages): ?OwnerDevice
    {
        for ($position = count($messages) - 1; $position >= 0; $position--) {
            $candidate = $messages[$position];
            if (!$candidate->isOwnerText()) {
                continue;
            }
            $ownerDevice = $candidate->ownerDevice ?? null;
            if ($ownerDevice !== null) {
                return $ownerDevice;
            }
        }
        return null;
    }

    /**
     * Whether a CUSTOMER is present in this conversation's run — the gate for the model's fast service tier
     * (fast mode on every conversation with the customer present, on the delegations that fork
     * from one, never on scheduled tasks, background operations or the platform's own hidden runs). A live dialog or
     * a phone call ({@see isUserPresentRun()}) with a customer-facing purpose or none; a delegation child carries its
     * root's initiator type ({@see inheritDelegationScopeFrom()}), so it answers like its root. A purpose of the
     * platform's own work ({@see AIConversationPurpose::CUSTOMER_HIDDEN_PURPOSE_NAMES}) is never customer-present.
     */
    public function runsWithCustomerPresent(): bool
    {
        return $this->getOrigin()->runsWithCustomerPresent();
    }

    /**
     * Whether a run is still IN PROGRESS on this conversation: a non-terminal `runState` (RUNNING /
     * AWAITING_DELEGATES / QUEUED) OR an open yield→claim handover ({@see $agentTurnOwedAt} set while briefly
     * IDLE — same openness definition as {@see getUnfinishedTurnFloorIndex}). The schedule fire path's guardrail:
     * a due occurrence is SKIPPED while the previous run is still in progress.
     */
    public function isRunInProgress(): bool
    {
        return $this->getRunState()->isNonTerminal()
            || isset($this->agentTurnOwedAt);
    }

    /**
     * Whether this conversation may offer the `search_conversations` tool — cross-conversation episodic recall over
     * the account's OTHER conversations. Resolved by the scope service
     * ({@see AgentSpacesService::isCrossConversationRecallEnabled()}), default
     * ON when the scope says nothing.
     */
    /**
     * The MCPPackage classes whose tools are active on this conversation — read off the LAST message's cumulative
     * loaded skills (the single source of truth; no scan over earlier messages, same source as the system content's
     * skill instructions). Empty when no skill is loaded yet. Pure (in-memory over the loaded messages).
     *
     * @return array<int, class-string>
     */
    public function findLoadedPackages(): array
    {
        return $this->findLoadedAISkills()->getPackages();
    }

    /**
     * The definitions of the tools this conversation's loaded skills reach: what the loop asks of a
     * tool — its ceiling, parallel safety, untrusted content, state refresh — read once per package set.
     */
    public function buildLoadedToolDefinitions(): ToolDefinitions
    {
        return ToolDefinitions::forPackages($this->findLoadedPackages());
    }

    /**
     * The resolved skills loaded on `$anchorMessage`, else on the newest message that carries a set (a backward
     * search — a capability-less newest message, an injected signal or a carry gap, must not read as "no skills").
     * Empty when no message carries one.
     */
    public function findLoadedAISkills(?AIConversationMessage $anchorMessage = null): AISkills
    {
        $anchorMessage ??= isset($this->messages) ? $this->messages->getNewestMessageWithLoadedCapabilities() : null;
        return $anchorMessage?->findLoadedAISkills() ?? new AISkills();
    }

    public function isCrossConversationRecallEnabled(): bool
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->isCrossConversationRecallEnabled($this);
    }

    /**
     * The loaded capabilities a SEED starts with: the named skills — resolved here, tools filtered by
     * this conversation's agent space flags — promoted onto an empty set from `$origin` (a purpose kickoff, a start signal, a
     * delegation or background child, an eval anchor). The seeds' one gatherer; the set itself resolves nothing.
     *
     * @param string[] $skillNames
     */
    public function buildSeededCapabilities(array $skillNames, bool $eager, LoadedCapabilityOrigin $origin): LoadedCapabilities
    {
        $requestedAISkills = AISkill::getService()->resolveRequestedSkills($skillNames, []);
        $loadedSkillToolDefinitions = LoadedSkillToolDefinitions::fromRequestedSkills($requestedAISkills, $this->findFeatureFlags());
        return (new LoadedCapabilities())->withSkillsPromoted($requestedAISkills, $loadedSkillToolDefinitions, $eager, $origin);
    }

    /**
     * The applied feature flags of this conversation ({@see AIConversationEntitlementsService::findFeatureFlagsOfConversation()})
     * — the entitlement that gates the offered skills/tools and feeds the product-capabilities awareness. Null when
     * the application gives none (a conversation without a space, an account-level or bare conversation), which the
     * callers treat as "no gating" (ungated fallback — never lock out a conversation without a space).
     */
    public function findFeatureFlags(): ?AgentFeatureFlagsInterface
    {
        /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
        $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        return $aIConversationEntitlementsService->findFeatureFlagsOfConversation($this);
    }

    /**
     * The ACTING USER's account rights — the second entitlement axis beside
     * {@see findFeatureFlags()}. Null when the conversation has no account, which the callers treat as "no
     * rights gating" (never lock out an account-less conversation), mirroring the flag axis.
     *
     * Read off the CONVERSATION's account, and that is the same identity the execution guard reads from
     * `AuthService` ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService::refuseToolCallLackingAccountRights()}):
     * the loop authenticates as `$conversation->account` before it drives (see `resumeConversationIfReady`), and an
     * admin-driven conversation impersonates the owning account of its space. Offer and execution therefore cannot
     * disagree about WHO is acting — which matters, because a divergence would show a tool the user may not call.
     *
     * No memo property: `$account` and `$settings` are both `#[LazyLoad]` (settings behind a 300 s `#[EntityCache]`)
     * and `$rights` is a plain property on the loaded settings, so repeated calls within a turn read already-resident
     * objects. `isset()` first for the same reason the flag getter does it — a bare `?->` on an unset lazy property
     * triggers the DDD `__get` load, which throws in the container-less unit-test stubs.
     */
    public function getAccountRights(): ?AgentAccountRightsInterface
    {
        /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
        $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        return $aIConversationEntitlementsService->findAccountRightsOfConversation($this);
    }

    /**
     * What this conversation's space and acting user may use: the agent space's applied flags, the
     * acting user's rights ({@see findFeatureFlags()}, {@see getAccountRights()}) and the account's billing
     * surface — read in that order, each axis null when there is nothing to ask.
     */
    public function getEntitlement(): AIConversationEntitlement
    {
        $aIConversationEntitlement = new AIConversationEntitlement();
        $aIConversationEntitlement->featureFlags = $this->findFeatureFlags();
        $aIConversationEntitlement->accountRights = $this->getAccountRights();
        // the AI-budget policy the offer applies (null = reportable; the host: the agent space's AI-budget flag)
        /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
        $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        $aIConversationEntitlement->exactAIBudgetReportable = $aIConversationEntitlementsService->isExactAIBudgetReportable($this);
        // `?->` (not `??`) so the #[LazyLoad] account actually loads; a conversation with neither the account nor its
        // id has no account to ask (isset first, as getAccountRights() does — a container-less stub never loads)
        $aIConversationEntitlement->accountHasBillingAccess = isset($this->account) || isset($this->accountId)
            ? $aIConversationEntitlementsService->findAccountBillingAccessOfConversation($this)
            : null;
        return $aIConversationEntitlement;
    }

    /**
     * Whether this conversation unloads capabilities per SKILL (the default): the per-conversation
     * {@see $contextRetentionSettings} value, else the global default. isset-guarded (the column may be unset).
     */
    public function unloadsPerSkill(): bool
    {
        $settings = isset($this->contextRetentionSettings) ? $this->contextRetentionSettings : null;
        return ($settings ?? new ContextRetentionSettings())->unloadsPerSkill();
    }

    /** The skill-loading strategy of this conversation: the stamped value, else the global default. */
    public function getSkillLoadingStrategy(): string
    {
        $settings = isset($this->contextRetentionSettings) ? $this->contextRetentionSettings : null;
        return ($settings ?? new ContextRetentionSettings())->getSkillLoadingStrategy();
    }

    /** Whether this conversation loads skills append-only. */
    public function usesConversationAppend(): bool
    {
        return $this->getSkillLoadingStrategy() === ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
    }

    /**
     * Whether a situation change starts a new prefix epoch under append-only loading: the
     * stamped {@see ContextRetentionSettings::$rebuildEpochOnSituationChange}, else the global default.
     */
    public function rebuildsEpochOnSituationChange(): bool
    {
        $settings = isset($this->contextRetentionSettings) ? $this->contextRetentionSettings : null;
        return ($settings ?? new ContextRetentionSettings())->rebuildsEpochOnSituationChange();
    }

    /** The state-change delivery of this conversation: the stamped value, else the global default. */
    public function getStateChangeDelivery(): string
    {
        $settings = isset($this->contextRetentionSettings) ? $this->contextRetentionSettings : null;
        return ($settings ?? new ContextRetentionSettings())->getStateChangeDelivery();
    }

    /** Whether this conversation delivers a volatile value's change once, as a note (`onceWhenChanged`). */
    public function deliversStateChangesOnce(): bool
    {
        return $this->getStateChangeDelivery() === ContextRetentionSettings::STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED;
    }

    /** {@see AIConversationMessages::hasUnansweredInboundMessage()} over this conversation's loaded messages. */
    public function hasUnansweredInboundMessage(): bool
    {
        return ($this->messages ?? null)?->hasUnansweredInboundMessage() ?? false;
    }

    /** {@see AIConversationMessages::isNewestWireMessageFinalAssistantText()} over this conversation's loaded messages. */
    public function lastWireMessageIsFinalAssistantText(): bool
    {
        return ($this->messages ?? null)?->isNewestWireMessageFinalAssistantText() ?? false;
    }

    /**
     * The tool calls over the index-sorted rows — the delegation scans read them in that order (the
     * status report numbers the delegations). Transitional like {@see buildOwnerTurns()}.
     */
    public function buildToolCallHistory(): AIConversationToolCallHistory
    {
        return AIConversationToolCallHistory::fromRows($this->getSortedMessages());
    }

    /**
     * The OWNER REPORT this run delivered (subject + body Markdown), composed from persisted state — the same
     * composition the report e-mail was built from. Crosses into the service (reloads the messages).
     *
     * @throws \Throwable
     */
    public function composeOwnerReport(): AIConversationOwnerReport
    {
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        return $aIConversationOwnerReportDeliveryService->composeOwnerReportOfRun($this);
    }

    /** {@see AIConversationMessages::getNewestAssistantAuthoredText()} over this conversation's loaded messages. */
    public function getLastAssistantText(): ?string
    {
        return ($this->messages ?? null)?->getNewestAssistantAuthoredText();
    }

    /** {@see AIConversationMessages::getNewestUserAuthoredRow()} over this conversation's loaded messages. */
    public function getNewestUserMessage(): ?AIConversationMessage
    {
        return ($this->messages ?? null)?->getNewestUserAuthoredRow();
    }

    /**
     * Assemble the customer-facing progress STEPPER for this conversation: the
     * purpose's `customerProgressSteps` declaration (order + keys + labels, resolved from {@see $purposeName}
     * exactly like the purpose directive — a config read via the purposes service, no repo) aggregated over the
     * live task plan's member items ({@see Agent\Progress\CustomerProgressSteps::fromPurposeDeclarationAndPlanItems()}).
     * Returns an EMPTY set when this conversation has no purpose, the purpose declares no stepper, or no plan
     * exists yet — the frontend renders the stepper only when the set is non-empty (the presence contract).
     */
    public function buildCustomerProgressSteps(): CustomerProgressSteps
    {
        if (!isset($this->purposeName) || !$this->purposeName || $this->isDelegation()) {
            return new CustomerProgressSteps();
        }
        $aIConversationPurpose = $this->findPurpose();
        if ($aIConversationPurpose === null || $aIConversationPurpose->customerProgressSteps === []) {
            return new CustomerProgressSteps();
        }
        $taskPlanItems = $this->getTaskPlan()?->items;
        return CustomerProgressSteps::fromPurposeDeclarationAndPlanItems(
            $aIConversationPurpose->customerProgressSteps,
            $taskPlanItems
        );
    }

    /**
     * The gate tool this conversation's task plan owes NEXT — the first open gated leaf's `doneRequiresToolCall`
     * with no completed, argument-qualifying call in the loaded messages
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems::getNextOwedDoneGateToolName()}) — or
     * null; a gate tool the account cannot run counts as resolved ({@see findEntitlementLockedToolNamesForPlanGates()}). The loop asks this
     * before forcing a tool-less answer step after a refused duplicate read: the owed write (a goal, a save) must still
     * be able to run in the same turn, and the corrective can name it. Null without a plan or without loaded messages
     * (`isset` on purpose — never a lazy full-history load from the hot loop).
     */
    public function findNextOwedPlanDoneGateToolName(): ?string
    {
        $taskPlanItems = $this->getTaskPlan()?->items;
        if ($taskPlanItems === null || !isset($this->messages)) {
            return null;
        }
        return $taskPlanItems->getNextOwedDoneGateToolName($this->messages, $this->findEntitlementLockedToolNamesForPlanGates());
    }

    /**
     * What the platform's task-plan closings read, gathered ONCE: the loaded messages (the
     * same set, never a copy), the current turn's initiator index, `$candidateAnswerText` (the final text the checked stop
     * judges), the plan's done-gate tools this account cannot run, whether the purpose's criteria decide every tick
     * ({@see findPurposeWhoseCriteriaDecideTicks()}) and the space. The locked gate tools are read only when a reader
     * asks ({@see TaskPlanEvidence::getEntitlementLockedToolNames()}): the evidence keeps the plan's gate-tool set of this
     * moment and this conversation, which answers from its memo ({@see findEntitlementLockedToolNamesAmongPlanGateTools()})
     * — a path that never asks (the completed-call closing, a refused tick) never sweeps the skill catalog.
     * Needs the messages loaded.
     */
    public function buildTaskPlanEvidence(?string $candidateAnswerText = null): TaskPlanEvidence
    {
        $taskPlanEvidence = TaskPlanEvidence::forTurn(
            $this->messages,
            $this->messages->getNewestInitiatorMessageIndex() ?? -1,
            $candidateAnswerText,
            $this->findPurposeWhoseCriteriaDecideTicks() !== null
        );
        $taskPlanEvidence->readEntitlementLocksFromConversation($this, $this->getTaskPlan()?->items?->getDoneGateToolNames() ?? []);
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $taskPlanEvidence->widgetSelfHydrationKey = $agentSpacesService->findWidgetSelfHydrationKeyOfConversation($this);
        return $taskPlanEvidence;
    }

    /** {@see buildTaskPlanEvidence()} plus what only a `set_task_status` tick reads: the phone line. */
    public function buildTaskPlanEvidenceForStatusTick(): TaskPlanEvidence
    {
        $taskPlanEvidence = $this->buildTaskPlanEvidence();
        $taskPlanEvidence->ownerIsOnThePhoneLine = $this->isOwnerOnThePhoneLine();
        return $taskPlanEvidence;
    }

    /**
     * This conversation's purpose when its criteria decide every tick of a criterion leaf
     * ({@see AIConversationPurpose::enforcesDoneCriterionOnTicks()}); null otherwise (no purpose, the
     * purpose cannot be read, or its criteria only pre-tick).
     */
    public function findPurposeWhoseCriteriaDecideTicks(): ?AIConversationPurpose
    {
        $purposeName = $this->purposeName ?? null;
        if ($purposeName === null || $purposeName === '') {
            return null;
        }
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $aIConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
        return $aIConversationPurpose instanceof AIConversationPurpose && $aIConversationPurpose->enforcesDoneCriterionOnTicks()
            ? $aIConversationPurpose
            : null;
    }

    /**
     * Memo for {@see findEntitlementLockedToolNamesAmongPlanGateTools()}, keyed by the sorted gate-tool set: the catalog
     * sweep runs once per (conversation, gate-tool set), not at every checked stop, turn end and tick. Lives on the
     * conversation object, which a worker job loads fresh, so a plan or entitlement change between two jobs is seen by the
     * next one. Plain in-memory state, never persisted, never cached with the conversation.
     *
     * @var array<string, string[]>
     */
    #[HidePropertyOnSystemSerialization]
    protected array $entitlementLockedPlanGateToolNamesMemo = [];

    /**
     * The plan's done-gate tools that are structurally UNREACHABLE for this conversation's account: the tool is anchored
     * in at least one skill, but NO entitled skill (feature flags AND account rights, both the skill-level frontmatter and
     * the tool-level attributes) offers it. Such a gate can never produce its evidence — e.g. a closing item's
     * `scheduleOneTimeTask` on an account without the right to manage scheduled tasks (the denial is
     * legitimate; the gate is waived instead of wedging the item open forever). Scoped to the PLAN's declared gate tools,
     * so the catalog sweep runs only when a plan carries tool gates. A gate tool anchored in NO skill (a meta/plan tool)
     * is never reported locked. Crosses into the skill catalog.
     *
     * @return string[]
     */
    public function findEntitlementLockedToolNamesForPlanGates(): array
    {
        return $this->findEntitlementLockedToolNamesAmongPlanGateTools($this->getTaskPlan()?->items?->getDoneGateToolNames() ?? []);
    }

    /**
     * {@see findEntitlementLockedToolNamesForPlanGates()} for a given gate-tool set (the set a {@see TaskPlanEvidence}
     * captured when it was gathered), memoized per sorted set ({@see $entitlementLockedPlanGateToolNamesMemo}).
     *
     * @param string[] $planGateToolNames
     * @return string[]
     */
    public function findEntitlementLockedToolNamesAmongPlanGateTools(array $planGateToolNames): array
    {
        if ($planGateToolNames === []) {
            return [];
        }
        sort($planGateToolNames);
        $memoKey = implode(',', $planGateToolNames);
        if (!isset($this->entitlementLockedPlanGateToolNamesMemo[$memoKey])) {
            $this->entitlementLockedPlanGateToolNamesMemo[$memoKey] = $this->sweepSkillCatalogForEntitlementLockedToolNames($planGateToolNames);
        }
        return $this->entitlementLockedPlanGateToolNamesMemo[$memoKey];
    }

    /**
     * The uncached sweep behind {@see findEntitlementLockedToolNamesAmongPlanGateTools()}: none locked when no
     * entitlement axis gates anything, else the skill catalog (rebuilt from config, every skill's frontmatter parsed) is
     * asked which of the sorted `$planGateToolNames` no permitted skill provides.
     *
     * @param string[] $planGateToolNames sorted
     * @return string[]
     */
    protected function sweepSkillCatalogForEntitlementLockedToolNames(array $planGateToolNames): array
    {
        $entitlement = $this->getEntitlement();
        if ($entitlement->isUngatedByFlagsAndRights()) {
            return [];
        }
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = AISkill::getService();
        return $aiSkillsService->getCatalog()->getToolNamesNoPermittedSkillProvides($planGateToolNames, $entitlement);
    }

    /**
     * The model-call context AS OF $anchorMessage — for the live loop AND for inspection
     * ("what was sent for this call"). This conversation's messages are sorted by (indexInConversation, id) — never
     * trust repo load order —, cut at the anchor (inclusive; null = whole conversation), and the compaction tree's
     * cover ({@see buildCompactionCover()}, point-in-time via `$onlyCompactionsBefore`) replaces everything up to its
     * prefix edge with one summary; the messages AFTER the edge stay verbatim. Without a cover the context is the
     * whole cut range. Pure assembly; the skills' instruction resolution is deferred to
     * {@see AgentModelInput::composeSystemContent()} (compose time = egress side).
     *
     * Built in phases, in this order: A identity and time, B the sub-agent directive, C the purpose or
     * scheduled-run directive, D the task-plan tail inputs, E the reply language, F the history
     * ({@see buildModelInputHistoryUpToAndIncludingMessage()}), G the owner presence and the variants, H the security
     * filter and the offered skill catalog, I the creation snapshots, J the memory directives
     * ({@see findMemoryDirectivesBlock()}), K the product awareness ({@see findProductAwareness()}).
     *
     * @param AgentRun|null $agentRun the run that builds the context (its per-run reads — the offered catalog reads the
     *      run's root-purpose memo), null outside a run (an inspection, a summary, a collaborator's call)
     */
    public function buildModelInputUpToAndIncludingMessage(
        ?AIConversationMessage $anchorMessage,
        string $baseSystemPrompt,
        ?DateTime $onlyCompactionsBefore = null,
        ?AgentRun $agentRun = null
    ): AgentModelInput {
        $agentContext = AgentModelInput::newInstance();
        $agentContext->systemPrompt = $baseSystemPrompt;
        $this->putIdentityAndTimeIntoContext($agentContext);
        if ($agentContext->isDelegation) {
            $agentContext->subAgentDirective = $this->findSubAgentDirectiveText();
        }
        $this->putDirectivesIntoContext($agentContext);
        $this->putTaskPlanTailInputsIntoContext($agentContext, $anchorMessage, $onlyCompactionsBefore);
        $this->putReplyLanguageIntoContext($agentContext);
        $agentModelInputHistory = $this->buildModelInputHistoryUpToAndIncludingMessage($anchorMessage, $onlyCompactionsBefore);
        $this->putHistoryIntoContext($agentContext, $agentModelInputHistory);
        $this->putOwnerPresenceAndVariantsIntoContext($agentContext);
        // The owner messages the injection check blocked — the egress shows the model a notice instead.
        $agentContext->securityFilteredOwnerMessageIndexes = $this->getAgentWorkingState()->securityFilteredOwnerMessageIndexes ?? [];
        // The skill CATALOG the model may load from — the SAME offer the `load_skills` enum is built from,
        // resolved off the capability anchor so the point-in-time rebuild shows the catalog as of that turn;
        // accountId-guarded like the memory directives, so a container-less / account-less conversation (unit tests,
        // ephemeral fixtures) skips it. Inside a run the offer reads the run's root-purpose memo.
        if (isset($this->accountId) && $this->accountId) {
            $agentContext->offeredSkillCatalog = $this->buildOfferedSkillCatalog($agentModelInputHistory->capabilityAnchorMessage ?? $anchorMessage, [], $agentRun)->skills;
        }
        $this->putCreationSnapshotsIntoContext($agentContext);
        $agentContext->memoryDirectives = $this->findMemoryDirectivesBlock();
        $this->putProductAwarenessIntoContext($agentContext);
        // a `onceWhenChanged` conversation's call runs in a state epoch (the display summary's build — no
        // anchor, no base prompt — renders no system content and needs none)
        $agentStateEpoch = $this->deliversStateChangesOnce() && ($anchorMessage !== null || $baseSystemPrompt !== '')
            ? $this->resolveStateEpochOfContext($agentContext, $anchorMessage, $onlyCompactionsBefore)
            : null;
        // an append-only conversation's system content renders its prefix epoch's base set, and the
        // catalog the system content lists sub-skills from is the reachable one (the same for the whole epoch)
        if ($this->usesConversationAppend()) {
            $agentContext->prefixEpoch = $this->buildPrefixEpoch($agentContext->messages, $onlyCompactionsBefore, $agentRun, $agentStateEpoch?->startIndex);
            if (isset($this->accountId) && $this->accountId) {
                $agentContext->offeredSkillCatalog = $this->buildReachableSkillCatalog()->skills;
            }
        }
        if ($agentStateEpoch !== null) {
            $this->putStateDeliveryIntoContext($agentContext, $agentStateEpoch, $anchorMessage, $agentRun);
        }
        if ($this->deliversStateChangesOnce() && $baseSystemPrompt !== '' && $agentContext->stateDelivery === null) {
            $this->fillStateDeliveryTemplateWithCurrentValues($agentContext, $agentRun);
        }
        return $agentContext;
    }

    /**
     * A rebuilt call of a `onceWhenChanged` conversation whose state epoch cannot be read from the snapshots (its first
     * call's row is not in the context — an edit-branch truncated it): the template's
     * placeholders are filled with the current values — the admin composition and the judge's system prompt never show a
     * literal placeholder — and the context says that it was filled so.
     */
    protected function fillStateDeliveryTemplateWithCurrentValues(AgentModelInput $agentContext, ?AgentRun $agentRun): void
    {
        $agentStateDeliveryBaseValues = $this->findStateDeliveryBaseValues($agentRun);
        $agentContext->systemPrompt = AgentModelInput::fillStateDeliveryBasePromptPlaceholdersOf(
            $agentContext->systemPrompt,
            $agentStateDeliveryBaseValues?->agentSpaceContext,
            $agentStateDeliveryBaseValues?->ownerAddressing
        );
        $agentContext->stateEpochFilledFromCurrentValues = true;
    }

    /**
     * The prefix epoch of this append-only conversation as of a context build: it starts at the
     * conversation start (index 0) or at the first row after the compaction cover's edge (as of
     * `$onlyCompactionsBefore`); its base set is the capability set at that start — the kickoff / child seed set on row
     * 0, else the set right before the first raw row, read from the stored chain once per run
     * ({@see findLoadedCapabilitiesAtPrefixEpochStart()}); the system content renders with the variants of the epoch's
     * first model call (its request snapshot), the call's own variants before that call exists.
     *
     * Under `stateChangeDelivery = onceWhenChanged` the state epoch's start is the prefix
     * epoch's start when it is the later one: a cold epoch then folds the skills the old epoch appended into the base
     * set, renders none of its items, and carries the conditional meta tools the old epoch appended
     * ({@see AgentPrefixEpoch::$carriedConditionalMetaToolNames}).
     *
     * @param AIConversationMessage[] $contextMessages the raw tail of the context build (rows of the epoch)
     * @param int|null $stateEpochStartIndex the state epoch's start; null = the cover epoch only
     */
    public function buildPrefixEpoch(array $contextMessages, ?DateTime $onlyCompactionsBefore = null, ?AgentRun $agentRun = null, ?int $stateEpochStartIndex = null): AgentPrefixEpoch
    {
        $agentPrefixEpoch = new AgentPrefixEpoch();
        $compactionCover = $this->buildCompactionCover($onlyCompactionsBefore);
        $coveredUpToIndex = $compactionCover->getCoveredUpToIndex();
        $agentPrefixEpoch->startIndex = $coveredUpToIndex === null ? 0 : $coveredUpToIndex + 1;
        if ($stateEpochStartIndex !== null && $stateEpochStartIndex > $agentPrefixEpoch->startIndex) {
            $agentPrefixEpoch->carriedConditionalMetaToolNames = AgentPrefixEpoch::findConditionalMetaToolNamesAppendedBetween($contextMessages, $agentPrefixEpoch->startIndex, $stateEpochStartIndex);
            $agentPrefixEpoch->startIndex = $stateEpochStartIndex;
        }
        $agentPrefixEpoch->coverFingerprint = AgentPrefixEpoch::buildCoverFingerprint($compactionCover->getCoverNodes());
        $agentPrefixEpoch->baseCapabilities = $this->findLoadedCapabilitiesAtPrefixEpochStart($agentPrefixEpoch->startIndex, $agentRun);
        $agentPrefixEpoch->appendOnlyToolSet = $this->buildAppendOnlyToolSet($agentRun);
        if ($agentPrefixEpoch->baseCapabilities !== null) {
            /** @var AISkillsService $aiSkillsService */
            $aiSkillsService = AISkill::getService();
            $agentPrefixEpoch->baseAISkills = $aiSkillsService->findLoadedSkillsOf($agentPrefixEpoch->baseCapabilities);
        }
        // The situation epoch the newest model call ran in (its recorded start), read back
        $agentPrefixEpoch->rebuildsOnSituationChange = $this->rebuildsEpochOnSituationChange();
        if ($agentPrefixEpoch->rebuildsOnSituationChange) {
            $recordedSituationChangeStartIndex = AgentPrefixEpoch::findRecordedSituationChangeStartIndex($contextMessages, $agentPrefixEpoch->startIndex);
            if ($recordedSituationChangeStartIndex !== null) {
                $this->startSituationEpochOf($agentPrefixEpoch, $recordedSituationChangeStartIndex, null, $agentRun);
            }
        }
        foreach ($contextMessages as $contextMessage) {
            if (($contextMessage->indexInConversation ?? -1) >= $agentPrefixEpoch->getSkillTextStartIndex()
                && ($contextMessage->inputTokens ?? 0) > 0
                && isset($contextMessage->requestSnapshot)
                && is_array($contextMessage->requestSnapshot->variants)
            ) {
                $agentPrefixEpoch->variantsAtEpochStart = AIConversationVariants::fromSnapshotArray($contextMessage->requestSnapshot->variants);
                break;
            }
        }
        return $agentPrefixEpoch;
    }

    /**
     * Start a new situation epoch for the model call about to be sent when its situation differs
     * from the epoch's ({@see AgentPrefixEpoch::isStartedAnewBySituationOf()}): the epoch starts right after the newest
     * history row the call sees (`$contextTailIndex` + 1), its base set is the capability set right before that index
     * (every skill loaded so far) and its situation the call's. The call records the start on its request snapshot, so
     * later calls and the reconstruction find the same epoch. Only on the live call path — a point-in-time rebuild reads
     * the recorded start ({@see buildPrefixEpoch()}). Returns whether a new epoch started.
     */
    public function startSituationEpochIfTheSituationChanged(AgentModelInput $agentModelInput, ?int $contextTailIndex, ?AgentRun $agentRun = null): bool
    {
        $agentPrefixEpoch = $agentModelInput->prefixEpoch;
        if ($agentPrefixEpoch === null || $contextTailIndex === null) {
            return false;
        }
        $cutNames = AgentAppendedSituation::findCutNames(
            $agentModelInput,
            $agentModelInput->loadedAISkills ?? new AISkills(),
            $agentModelInput->loadedCapabilities?->getLoadedSkillNames() ?? []
        );
        if (!$agentPrefixEpoch->isStartedAnewBySituationOf($agentModelInput->variants, $cutNames)) {
            return false;
        }
        $this->startSituationEpochOf($agentPrefixEpoch, $contextTailIndex + 1, $agentModelInput->variants, $agentRun);
        return true;
    }

    /**
     * Put the situation epoch starting at `$situationChangeStartIndex` onto `$agentPrefixEpoch`: the base set right
     * before that index (memoized per run), its resolved skills, and the situation — `$situationVariants` for the call
     * that starts it, else the variants of the epoch's first model call row (null = the call's own).
     */
    protected function startSituationEpochOf(AgentPrefixEpoch $agentPrefixEpoch, int $situationChangeStartIndex, ?AIConversationVariants $situationVariants, ?AgentRun $agentRun): void
    {
        $situationChangeBaseCapabilities = $this->findLoadedCapabilitiesAtPrefixEpochStart($situationChangeStartIndex, $agentRun);
        $situationChangeBaseAISkills = null;
        if ($situationChangeBaseCapabilities !== null) {
            /** @var AISkillsService $aiSkillsService */
            $aiSkillsService = AISkill::getService();
            $situationChangeBaseAISkills = $aiSkillsService->findLoadedSkillsOf($situationChangeBaseCapabilities);
        }
        $agentPrefixEpoch->startSituationEpoch($situationChangeStartIndex, $situationChangeBaseCapabilities, $situationChangeBaseAISkills, $situationVariants);
    }

    /**
     * The state epoch of a context build: a point-in-time rebuild of a recorded call (`$onlyCompactionsBefore`
     * set, the anchor is the call's row) reads the epoch its snapshot recorded; every other build decides it for a call
     * sent now — the newest call's epoch, continued unless a start rule fires. Null = a rebuild of a call that recorded none
     * (rendered as before).
     */
    protected function resolveStateEpochOfContext(AgentModelInput $agentContext, ?AIConversationMessage $anchorMessage, ?DateTime $onlyCompactionsBefore): ?AgentStateEpoch
    {
        if ($onlyCompactionsBefore !== null && $anchorMessage !== null && AgentStateEpoch::isModelCallRow($anchorMessage)) {
            return AgentStateEpoch::readForRecordedCall($agentContext->messages, $anchorMessage->requestSnapshot);
        }
        $compactionCover = $this->buildCompactionCover($onlyCompactionsBefore);
        $coveredUpToIndex = $compactionCover->getCoveredUpToIndex();
        return AgentStateEpoch::decideForNewCall(
            $agentContext->messages,
            AgentPrefixEpoch::buildCoverFingerprint($compactionCover->getCoverNodes()),
            $coveredUpToIndex === null ? 0 : $coveredUpToIndex + 1,
            new DateTime(),
            $agentContext->buildStateDeliverySurfaceKey()
        );
    }

    /**
     * Put the state delivery onto the context: a recorded call's from its snapshots, a new call's against
     * the current values; the system blocks then render the epoch record (the base-prompt placeholders and the `# Time`
     * zone read it in {@see AgentModelInput}).
     */
    protected function putStateDeliveryIntoContext(AgentModelInput $agentContext, AgentStateEpoch $agentStateEpoch, ?AIConversationMessage $anchorMessage, ?AgentRun $agentRun): void
    {
        $agentStateDelivery = $agentStateEpoch->isOfARecordedCall && $anchorMessage !== null
            ? AgentStateDelivery::buildForRecordedCall($agentContext->messages, $anchorMessage)
            : AgentStateDelivery::buildForNewCall(
                $agentStateEpoch,
                $agentContext->messages,
                $this->buildCurrentConversationState($agentContext, $agentRun)
            );
        if ($agentStateDelivery === null) {
            return;
        }
        $agentContext->stateDelivery = $agentStateDelivery;
        $agentContext->memoryDirectives = $agentStateDelivery->record->memoryDirectives;
        $agentContext->productCapabilities = $agentStateDelivery->record->productCapabilities;
        $agentContext->aiBudgetAwareness = $agentStateDelivery->record->aiBudgetAwareness;
        $agentContext->restrictedCapabilities = $agentStateDelivery->record->restrictedCapabilities;
    }

    /**
     * The volatile values of a call sent now: the context's blocks before the record replaces them, the
     * tail notes the per-call render would show, the surface, and the base-prompt values of the run (unknown outside a
     * run that resolved them — those kinds are then not compared).
     */
    protected function buildCurrentConversationState(AgentModelInput $agentContext, ?AgentRun $agentRun): AgentConversationState
    {
        $agentConversationState = new AgentConversationState();
        $agentStateDeliveryBaseValues = $this->findStateDeliveryBaseValues($agentRun);
        if ($agentStateDeliveryBaseValues === null) {
            $agentConversationState->unknownKinds = AgentConversationState::getKindsAnEpochRecordKeepsUnknown();
        } else {
            $agentConversationState->agentSpaceContext = $agentStateDeliveryBaseValues->agentSpaceContext;
            $agentConversationState->ownerAddressing = $agentStateDeliveryBaseValues->ownerAddressing;
            $agentConversationState->unknownKinds = $agentStateDeliveryBaseValues->unknownKinds;
        }
        $agentConversationState->timezoneIdentifier = $agentContext->timezone?->getName();
        $agentConversationState->timezoneUnconfirmedNote = $agentContext->timeZoneUnconfirmedTailHint();
        $agentConversationState->memoryDirectives = $agentContext->memoryDirectives;
        $agentConversationState->productCapabilities = $agentContext->productCapabilities;
        $agentConversationState->aiBudgetAwareness = $agentContext->aiBudgetAwareness;
        $agentConversationState->restrictedCapabilities = $agentContext->restrictedCapabilities;
        $agentConversationState->situationNote = $agentContext->situationTailHint();
        // no situation line renders (no loaded skill is cut — the swap unloaded the last cut
        // one): the line's provenance is not known for this call, never "every value is confirmed"
        if (!$agentContext->rendersSituationLine()) {
            $agentConversationState->unknownKinds[] = AgentStateDelta::KIND_SITUATION;
        }
        $agentConversationState->surfaceKey = $agentContext->buildStateDeliverySurfaceKey();
        return $agentConversationState;
    }

    /**
     * The base-prompt values of a `onceWhenChanged` conversation for the run, memoized per run; null
     * outside a run and when they cannot be resolved.
     */
    public function findStateDeliveryBaseValues(?AgentRun $agentRun): ?AgentStateDeliveryBaseValues
    {
        // a conversation without an account (an in-memory fixture) has no base-prompt values to resolve
        if (!(isset($this->accountId) && $this->accountId)) {
            return null;
        }
        $memoKey = (string)($this->id ?? spl_object_id($this));
        if ($agentRun !== null && array_key_exists($memoKey, $agentRun->stateDeliveryBaseValuesByConversationKey)) {
            return $agentRun->stateDeliveryBaseValuesByConversationKey[$memoKey];
        }
        try {
            /** @var AgentBaseSystemPromptCacheService $agentBaseSystemPromptCacheService */
            $agentBaseSystemPromptCacheService = DDDService::instance()->getService(AgentBaseSystemPromptCacheService::class);
            $agentStateDeliveryBaseValues = $agentBaseSystemPromptCacheService->resolveStateDeliveryBaseValues($this);
        } catch (Throwable $throwable) {
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->warning(
                'AIConversation: the state-delivery base values of conversation ' . ($this->id ?? 'transient') . ' could not be resolved',
                ['error' => mb_substr($throwable->getMessage(), 0, 500)]
            );
            $agentStateDeliveryBaseValues = null;
        }
        if ($agentRun !== null) {
            $agentRun->stateDeliveryBaseValuesByConversationKey[$memoKey] = $agentStateDeliveryBaseValues;
        }
        return $agentStateDeliveryBaseValues;
    }

    /**
     * The loaded capability set at a prefix epoch's start index: the epoch starting at 0 takes the set on
     * row 0 (the kickoff / child seed), a later epoch the set right before its first row — from the loaded rows when one
     * below the start is in memory, else from the stored chain ({@see AIConversationMessagesService::findLoadedCapabilitiesBeforeIndex()}).
     * Covered rows are settled, so the run memoizes it per start index. Null = nothing was loaded at that point.
     */
    public function findLoadedCapabilitiesAtPrefixEpochStart(int $epochStartIndex, ?AgentRun $agentRun = null): ?LoadedCapabilities
    {
        $beforeIndex = max($epochStartIndex, 1);
        $memoKey = ($this->id ?? spl_object_id($this)) . ':' . $beforeIndex;
        if ($agentRun !== null && array_key_exists($memoKey, $agentRun->prefixEpochBaseCapabilitiesByKey)) {
            return $agentRun->prefixEpochBaseCapabilitiesByKey[$memoKey];
        }
        $newestRowBelowStart = null;
        foreach (isset($this->messages) ? $this->messages->getElements() : [] as $loadedMessage) {
            $loadedMessageIndex = $loadedMessage->indexInConversation ?? null;
            if ($loadedMessageIndex !== null && $loadedMessageIndex < $beforeIndex && $loadedMessage->hasLoadedCapabilities()
                && ($newestRowBelowStart === null || $loadedMessageIndex > ($newestRowBelowStart->indexInConversation ?? -1))
            ) {
                $newestRowBelowStart = $loadedMessage;
            }
        }
        if ($newestRowBelowStart !== null) {
            $baseCapabilities = $newestRowBelowStart->getLoadedCapabilities()?->deepCopy();
        } elseif (isset($this->id)) {
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $baseCapabilities = $aIConversationMessagesService->findLoadedCapabilitiesBeforeIndex($this, $beforeIndex);
        } else {
            $baseCapabilities = null;
        }
        if ($agentRun !== null) {
            $agentRun->prefixEpochBaseCapabilitiesByKey[$memoKey] = $baseCapabilities;
        }
        return $baseCapabilities;
    }

    /**
     * Phase A: delegation flag and the zone of THIS model request (the space's, read per request; a
     * schedule-bound run's frozen one) — it drives the time rule and the clock signal; a conversation without a
     * space (null) keeps today's wire. `clockInstant` is NOT set here — a live build stamps it in the loop right
     * before the wire call, a historical rebuild seeds clock and zone from the request snapshot.
     */
    protected function putIdentityAndTimeIntoContext(AgentModelInput $agentContext): void
    {
        $agentContext->isDelegation = $this->isDelegation();
        $agentContext->timezone = $this->getTimezone();
        $agentContext->timezoneUnverified = $this->isTimezoneUnverified();
        $agentContext->isScheduleBound = $this->isScheduleBound();
    }

    /**
     * Phase B: the sub-agent isolation directive of a delegation — a config AIPrompt (no longer a hardcoded const),
     * resolved ON the object the same way the base prompt is a resolved AIPrompt. Resolved here (the loop side, with the
     * container), NOT in the AgentModelInput VO (built container-less in tests). A misconfigured/missing prompt → '' →
     * the segment is simply omitted.
     */
    protected function findSubAgentDirectiveText(): string
    {
        /** @var AIPromptsService $aIPromptsService */
        $aIPromptsService = AIPrompt::getService();
        $subAgentDirectivePrompt = $aIPromptsService->getAIPromptByName(AgentAIPromptNames::APP_AI_AGENT_SUB_AGENT_DIRECTIVE);
        return $subAgentDirectivePrompt?->getPromptTextWithParametersApplied() ?? '';
    }

    /** Phase C: the purpose directive of a root, or the static scheduled-run directive of a purpose-less run. */
    protected function putDirectivesIntoContext(AgentModelInput $agentContext): void
    {
        // Conversation PURPOSE directive — resolved ON the object from this conversation's purposeName
        // (an entity MAY call a service; this only reaches the purposes service, which reads config, no repo). Never
        // for a delegation child (children get a focused task, not the conversation's purpose). Graceful: a purposeName
        // whose .md is gone resolves to null → the segment is simply omitted, never a throw in the context build.
        if (!$agentContext->isDelegation && isset($this->purposeName) && $this->purposeName) {
            $aIConversationPurpose = $this->findPurpose();
            $agentContext->purposeDirective = $aIConversationPurpose?->getDirectiveBlock();
            $agentContext->purposeName = $aIConversationPurpose?->name;
            $agentContext->purposeInheritsFromName = $aIConversationPurpose?->inheritsFromPurposeName;
        }
        // Scheduled-run role directive for a run conversation WITHOUT a purpose — the runs spawned before the
        // `Schedule.Run` purpose carried the role statement (execute the mission, never administer schedules) as this
        // static segment; a historical rebuild of such a run must still show it. A run with a purpose carries the
        // statement in its purpose directive instead ({@see Purposes\ScheduleRunPurpose}).
        if (!$agentContext->isDelegation && isset($this->scheduleId) && $this->scheduleId && !(isset($this->purposeName) && $this->purposeName)) {
            $agentContext->scheduledRunDirective = AIConversationSchedule::RUN_DIRECTIVE;
        }
    }

    /**
     * The task plan a context build renders (traceability): a call sent now renders the live plan; a
     * point-in-time rebuild of a RECORDED call (`$onlyCompactionsBefore` set from its snapshot, the anchor is the call's
     * row — {@see AIConversationMessage::buildModelInputThatProducedThisMessage()}) renders the plan AS OF the row before
     * that call ({@see buildTaskPlanAsOfMessageIndex()} — the recorded plan calls replayed by their recorded outcomes), so
     * the inspector never shows statuses written later. Old rows stay readable: nothing new is stored. Known limit: a
     * leaf the platform closed from the record is not part of the replay; a rebuild without a seed and without a plan
     * call before the anchor renders no plan, while loaded rows without any plan call (a plan that came from elsewhere)
     * keep the stored plan.
     */
    protected function buildTaskPlanOfContext(?AIConversationMessage $anchorMessage, ?DateTime $onlyCompactionsBefore): ?TaskPlan
    {
        if ($onlyCompactionsBefore === null || $anchorMessage === null || !isset($anchorMessage->indexInConversation)) {
            return $this->getTaskPlan();
        }
        $taskPlanAsOfTheCall = $this->buildTaskPlanAsOfMessageIndex((int)$anchorMessage->indexInConversation - 1);
        if ($taskPlanAsOfTheCall === null
            && array_intersect($this->messages->buildToolCallHistory()->getCalledToolNames(), TaskPlan::PLAN_TOOL_NAMES) === []
        ) {
            return $this->getTaskPlan();
        }
        return $taskPlanAsOfTheCall;
    }

    /**
     * Phase D: what the volatile wire tail renders from the conversation — the reads hint switch, the plan, the plan
     * directive. The plan is the one of the call being built ({@see buildTaskPlanOfContext()}).
     */
    protected function putTaskPlanTailInputsIntoContext(AgentModelInput $agentContext, ?AIConversationMessage $anchorMessage = null, ?DateTime $onlyCompactionsBefore = null): void
    {
        // Opt-in (default off): emit the recent-tool-calls reuse hint at the wire TAIL instead of nowhere — kept out of
        // the cacheable system prefix on purpose (the hint is volatile, the prefix must stay byte-stable).
        $agentContext->hintRecentToolCallsAtTail = (isset($this->contextRetentionSettings) ? $this->contextRetentionSettings : null)?->shouldHintRecentToolCalls() ?? false;
        // the current task plan rides the same volatile wire-TAIL seam (after the reads hint, before the
        // reply-language cue) — never the cacheable prefix. Copied here so the render stays a pure AgentModelInput fold.
        $agentContext->taskPlan = $this->buildTaskPlanOfContext($anchorMessage, $onlyCompactionsBefore);
        // the plan directive of the CURRENT owner turn and the tick-with-delivery header — only while the
        // plan-dynamics site GUIDES this conversation (switch 2); under off / shadow the wire stays byte-identical.
        $agentWorkingStateForPlan = $this->getAgentWorkingState();
        $planDynamicsDecision = $agentWorkingStateForPlan?->planDynamicsDecision;
        $planDynamicsGuides = AgentDecisionModelSettings::current()->siteModeForConversation(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $this) === AgentDecisionModelSettings::MODE_ON;
        if ($planDynamicsGuides && $agentWorkingStateForPlan !== null && $planDynamicsDecision !== null && $planDynamicsDecision->isApplied()
            && $planDynamicsDecision->ownerMessageIndex === $this->messages->getNewestInitiatorMessageIndex()
        ) {
            // the earlier-plan drop is named only for a plan that stood at the decision, and only until a plan update of
            // this turn applied — the context is rebuilt on every model call of the turn, the decision stays
            $planUpdateAppliedThisTurn = $this->messages->buildCurrentTurn()->hasAppliedTaskPlanUpdateCall();
            $agentContext->planDirective = $planDynamicsDecision->toDirectiveText(
                $agentWorkingStateForPlan->countFinishedTaskPlans(),
                $planDynamicsDecision->resolveEarlierPlanItemKeyToDrop($agentWorkingStateForPlan->taskPlan, $planUpdateAppliedThisTurn),
                $planUpdateAppliedThisTurn
            );
        }
        // the turn's direct widget, while no work tool ran this turn — after a work call the
        // answer is the result's, and the DIRECT_WIDGET_MISSING stall no longer applies either
        if (!$agentContext->isDelegation && $this->getDecidedDirectWidgetTypeOfCurrentTurn() !== null
            && !$this->messages->buildCurrentTurn()->hasWorkToolCallThatRanOrFailed()
        ) {
            $agentContext->directWidgetSignal = $agentWorkingStateForPlan?->directWidgetDecision?->toSignalText();
        }
    }

    /**
     * Phase E: the owner's interface language, so the tail cue can NAME the reply language instead of only pointing at
     * the newest owner message (which let the reply language flip back and forth within one run). A decision model may
     * have measured the reply language from the owner's own words this turn — an APPLIED stamp on the working state
     * names it (its provenance rides along for the cue wording); otherwise today's configured value. Read once per
     * build; the base prompt's {%USER_LANGUAGE%} stays untouched (prefix).
     */
    protected function putReplyLanguageIntoContext(AgentModelInput $agentContext): void
    {
        $appliedReplyLanguageDecision = $this->getAppliedReplyLanguageDecision();
        $agentContext->userLanguageCode = $appliedReplyLanguageDecision?->languageCode ?? $this->resolveUserLanguageCode();
        $agentContext->replyLanguageSource = $appliedReplyLanguageDecision?->source;
        $agentContext->configuredLanguageCode = $this->resolveUserLanguageCode();
    }

    /**
     * Phase F, the history of the model input as of `$anchorMessage`: the sorted messages cut at the
     * anchor (inclusive; null = the whole conversation), the compaction cover's summary in place of everything up to its
     * prefix edge (point-in-time via `$onlyCompactionsBefore`), the tool-result retention verdict over the remaining
     * tail, and the loaded capabilities of the newest message in that tail that carries a set. Pure over the loaded
     * messages and tree; resolving the loaded skills reads the skill catalog.
     */
    public function buildModelInputHistoryUpToAndIncludingMessage(?AIConversationMessage $anchorMessage, ?DateTime $onlyCompactionsBefore = null): AgentModelInputHistory
    {
        $agentModelInputHistory = new AgentModelInputHistory();
        $sortedMessages = $this->getSortedMessages();

        // Cut at the anchor (inclusive): everything after it is a later turn's content. Identity search first;
        // when the anchor INSTANCE is not in the sorted array (a reload swapped instances), fall back to cutting
        // by indexInConversation instead of SILENTLY skipping the cut (the silent skip variant shipped a wrong
        // range without any signal).
        if ($anchorMessage !== null) {
            $anchorPosition = array_search($anchorMessage, $sortedMessages, true);
            if ($anchorPosition !== false) {
                $sortedMessages = array_slice($sortedMessages, 0, $anchorPosition + 1);
            } elseif (($anchorMessage->indexInConversation ?? null) !== null) {
                $anchorIndexInConversation = $anchorMessage->indexInConversation;
                $sortedMessages = array_values(array_filter(
                    $sortedMessages,
                    static fn (AIConversationMessage $message): bool
                        => ($message->indexInConversation ?? PHP_INT_MAX) <= $anchorIndexInConversation
                ));
            }
        }

        // Tree compaction: the recency-graded cover replaces everything up to its prefix edge with
        // ONE synthetic summary (oldest→newest, coarse→fine); the rest is the raw verbatim tail. Point-in-time via
        // $onlyCompactionsBefore = the inspected message's requestSnapshot->sentAt (asOf) — nodes that landed AFTER
        // the wire call must not falsify "what was sent then" (the point-in-time gate generalized to the tree).
        $coveredUpToIndex = $this->getCompactionCoveredUpToIndex($onlyCompactionsBefore);
        if ($coveredUpToIndex !== null) {
            $agentModelInputHistory->compactionSummary = $this->buildCompactionSummaryText($onlyCompactionsBefore);
            $sortedMessages = array_values(array_filter(
                $sortedMessages,
                static fn (AIConversationMessage $message): bool => ($message->indexInConversation ?? -1) > $coveredUpToIndex
            ));
        }

        $agentModelInputHistory->messages = array_values($sortedMessages);
        // Tool-result clearing boundary: computed ONCE over the (bounded) tail; the egress stubs older/
        // over-budget tool RESULTS O(1)/result. Only the raw tail carries tool results (the cover is a summary string),
        // so this is inherently bounded — no whole-conversation scan.
        $toolResultRetention = $this->resolveToolResultRetention($agentModelInputHistory->messages);
        $agentModelInputHistory->keepToolResultsFromIndex = $toolResultRetention->fromIndex;
        $agentModelInputHistory->toolResultIndexesStubbedByBudget = $toolResultRetention->stubbedIndexes;
        // Per-message truth: the anchor carries the cumulative loaded-capability set (skills + tools). Search
        // BACKWARD through the range — a capability-less anchor (injected signal, carry gap) must not build a
        // skill-less context (that presented the model a context without its pinned conductor).
        for ($rangePosition = count($agentModelInputHistory->messages) - 1; $rangePosition >= 0; $rangePosition--) {
            if ($agentModelInputHistory->messages[$rangePosition]->hasLoadedCapabilities()) {
                $agentModelInputHistory->loadedCapabilities = $agentModelInputHistory->messages[$rangePosition]->getLoadedCapabilities();
                $agentModelInputHistory->capabilityAnchorMessage = $agentModelInputHistory->messages[$rangePosition];
                $agentModelInputHistory->loadedAISkills = $agentModelInputHistory->capabilityAnchorMessage->findLoadedAISkills();
                break;
            }
        }
        return $agentModelInputHistory;
    }

    /** Phase F into the context: the history's messages, cover summary, retention verdict and loaded capabilities. */
    protected function putHistoryIntoContext(AgentModelInput $agentContext, AgentModelInputHistory $agentModelInputHistory): void
    {
        $agentContext->compactionSummary = $agentModelInputHistory->compactionSummary;
        $agentContext->messages = $agentModelInputHistory->messages;
        $agentContext->keepToolResultsFromIndex = $agentModelInputHistory->keepToolResultsFromIndex;
        $agentContext->toolResultIndexesStubbedByBudget = $agentModelInputHistory->toolResultIndexesStubbedByBudget;
        $agentContext->loadedCapabilities = $agentModelInputHistory->loadedCapabilities;
        $agentContext->loadedAISkills = $agentModelInputHistory->loadedAISkills;
    }

    /** Phase G: the situation variants, who the agent is talking to and the surface the owner is on. */
    protected function putOwnerPresenceAndVariantsIntoContext(AgentModelInput $agentContext): void
    {
        // Skill variants: the LIVE situation from the working state (the loop resolved it at turn start
        // and after every record-changing call); a point-in-time reconstruction overrides it from the snapshot
        // ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::buildModelInputThatProducedThisMessage()}).
        $agentContext->variants = $this->isDelegation() ? null : ($this->getAgentWorkingState()?->variants);
        if (!$agentContext->isDelegation) {
            // Who the agent is talking to: roots only, the same guard the purpose directive uses; the loaded tail
            // tells a phone conversation continued in the chat from one left after the call; a historical rebuild
            // derives it from the same columns, so the inspector shows it. Computed BEFORE the surface.
            $agentContext->ownerPresence = $this->getOwnerPresence($agentContext->messages);
            // an unattended run whose report goes out by mail is taught the kit's e-mail variant
            $agentContext->writesEmailReport = $agentContext->ownerPresence === OwnerPresence::ABSENT_SCHEDULED_RUN
                && ($this->reportChannel ?? null) === AIConversationSchedule::REPORT_CHANNEL_EMAIL;
            // Owner channel awareness: the SURFACE the owner is on right now — derived per call
            // from the newest owner text row in the loaded tail that carries a screen device, merged into a COPY of
            // the rendered variants AFTER the working-state copy above (the record stays untouched: the owner starts
            // on the phone and continues on the laptop; the snapshot records what each call was rendered with). No
            // device row → no dimension → the `[surface=screen]` default applies.
            $this->mergeSurfaceVariantIntoContext($agentContext, $agentContext->ownerPresence === OwnerPresence::PRESENT_ON_THE_PHONE);
            // which call channel reaches the owner right now — only while a loaded skill keys sections on it
            $this->mergeVoiceCallReachVariantsIntoContext($agentContext);
        } elseif ($this->getOwnerPresence() === OwnerPresence::PRESENT_ON_THE_PHONE) {
            // a delegation child of a turn whose owner is on the phone line writes for the caller too — the
            // presence and `surface=phone` only, never the root's other variants (a child keeps its own wire).
            $agentContext->ownerPresence = OwnerPresence::PRESENT_ON_THE_PHONE;
            $this->mergeSurfaceVariantIntoContext($agentContext, true);
        }
    }

    /** Phase I: the conversation's own creation-time snapshots — never for a delegation child. */
    protected function putCreationSnapshotsIntoContext(AgentModelInput $agentContext): void
    {
        // Episodic history: the conversation's OWN creation-time narrative snapshot — a loaded column,
        // no service/repo touch (single-level isset is lazy-safe). Never for a delegation child (children get a focused
        // task, not the customer's story; their column is unset anyway).
        if (!$agentContext->isDelegation && isset($this->episodicHistory)) {
            $agentContext->episodicHistory = $this->episodicHistory;
        }
        // Day context: the conversation's OWN creation-time snapshot on its working state — never the day's
        // row, which a later update of the day changes. Never for a delegation child.
        if (!$agentContext->isDelegation && isset($this->agentWorkingState)) {
            $agentContext->dayContext = $this->agentWorkingState->dayContextText;
            // the local situation: the conversation's own snapshot as well
            $agentContext->localSituation = $this->agentWorkingState->localSituationText;
        }
    }

    /**
     * Phase J: the standing-instruction (DIRECTIVE) memories for this account ∪ agent space, rendered as the
     * system block — null without an account or without directives. Part of the per-account-STABLE prefix (cacheable).
     * The directives query runs once per memo key ({@see $memoryDirectivesMemoKey}): the scope plus the number of
     * COMPLETED memory-tool rows (remember / updateMemory / forget) — the key moves exactly when a mutation landed.
     */
    public function findMemoryDirectivesBlock(): ?string
    {
        if (!(isset($this->accountId) && $this->accountId)) {
            return null;
        }
        /** @var AgentMemoriesService $agentMemoriesService */
        $agentMemoriesService = DDDService::instance()->getService(AgentMemoriesService::class);
        $memoryDirectivesMemoKey = (int)$this->accountId . '|' . (int)($this->agentSpaceId ?? 0) . '|'
            . $this->messages->buildToolCallHistory()->countCompletedToolCallsNamed(['remember', 'updateMemory', 'forget']);
        if ($this->memoryDirectivesMemoKey !== $memoryDirectivesMemoKey) {
            $agentStandingDirectives = $agentMemoriesService->findStandingDirectivesForAgentSpace((int)$this->accountId, $this->agentSpace ?? null);
            $this->memoryDirectivesMemoKey = $memoryDirectivesMemoKey;
            $this->memoryDirectivesMemoBlock = $agentStandingDirectives?->directivesBlock;
        }
        return $this->memoryDirectivesMemoBlock;
    }

    /** Phase K into the context: the two entitlement axes and the three product-awareness texts. */
    protected function putProductAwarenessIntoContext(AgentModelInput $agentContext): void
    {
        // the loaded-skills block filters each skill's tools line by entitlement (AgentModelInput passes this
        // into AISkill::getInstructionBlock()), so it cannot contradict the product-capabilities block.
        $agentContext->featureFlags = $this->findFeatureFlags();
        // the SECOND axis, set unconditionally (it does not depend on a space): the loaded-skills
        // block drops the tools this user may not call, and the restricted block names what was dropped.
        $agentContext->accountRights = $this->getAccountRights();
        $agentProductAwareness = $this->findProductAwareness();
        $agentContext->restrictedCapabilities = $agentProductAwareness->restrictedCapabilities;
        $agentContext->productCapabilities = $agentProductAwareness->productCapabilities;
        $agentContext->aiBudgetAwareness = $agentProductAwareness->aiBudgetAwareness;
    }

    /**
     * Phase K: the product-capabilities awareness, built by the entitlement service
     * ({@see AIConversationEntitlementsService::buildProductAwareness()}; the host: the agent space's applied flags + the
     * agent-relevant flag set, the capabilities restricted for the acting user and the AI-budget policy). Scope-guarded (a scope-less conversation has no agent space → no gating, no
     * awareness), rights-guarded (an account-less one has no restrictions). Part of the per-account-STABLE prefix
     * (cacheable), injected after the memory directives. `buildVoiceSessionContext()` builds its own product block.
     */
    public function findProductAwareness(): AgentProductAwareness
    {
        /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
        $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        return $aIConversationEntitlementsService->buildProductAwareness($this);
    }

    /**
     * The customer's configured interface language, resolved by the scope service
     * ({@see AgentSpacesService::resolveUserLanguageCode()}; an application: the interface language of its space shared with the agent's
     * phone voice, then the account and platform fallbacks).
     * The SAME chain that fills {%USER_LANGUAGE%} in the agent prompt
     * ({@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIConversation::getAIPromptWithParametersApplied()}), plus the
     * account's own languageCode as the fallback without a space. Used to localize the product/plan display names in
     * the product-awareness block and the template-based
     * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport} scaffold (which must never make a model call,
     * so the model cannot pick the language itself there) — the agent drive path does not set the ambient translation
     * language; the agent's REPLY language is decided separately by the model. isset-guarded so an unset #[LazyLoad]
     * space/account does not force a load.
     */
    public function resolveUserLanguageCode(): string
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->resolveUserLanguageCode($this);
    }

    /**
     * The reply-language decision the context build must APPLY: the working-state stamp, only when a
     * decision model decided it (`decidedBy` jev — a shadow stamp or a fallback is a record, not an instruction) AND
     * it belongs to the NEWEST owner message (a stamp from an earlier turn never names this turn's language — it is
     * that turn's chain that carries it forward as the anchor). Null = today's configured language applies.
     * isset-guarded so an unset working state does not force a load.
     */
    public function getAppliedReplyLanguageDecision(): ?AgentReplyLanguageDecision
    {
        $agentWorkingState = $this->getAgentWorkingState();
        $replyLanguageDecision = $agentWorkingState?->replyLanguageDecision;
        if ($replyLanguageDecision === null || !$replyLanguageDecision->isApplied()) {
            return null;
        }
        $newestOwnerMessageIndex = $this->getNewestUserMessage()?->indexInConversation ?? null;
        if ($newestOwnerMessageIndex === null || $replyLanguageDecision->ownerMessageIndex !== $newestOwnerMessageIndex) {
            return null;
        }
        return $replyLanguageDecision;
    }

    /**
     * The language the reply-language cue names for the current turn: an applied decision-model verdict
     * ({@see getAppliedReplyLanguageDecision()}), else the configured chain ({@see resolveUserLanguageCode()}).
     */
    public function resolveReplyLanguageCode(): string
    {
        return $this->getAppliedReplyLanguageDecision()?->languageCode ?? $this->resolveUserLanguageCode();
    }

    /**
     * Deterministic safety net for the agent's ANSWER text: replace any English product-plan name the model emitted
     * ("Workshop Planner") with its localized form ("Werkstattplaner"), because a cheap model reproduces
     * the English name from its own knowledge even when the product-awareness block already gave it the localized one.
     * Delegates to {@see AIConversationEntitlementsService::localizeProductNamesInText()} (the host: the customer's language
     * and the agent space's applied flags, which own the plans). No entitlement component → unchanged.
     *
     * @param string $answerText
     * @return string
     */
    public function localizeProductNamesInAnswer(string $answerText): string
    {
        if ($answerText === '') {
            return $answerText;
        }
        /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
        $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        return $aIConversationEntitlementsService->localizeProductNamesInText($this, $answerText);
    }

    /** Global defaults for the tool-result clearing gate — overridable per-conversation via {@see ContextRetentionSettings}. */
    public const int CONTEXT_RETENTION_DEFAULT_KEEP_TOOL_RESULTS_RECENT_TURNS = 2;
    /**
     * 24k: at 8k a web research step (a hit list plus page extracts) pushed
     * the step before it to a stub before the answer was written. A purpose runs on it as is — the context budget does
     * not scale it — unless its `contextRetention:` map pins `toolResultTokenBudget` (a purpose may pin a smaller one).
     */
    public const int CONTEXT_RETENTION_DEFAULT_TOOL_RESULT_TOKEN_BUDGET = 24000;

    /**
     * The index from which tool RESULTS are kept VERBATIM on the wire; a result on a message BELOW it
     * is stubbed by the egress. TWO combined caps over the passed (bounded) tail, ONE early-break reverse pass, O(1)/msg:
     *  (a) TURN window — keep results from the last `keepToolResultsRecentTurns` turns (a turn = a user-initiator
     *      message); anchored to turns → cache-stable within a turn, batched across turns.
     *  (b) TOKEN budget — within that, keep the newest results until Σ `toolResultChars`/4 reaches
     *      `toolResultTokenBudget` (always keep ≥ the single newest result).
     * A result is kept iff within BOTH → boundary = max of the two cap indices. The token side reads the STORED
     * {@see AIConversationMessage::$toolResultChars} (chars/4 — NEVER the GPT-3 tokenizer; ephemeral fallback mb_strlen).
     * `PHP_INT_MIN` = keep all (feature off / neither cap hit). Pure over the given messages + the resolved
     * per-conversation ({@see $contextRetentionSettings}) / global policy.
     *
     * @param AIConversationMessage[] $tailMessages index-sorted ascending (the AgentModelInput's messages)
     */
    public function getToolResultRetentionFromIndex(array $tailMessages): int
    {
        return $this->resolveToolResultRetention($tailMessages)->fromIndex;
    }

    /**
     * The full retention verdict ({@see getToolResultRetentionFromIndex()} is its boundary alone): the token budget
     * keeps every result that still FITS, newest first — a result that does not fit is stubbed by itself
     * ({@see ToolResultRetention::$stubbedIndexes}) and the walk goes on, so a small older result behind two large
     * ones stays on the wire (the contiguous boundary evicted a 2.5k summary behind two 26k page reads although it
     * fit, and the model re-read it). Unseen results are never in the stubbed set.
     *
     * @param AIConversationMessage[] $tailMessages index-sorted ascending (the AgentModelInput's messages)
     */
    /** Tool results the budget-by-fit rule never stubs inside the kept turns — the reads a later draft or answer is composed from (the search and its readings too). */
    public const array BUDGET_EXEMPT_TOOL_RESULT_NAMES = ['getWebPageAsMarkdown', 'searchTheWeb', 'extractInformationFromWebPagesWithFastLLM'];

    public function resolveToolResultRetention(array $tailMessages): ToolResultRetention
    {
        $settings = $this->contextRetentionSettings ?? null;
        if ($settings !== null && !$settings->isEnabled()) {
            return new ToolResultRetention(); // footprint layer off for this conversation → keep all
        }
        $keepRecentTurns = $settings?->keepToolResultsRecentTurns ?? self::CONTEXT_RETENTION_DEFAULT_KEEP_TOOL_RESULTS_RECENT_TURNS;
        $tokenBudget = $settings?->toolResultTokenBudget ?? self::CONTEXT_RETENTION_DEFAULT_TOOL_RESULT_TOKEN_BUDGET;
        if ($keepRecentTurns < 1 && $tokenBudget < 1) {
            return new ToolResultRetention();
        }

        $turnBoundary = PHP_INT_MIN;     // K-th-newest turn's start index; below → stub by the turn rule
        $budgetBoundary = PHP_INT_MIN;   // the OLDEST result the budget still holds; below → stub by the budget rule
        $stubbedByBudget = [];           // results above the budget boundary that did NOT fit (budget by fit)
        $lastAssistantIndex = PHP_INT_MIN; // newest ASSISTANT reply — results AFTER it are UNSEEN (never stub them)
        $seenMarkerIsToolCallRow = false;
        $userTurns = 0;
        $accumulatedChars = 0;
        $turnCapReached = $keepRecentTurns < 1;
        $budgetCapReached = $tokenBudget < 1;
        $continueAfterPausePositions = AIConversationOwnerTurns::fromRows($tailMessages)->getOwnerContinuePositionsAfterPauseReports();
        for ($position = count($tailMessages) - 1; $position >= 0; $position--) {
            $message = $tailMessages[$position];
            $messageIndex = $message->indexInConversation ?? PHP_INT_MAX;
            // The newest MODEL-CALL row marks the SEEN boundary: a tool result is only ever stubbed once a LATER
            // model call has reasoned over it (the model saw it ≥ once). Results after it are brand-new (the
            // upcoming call sees them for the first time) → NEVER stub, regardless of budget — the owner's invariant.
            // Two marker forms: an ASSISTANT-role row (a prose head / final answer), OR any usage-stamped row
            // (`inputTokens > 0` — a model call happened AT this row, so everything before it was in that call's
            // context). The second form is load-bearing since the step-persistence remodel: a PROSE-LESS tool step
            // persists NO assistant TEXT head anymore — its stamped first TOOL_CALL row is the step's seen marker
            // (without it the unseen floor would collapse to the previous turn's final answer and disable in-turn
            // clearing for every prose-less multi-step run). Also correct for stamped stall nudges/bounces.
            if ($lastAssistantIndex === PHP_INT_MIN
                && (($message->role ?? null) === AIConversationMessage::ROLE_ASSISTANT
                    || ($message->inputTokens ?? 0) > 0)) {
                $lastAssistantIndex = $messageIndex;
                // A stamped TOOL_CALL head: the model call happened BEFORE this row's own result existed — the result
                // is as unseen as its siblings'. Only a prose row (an answer, a head text) marks its own index as seen.
                $seenMarkerIsToolCallRow = $message->messageType === AIConversationMessage::MESSAGE_TOOL_CALL;
            }
            if (!$turnCapReached
                && $message->isOwnerOrLegacyUserRoleRow()
                && !isset($continueAfterPausePositions[$position])) {
                $userTurns++;
                if ($userTurns >= $keepRecentTurns) {
                    $turnBoundary = $messageIndex; // keep results from this (K-th-newest) turn forward
                    $turnCapReached = true;
                }
            }
            if (!$budgetCapReached) {
                $toolCall = isset($message->toolCall) ? $message->toolCall : null;
                if ($message->messageType === AIConversationMessage::MESSAGE_TOOL_CALL
                    && $toolCall !== null && $toolCall->result !== null && $toolCall->result !== '') {
                    $resultChars = $message->toolResultChars ?? mb_strlen($toolCall->result);
                    // A page read is the research a later write rests on: never stubbed by the budget while its turn is
                    // kept (what an early read found was cleared before the write and reported as "none found").
                    $budgetExempt = in_array((string)($toolCall->name ?? ''), self::BUDGET_EXEMPT_TOOL_RESULT_NAMES, true);
                    if (!$budgetExempt && $accumulatedChars > 0 && (int)(($accumulatedChars + $resultChars) / 4) > $tokenBudget) {
                        // BUDGET BY FIT: this result does not fit — it is stubbed by itself; an older, smaller one
                        // may still fit, so the walk goes on. The newest result is always kept.
                        $stubbedByBudget[$messageIndex] = true;
                    } else {
                        $accumulatedChars += $resultChars;
                        $budgetBoundary = $messageIndex; // the oldest result the budget holds so far
                    }
                }
                // Below the kept turn window every result is stubbed by the turn rule — the budget walk is over.
                if ($turnCapReached && $messageIndex < $turnBoundary) {
                    $budgetCapReached = true;
                }
            }
            // Only break once the UNSEEN floor is also captured (the newest assistant is recent → found first); if the
            // tail has no assistant at all, run the full bounded pass (then unseen-floor stays PHP_INT_MIN = keep all).
            if ($turnCapReached && $budgetCapReached && $lastAssistantIndex !== PHP_INT_MIN) {
                break; // all three determined → stop walking
            }
        }
        if ($stubbedByBudget === []) {
            $budgetBoundary = PHP_INT_MIN; // the budget pruned nothing → no budget boundary (keep all by this rule)
        }
        // Keep a result iff (within BOTH caps: idx ≥ max(turn, budget)) OR (UNSEEN: idx ≥ lastAssistant+1) → the keep
        // threshold is the MIN of the two. The unseen floor guarantees a just-produced result is always sent ≥ once,
        // even if it alone blows the budget — the caps only ever prune results the model has already reasoned over.
        $capBoundary = max($turnBoundary, $budgetBoundary);
        // The stamped first call row of a prose-less step is the seen marker AND carries an unseen result (produced
        // after the call it marks): the floor includes it. Two 26 KB page reads of one step otherwise lost the first
        // one before the model ever read it.
        $unseenFloor = $lastAssistantIndex === PHP_INT_MIN
            ? PHP_INT_MIN
            : ($seenMarkerIsToolCallRow ? $lastAssistantIndex : $lastAssistantIndex + 1);
        $fromIndex = min($capBoundary, $unseenFloor);
        // An UNSEEN result is never stubbed, and a result below the boundary is stubbed by the boundary already —
        // the set carries only the seen results above it that did not fit.
        $stubbedIndexes = [];
        foreach ($stubbedByBudget as $stubbedIndex => $_) {
            if ($stubbedIndex >= $fromIndex && $stubbedIndex < $unseenFloor) {
                $stubbedIndexes[$stubbedIndex] = true;
            }
        }
        return new ToolResultRetention($fromIndex, $stubbedIndexes);
    }

    // ---- Compaction planning — pure logic ON the aggregate; the model call + persistence live in AIConversationCompactionService ----

    /** {@see AIConversationMessages::getNewestInputTokens()} over this conversation's loaded messages. */
    public function getLatestContextInputTokens(): ?int
    {
        return ($this->messages ?? null)?->getNewestInputTokens();
    }

    /**
     * The owner's turns over the index-sorted rows — the turn count, the agent turns. Transitional:
     * when the sort memo moves to the set (with the conversation scans), the set's builder is the one that remains.
     */
    public function buildOwnerTurns(): AIConversationOwnerTurns
    {
        return AIConversationOwnerTurns::fromRows($this->getSortedMessages());
    }

    /**
     * The TOKEN-BUDGET compaction shape for THIS conversation — resolve `workingContextLimit` from the 2
     * trigger knobs (`min(triggerTokens, fraction · window)`, with the `window ≤ 0 → triggerTokens` guard for
     * provider-varying OpenRouter windows), measure the fixed overhead, and derive the rest via
     * {@see CompactionBudgets::fromMeasured()}. Pure (reads loaded messages + settings; no repo).
     */
    public function buildCompactionBudgets(int $effectiveContextTokens): CompactionBudgets
    {
        $settings = $this->compactionSettings ?? null;
        // ABSOLUTE working-context limit: the per-conversation override wins, else the model's effectiveContextTokens
        // (the compaction threshold). No fraction-of-window. Everything else (leaf size, raw tail, summary budget) is
        // derived from this single value by CompactionBudgets::fromMeasured().
        $triggerTokens = $settings?->triggerTokens;
        $workingContextLimit = ($triggerTokens !== null && $triggerTokens > 0) ? $triggerTokens : $effectiveContextTokens;
        // the newest STAMPED overhead (system content without the summary + the offered schemas, measured
        // at the wire call) — the earliest loaded input is only the fallback for rows before the stamp shipped: after a
        // compaction it is a later, history-heavy prompt and sized the budget degenerate on every long purposed run.
        return CompactionBudgets::fromMeasured(
            $workingContextLimit,
            ($this->messages ?? null)?->getNewestFixedOverheadTokens() ?? ($this->messages ?? null)?->getOldestInputTokens() ?? 0,
            $settings?->debugLeafSegmentTokensOverride
        );
    }

    /**
     * What one threshold round of the compaction tree works with: the token budget for
     * `$effectiveContextTokens` — on a degenerate budget none, and the per-conversation message-count knobs (else
     * {@see CompactionSettings}' defaults) plan the leaves instead —, the footprints (token path only), the leaf ranges
     * to close, the roll-up width ({@see getChildNodesPerCompactionRollUp()}) and the per-level retention (the
     * budget's fine nodes per level, else the `levelRetention` knob). Pure over the loaded messages and tree; the
     * caller loads both first.
     */
    public function buildCompactionRoundSettings(int $effectiveContextTokens): AIConversationCompactionRoundSettings
    {
        // NPE guard: compactionSettings is nullable + DEFAULTS to null (the production default).
        // Coalesce to a fresh all-null instance so every `$settings->…` read resolves to its `?? DEFAULT` branch.
        $settings = $this->compactionSettings ?? new CompactionSettings();
        // Token-budget shape is the DEFAULT: the whole tree (leaf size, raw tail, summary budget) is derived in
        // tokens from the ABSOLUTE threshold. A degenerate budget (overhead eats it / too small to tile) falls back to
        // the message-count knobs (the safe fallback).
        $budgets = $this->buildCompactionBudgets($effectiveContextTokens);
        $childNodesPerRollUp = $this->getChildNodesPerCompactionRollUp($budgets);
        if ($budgets->isDegenerate) {
            $budgets = null;
        }
        $keepRecentMessages = $settings->keepRecentMessages ?? CompactionSettings::DEFAULT_KEEP_RECENT_MESSAGES;
        $segmentMessages = $settings->segmentMessages ?? CompactionSettings::DEFAULT_SEGMENT_MESSAGES;
        $levelRetention = $budgets !== null ? $budgets->fineNodesPerLevel : ($settings->levelRetention ?? CompactionSettings::DEFAULT_LEVEL_RETENTION);
        // ALL leaf ranges in ONE pass; the footprint map is computed once and threaded into both the planner
        // and the leaf-node build. Token-budget tiling by default; the message-count tiling only on the fallback.
        $footprints = $budgets !== null ? $this->buildMessageFootprints() : null;
        $compactionRoundSettings = new AIConversationCompactionRoundSettings();
        $compactionRoundSettings->compactionBudgets = $budgets;
        $compactionRoundSettings->messageFootprints = $footprints;
        $compactionRoundSettings->leafRanges = $budgets !== null
            ? $this->buildPendingLeafRanges($budgets, $footprints)
            : $this->buildPendingLeafRangesByMessageCount($keepRecentMessages, $segmentMessages);
        $compactionRoundSettings->childNodesPerRollUp = $childNodesPerRollUp;
        $compactionRoundSettings->retainedExposedNodesPerLevel = $levelRetention;
        return $compactionRoundSettings;
    }

    /**
     * How many exposed nodes one roll-up consolidates on `$compactionBudgets` — the ONE rule the threshold round and
     * the completion pass share: the budget's self-similar {@see CompactionBudgets::$rollupFanIn} on a tileable
     * budget; on a DEGENERATE budget the per-conversation {@see CompactionSettings::$tierFanIn} override, else
     * {@see CompactionSettings::DEFAULT_TIER_FAN_IN}.
     */
    public function getChildNodesPerCompactionRollUp(CompactionBudgets $compactionBudgets): int
    {
        if (!$compactionBudgets->isDegenerate) {
            return $compactionBudgets->rollupFanIn;
        }
        // `??` reads the whole chain isset-style: an unset or null compactionSettings falls to the default
        return $this->compactionSettings->tierFanIn ?? CompactionSettings::DEFAULT_TIER_FAN_IN;
    }

    /**
     * Whether the conversation has grown enough to warrant a PROACTIVE compaction — the live context footprint has
     * reached the configured fraction of the model's usable window. The watermark sits well below the
     * wall so the async job lands before the ceiling. False when the footprint is unknown.
     */
    /**
     * The ABSOLUTE compaction threshold in tokens actually in effect: the per-conversation {@see CompactionSettings::$triggerTokens}
     * override when set (>0), else the passed model/global fallback. The single source of truth for both the decision
     * ({@see shouldConsiderCompaction}) and the trace label (so the logged threshold is the one really applied).
     */
    public function getEffectiveCompactionThreshold(int $fallbackContextTokens): int
    {
        $triggerTokens = $this->compactionSettings?->triggerTokens ?? null;
        return ($triggerTokens !== null && $triggerTokens > 0) ? $triggerTokens : $fallbackContextTokens;
    }

    /**
     * Admin observability: which compaction threshold is in effect for this conversation, where
     * it comes from (per-conversation override / purpose context budget / model effectiveContextTokens / 100k
     * fallback), the live footprint and whether compaction is due — the resolution {@see shouldConsiderCompaction()}
     * applies, so the admin sees the real decision. The world reads are gathered here (one model-catalog read, the
     * purpose registry); the breakdown is {@see CompactionThresholdBreakdown::buildFor()}. The node count reads the
     * LOADED tree (`?->` on the lazy relation loads it when unset; the admin endpoint loads the full set first).
     */
    public function buildCompactionThresholdBreakdown(): CompactionThresholdBreakdown
    {
        $compactionModelLimits = $this->findCompactionModelLimits();
        // The PURPOSE CONTEXT BUDGET candidate: the purposed start stamps the purpose's budget (window-clamped)
        // as the conversation's triggerTokens — so an override EQUAL to that clamped budget is the purpose's.
        $purposeContextBudgetTokens = null;
        $declaredPurposeBudget = $this->findPurpose()?->contextBudgetTokens;
        if ($declaredPurposeBudget !== null && $declaredPurposeBudget > 0) {
            $purposeContextBudgetTokens = $compactionModelLimits->clampContextBudget($declaredPurposeBudget);
        }
        return CompactionThresholdBreakdown::buildFor(
            $compactionModelLimits,
            $this->aiModelNameOverride ?? null,
            $this->compactionSettings ?? null,
            $this->purposeName ?? null,
            $purposeContextBudgetTokens,
            $this->getEffectiveCompactionThreshold($compactionModelLimits->effectiveContextTokens),
            $this->shouldConsiderCompaction($compactionModelLimits->effectiveContextTokens),
            $this->getLatestContextInputTokens(),
            count($this->compactionNodes?->getElements() ?? [])
        );
    }

    public function shouldConsiderCompaction(int $effectiveContextTokens): bool
    {
        $settings = $this->compactionSettings ?? null;
        if ($settings !== null && !$settings->isEnabled()) {
            return false;
        }
        $contextTokens = $this->getLatestContextInputTokens();
        if ($contextTokens === null) {
            return false;
        }
        $thresholdTokens = $this->getEffectiveCompactionThreshold($effectiveContextTokens);
        return $thresholdTokens > 0 && $contextTokens >= $thresholdTokens;
    }

    /**
     * The recency-graded resolution COVER for the model context: the minimal set of compaction
     * tree nodes that tiles [0 .. coveredUpTo] — coarsest (highest level) for old history, finest (level 0) near the
     * present. Derived IN-MEMORY from the already-loaded {@see $compactionNodes} (the SAME path for persisted and
     * ephemeral conversations — the caller loads the set, exactly as it loads `messages`; this method never queries).
     *
     * Greedy + GAP-SAFE: at each position take the node that COVERS it (coveredFrom <= pos <= coveredTo, NOT
     * exact-start — tolerant of a half-applied async roll-up cascade), preferring the coarsest level then the longest
     * reach, and advance past its end. `$asOf` makes it point-in-time: pass a past message's
     * `requestSnapshot.sentAt` to reconstruct the cover as it was when that message was produced (the immutable
     * append-only tree + the `created` time make this exact — the forensic point-in-time gate generalized); null = now (all nodes).
     * Its nodes run oldest→newest.
     *
     * @return AIConversationCompactionCover the cover; an empty one when no tree is loaded
     */
    public function buildCompactionCover(?DateTime $asOf = null): AIConversationCompactionCover
    {
        // The cover logic lives ON the tree (the node set); guard the #[LazyLoad] boundary with isset so
        // a build over an unloaded/ephemeral-pre-first-round tree stays in-memory + inert.
        return isset($this->compactionNodes) ? $this->compactionNodes->buildCover($asOf) : AIConversationCompactionCover::ofCoverNodes([]);
    }

    /**
     * The prefix-tiling edge: the highest message index the compaction cover fully covers, so
     * every message with a STRICTLY greater index is the raw verbatim tail the read keeps in full. Null = nothing
     * compacted yet (the whole conversation is raw). Derived from {@see buildCompactionCover()} — NOT a MAX over all
     * nodes (a half-applied cascade could leave a node past the contiguous prefix; the prefix edge is what's safe).
     */
    public function getCompactionCoveredUpToIndex(?DateTime $asOf = null): ?int
    {
        return isset($this->compactionNodes) ? $this->compactionNodes->getCoveredUpToIndex($asOf) : null;
    }

    /**
     * Render the cover into the ONE synthetic "conversation so far" summary fed to the model:
     * standing constraints pinned once on top, then each cover node oldest→newest with its `[messages a–b]` range
     * label — so resolution rises toward the present (coarse old → fine recent) and reads as a continuous timeline
     * into the raw tail. Null when nothing is compacted. Pure (over the loaded tree).
     */
    public function buildCompactionSummaryText(?DateTime $asOf = null): ?string
    {
        return isset($this->compactionNodes) ? $this->compactionNodes->buildSummaryText($asOf) : null;
    }

    /**
     * The text fed to the display-summary generator ({@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIConversationSummary})
     * for the conversation TITLE + SUMMARY. Built ON the SAME {@see AgentModelInput} the agent assembles for the latest
     * message (null anchor = whole conversation): the compaction cover (coarse old → fine recent) + the raw verbatim
     * tail, already correctly index-aligned via `indexInConversation`. {@see AgentModelInput::buildSummaryContent()} then
     * keeps only the conversation CONTENT and drops the scaffolding (system prompt, skills, memory directives,
     * fetched-reads, tool schemas) — the summary model sees only what the conversation is ABOUT. Reusing the agent
     * context = no duplicate cover/tail logic, bounded (logarithmic) input, consistent with what the agent saw.
     * `baseSystemPrompt` is '' because `buildSummaryContent()` discards the system content. Pure.
     */
    public function buildDisplaySummaryInput(): string
    {
        $summaryContent = $this->buildModelInputUpToAndIncludingMessage(null, '')->buildSummaryContent();
        $frameText = $this->buildConversationFrameText();

        return $frameText !== null ? $frameText . "\n\n" . $summaryContent : $summaryContent;
    }

    /**
     * The compact CONVERSATION FRAME: what this conversation structurally IS — a purposed conversation (which
     * purpose, and what it is for), one run of a scheduled task (which task), a delegation sub-conversation.
     * Null for a plain dialog.
     *
     * The frame exists because every summarizer input strips the system scaffolding (purpose directive,
     * scheduled-run directive) as noise — right for token economy, but it left the summarizer blind to the
     * conversation's own nature: a guided, purposed conversation got summarized as whatever topic its last turns
     * grazed. Prepending these few lines anchors the display summary AND the compaction gists
     * ({@see buildDisplaySummaryInput}, {@see buildDisplaySummaryDeltaInput}, {@see buildLeafCompactionInput},
     * {@see buildRollUpCompactionInput}) without re-injecting the full directives.
     */
    public function buildConversationFrameText(): ?string
    {
        $frameLines = [];
        if (isset($this->purposeName) && $this->purposeName !== null && $this->purposeName !== '') {
            $purposeDescription = '';
            try {
                $purposeDescription = $this->findPurpose()?->description ?? '';
            } catch (Throwable) {
                // The frame is best-effort context — a missing purpose definition never blocks a summary.
            }
            $frameLines[] = 'This is a PURPOSED conversation executing the purpose "' . $this->purposeName . '"'
                . ($purposeDescription !== '' ? ' — ' . $purposeDescription : '') . '.';
        }
        if (isset($this->scheduleId) && $this->scheduleId !== null) {
            // A non-positive id is the eval harness's stand-in for "a schedule run" (no row exists) — no lazy-load,
            // and no id in the frame.
            $schedule = $this->scheduleId > 0 ? $this->schedule : null; // plain property read — the lazy-load is wanted here
            $scheduleIdentifier = $schedule !== null && isset($schedule->title) && trim((string)$schedule->title) !== ''
                ? ' "' . trim((string)$schedule->title) . '"'
                : ($this->scheduleId > 0 ? ' #' . $this->scheduleId : '');
            $scheduleTask = $schedule !== null && isset($schedule->task) && trim((string)$schedule->task) !== ''
                ? ' Its task: ' . mb_substr(trim((string)$schedule->task), 0, 400)
                : '';
            $frameLines[] = 'This is ONE RUN of a scheduled task' . $scheduleIdentifier . '.' . $scheduleTask;
        }
        if (isset($this->rootConversationId) && $this->rootConversationId !== null
            && $this->rootConversationId !== ($this->id ?? null)
        ) {
            $frameLines[] = 'This is a DELEGATION sub-conversation working one sub-task of conversation #'
                . $this->rootConversationId . '.';
        }
        if ($frameLines === []) {
            return null;
        }

        return "CONVERSATION FRAME (what this conversation IS — anchor the summary in it; never present an incidental side topic as its subject):\n- "
            . implode("\n- ", $frameLines);
    }

    /**
     * The summary this conversation holds already: the stored one, or one generated earlier in this process. Null
     * when none is held. Never generates one — reading `$summary` directly does, with a model call, when none is
     * stored. For every read that shows a summary beside other data (a search result, a list row).
     */
    public function getSummaryWithoutGenerating(): ?AIConversationSummary
    {
        return isset($this->summary) ? $this->summary : null;
    }

    /**
     * Whether the display summary needs a refresh: no summary yet, OR the conversation's newest message
     * index has grown past the summary's {@see AIConversationSummary::$coveredUpToMessageIndex} staleness marker.
     * A `manuallyEdited` title latches the WHOLE auto-refresh off (the pre-existing compaction-refresh semantic).
     * Single-level `isset` is lazy-safe — it must never fire the Argus generator. Pure over the loaded messages.
     */
    public function isDisplaySummaryStale(): bool
    {
        if (!isset($this->summary)) {
            return true;
        }
        if ($this->summary->manuallyEdited) {
            return false;
        }
        // getNextIndex() - 1 = the highest indexInConversation among the loaded messages (the bounded tail always
        // contains the newest message on every settle/load path — see loadMessagesForDerive).
        $highestMessageIndex = $this->messages->getNextIndex() - 1;
        return $highestMessageIndex > ($this->summary->coveredUpToMessageIndex ?? -1);
    }

    /**
     * The INCREMENTAL summarizer input: the PREVIOUS summary (title/text/outcome) + ONLY the conversation
     * content past its staleness marker — so a refresh costs one bounded slice (typically one turn), never the whole
     * conversation. Falls back to the full {@see buildDisplaySummaryInput()} when the previous summary carries no
     * marker (pre-field summaries). Pure; the model call lives in the service/Argus repo.
     */
    public function buildDisplaySummaryDeltaInput(AIConversationSummary $previousSummary): string
    {
        $markerIndex = $previousSummary->coveredUpToMessageIndex ?? null;
        if ($markerIndex === null) {
            return $this->buildDisplaySummaryInput();
        }
        $deltaContext = AgentModelInput::newInstance();
        $deltaContext->messages = array_values(array_filter(
            $this->getSortedMessages(),
            static fn (AIConversationMessage $message): bool => ($message->indexInConversation ?? -1) > $markerIndex
        ));
        $previousSummaryBlock = "PREVIOUS SUMMARY (update it with the new messages below — carry forward what still holds, revise what changed):\n"
            . 'Title: ' . ($previousSummary->title ?? '') . "\n"
            . 'Text: ' . ($previousSummary->text ?? '')
            . (isset($previousSummary->outcome) && $previousSummary->outcome !== ''
                ? "\nOutcome: " . $previousSummary->outcome : '');
        // The frame anchors the incremental refresh exactly like the full build — without it each refresh can
        // drift the summary toward whatever the newest slice happens to discuss.
        $frameText = $this->buildConversationFrameText();
        if ($frameText !== null) {
            $previousSummaryBlock = $frameText . "\n\n" . $previousSummaryBlock;
        }
        $deltaContent = $deltaContext->buildSummaryContent();
        return $previousSummaryBlock . "\n\n" . ($deltaContent !== ''
            ? "NEW MESSAGES SINCE THE PREVIOUS SUMMARY:\n" . $deltaContent
            : 'NO new user/assistant text since the previous summary (only internal activity) — keep the summary, refine the outcome if the actions below imply one.');
    }

    /**
     * Assemble the admin-debug breakdown of WHAT the compaction layer contributed to $message's model context,
     * reconstructed POINT-IN-TIME (the tree as it stood when $message was produced, via its requestSnapshot.sentAt —
     * the immutable append-only tree + node.created time make this exact). Returns the cover nodes (the compacted
     * aspects), the raw verbatim predecessor indices (the un-compacted aspects), the prefix edge, and the rendered
     * summary. Pure over the loaded tree + messages — the caller loads {@see $compactionNodes} + {@see $messages}
     * first (same contract as {@see buildCompactionCover}). `build…` = allocates a NEW composite VO.
     */
    public function buildMessageCompactionContext(AIConversationMessage $message): AIConversationMessageCompactionContext
    {
        $asOf = (isset($message->requestSnapshot) ? $message->requestSnapshot : null)?->sentAt;
        $coveredUpToMessageIndex = $this->getCompactionCoveredUpToIndex($asOf);

        $coverNodes = AIConversationCompactionNodes::newInstance();
        foreach ($this->buildCompactionCover($asOf)->getCoverNodes() as $coverNode) {
            $coverNodes->add($coverNode);
        }

        // Raw verbatim predecessors that were ALSO in this message's context: index > the cover edge AND before this
        // message (the producing call saw only predecessors). These are the un-compacted aspects of the context.
        $anchorIndex = $message->indexInConversation ?? PHP_INT_MAX;
        $rawTailMessageIndices = [];
        foreach ($this->getSortedMessages() as $sortedMessage) {
            $sortedMessageIndex = $sortedMessage->indexInConversation ?? null;
            if ($sortedMessageIndex === null || $sortedMessageIndex >= $anchorIndex) {
                continue;
            }
            if ($coveredUpToMessageIndex !== null && $sortedMessageIndex <= $coveredUpToMessageIndex) {
                continue;
            }
            $rawTailMessageIndices[] = $sortedMessageIndex;
        }

        $compactionContext = new AIConversationMessageCompactionContext();
        $compactionContext->coverNodes = $coverNodes;
        $compactionContext->coveredUpToMessageIndex = $coveredUpToMessageIndex;
        $compactionContext->rawTailMessageIndices = $rawTailMessageIndices;
        $compactionContext->compactionSummaryText = $this->buildCompactionSummaryText($asOf);
        $compactionContext->asOf = $asOf;
        return $compactionContext;
    }

    /**
     * Load this conversation's whole compaction tree (newest-cap-free), or null when ephemeral (no id). Delegates to
     * the {@see ConversationCompactionNodesService} — `find*` = crosses into the repo (mirrors {@see findMessagesInOrder}).
     * The turn-prep points assign the result to {@see $compactionNodes} so the in-memory cover/roll-up planning sees it.
     */
    public function findCompactionNodesInOrder(): ?AIConversationCompactionNodes
    {
        if (!isset($this->id)) {
            return null;
        }
        return AIConversationCompactionNodes::getService()->findAllNodesForConversation($this);
    }

    /**
     * The recency-graded COVER nodes only (the endless-scaling read) — the ~log_K(N) staircase the
     * context build consumes, loaded by walking the implicit tree one indexed lookup per step instead of loading the
     * whole node set. Set this onto {@see $compactionNodes} on the live turn path: the cover is a disjoint contiguous
     * tiling, so the in-memory {@see buildCompactionCover} / {@see buildCompactionSummaryText} /
     * {@see getCompactionCoveredUpToIndex} reproduce the IDENTICAL result over just these nodes. NOT for the forensic
     * asOf inspector — a cover-only LIVE load can't reconstruct a past cover; that path keeps the full
     * {@see findCompactionNodesInOrder} load + in-memory `created <= asOf` filter. Null on an unpersisted conversation.
     */
    public function findCompactionCoverNodes(?DateTime $asOf = null): ?AIConversationCompactionNodes
    {
        if (!isset($this->id)) {
            return null;
        }
        return AIConversationCompactionNodes::getService()->findCoverNodesForConversation($this, $asOf);
    }

    /**
     * The compaction nodes valid as of $asOf (created <= $asOf; null = now/all), sorted by covered-range start then
     * level (higher first). The bi-temporal `created` filter is what makes the point-in-time debug read correct over
     * the immutable, append-only tree. Pure in-memory over the loaded set.
     */
    protected function getCompactionNodesAsOf(?DateTime $asOf): AIConversationCompactionNodes
    {
        // Delegates to the tree (the node set). isset() (NOT ?-> / ??) keeps the #[LazyLoad] boundary
        // in-memory; unset (never loaded / ephemeral pre-first-round) → empty (safe/inert).
        return isset($this->compactionNodes) ? $this->compactionNodes->getNodesAsOf($asOf) : AIConversationCompactionNodes::newInstance();
    }

    /**
     * The compaction TURN-SETTLE FLOOR: while a turn is LIVE on this conversation (`runState`
     * RUNNING/AWAITING_DELEGATES — a compaction round now runs concurrently with chunked turns BY DESIGN), a round
     * may only cover PREVIOUS turns — never the in-flight turn's own messages. Covering them would (a) summarize
     * away tool results the running task still needs verbatim, (b) rob {@see AIConversationMessages::buildCurrentTurn()}
     * of its USER initiator (silently re-scoping every per-turn loop fold to the whole loaded tail), and (c) blind
     * the read-dedup/repeat guards to the covered calls. Returns the newest user-initiator's message index (the
     * first index a round must NOT cover), or null = no floor (settled conversation — IDLE and the finalize path
     * stay unclamped, completion compaction still covers to the end). Data-derived — the compaction worker's fresh
     * load carries `runState` + the messages; no parameter threading.
     */
    public function getUnfinishedTurnFloorIndex(): ?int
    {
        // The floor holds while a turn is OPEN, not merely while a worker is RUNNING: the yield→claim handover window ({@see $agentTurnOwedAt} set, runState briefly IDLE) — and its
        // strand case, the lost re-fire — is exactly when a floor-less async round could cover the in-flight turn.
        $turnIsLive = $this->getRunState()->holdsALiveTurn()
            || isset($this->agentTurnOwedAt);
        if (!$turnIsLive) {
            return null;
        }
        // isset-guarded (never `?->`): on an unset lazy relation `?->` would FIRE the lazy-load and read the capped
        // oldest-first window — a truncated set whose "newest initiator" is wrong. Callers (the planners) run over an
        // explicitly loaded set; without one there is no floor to derive.
        return isset($this->messages) ? $this->messages->getNewestInitiatorMessageIndex() : null;
    }

    /**
     * The compaction UNANSWERED-INBOUND FLOOR: the index of the OLDEST inbound message (a USER message or an injected
     * SIGNAL) that no final assistant TEXT has answered yet — the first index no round may cover — or null when the
     * newest wire-visible message is the agent's answer (nothing owed). A covered inbound message is invisible to the
     * bounded derive ({@see hasUnansweredInboundMessage} reads only the raw tail past the cover edge), so a completion
     * pass over a FAILED conversation with unanswered customer messages made the retry see "answered" and refuse —
     * the customer's messages were summarized away instead of driven. Same walk as the owed-turn predicates: ERROR /
     * CHECKPOINT rows, call-lifecycle markers, tool messages and stored non-SIGNAL SYSTEM rows are seen through; a
     * stranded TOOL_CALL after the inbound leaves it unanswered (the turn started, it never answered).
     */
    public function getUnansweredInboundFloorIndex(): ?int
    {
        if (!isset($this->messages)) {
            return null; // the planners run over an explicitly loaded set; without one there is no floor to derive
        }
        return $this->messages->getOldestUnansweredInboundIndex();
    }

    /**
     * The index a compaction round must stay BELOW — the lower of the unfinished-turn floor
     * ({@see getUnfinishedTurnFloorIndex}) and the unanswered-inbound floor ({@see getUnansweredInboundFloorIndex});
     * null when neither applies. Every leaf planner (threshold round AND completion pass) clamps its settled edge to
     * this floor minus one.
     */
    public function getCompactionSettleFloorIndex(): ?int
    {
        $floors = array_filter(
            [$this->getUnfinishedTurnFloorIndex(), $this->getUnansweredInboundFloorIndex()],
            static fn (?int $floor): bool => $floor !== null
        );
        return $floors === [] ? null : min($floors);
    }

    /**
     * The next RAW-message segment to close into a level-0 compaction node (the write side), or null if
     * not enough SETTLED history beyond the current tree + the recent verbatim tail. Contiguous + aligned to the
     * prefix edge: starts at coveredUpTo+1, spans exactly $segmentSize messages, and only when that full span sits
     * behind the most-recent $keepRecentMessages (kept raw) AND no unsettled delegate falls at/before its end (a
     * delegate's verdict lands later — never summarize it away). Pure. The service loops on this, persisting each
     * leaf so the next call sees the advanced prefix edge.
     *
     */
    public function getNextCompactionLeafRange(int $keepRecentMessages, int $segmentSize): ?AIConversationCompactionLeafRange
    {
        $sortedMessages = $this->getSortedMessages();
        if ($sortedMessages === [] || $segmentSize < 1) {
            return null;
        }
        $highestIndex = $sortedMessages[count($sortedMessages) - 1]->indexInConversation ?? -1;
        $startIndex = ($this->getCompactionCoveredUpToIndex() ?? -1) + 1;
        $maxSettledIndex = $highestIndex - $keepRecentMessages;
        $maxSettledIndex = $this->clampToCompactionSettleFloor($maxSettledIndex); // never cover the live turn
        $endIndex = $startIndex + $segmentSize - 1;
        if ($endIndex > $maxSettledIndex) {
            return null; // not a full settled segment yet — wait for more turns
        }
        if ($this->buildToolCallHistory()->hasUnfinishedDelegationUpToIndex($endIndex)) {
            return null; // an in-flight delegate at/before the span — hold until it settles
        }
        return AIConversationCompactionLeafRange::between($startIndex, $endIndex);
    }

    /**
     * The TOKEN-BUDGET twin of {@see self::getNextCompactionLeafRange()}: the next level-0 leaf to close,
     * sized by TOKENS not message-count. The newest `rawRecentTokens` worth of messages stay verbatim (the raw tail);
     * starting at the prefix edge (coveredUpTo+1) we accumulate per-message token cost until it reaches
     * `leafSegmentTokens`. Token cost comes from {@see self::buildMessageFootprints()} — the model-counted
     * `inputTokens` delta (NOT a text estimate). Returns null until a FULL settled leaf-worth of tokens sits behind the
     * raw tail (and no unsettled delegate falls at/before its end). Pure; the service loops, persisting each leaf
     * so the next call sees the advanced prefix edge.
     *
     */
    public function getNextTokenBudgetedLeafRange(CompactionBudgets $budgets): ?AIConversationCompactionLeafRange
    {
        $sortedMessages = $this->getSortedMessages();
        if ($sortedMessages === [] || $budgets->leafSegmentTokens < 1) {
            return null;
        }
        $footprints = $this->buildMessageFootprints();
        // (a) the raw verbatim tail: walk newest→oldest summing token cost until we have rawRecentTokens; that message's
        //     index is where the tail begins — everything from it stays raw, so the last SETTLED index is one before it.
        $maxSettledIndex = $this->getRawTailStartIndex($budgets, $sortedMessages, $footprints) - 1;
        $maxSettledIndex = $this->clampToCompactionSettleFloor($maxSettledIndex); // never cover the live turn
        $startIndex = ($this->getCompactionCoveredUpToIndex() ?? -1) + 1;
        if ($startIndex > $maxSettledIndex) {
            return null; // nothing settled beyond the cover + raw tail yet
        }
        // (b) accumulate forward from the prefix edge until the leaf token budget is met (within the settled range).
        $accumulated = 0;
        $endIndex = $startIndex - 1;
        foreach ($sortedMessages as $message) {
            $index = $message->indexInConversation ?? -1;
            if ($index < $startIndex || $index > $maxSettledIndex) {
                continue;
            }
            $accumulated += $footprints->getTokensAtIndex($index);
            $endIndex = $index;
            if ($accumulated >= $budgets->leafSegmentTokens) {
                break;
            }
        }
        if ($endIndex < $startIndex || $accumulated < $budgets->leafSegmentTokens) {
            return null; // not a full leaf-worth of settled tokens yet — wait for more turns
        }
        if ($this->buildToolCallHistory()->hasUnfinishedDelegationUpToIndex($endIndex)) {
            return null; // an in-flight delegate at/before the span — hold until it settles
        }
        return AIConversationCompactionLeafRange::between($startIndex, $endIndex);
    }

    /**
     * ALL the level-0 leaf ranges to close this round, in ONE pass — the bounded twin of the per-call
     * {@see self::getNextTokenBudgetedLeafRange()}. The leaf loop used to call that per iteration, each re-running
     * getSortedMessages (a full usort), buildMessageFootprints (a full scan), getCompactionCoveredUpToIndex
     * and the unsettled-delegate scan — O(leaves · N log N) per round. This computes the shared state ONCE (the raw-tail
     * boundary, the unsettled-delegate floor, the cover edge) and tiles forward from the edge through the settled
     * region, so the round is O(N log N) once + O(tail). `buildPendingLeafRanges($b)[0]` equals
     * `getNextTokenBudgetedLeafRange($b)` (locked by a test of the application) — the tiling is the
     * single walker repeated, with the cover edge advancing by each leaf's end (which the persists also do, so
     * precomputing from the original edge is equivalent). `$footprints` is passed by the service (computed once)
     * to avoid the per-leaf footprint rebuild; null = compute it here (the test/standalone path).
     *
     */
    public function buildPendingLeafRanges(CompactionBudgets $budgets, ?AIConversationMessageFootprints $footprints = null): AIConversationCompactionLeafRanges
    {
        $sortedMessages = $this->getSortedMessages();
        if ($sortedMessages === [] || $budgets->leafSegmentTokens < 1) {
            return new AIConversationCompactionLeafRanges();
        }
        $footprints ??= $this->buildMessageFootprints();
        // (a) the raw verbatim tail boundary (once): walk newest→oldest until rawRecentTokens — its start index begins
        //     the tail, so the last SETTLED index is one before it.
        $maxSettledIndex = $this->getRawTailStartIndex($budgets, $sortedMessages, $footprints) - 1;
        // (b) hold the whole settled region strictly below the OLDEST in-flight delegate (its verdict lands later) and
        //     below the unfinished turn (a round concurrent with a LIVE turn never covers the in-flight turn).
        $maxSettledIndex = $this->getMaxSettledIndexForCompaction($maxSettledIndex);
        // (c) tile forward from the cover edge, one leaf per leafSegmentTokens; a trailing partial leaf is NOT closed.
        $ranges = new AIConversationCompactionLeafRanges();
        $currentFromIndex = ($this->getCompactionCoveredUpToIndex() ?? -1) + 1;
        $accumulatedTokens = 0;
        foreach ($sortedMessages as $message) {
            $messageIndex = $message->indexInConversation ?? -1;
            if ($messageIndex < $currentFromIndex || $messageIndex > $maxSettledIndex) {
                continue;
            }
            $accumulatedTokens += $footprints->getTokensAtIndex($messageIndex);
            if ($accumulatedTokens >= $budgets->leafSegmentTokens) {
                $ranges->addRange($currentFromIndex, $messageIndex);
                $currentFromIndex = $messageIndex + 1;
                $accumulatedTokens = 0;
            }
        }
        return $ranges;
    }

    /**
     * The COMPLETION tiling (the idle finalize): every leaf range needed to cover the conversation to its LAST
     * message — unlike {@see self::buildPendingLeafRanges()} there is NO raw-tail reserve and the TRAILING PARTIAL
     * leaf IS closed (a finalized conversation keeps no verbatim tail; on a resume, new messages simply grow past the
     * new anchor). The unsettled-delegate floor still holds (a verdict landing later must never be summarized
     * away) — irrelevant in practice, because the finalize sweep never marks a conversation with a live run. A
     * degenerate token budget (leafSegmentTokens < 1) closes ONE leaf over the whole uncovered range. Pure; the
     * service loops over the result, persisting each leaf.
     *
     */
    public function buildLeafRangesToEnd(CompactionBudgets $budgets, ?AIConversationMessageFootprints $footprints = null): AIConversationCompactionLeafRanges
    {
        $sortedMessages = $this->getSortedMessages();
        if ($sortedMessages === []) {
            return new AIConversationCompactionLeafRanges();
        }
        $footprints ??= $this->buildMessageFootprints();
        $maxSettledIndex = $sortedMessages[count($sortedMessages) - 1]->indexInConversation ?? -1;
        // the delegate floor and the unfinished-turn floor — the latter defensive here: the finalize sweep never
        // marks a conversation with a live run (runState IDLE → no floor → completion compaction covers to the very end,
        // unchanged), but a round that DOES race a live turn must still hold below it.
        $maxSettledIndex = $this->getMaxSettledIndexForCompaction($maxSettledIndex);
        $currentFromIndex = ($this->getCompactionCoveredUpToIndex() ?? -1) + 1;
        if ($currentFromIndex > $maxSettledIndex) {
            return new AIConversationCompactionLeafRanges(); // already covered to the end (idempotent re-run) / nothing settled
        }
        if ($budgets->leafSegmentTokens < 1) {
            $ranges = new AIConversationCompactionLeafRanges();
            $ranges->addRange($currentFromIndex, $maxSettledIndex); // degenerate budget: one leaf over everything
            return $ranges;
        }
        $ranges = new AIConversationCompactionLeafRanges();
        $accumulatedTokens = 0;
        $lastIncludedIndex = null;
        foreach ($sortedMessages as $message) {
            $messageIndex = $message->indexInConversation ?? -1;
            if ($messageIndex < $currentFromIndex || $messageIndex > $maxSettledIndex) {
                continue;
            }
            $accumulatedTokens += $footprints->getTokensAtIndex($messageIndex);
            $lastIncludedIndex = $messageIndex;
            if ($accumulatedTokens >= $budgets->leafSegmentTokens) {
                $ranges->addRange($currentFromIndex, $messageIndex);
                $currentFromIndex = $messageIndex + 1;
                $accumulatedTokens = 0;
                $lastIncludedIndex = null;
            }
        }
        if ($lastIncludedIndex !== null) {
            $ranges->addRange($currentFromIndex, $maxSettledIndex); // the trailing partial leaf
        }
        return $ranges;
    }

    /**
     * The message-count degenerate-fallback twin of {@see self::buildPendingLeafRanges()} — all leaf ranges
     * to close in one pass, sized by message count. `[0]` equals {@see self::getNextCompactionLeafRange()}.
     *
     */
    public function buildPendingLeafRangesByMessageCount(int $keepRecentMessages, int $segmentSize): AIConversationCompactionLeafRanges
    {
        $sortedMessages = $this->getSortedMessages();
        if ($sortedMessages === [] || $segmentSize < 1) {
            return new AIConversationCompactionLeafRanges();
        }
        $highestIndex = $sortedMessages[count($sortedMessages) - 1]->indexInConversation ?? -1;
        $maxSettledIndex = $highestIndex - $keepRecentMessages;
        $maxSettledIndex = $this->getMaxSettledIndexForCompaction($maxSettledIndex);
        $ranges = new AIConversationCompactionLeafRanges();
        $currentFromIndex = ($this->getCompactionCoveredUpToIndex() ?? -1) + 1;
        while ($currentFromIndex + $segmentSize - 1 <= $maxSettledIndex) {
            $endIndex = $currentFromIndex + $segmentSize - 1;
            $ranges->addRange($currentFromIndex, $endIndex);
            $currentFromIndex = $endIndex + 1;
        }
        return $ranges;
    }

    /**
     * Where the raw verbatim tail begins: walking newest→oldest, the index of the message at which the summed footprint
     * reaches `rawRecentTokens` (everything from it stays raw; the last SETTLED index is one before it). With fewer
     * tokens than that in the whole set, the oldest message's index.
     *
     * @param AIConversationMessage[] $sortedMessages non-empty, in index order
     */
    protected function getRawTailStartIndex(CompactionBudgets $budgets, array $sortedMessages, AIConversationMessageFootprints $footprints): int
    {
        $rawTailTokens = 0;
        $rawTailStartIndex = ($sortedMessages[count($sortedMessages) - 1]->indexInConversation ?? -1) + 1;
        for ($position = count($sortedMessages) - 1; $position >= 0; $position--) {
            $message = $sortedMessages[$position];
            $messageIndex = $message->indexInConversation ?? -1;
            $rawTailTokens += $footprints->getTokensAtIndex($messageIndex);
            $rawTailStartIndex = $message->indexInConversation ?? $rawTailStartIndex;
            if ($rawTailTokens >= $budgets->rawRecentTokens) {
                break;
            }
        }
        return $rawTailStartIndex;
    }

    /** $candidateMaxIndex held below the unfinished turn ({@see getCompactionSettleFloorIndex()}): a live turn is never covered. */
    protected function clampToCompactionSettleFloor(int $candidateMaxIndex): int
    {
        $turnSettleFloorIndex = $this->getCompactionSettleFloorIndex();
        return $turnSettleFloorIndex !== null ? min($candidateMaxIndex, $turnSettleFloorIndex - 1) : $candidateMaxIndex;
    }

    /**
     * $candidateMaxIndex held below the OLDEST in-flight delegate (its verdict lands later) and below the unfinished
     * turn — the clamp of every one-pass tiling. The single walkers check the delegate after their span instead.
     */
    protected function getMaxSettledIndexForCompaction(int $candidateMaxIndex): int
    {
        $lowestUnsettledDelegateIndex = $this->buildToolCallHistory()->getLowestUnfinishedDelegationIndex();
        if ($lowestUnsettledDelegateIndex !== null) {
            $candidateMaxIndex = min($candidateMaxIndex, $lowestUnsettledDelegateIndex - 1);
        }
        return $this->clampToCompactionSettleFloor($candidateMaxIndex);
    }

    /**
     * Model-counted token cost PER message index for the token-budget compaction sizing:
     * the precise per-turn footprint, NOT a text estimate. Each ASSISTANT reply stores `inputTokens` = the CUMULATIVE
     * prompt footprint of that model call, so the cost a turn ADDED is the delta from the previous reply
     * (`inputTokens_i − inputTokens_{i-1}`). The deltas telescope: Σ over any range = the cumulative `inputTokens`
     * difference across it, exactly what we want to size a leaf / the raw tail by. USER and TOOL-result messages carry
     * NO token figure of their own (they are not model calls) — their cost is folded into the NEXT reply's delta, so
     * they get footprint 0. A compaction LANDING shrinks the prompt → a negative delta; floor it to that reply's own
     * `outputTokens` (a safe lower bound). The FIRST measured reply is the overhead baseline (footprint = its own
     * output, not the cumulative prompt), so fixed system+tools overhead is excluded from history sizing. EPHEMERAL
     * fallback: a conversation with NO measured reply (a fresh test chat, no API call yet) has no `inputTokens`
     * anchor → fall back to the cheap text estimate ({@see AIConversationMessage::getEstimatedTokenCount()}). Pure.
     *
     */
    public function buildMessageFootprints(): AIConversationMessageFootprints
    {
        $sortedMessages = $this->getSortedMessages();
        $footprintByIndex = [];
        $previousInputTokens = null;
        $anyReplyMeasured = false;
        foreach ($sortedMessages as $message) {
            $messageIndex = $message->indexInConversation ?? -1;
            $inputTokens = $message->inputTokens ?? null;
            if ($inputTokens !== null && $inputTokens > 0) {
                $anyReplyMeasured = true;
                if ($previousInputTokens === null) {
                    $footprintByIndex[$messageIndex] = $message->outputTokens ?? $message->getEstimatedTokenCount();
                } else {
                    $delta = $inputTokens - $previousInputTokens;
                    $footprintByIndex[$messageIndex] = $delta > 0
                        ? $delta
                        : ($message->outputTokens ?? $message->getEstimatedTokenCount());
                }
                $previousInputTokens = $inputTokens;
            } else {
                $footprintByIndex[$messageIndex] = 0; // user / tool message — its cost is in the next reply's delta
            }
        }
        if (!$anyReplyMeasured) {
            foreach ($sortedMessages as $message) {
                $footprintByIndex[$message->indexInConversation ?? -1] = $message->getEstimatedTokenCount();
            }
        }
        return AIConversationMessageFootprints::fromTokensByIndex($footprintByIndex);
    }

    /**
     * The next contiguous K-group of exposed level-$level nodes that are AGED past the retention window and ready to
     * roll up into a level-($level+1) node, or null. Sorted by RANGE (coveredFrom), NOT created-time
     * (the correctness fix: created-order ≠ range-order under retention → a non-contiguous group would claim
     * coverage of a range it never summarized = silent data loss). Keeps the most-recent $retentionPerLevel exposed
     * nodes FINE near the frontier; rolls up only the OLDEST $fanIn, and ONLY if they form a hole-free contiguous run.
     */
    public function getNextRollUpGroup(int $level, int $fanIn, int $retentionPerLevel): ?AIConversationCompactionRollUpGroup
    {
        // The roll-up grouping lives ON the tree now.
        return isset($this->compactionNodes) ? $this->compactionNodes->getNextRollUpGroup($level, $fanIn, $retentionPerLevel) : null;
    }

    /**
     * Exposed (not-yet-rolled-up) level-$level nodes, sorted by coveredFrom ASC. "Exposed" = NO higher-level node's
     * range contains this node's range — derived purely from (level, range), no parent pointer (immutability). Pure.
     */
    public function getExposedCompactionNodesAtLevel(int $level): AIConversationCompactionNodes
    {
        // The exposed-frontier logic (a single pass) lives ON the tree now.
        return isset($this->compactionNodes) ? $this->compactionNodes->getExposedNodesAtLevel($level) : AIConversationCompactionNodes::newInstance();
    }

    /**
     * Deterministically extract the identifiers in a raw range — code writes ids, the cheap model never authors
     * one. Standing constraints are carried/merged at roll-up; a leaf starts with the prose-carried set empty
     * (a dedicated detector is a refinement). Pure.
     */
    public function buildPreservedFactsForRange(int $fromIndex, int $toIndex): AIConversationCompactionPreservedFacts
    {
        return AIConversationCompactionPreservedFacts::buildForMessages($this->getRawMessagesInRange($fromIndex, $toIndex));
    }

    /**
     * The loaded raw rows whose `indexInConversation` falls in [$fromIndex, $toIndex] — what a level-0 compaction node
     * over that range summarizes, counts and sizes ({@see AIConversationMessages::getRowsInIndexRange()}). `??` reads
     * the messages like a plain read: an unset relation is lazy-loaded. Pure over the loaded rows.
     *
     * @return AIConversationMessage[]
     */
    public function getRawMessagesInRange(int $fromIndex, int $toIndex): array
    {
        return ($this->messages ?? null)?->getRowsInIndexRange($fromIndex, $toIndex) ?? [];
    }

    /**
     * The summarizer INPUT for a level-0 leaf: the deterministic IDENTIFIERS block + the raw transcript of the range
     * (errors dropped, tool results capped). Pure — the model call lives in the service.
     */
    public function buildLeafCompactionInput(int $fromIndex, int $toIndex, ?AIConversationCompactionPreservedFacts $preservedFacts = null): string
    {
        $segments = [];
        // The conversation frame anchors the gist in what the conversation IS (a purposed intake, a scheduled
        // run) — interpretation context only; the compaction prompt tells the model not to restate it.
        $frameText = $this->buildConversationFrameText();
        if ($frameText !== null) {
            $segments[] = $frameText;
        }
        // The round computes the facts ONCE per leaf and threads them in (they also land on the node); compute only when
        // called standalone (a test). Same deterministic extraction either way.
        $preservedFacts ??= $this->buildPreservedFactsForRange($fromIndex, $toIndex);
        if ($preservedFacts->identifiers !== []) {
            $segments[] = "IDENTIFIERS (copy verbatim into the summary; never alter or omit an id):\n"
                . implode("\n", $preservedFacts->identifiers);
        }
        $transcript = [];
        foreach ($this->getRawMessagesInRange($fromIndex, $toIndex) as $message) {
            if ($message->messageType === AIConversationMessage::MESSAGE_ERROR) {
                continue;
            }
            $messageText = isset($message->message) ? $message->message : '';
            // the summary goes back on the chat model's wire: a tool row's widget block without its `emittedBy` — the
            // row's exact bytes from before the field
            if ($message->messageType === AIConversationMessage::MESSAGE_TOOL_MESSAGE) {
                $messageText = WidgetBlocks::fromMarkdown($messageText)->buildMarkdownWithoutEmittedBy();
            }
            // the summarizer reads a ComposedView block without its bound rows, as the chat model does
            $messageText = WidgetBlocks::buildModelFacingText($messageText);
            $line = ($message->role ?? 'ASSISTANT') . ': ' . $messageText;
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null) {
                $line .= "\n[tool: " . ($toolCall->name ?? '?')
                    . ' args=' . (string)json_encode($toolCall->arguments);
                // getToolResultText(): for a `delegate` call the result is DERIVED from its children — so the
                // delegation verdicts are captured into the summary and survive once this message is compacted away.
                $resultText = $message->getToolResultText();
                if ($resultText !== '') {
                    $line .= ' -> ' . mb_substr($resultText, 0, 2000);
                }
                $line .= ']';
            }
            $transcript[] = $line;
        }
        $segments[] = "CONVERSATION SEGMENT TO SUMMARIZE:\n" . implode("\n\n", $transcript);
        return implode("\n\n", $segments);
    }

    /**
     * The summarizer INPUT for a roll-up: the K child summaries to consolidate into one coarser summary. Pure.
     */
    public function buildRollUpCompactionInput(AIConversationCompactionRollUpGroup $rollUpGroup): string
    {
        $childNodes = $rollUpGroup->getChildNodes();
        $segments = [];
        // Same anchoring as the leaf input — a roll-up consolidates gists and needs the same frame.
        $frameText = $this->buildConversationFrameText();
        if ($frameText !== null) {
            $segments[] = $frameText;
        }
        // Re-assert the deterministically-merged identifiers FIRST so a roll-up cannot drop an id that a child held
        // (the cheap model is told to copy them verbatim — they must not erode going up the tree).
        $mergedFacts = AIConversationCompactionPreservedFacts::mergeOf($childNodes);
        if ($mergedFacts->identifiers !== []) {
            $segments[] = "IDENTIFIERS (copy verbatim into the summary; never alter or omit an id):\n"
                . implode("\n", $mergedFacts->identifiers);
        }
        $segments[] = 'CHILD SUMMARIES TO CONSOLIDATE (oldest→newest; merge into ONE coherent higher-level summary; keep all '
            . 'identifiers + standing constraints verbatim; drop redundancy; preserve the latest state on supersession):';
        foreach ($childNodes as $childNode) {
            $segments[] = sprintf(
                "[messages %d–%d]\n%s",
                $childNode->coveredFromMessageIndex ?? 0,
                $childNode->coveredToMessageIndex ?? 0,
                $childNode->summaryText ?? ''
            );
        }
        return implode("\n\n", $segments);
    }

    /**
     * The user-bounded TURNS of this conversation — {@see AIConversationOwnerTurns::buildAgentTurns()}
     * over the sorted messages, slicing at every initiator message (`ROLE_USER` authored by `AUTHOR_USER`, or by the pre-remodel
     * legacy rows' null authorType; nudges are `AUTHOR_AGENT` and never boundaries). Delegation child turns are
     * attached to the parent turn containing the `delegate` call that spawned them (each child's `delegatedFromMessageId`
     * anchor; in-memory `delegatedConversations` edges only — batch-hydrate first for persisted trees).
     *
     * @return AgentTurn[]
     */
    public function buildAgentTurns(array &$visitedConversationObjectIds = []): array
    {
        // Cycle-guard: a corrupt self/loop `delegatedConversation` edge would recurse forever → OOM
        // (an observed symptom). Skip an already-visited conversation so a residual bad edge degrades instead
        // of exhausting memory. The write invariant and the resolver prevent NEW such edges; this hardens reads over any
        // un-repaired legacy data. Sharing the set across siblings also dedups a diamond (one child, two parents).
        $conversationObjectId = spl_object_id($this);
        if (isset($visitedConversationObjectIds[$conversationObjectId])) {
            return [];
        }
        $visitedConversationObjectIds[$conversationObjectId] = true;

        $sortedMessages = $this->getSortedMessages();
        $turns = AIConversationOwnerTurns::fromRows($sortedMessages)->buildAgentTurns();

        // Attribute delegation child turns to the parent turn containing the `delegate` call that spawned them: ONE
        // delegate message may spawn several children; the causation anchor is each CHILD's own delegatedFromMessageId
        // (= the delegate message's id), so all of a child's turns bill the parent turn holding that message.
        foreach ($sortedMessages as $message) {
            foreach ($message->getDelegatedConversationsIfLoaded() as $childConversation) {
                $causingMessageId = $childConversation->delegatedFromMessageId ?? null;
                foreach ($childConversation->buildAgentTurns($visitedConversationObjectIds) as $childTurn) {
                    foreach ($turns as $turn) {
                        $turnContainsCause = false;
                        foreach ($turn->messages as $turnMessage) {
                            if ($turnMessage->id !== null && $turnMessage->id === $causingMessageId) {
                                $turnContainsCause = true;
                                break;
                            }
                        }
                        if ($turnContainsCause) {
                            $turn->causedChildTurns[] = $childTurn;
                            break;
                        }
                    }
                }
            }
        }
        return $turns;
    }

    /** The turn whose slice contains the given message (null = not found). */
    public function getAgentTurnForMessage(AIConversationMessage $message): ?AgentTurn
    {
        foreach ($this->buildAgentTurns() as $turn) {
            if ($turn->containsMessage($message)) {
                return $turn;
            }
        }
        return null;
    }

    /**
     * ALL messages of this conversation AND its delegation children, recursively — for whole-tree aggregates
     * (eval cost/tool folds; without this they silently exclude every delegation's activity).
     * Uses the IN-MEMORY `delegatedConversation` edges only (live loop wires them; persisted trees batch-hydrate
     * first) — deliberately NO repo fall-through here.
     *
     * @return AIConversationMessages
     */
    public function getAllMessagesIncludingDelegations(array &$visitedConversationObjectIds = []): AIConversationMessages
    {
        $treeMessages = AIConversationMessages::newInstance();
        // Cycle-guard: see buildAgentTurns() — a corrupt self/loop delegatedConversation edge would
        // recurse forever → OOM. Skip an already-visited conversation; degrade instead of exhausting memory.
        $conversationObjectId = spl_object_id($this);
        if (isset($visitedConversationObjectIds[$conversationObjectId])) {
            return $treeMessages;
        }
        $visitedConversationObjectIds[$conversationObjectId] = true;
        foreach ($this->getSortedMessages() as $message) {
            // Read the in-memory children BEFORE add(): the by-ref variadic add() widens $message's static type to
            // BaseObject|null, so the method call must happen while $message is still typed AIConversationMessage.
            $childConversations = $message->getDelegatedConversationsIfLoaded();
            $treeMessages->add($message);
            foreach ($childConversations as $childConversation) {
                foreach ($childConversation->getAllMessagesIncludingDelegations($visitedConversationObjectIds)->getElements() as $childMessage) {
                    $treeMessages->add($childMessage);
                }
            }
        }
        return $treeMessages;
    }

    /**
     * @var \DDD\Domain\AI\Entities\MCPPackages\ToolResultImage[][] Transient in-memory carry — NEVER
     * persisted (no `#[LazyLoad]`, not in the DB model): the resolved image bytes a
     * tool result produced THIS turn, keyed by `tool_call_id`. Populated in
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::dispatchToolCall()} right after a
     * non-error tool result carried images; read by that service's `resolveToolCallImages()` when the wire is composed
     * for the next model call. A resumed worker loads a fresh conversation → empty map → the downgrade line,
     * because inline tool images are single-turn only until a persisted artifact resolver exists.
     * PROTECTED — the schema generator iterates only PUBLIC properties, and a
     * bare `array` public would crash it ("no SQL TYPE allocation"); access goes through the two methods below.
     */
    #[DontPersistProperty]
    protected array $transientToolCallImages = [];

    /**
     * Stash the resolved image bytes a COMPLETED tool call produced THIS turn (keyed by tool_call_id) for the
     * next model call's wire composition. In-memory only — a worker re-load starts empty.
     *
     * @param \DDD\Domain\AI\Entities\MCPPackages\ToolResultImage[] $toolResultImages
     */
    public function stashTransientToolCallImages(string $toolCallId, array $toolResultImages): void
    {
        $this->transientToolCallImages[$toolCallId] = $toolResultImages;
    }

    /**
     * The transient tool-result images stashed THIS turn, keyed by tool_call_id — empty after any re-load.
     *
     * @return \DDD\Domain\AI\Entities\MCPPackages\ToolResultImage[][]
     */
    public function getTransientToolCallImages(): array
    {
        return $this->transientToolCallImages;
    }

    /** This conversation's messages sorted by (indexInConversation, id) — {@see AIConversationMessages::getRowsSortedByIndex()}. */
    protected function getSortedMessages(): array
    {
        // mirrors the prior `?->` access — lazy-loads an unset #[LazyLoad] relation
        return ($this->messages ?? null)?->getRowsSortedByIndex() ?? [];
    }

    public function findMessagesInOrder(
        ?int $offset = null,
        ?int $limit = null,
        bool $useEntityRegistrCache = true
    ): ?AIConversationMessages
    {
        $aiConversationMessagesService = AIConversationMessages::getService();
        return $aiConversationMessagesService->findAllMessagesForConversationInOrder(
            $this,
            $offset,
            $limit,
            $useEntityRegistrCache
        );
    }

    /**
     * Every widget shown in this conversation with the owner's reactions after each, independent of
     * the loaded message window — a widget and its pick behind the compaction edge included
     * ({@see AIConversationMessagesService::findWidgetAndReactionRows()}). An unpersisted conversation has no stored
     * rows: its view is built over the rows it holds in memory (`isset`: an unset relation is not loaded for that).
     */
    public function findShownWidgets(): ShownConversationWidgets
    {
        if (!isset($this->id)) {
            return isset($this->messages) ? $this->messages->buildShownWidgets() : new ShownConversationWidgets();
        }
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = AIConversationMessages::getService();
        return ShownConversationWidgets::buildFor($aIConversationMessagesService->findWidgetAndReactionRows($this) ?? AIConversationMessages::newInstance());
    }

    /**
     * The rows an A2A task projection reads for the task started by the owner text `$taskUserMessageId`
     * — see {@see AIConversationMessagesService::findTaskSliceMessages()}.
     */
    public function findTaskSliceMessages(int $taskUserMessageId): AIConversationMessages
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = AIConversationMessages::getService();
        return $aIConversationMessagesService->findTaskSliceMessages($this, $taskUserMessageId);
    }

    /**
     * The raw verbatim tail only — the messages after the compaction edge ($afterIndex =
     * {@see getCompactionCoveredUpToIndex}), which is everything NOT already represented by the cover nodes. Load this
     * instead of the whole message list on the turn path: O(tail) rows, and the context build treats them exactly like
     * the no-compaction case (it filters to `> coveredUpToIndex` anyway). Null on an unpersisted conversation.
     */
    public function findRawTailMessagesInOrder(int $afterIndex, bool $useEntityRegistrCache = true): ?AIConversationMessages
    {
        return AIConversationMessages::getService()->findRawTailMessagesAfterIndex($this, $afterIndex, $useEntityRegistrCache);
    }

    /**
     * Run the agent loop on this conversation until a final answer (or MAX_STEPS), appending the messages and
     * returning it. The behaviour entry on the aggregate; the loop is service-bound (model egress, executor port,
     * persistence), so it delegates to the agent service. $output streams each message live (CLI); null = no
     * streaming (HTTP / Messenger).
     */
    public function generateAgentReply(
        ?OutputInterface $output = null,
        bool $full = false,
        bool $debug = false,
        bool $collapseThreads = false,
        ?string $aiModelName = null
    ): AIConversation {
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
        return $aIConversationAgentService->generateAgentReply($this, $output, $full, $debug, $collapseThreads, $aiModelName);
    }

    /**
     * Append a message to THIS conversation — the PURE stamping half (repo-agnostic, container-free, directly
     * callable in unit tests): conversation id, role/author/type/content, the loaded-skills carry-forward and the
     * monotonic index, then add it to the message set. The container-coupled `new` and the transient-id assignment
     * live in the agent service's construction tail ({@see AIConversationAgentService} `newMessage()` /
     * `appendMessageTo()`), which passes the blank message in. The message is built incrementally — the caller sets
     * the ToolCall VO / usage AFTER append and writes it via the service's persistMessage().
     */
    public function appendMessage(
        AIConversationMessage $message,
        string $messageType,
        string $content,
        ?string $role = null,
        ?string $authorType = null,
        ?RequestSnapshot $requestSnapshot = null,
        ?string $aiModelName = null,
        ?string $signalSource = null
    ): AIConversationMessage {
        $message->aiConversationId = $this->id;
        // the wire bracket of an owner message renders its `createdTime`; an in-process message without one
        // rendered a bracket-less text on the first calls and the timestamped text after the reload — the first message
        // of the wire rewritten mid-turn (a full prompt-cache bust). Stamp it at append.
        if (!isset($message->getChangeHistory()->createdTime)) {
            $message->getChangeHistory()->createdTime = new DateTime();
        }
        // Wire the in-memory back-reference so the message carries its conversation (the framework back-fills the same
        // relation when a conversation is loaded with its messages; setting it here keeps freshly-appended messages
        // consistent). Lets downstream code — e.g. persistMessage — reach the conversation FROM the message instead of
        // threading it as a redundant parameter.
        $message->aiConversation = $this;
        if ($role !== null) {
            $message->role = $role;
        }
        if ($authorType !== null) {
            $message->authorType = $authorType;
        }
        if ($signalSource !== null) {
            $message->signalSource = $signalSource;
        }
        $message->messageType = $messageType;
        $message->message = $content;
        if ($requestSnapshot !== null) {
            $message->requestSnapshot = $requestSnapshot;
        }
        if ($aiModelName !== null) {
            $message->aiModelName = $aiModelName;
        }
        // Carry THIS conversation's current loaded-skill set forward onto this message (a reference — no
        // construction) so EVERY message holds the active skills as its own truth; a load_skills call then adds to
        // it cumulatively via loadSkills(). A delegation is its OWN conversation — no partition filtering.
        // Pin-wipe guard: search BACKWARD for the newest message THAT HAS a set — `last()` alone meant one
        // capability-less message (an injected signal, any carry gap) nulled the chain and the next loadSkills()
        // promoted onto a NULL prior, wiping the whole cumulative set incl. the purpose-pinned conductor.
        $message->loadedCapabilities = $this->messages->getNewestLoadedCapabilities();
        $message->indexInConversation = $this->messages->getNextIndex();
        // Keep a typed handle: the by-ref variadic add() widens $message's static type to BaseObject|null, so return
        // the pre-call reference (same instance) to honour the AIConversationMessage return type.
        $appendedMessage = $message;
        $this->messages->add($message);
        return $appendedMessage;
    }

    /**
     * The two inputs of the owner addressing for THIS conversation: the register the settings of its space ask for
     * and whether the space runs the agent informal by default. The application answers for its space
     * ({@see AgentSpacesService::resolveOwnerAddressingArguments()}; the default: no register, formal).
     *
     * @return AgentOwnerAddressingArguments
     */
    public function resolveOwnerAddressingArguments(): AgentOwnerAddressingArguments
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->resolveOwnerAddressingArguments($this);
    }

    /** How many loadable tool names lead the loadable capabilities before the skill names follow. */
    public const int LOADABLE_TOOL_NAMES_BEFORE_SKILLS = 12;

    /**
     * The capabilities the model could still LOAD at this point (the `load_required_capability` move's
     * catalog): the `load_tool` schema's still-unloaded tool names — the tools of the loaded skills and of their
     * sub-skills, the capabilities closest to the work at hand, so they lead the list the decision model reads
     * (it is cut to a fixed length) — then the offered skills not yet loaded.
     *
     * @param array<int, array<string, mixed>> $toolSchemas
     * @return string[]
     */
    public function buildLoadableCapabilityNames(AgentRun $agentRun, array $toolSchemas): array
    {
        $loadableSkillNames = [];
        $loadableSubSkillNames = [];
        try {
            $offeredSkillCatalog = $this->buildOfferedSkillCatalog(null, [], $agentRun);
            $loadableSkillNames = $offeredSkillCatalog->getLoadableSkillNames();
            foreach ($offeredSkillCatalog->skills->getElements() as $offeredSkill) {
                if ($offeredSkill->isSubSkill && in_array($offeredSkill->name, $loadableSkillNames, true)) {
                    $loadableSubSkillNames[] = (string)$offeredSkill->name;
                }
            }
        } catch (Throwable) {
            // an unresolvable catalog simply offers no skills here
        }
        $loadableNames = [];
        foreach ($toolSchemas as $schema) {
            if (($schema['function']['name'] ?? null) !== LoadToolTool::NAME) {
                continue;
            }
            foreach (($schema['function']['parameters']['properties'] ?? []) as $property) {
                // `tool_names` is an array parameter: its names are the enum of its ITEMS
                foreach ((array)($property['items']['enum'] ?? $property['enum'] ?? []) as $loadableToolName) {
                    $loadableNames[] = (string)$loadableToolName;
                }
            }
        }
        if (SubSkillToolDiscovery::listsSubSkillsUnderLoadedParent()) {
            // the hierarchy: what lies one level below the loaded skills, then their unloaded tools, then the rest
            return array_values(array_unique(array_merge(
                $loadableSubSkillNames,
                array_slice(array_values(array_unique($loadableNames)), 0, self::LOADABLE_TOOL_NAMES_BEFORE_SKILLS),
                $loadableSkillNames
            )));
        }
        if (!SubSkillToolDiscovery::offersToolsThroughLoadTool()) {
            return array_values(array_unique($loadableSkillNames)); // the list as it was before tools were read
        }
        // both kinds stay visible under the reader's cut: the nearest tools, then the skills, then the other tools
        $loadableNames = array_values(array_unique($loadableNames));
        return array_values(array_unique(array_merge(
            array_slice($loadableNames, 0, self::LOADABLE_TOOL_NAMES_BEFORE_SKILLS),
            $loadableSkillNames,
            array_slice($loadableNames, self::LOADABLE_TOOL_NAMES_BEFORE_SKILLS)
        )));
    }

    /**
     * The tool names a `load_tool` call can load at this message: the unloaded tools of the loaded skills and the
     * tools of their not-yet-loaded sub-skills — the enum the offered `load_tool` schema carries, without building schemas.
     *
     * @return string[]
     */
    public function buildLoadableToolNamesAtMessage(?AIConversationMessage $anchorMessage, ?AgentRun $agentRun): array
    {
        $loadedCapabilities = $anchorMessage?->getLoadedCapabilities();
        if ($loadedCapabilities === null) {
            return [];
        }
        $entitlement = $this->getEntitlement();
        $residentToolNames = $loadedCapabilities->getLoadedToolNames();
        $loadableToolNames = [];
        // the loaded skills as the wire offers them: a skill the plan or the rights no longer cover has no tools
        foreach ($entitlement->filterSkills($anchorMessage->findLoadedAISkills())->getElements() as $loadedAiSkill) {
            foreach ($loadedAiSkill->getToolNames($entitlement) as $toolNameOfLoadedSkill) {
                if (!in_array($toolNameOfLoadedSkill, $residentToolNames, true)) {
                    $loadableToolNames[] = $toolNameOfLoadedSkill;
                }
            }
        }
        $loadableSubSkillTools = $this->buildLoadableSubSkillTools($this->buildOfferedSkillCatalog($anchorMessage, [], $agentRun), $loadedCapabilities);
        return array_values(array_unique(array_merge($loadableToolNames, $loadableSubSkillTools->getToolNames())));
    }

    /**
     * The pure head of every non-model preload's promotion: the not-yet-loaded ones of `$admittedSkillNames` (their
     * policy passed) are loaded onto `$anchorMessage` — the newest message's cumulative set extended, provenance stamped
     * with `$source`. Returns the names actually promoted; the caller writes the anchor's capability column.
     *
     * @param string[] $admittedSkillNames
     * @return string[]
     */
    public function promoteSkillNamesOntoAnchorMessage(array $admittedSkillNames, string $source, AIConversationMessage $anchorMessage): array
    {
        $newestLoadedCapabilities = $this->messages->getNewestLoadedCapabilities();
        $newSkillNames = array_values(array_diff($admittedSkillNames, $newestLoadedCapabilities?->getLoadedSkillNames() ?? []));
        if ($newSkillNames === []) {
            return [];
        }
        if (!$anchorMessage->hasLoadedCapabilities() && $newestLoadedCapabilities !== null) {
            // A message appended outside appendMessage() (the eval runner builds its own) carries no set yet — carry the
            // cumulative one forward so the promotion extends it instead of starting from nothing.
            $anchorMessage->loadedCapabilities = $newestLoadedCapabilities;
        }
        $anchorMessage->loadSkillsFromSource($source, ...$newSkillNames);
        return $newSkillNames;
    }
}
