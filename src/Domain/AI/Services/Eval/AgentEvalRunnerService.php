<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnEndFollowUpsService;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilitiesEvictionSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilityOrigin;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Environment\AgentApplicationNames;
use DDD\Domain\AI\Entities\Evals\AgentEvalApplicabilityFacts;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurn;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurnFigures;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurns;
use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCalls;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalRecordReference;
use DDD\Domain\AI\Entities\Evals\AgentEvalRecordReferences;
use DDD\Domain\AI\Entities\Evals\AgentEvalRestoreBracket;
use DDD\Domain\AI\Entities\Evals\AgentEvalRestorePlan;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOutcome;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalStateAfterRunCheck;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalPreconditionNotEstablishedException;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeRunsService;
use DDD\Domain\AI\Services\Conversations\Agent\SupervisorCheckpointRunsService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use RuntimeException;
use Throwable;

/**
 * Drives one evaluation run of one case against the live agent and hands back the driven conversation — the lossless
 * trace: loaded skills, tool calls with their results and status, the final text, tokens and cost per message all live
 * on its messages. What the run is checked against is not decided here: the case's expectation, figures, budget and
 * summaries are value objects over the returned conversation ({@see \DDD\Domain\AI\Entities\Evals}).
 *
 * A run takes the scope ({@see AgentEvalScope}, from {@see AgentEvalRunHooksService::resolveEvalScope()}), the case
 * ({@see AgentEvalCase}) and the caller's options ({@see AgentEvalRunOptions}: arms, persistence, the simulated owner
 * device, the model, where report lines go); the service keeps no state of a run. What an application adds — the
 * scope, how a conversation is attached to it and when its space is stored, what its tools leave behind, what it knows
 * about whether a case applies — comes from {@see AgentEvalRunHooksService}, resolved through the container at every
 * use.
 *
 * Parts: driving a case (one turn, follow-up turns on the same conversation, a purposed or a schedule-firing run),
 * storing a run's conversation and its owner messages, reading a stored conversation back after the last turn, the
 * restore orchestration around the agent's run and the harness tool calls it makes, the state check after a run, the
 * teardown of the schedules and standing permissions a run's conversation created, the applicability check, the
 * eviction trajectory, the recall run on seeded conversations, and the messages trace of a case row.
 *
 * Makes real model and tool calls; it is driven by the console, never by a test of the package.
 */
class AgentEvalRunnerService extends Service
{
    /** @var string The case key of a recall run that lists the conversations to seed: `[{messages: [{role, text}, …]}, …]` */
    public const string CASE_KEY_SEED_CONVERSATIONS = 'seedConversations';

    /** @var int How deep the reload follows delegation children */
    public const int DELEGATION_CHILDREN_MAX_DEPTH = 3;

    /** @var int The longest error text a teardown report line carries */
    public const int REPORT_ERROR_MAX_LENGTH = 200;

    /** @var int The longest error text the report line of a failed applicability check carries */
    public const int APPLICABILITY_ERROR_MAX_LENGTH = 300;

    /** @var string The prefix of every teardown report line */
    public const string TEARDOWN_REPORT_LINE_PREFIX = 'restore teardown: ';

    /** @var string The prefix of a line about the case's own workspace (prepared, removed, failed) */
    public const string WORKSPACE_REPORT_LINE_PREFIX = 'workspace: ';

    /**
     * The scope of a run of `$agentEvalCase`, resolved by the application's hooks: the first call for every case, also
     * before the applicability check. Reads only.
     *
     * @param AgentEvalCase $agentEvalCase
     * @param int|null $fallbackAgentSpaceId the agent space the caller names for cases that name none
     * @return AgentEvalScope
     * @throws RuntimeException when the scope cannot be resolved
     */
    public function resolveEvalScope(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId = null): AgentEvalScope
    {
        return $this->getAgentEvalRunHooksService()->resolveEvalScope($agentEvalCase, $fallbackAgentSpaceId);
    }

    /**
     * The scope of a run of `$agentEvalCase` that may name an account (`--accountId`): without one
     * {@see resolveEvalScope()}; with one the hooks' {@see AgentEvalRunHooksService::resolveEvalScopeWithFallbackAccount()}
     * (a run at account level, or in a space without an owner).
     *
     * @param AgentEvalCase $agentEvalCase
     * @param int|null $fallbackAgentSpaceId the agent space the caller names for cases that name none
     * @param int|null $fallbackAccountId the account the caller names
     * @return AgentEvalScope
     */
    public function resolveEvalScopeWithFallbackAccount(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId, ?int $fallbackAccountId): AgentEvalScope
    {
        if ($fallbackAccountId === null) {
            return $this->resolveEvalScope($agentEvalCase, $fallbackAgentSpaceId);
        }
        return $this->getAgentEvalRunHooksService()->resolveEvalScopeWithFallbackAccount($agentEvalCase, $fallbackAgentSpaceId, $fallbackAccountId);
    }

    /**
     * Authenticates the account that owns the scope: every read and model call of the run runs in its name.
     *
     * @param AgentEvalScope $agentEvalScope
     * @return void
     */
    public function authenticateOwnerOfScope(AgentEvalScope $agentEvalScope): void
    {
        $ownerAccount = $agentEvalScope->getOwnerAccount();
        AuthService::instance()->setAccount($ownerAccount);
    }

    /**
     * Runs a case: with its `restore` block through {@see runWithRestore()}, without one through {@see run()}.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalCase $agentEvalCase
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AgentEvalRunOutcome
     */
    public function runCase(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalRunOutcome
    {
        $agentEvalCase = $this->resolveRecordReferencesOfCase($agentEvalScope, $agentEvalCase);
        $this->prepareWorkspaceOfCase($agentEvalScope, $agentEvalCase, $agentEvalRunOptions);
        if ($agentEvalCase->getRestore() !== null) {
            return $this->runWithRestore($agentEvalScope, $agentEvalCase, $agentEvalRunOptions);
        }
        return $this->run($agentEvalScope, $agentEvalCase, $agentEvalRunOptions);
    }

    /**
     * The case with every record reference `{{ref:<kind>:<name>}}` of its strings replaced by the value the application
     * resolves in the scope ({@see AgentEvalRunHooksService::resolveRecordReference()}, once per kind and name): a
     * string that is only the token takes the value as resolved, a longer text takes it as text. The case itself when it
     * has no reference. Idempotent: a resolved case has no token left.
     *
     * @param AgentEvalScope $agentEvalScope the scope the case runs in
     * @param AgentEvalCase $agentEvalCase
     * @return AgentEvalCase
     * @throws AgentEvalPreconditionNotEstablishedException when a reference does not resolve in the scope (the case is not applicable)
     */
    public function resolveRecordReferencesOfCase(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase): AgentEvalCase
    {
        $caseFields = $agentEvalCase->toArray();
        $encodedCaseFields = json_encode($caseFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedCaseFields) || !str_contains($encodedCaseFields, '{{ref:')) {
            return $agentEvalCase;
        }
        $resolvedRecordReferences = AgentEvalRecordReferences::newInstance();
        return $agentEvalCase::fromArray((array)$this->substituteRecordReferencesInValue($caseFields, $agentEvalScope, $resolvedRecordReferences));
    }

    /**
     * `$value` with its record references replaced, walking arrays (values only, never keys).
     *
     * @param mixed $value
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalRecordReferences $resolvedRecordReferences the references resolved so far for this case
     * @return mixed
     * @throws AgentEvalPreconditionNotEstablishedException
     */
    protected function substituteRecordReferencesInValue(mixed $value, AgentEvalScope $agentEvalScope, AgentEvalRecordReferences $resolvedRecordReferences): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $nestedValue) {
                $value[$key] = $this->substituteRecordReferencesInValue($nestedValue, $agentEvalScope, $resolvedRecordReferences);
            }
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
        foreach (AgentEvalRecordReferences::fromText($value)->getElements() as $agentEvalRecordReference) {
            $resolvedRecordReference = $this->resolveRecordReference($agentEvalRecordReference, $agentEvalScope, $resolvedRecordReferences);
            if ($resolvedRecordReference->isWholeText($value)) {
                return $resolvedRecordReference->resolvedValue;
            }
            $value = str_replace($resolvedRecordReference->token, (string)$resolvedRecordReference->resolvedValue, $value);
        }
        return $value;
    }

    /**
     * The reference resolved: from the references resolved so far for the case, else through the hook (and kept).
     *
     * @throws AgentEvalPreconditionNotEstablishedException when the application resolves no value
     */
    protected function resolveRecordReference(
        AgentEvalRecordReference $agentEvalRecordReference,
        AgentEvalScope $agentEvalScope,
        AgentEvalRecordReferences $resolvedRecordReferences
    ): AgentEvalRecordReference {
        $heldRecordReference = $resolvedRecordReferences->getByUniqueKey($agentEvalRecordReference->uniqueKey());
        if ($heldRecordReference instanceof AgentEvalRecordReference) {
            $agentEvalRecordReference->resolvedValue = $heldRecordReference->resolvedValue;
            return $agentEvalRecordReference;
        }
        // an agent space reference resolves without the scope, the same way as in `agentSpaceId`
        $resolvedValue = $agentEvalRecordReference->recordKind === AgentEvalCase::AGENT_SPACE_REFERENCE_KIND
            ? $this->getAgentEvalRunHooksService()->resolveAgentSpaceReference($agentEvalRecordReference->recordName)
            : $this->getAgentEvalRunHooksService()->resolveRecordReference($agentEvalScope, $agentEvalRecordReference->recordKind, $agentEvalRecordReference->recordName);
        if ($resolvedValue === null) {
            throw new AgentEvalPreconditionNotEstablishedException(sprintf(
                "record reference %s: no %s named '%s' in this scope",
                $agentEvalRecordReference->token,
                $agentEvalRecordReference->recordKind,
                $agentEvalRecordReference->recordName
            ));
        }
        $agentEvalRecordReference->resolvedValue = $resolvedValue;
        $resolvedRecordReferences->add($agentEvalRecordReference);
        return $agentEvalRecordReference;
    }

    /**
     * Drives the case's prompt through the agent in the scope and returns the driven conversation. The case's tool
     * block guards the agent's run ({@see applyToolBlockOfCase()}) and is lifted when the run ends.
     *
     * The case's `followUpPrompts` are sent one per turn on the SAME conversation after the previous turn finished,
     * each after the owner's idle time of `followUpIdleMinutes` ({@see simulateOwnerIdleBeforeNextTurn()}). A case
     * with `purposeName` runs inside that purposed conversation ({@see applyEvalPurpose()}); a `scheduledRun` case
     * replays one firing of a task schedule on a conversation that is never stored; a `reportChannel` case simulates
     * an unattended run on that channel. A stored conversation is read back after the last turn
     * ({@see reloadIfPersisted()}).
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalCase $agentEvalCase
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AgentEvalRunOutcome
     * @throws RuntimeException on an unknown purpose name
     */
    public function run(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalRunOutcome
    {
        $this->authenticateOwnerOfScope($agentEvalScope);
        $this->applyToolBlockOfCase($agentEvalCase, $agentEvalRunOptions);
        try {
            // the replay of a schedule firing carries an evaluation schedule id that has no row: it stays transient
            $conversation = $this->buildConversation($agentEvalScope, $agentEvalCase->getPrompt(), $agentEvalRunOptions, !$agentEvalCase->isScheduledRun());
            $this->applyRunIdentityOfCase($conversation, $agentEvalCase, $agentEvalRunOptions);
            $this->persistRunIdentityIfPersisted($conversation);
            $this->driveAgent($conversation, $agentEvalScope, $agentEvalRunOptions);
            $followUpIdleMinutes = $agentEvalCase->getFollowUpIdleMinutes();
            foreach ($agentEvalCase->getFollowUpPrompts() as $followUpPosition => $followUpPrompt) {
                $followUpPrompt = trim($followUpPrompt);
                if ($followUpPrompt === '') {
                    continue;
                }
                $this->simulateOwnerIdleBeforeNextTurn($conversation, $followUpIdleMinutes[$followUpPosition] ?? 0);
                $this->appendOwnerMessage($conversation, $followUpPrompt, $agentEvalRunOptions);
                $this->startNextOwnerTurnOnFreshState($conversation);
                $this->driveAgent($conversation, $agentEvalScope, $agentEvalRunOptions);
            }
            return $this->reloadIfPersisted($conversation);
        } finally {
            $this->liftToolBlockOfCase($agentEvalRunOptions);
        }
    }

    /**
     * Puts the case's run identity on the conversation before the first turn: a schedule firing and a report-channel
     * case run inside the schedule-run purpose unless the case names its own purpose; the purpose (with its step cap
     * and settings), then the case's own step cap, which wins over the purpose's; the report channel with the
     * unattended initiator type; last the schedule firing, whose initiator type wins over the report channel's.
     *
     * @param AIConversation $conversation the conversation just built, holding the case's first owner message
     * @param AgentEvalCase $agentEvalCase
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     * @throws RuntimeException on an unknown purpose name
     */
    protected function applyRunIdentityOfCase(AIConversation $conversation, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        $purposeName = $agentEvalCase->getPurposeName();
        $reportChannel = $agentEvalCase->getReportChannel();
        if ($purposeName === null && ($agentEvalCase->isScheduledRun() || $reportChannel !== null)) {
            $purposeName = AIConversationPurpose::SCHEDULE_RUN;
        }
        if ($purposeName !== null) {
            $this->applyEvalPurpose($conversation, $purposeName, $conversation->messages->first(), $agentEvalRunOptions);
        }
        $maxStepsPerTurn = $agentEvalCase->getMaxStepsPerTurn();
        if ($maxStepsPerTurn !== null) {
            $runSettings = isset($conversation->runSettings) ? $conversation->runSettings : RunSettings::newInstance();
            $runSettings->maxStepsPerTurn = $maxStepsPerTurn;
            $conversation->runSettings = $runSettings;
        }
        if ($reportChannel !== null) {
            $conversation->reportChannel = $reportChannel;
            $conversation->initiatorType = AIConversation::INITIATOR_TYPE_ADMIN_TASK;
        }
        if ($agentEvalCase->isScheduledRun()) {
            $conversation->markAsEvalScheduledRun();
        }
    }

    /**
     * Appends one owner message as a live owner message arrives: stamped with its creation time (the wire timestamp
     * and the reply-language pointer render only with one), carrying the simulated owner device and the conversation's
     * loaded capabilities, stored when the conversation is stored, and — on a stored conversation — followed by the
     * inbound-activity stamp the platform writes with every owner message (`lastInboundMessageAt`, which the recovery
     * of a lost resume and the idle finalize read; {@see AIConversationsService::recordInboundMessageActivity()}). A
     * new owner turn re-arms the report obligation; a transient conversation mirrors that in memory and writes no stamp.
     *
     * Every owner message after the first goes through here, except the turns of the eviction trajectory
     * ({@see runEvictionTrajectory()}, transient, no inbound stamp); the first owner message of a case is written by
     * {@see buildConversation()} with the same stamps but without the inbound-activity stamp.
     *
     * @param AIConversation $conversation
     * @param string $ownerMessageText
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     */
    protected function appendOwnerMessage(AIConversation $conversation, string $ownerMessageText, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        if (!isset($conversation->id)) {
            $conversation->reportDeliveredAt = null;
        }
        $ownerMessage = AIConversationMessage::newInstance();
        $ownerMessage->getChangeHistory()->createdTime = new DateTime();
        $ownerMessage->ownerDevice = $agentEvalRunOptions->simulatedOwnerDevice;
        $conversation->appendMessage(
            $ownerMessage,
            AIConversationMessage::MESSAGE_TEXT,
            $ownerMessageText,
            role: AIConversationMessage::ROLE_USER,
            authorType: AIConversationMessage::AUTHOR_USER
        );
        $this->persistUserMessageIfPersisted($conversation, $ownerMessage);
        $this->recordInboundActivityIfPersisted($conversation);
    }

    /**
     * Stamps the inbound activity of an owner message on a stored conversation, as the platform does when an owner
     * message arrives; a transient conversation is left as it is.
     *
     * @param AIConversation $conversation
     * @return void
     */
    protected function recordInboundActivityIfPersisted(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $aIConversationsService->recordInboundMessageActivity($conversation);
    }

    /**
     * Applies the case's tool block for the agent's run: the case's `blockedTools`, then every external-write tool of
     * the application the case does not opt into ({@see AgentEvalCase::resolveBlockedToolNames()} with
     * {@see AgentEvalRunHooksService::getExternalWriteToolNames()}), the case's simulated errors, and the read-only
     * switch of the options. The block is recorded on the options, so every conversation the run builds carries it
     * ({@see buildEvalRunSettings()}) and a worker that resumes one enforces it without this process's guards.
     *
     * @param AgentEvalCase $agentEvalCase
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     */
    public function applyToolBlockOfCase(AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        $agentEvalRunOptions->blockedToolNames = $agentEvalCase->resolveBlockedToolNames($this->getAgentEvalRunHooksService()->getExternalWriteToolNames());
        $agentEvalRunOptions->blockedToolSimulatedErrors = $agentEvalCase->getBlockedToolSimulatedErrors();
        MCPToolExecutorService::$blockedToolNames = $agentEvalRunOptions->blockedToolNames;
        MCPToolExecutorService::$blockedToolSimulatedErrors = $agentEvalRunOptions->blockedToolSimulatedErrors;
        MCPToolExecutorService::$everyWriteToolBlocked = $agentEvalRunOptions->everyWriteToolBlocked;
    }

    /**
     * Lifts the case's blocked tools and their simulated errors after the agent's run, from this process and from the
     * options (a cleanup after the run may need a tool the case blocked for the agent, and a conversation built after
     * the lift must not carry the block in its run settings). The read-only switch stays as the options set it: it is
     * a setting of the whole run, not of the case.
     *
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     */
    public function liftToolBlockOfCase(AgentEvalRunOptions $agentEvalRunOptions): void
    {
        $agentEvalRunOptions->blockedToolNames = [];
        $agentEvalRunOptions->blockedToolSimulatedErrors = [];
        MCPToolExecutorService::$blockedToolNames = [];
        MCPToolExecutorService::$blockedToolSimulatedErrors = [];
    }

    /**
     * Runs an edit case so that the agent space is left as it was: the preparation steps (`setup`), the capture of the
     * current values by the read tool, the agent's run ({@see run()}, with every follow-up turn of the case), then —
     * in a `finally`, also when the run threw — the revert write with the captured values (or the fixed `writeArgs`),
     * the `after` steps, the removal of what the preparation created (in reverse order), and the release of what the
     * harness calls left held ({@see AgentEvalRunHooksService::releaseHostHoldsAfterHarnessCalls()}).
     *
     * A preparation step that answered an error, or that does not show what the case names in `resultContains`, ends
     * the case before the agent runs ({@see \DDD\Domain\AI\Exceptions\Evals\AgentEvalPreconditionNotEstablishedException});
     * so does a failed capture. What earlier preparation steps created is removed all the same. Every harness call
     * reports one line ({@see AgentEvalHarnessToolCall::buildReportLine()}). The case's tool block guards the agent's
     * run, never these calls ({@see executeHarnessToolCall()}).
     *
     * The bracket is opened by {@see openRestoreBracket()} and closed by {@see closeRestoreBracket()}; a run of
     * another shape (a subclass's own run of a case) takes the same bracket around it. A state check the options carry
     * ({@see AgentEvalRunOptions::$stateAfterRunCheckInsideRestore}) runs after the agent's run and before the revert
     * write ({@see evaluateStateAfterRunInsideRestore()}).
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalCase $agentEvalCase a case with a `restore` block
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AgentEvalRunOutcome
     */
    public function runWithRestore(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalRunOutcome
    {
        // the workspace first: the restore's harness calls run in it (run() keeps the key)
        $this->prepareWorkspaceOfCase($agentEvalScope, $agentEvalCase, $agentEvalRunOptions);
        $agentEvalRestoreBracket = $this->openRestoreBracket($agentEvalScope, $agentEvalCase, $agentEvalRunOptions);
        try {
            $agentEvalRunOutcome = $this->run($agentEvalScope, $agentEvalCase, $agentEvalRunOptions);
            $this->evaluateStateAfterRunInsideRestore($agentEvalRunOutcome->getConversation(), $agentEvalScope, $agentEvalRunOptions);
            return $agentEvalRunOutcome;
        } finally {
            $this->closeRestoreBracket($agentEvalRestoreBracket, $agentEvalScope, $agentEvalRunOptions);
        }
    }

    /**
     * Opens the restore bracket of a case before the agent runs: authenticates the scope's owner, notes when the
     * harness calls start, builds the provenance (which stores the scope's agent space through the hooks when it has
     * none), takes every tool package and the arguments the platform fills, applies the case's tool block, runs the
     * preparation steps and captures the current values. When a preparation step or the capture ends the case, what
     * the earlier steps created is removed and the tool block is lifted before the error reaches the caller; the
     * bracket is then never handed out and must not be closed.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalCase $agentEvalCase a case with a `restore` block
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AgentEvalRestoreBracket what {@see closeRestoreBracket()} needs, in a `finally` after the run
     */
    protected function openRestoreBracket(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalRestoreBracket
    {
        $this->authenticateOwnerOfScope($agentEvalScope);
        $harnessCallsStartedAt = new DateTime();
        $agentEvalRestorePlan = AgentEvalRestorePlan::fromRestoreBlock($agentEvalCase->getRestore() ?? []);
        $harnessToolCallProvenance = $this->buildHarnessToolCallProvenance($agentEvalScope, $agentEvalRunOptions->workspaceKey);
        // the harness prepares and restores through any tool of the platform, whatever skill the case tests; tool
        // names are unique across all packages
        $packageClasses = $this->getPackageClassesOfEverySkill();
        $argumentsFilledByThePlatform = $this->getAgentEvalRunHooksService()->getHarnessToolCallArguments($agentEvalScope);
        $agentEvalRestoreBracket = AgentEvalRestoreBracket::forRestorePlan(
            $agentEvalRestorePlan,
            $harnessToolCallProvenance,
            $packageClasses,
            $argumentsFilledByThePlatform,
            $harnessCallsStartedAt
        );
        $this->applyToolBlockOfCase($agentEvalCase, $agentEvalRunOptions);
        try {
            $agentEvalRestoreBracket->undoCalls = $this->runPreparationSteps($agentEvalRestorePlan, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, $agentEvalRunOptions);
            try {
                $agentEvalRestoreBracket->capturedValues = $this->captureOriginalValues($agentEvalRestorePlan, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, $agentEvalRunOptions);
            } catch (Throwable $captureFailure) {
                // what the preparation created is removed all the same
                $this->runUndoCalls($agentEvalRestoreBracket->undoCalls, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, $agentEvalRunOptions);
                throw $captureFailure;
            }
        } catch (Throwable $openingFailure) {
            $this->liftToolBlockOfCase($agentEvalRunOptions);
            throw $openingFailure;
        }
        return $agentEvalRestoreBracket;
    }

    /**
     * Closes an opened restore bracket after the run, also when the run threw: the revert write, the `after` steps,
     * the removal of what the preparation created (the last created first), the release of what the harness calls
     * left held since the bracket opened, and — always, also when one of these threw — the lift of the case's tool
     * block.
     *
     * @param AgentEvalRestoreBracket $agentEvalRestoreBracket the bracket {@see openRestoreBracket()} returned
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     */
    protected function closeRestoreBracket(AgentEvalRestoreBracket $agentEvalRestoreBracket, AgentEvalScope $agentEvalScope, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        try {
            $this->restoreAfterTheRun(
                $agentEvalRestoreBracket->agentEvalRestorePlan,
                $agentEvalRestoreBracket->capturedValues,
                $agentEvalRestoreBracket->undoCalls,
                $agentEvalRestoreBracket->packageClasses,
                $agentEvalRestoreBracket->harnessToolCallProvenance,
                $agentEvalRestoreBracket->argumentsFilledByThePlatform,
                $agentEvalRunOptions
            );
            $this->getAgentEvalRunHooksService()->releaseHostHoldsAfterHarnessCalls($agentEvalScope, $agentEvalRestoreBracket->harnessCallsStartedAt, $agentEvalRunOptions);
        } finally {
            $this->liftToolBlockOfCase($agentEvalRunOptions);
        }
    }

    /**
     * The preparation steps of the restore block, in their order: each is executed and reported, refuses the case when
     * it failed or lacks its expected result, and leaves the removal of what it created. When a step ends the case,
     * what the earlier steps created is removed before the refusal reaches the caller.
     *
     * @param AgentEvalRestorePlan $agentEvalRestorePlan
     * @param array<int, class-string> $packageClasses
     * @param AgentToolCallProvenance $harnessToolCallProvenance
     * @param array<string, mixed> $argumentsFilledByThePlatform
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AgentEvalHarnessToolCalls the removals of what the steps created, in the order the steps created it
     */
    protected function runPreparationSteps(
        AgentEvalRestorePlan $agentEvalRestorePlan,
        array $packageClasses,
        AgentToolCallProvenance $harnessToolCallProvenance,
        array $argumentsFilledByThePlatform,
        AgentEvalRunOptions $agentEvalRunOptions
    ): AgentEvalHarnessToolCalls {
        $undoCalls = AgentEvalHarnessToolCalls::newInstance();
        try {
            foreach ($agentEvalRestorePlan->getSetupCalls()->getElements() as $setupCall) {
                $this->executeHarnessToolCall($setupCall, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, true);
                $agentEvalRunOptions->reportProgress($setupCall->buildReportLine());
                $setupCall->refuseCaseWhosePreparationStepFailed();
                $setupCall->refuseCaseWhosePreparationStepLacksExpectedResult();
                $undoCall = $setupCall->buildUndoCall();
                if ($undoCall !== null) {
                    $undoCalls->add($undoCall);
                }
            }
        } catch (Throwable $preparationFailure) {
            // what the earlier steps created is removed all the same
            $this->runUndoCalls($undoCalls, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, $agentEvalRunOptions);
            throw $preparationFailure;
        }
        return $undoCalls;
    }

    /**
     * The values the revert write needs, read by the capture read before the agent runs; empty when the block captures
     * nothing (a fixed revert payload only). A capture that failed or left an id unread ends the case.
     *
     * @param AgentEvalRestorePlan $agentEvalRestorePlan
     * @param array<int, class-string> $packageClasses
     * @param AgentToolCallProvenance $harnessToolCallProvenance
     * @param array<string, mixed> $argumentsFilledByThePlatform
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return array<string, mixed>
     * @throws RuntimeException when the capture failed or left an id unread
     */
    protected function captureOriginalValues(
        AgentEvalRestorePlan $agentEvalRestorePlan,
        array $packageClasses,
        AgentToolCallProvenance $harnessToolCallProvenance,
        array $argumentsFilledByThePlatform,
        AgentEvalRunOptions $agentEvalRunOptions
    ): array {
        $captureReadCall = $agentEvalRestorePlan->buildCaptureReadCall();
        if ($captureReadCall === null) {
            return [];
        }
        $this->executeHarnessToolCall($captureReadCall, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, true);
        $agentEvalRunOptions->reportProgress($agentEvalRestorePlan->buildCaptureReportLine($captureReadCall));
        $agentEvalRestorePlan->refuseCaseWhoseCaptureFailed($captureReadCall);
        return $agentEvalRestorePlan->captureOriginalValues($captureReadCall);
    }

    /**
     * After the agent's run: the revert write, the `after` steps, then the removal of what the preparation created and
     * the agent did not remove (an object the agent removed answers not-found, which is the state wanted here).
     *
     * @param AgentEvalRestorePlan $agentEvalRestorePlan
     * @param array<string, mixed> $capturedValues
     * @param AgentEvalHarnessToolCalls $undoCalls
     * @param array<int, class-string> $packageClasses
     * @param AgentToolCallProvenance $harnessToolCallProvenance
     * @param array<string, mixed> $argumentsFilledByThePlatform
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     */
    protected function restoreAfterTheRun(
        AgentEvalRestorePlan $agentEvalRestorePlan,
        array $capturedValues,
        AgentEvalHarnessToolCalls $undoCalls,
        array $packageClasses,
        AgentToolCallProvenance $harnessToolCallProvenance,
        array $argumentsFilledByThePlatform,
        AgentEvalRunOptions $agentEvalRunOptions
    ): void {
        $revertWriteCall = $agentEvalRestorePlan->buildRevertWriteCall($capturedValues);
        if ($revertWriteCall !== null) {
            $this->executeHarnessToolCall($revertWriteCall, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, true);
            $agentEvalRunOptions->reportProgress($revertWriteCall->buildReportLine());
        }
        foreach ($agentEvalRestorePlan->getAfterCalls()->getElements() as $afterCall) {
            $this->executeHarnessToolCall($afterCall, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, true);
            $agentEvalRunOptions->reportProgress($afterCall->buildReportLine());
        }
        $this->runUndoCalls($undoCalls, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, $agentEvalRunOptions);
    }

    /**
     * Removes what the preparation steps created, the last created first; each removal is reported (marked as an
     * error only when it left its object).
     *
     * @param AgentEvalHarnessToolCalls $undoCalls in the order the preparation created what they remove
     * @param array<int, class-string> $packageClasses
     * @param AgentToolCallProvenance $harnessToolCallProvenance
     * @param array<string, mixed> $argumentsFilledByThePlatform
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     */
    public function runUndoCalls(
        AgentEvalHarnessToolCalls $undoCalls,
        array $packageClasses,
        AgentToolCallProvenance $harnessToolCallProvenance,
        array $argumentsFilledByThePlatform,
        AgentEvalRunOptions $agentEvalRunOptions
    ): void {
        foreach (array_reverse($undoCalls->getElements()) as $undoCall) {
            $this->executeHarnessToolCall($undoCall, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, true);
            $agentEvalRunOptions->reportProgress($undoCall->buildReportLine());
        }
    }

    /**
     * Executes one call the harness makes itself (a preparation, capture, revert, after, removal, state read or cleanup
     * call) through the tool executor and records its result on the call.
     *
     * A harness tool call needs a STORED agent space: its provenance names the space the tools resolve their target
     * from ({@see buildHarnessToolCallProvenance()}). A case with a `restore` block or a state check therefore stores
     * the scope's agent space through the hooks, also in a transient run; only a transient case without both writes
     * no agent space row.
     *
     * `$liftCaseToolBlock`: the case's blocked tools guard the AGENT's run, not the harness: a case that blocks a tool
     * for the model must still restore, read back and clean up through it. When true, the blocked tools and their
     * simulated errors are lifted for this call and put back right after it; the read-only switch is never lifted.
     * Every harness call of the runner lifts the block: the restore calls and the state check's read and cleanup.
     *
     * @param AgentEvalHarnessToolCall $agentEvalHarnessToolCall
     * @param array<int, class-string> $packageClasses the tool packages the executor searches
     * @param AgentToolCallProvenance $harnessToolCallProvenance
     * @param array<string, mixed> $argumentsFilledByThePlatform {@see AgentEvalRunHooksService::getHarnessToolCallArguments()}
     * @param bool $liftCaseToolBlock
     * @return void
     */
    public function executeHarnessToolCall(
        AgentEvalHarnessToolCall $agentEvalHarnessToolCall,
        array $packageClasses,
        AgentToolCallProvenance $harnessToolCallProvenance,
        array $argumentsFilledByThePlatform,
        bool $liftCaseToolBlock
    ): void {
        $blockedToolNames = MCPToolExecutorService::$blockedToolNames;
        $blockedToolSimulatedErrors = MCPToolExecutorService::$blockedToolSimulatedErrors;
        if ($liftCaseToolBlock) {
            MCPToolExecutorService::$blockedToolNames = [];
            MCPToolExecutorService::$blockedToolSimulatedErrors = [];
        }
        try {
            /** @var MCPToolExecutorService $mCPToolExecutorService */
            $mCPToolExecutorService = DDDService::instance()->getService(MCPToolExecutorService::class);
            $toolResult = $mCPToolExecutorService->execute(
                $agentEvalHarnessToolCall->toolName,
                $agentEvalHarnessToolCall->buildExecutedArguments($argumentsFilledByThePlatform),
                $packageClasses,
                null,
                $harnessToolCallProvenance
            );
            $agentEvalHarnessToolCall->recordResult((string)$toolResult->content, (bool)$toolResult->isError);
        } finally {
            if ($liftCaseToolBlock) {
                MCPToolExecutorService::$blockedToolNames = $blockedToolNames;
                MCPToolExecutorService::$blockedToolSimulatedErrors = $blockedToolSimulatedErrors;
            }
        }
    }

    /**
     * The provenance of the harness tool calls of a case: the stored agent space of the scope, which the hooks store
     * now when the scope has none yet ({@see AgentEvalRunHooksService::findOrStoreAgentSpaceForHarnessToolCalls()}).
     *
     * The calls run in the workspace of the case run (`$workspaceKey`, {@see AgentToolCallProvenance::$workspaceKey}).
     *
     * @param AgentEvalScope $agentEvalScope
     * @param string|null $workspaceKey the workspace of the case run; null outside one
     * @return AgentToolCallProvenance
     */
    public function buildHarnessToolCallProvenance(AgentEvalScope $agentEvalScope, ?string $workspaceKey = null): AgentToolCallProvenance
    {
        return AgentToolCallProvenance::forEvalHarness($this->getAgentEvalRunHooksService()->findOrStoreAgentSpaceForHarnessToolCalls($agentEvalScope), null, $workspaceKey);
    }

    /**
     * The persisted end state after an agent run (`expect.stateAfterRun`): the read tool re-reads what the agent
     * wrote, its arguments taken from the agent's own tool results, and the field predicates are checked mechanically.
     * The cleanup (what the agent created) always runs, in a `finally`, and reports one line. Both calls are harness
     * calls ({@see executeHarnessToolCall()}) and lift the case's tool block themselves, so a case that blocks its own
     * read or cleanup tool for the agent is still read back and cleaned up, whether the caller lifted the block before
     * the check ({@see liftToolBlockOfCase()}) or not.
     *
     * @param AIConversation $conversation the driven conversation
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalStateAfterRunCheck $agentEvalStateAfterRunCheck the case's block (a judged run passes its cleanup only)
     * @param array<int, class-string> $packageClasses the tool packages the executor searches
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return string[] failure sentences; empty when the state matches
     */
    public function evaluateStateAfterRun(
        AIConversation $conversation,
        AgentEvalScope $agentEvalScope,
        AgentEvalStateAfterRunCheck $agentEvalStateAfterRunCheck,
        array $packageClasses,
        AgentEvalRunOptions $agentEvalRunOptions
    ): array {
        $treeMessages = $conversation->getAllMessagesIncludingDelegations();
        $harnessToolCallProvenance = null;
        $workspaceKeyOfRun = (isset($conversation->runSettings) ? $conversation->runSettings->workspaceKey : null) ?? $agentEvalRunOptions->workspaceKey;
        $argumentsFilledByThePlatform = $this->getAgentEvalRunHooksService()->getHarnessToolCallArguments($agentEvalScope);
        try {
            if (!$agentEvalStateAfterRunCheck->hasRead()) {
                return [];
            }
            $resolvedReadArguments = $agentEvalStateAfterRunCheck->resolveReadArgumentsFromToolCallsOf($treeMessages);
            $unresolvedArgumentFailures = $agentEvalStateAfterRunCheck->buildUnresolvedArgumentFailures($resolvedReadArguments);
            if ($unresolvedArgumentFailures !== []) {
                return $unresolvedArgumentFailures;
            }
            $readCall = $agentEvalStateAfterRunCheck->buildReadCall($resolvedReadArguments);
            if ($readCall === null) {
                return [];
            }
            $harnessToolCallProvenance = $this->buildHarnessToolCallProvenance($agentEvalScope, $workspaceKeyOfRun);
            $this->executeHarnessToolCall($readCall, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, true);
            return $agentEvalStateAfterRunCheck->evaluateReadResult($readCall);
        } finally {
            $cleanupCall = $agentEvalStateAfterRunCheck->buildCleanupCall($treeMessages);
            if ($cleanupCall !== null) {
                $harnessToolCallProvenance ??= $this->buildHarnessToolCallProvenance($agentEvalScope, $workspaceKeyOfRun);
                $this->executeHarnessToolCall($cleanupCall, $packageClasses, $harnessToolCallProvenance, $argumentsFilledByThePlatform, true);
                $agentEvalRunOptions->reportProgress($cleanupCall->buildReportLine());
            }
        }
    }

    /**
     * Runs the state check the options carry for a restore bracket ({@see AgentEvalRunOptions::$stateAfterRunCheckInsideRestore})
     * on the driven conversation, with the harness's packages ({@see getHarnessPackageClasses()}), and stores its
     * failures on the options ({@see AgentEvalRunOptions::$stateAfterRunFailuresInsideRestore}). Called after the
     * agent's run and before the bracket's revert write: the read sees what the agent wrote, then the cleanup runs, then
     * the restore. Nothing without a check.
     *
     * @param AIConversation $conversation the driven conversation
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     */
    public function evaluateStateAfterRunInsideRestore(AIConversation $conversation, AgentEvalScope $agentEvalScope, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        $agentEvalStateAfterRunCheck = $agentEvalRunOptions->stateAfterRunCheckInsideRestore;
        if ($agentEvalStateAfterRunCheck === null) {
            return;
        }
        $agentEvalRunOptions->stateAfterRunCheckInsideRestore = null;
        $agentEvalRunOptions->stateAfterRunFailuresInsideRestore = $this->evaluateStateAfterRun(
            $conversation,
            $agentEvalScope,
            $agentEvalStateAfterRunCheck,
            $this->getHarnessPackageClasses(),
            $agentEvalRunOptions
        );
    }

    /**
     * The tool packages the harness calls reach (preparation, capture, restore, state check): every tool package of
     * every skill.
     *
     * @return array<int, class-string>
     */
    public function getHarnessPackageClasses(): array
    {
        return $this->getPackageClassesOfEverySkill();
    }

    /**
     * Closes what the conversation the run built last (and its delegation children) left in the agent space and
     * nothing else restores — the call of a case's `finally`, so a case that threw mid-run is cleaned as well.
     *
     * @param AgentEvalRunOptions $agentEvalRunOptions the options of the run whose conversation is torn down
     * @return string[] what was closed
     */
    public function tearDownResidueOfRun(AgentEvalRunOptions $agentEvalRunOptions): array
    {
        $conversation = $agentEvalRunOptions->takeBuiltConversation();
        $closedLines = $conversation !== null ? $this->tearDownResidueOfConversation($conversation, $agentEvalRunOptions) : [];
        return array_merge($closedLines, $this->cleanUpWorkspaceOfRun($agentEvalRunOptions));
    }

    /**
     * Gives the case its own workspace for this run: the key `eval-<case>-r<repetition>-<runToken>`
     * ({@see buildWorkspaceKeyOfCase()}) on the options (the conversation carries it in its run settings, the tools
     * receive it on their provenance), and the application prepares it with the case's `scratchFiles`
     * ({@see AgentEvalRunHooksService::prepareWorkspaceOfCase()}). A run that already has a key keeps it (the restore
     * path calls back in). Never fails the case.
     */
    public function prepareWorkspaceOfCase(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        if ($agentEvalRunOptions->workspaceKey !== null) {
            return;
        }
        $agentEvalRunOptions->workspaceKey = $this->buildWorkspaceKeyOfCase($agentEvalCase->getId(), $agentEvalRunOptions->repetition, bin2hex(random_bytes(4)));
        try {
            foreach ($this->getAgentEvalRunHooksService()->prepareWorkspaceOfCase($agentEvalScope, $agentEvalRunOptions->workspaceKey, $agentEvalCase->getScratchFiles(), $agentEvalRunOptions) as $preparedLine) {
                $agentEvalRunOptions->reportProgress(static::WORKSPACE_REPORT_LINE_PREFIX . $preparedLine);
            }
        } catch (Throwable $throwable) {
            $agentEvalRunOptions->reportProgress(static::WORKSPACE_REPORT_LINE_PREFIX . '[ERROR] ' . $throwable->getMessage());
        }
        // the modules that keep state per workspace (e.g. a drive): their own isolation of the case
        /** @var AgentEvalWorkspaceParticipantsService $agentEvalWorkspaceParticipantsService */
        $agentEvalWorkspaceParticipantsService = DDDService::instance()->getService(AgentEvalWorkspaceParticipantsService::class);
        foreach ($agentEvalWorkspaceParticipantsService->prepareWorkspaceOfCase($agentEvalScope, $agentEvalRunOptions->workspaceKey, $agentEvalCase->getScratchFiles(), $agentEvalRunOptions) as $preparedLine) {
            $agentEvalRunOptions->reportProgress(static::WORKSPACE_REPORT_LINE_PREFIX . $preparedLine);
        }
    }

    /**
     * The workspace key of a case's run: `eval-<case>-r<repetition>-<runToken>`, or `eval-<case>-<runToken>` when the
     * caller does not count repetitions (`$repetition` null); lower case `[a-z0-9-]`, the case part at most 60 characters.
     */
    public function buildWorkspaceKeyOfCase(string $caseId, ?int $repetition, string $runToken): string
    {
        $caseKey = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($caseId)), '-');
        $caseKey = trim(substr($caseKey !== '' ? $caseKey : 'case', 0, 60), '-');
        $runKey = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($runToken)), '-');
        return 'eval-' . $caseKey . ($repetition !== null ? '-r' . max(1, $repetition) : '') . '-' . $runKey;
    }

    /** Removes the run's own workspace ({@see AgentEvalRunHooksService::cleanUpWorkspaceOfCase()}) and forgets its key; never fails the case. */
    protected function cleanUpWorkspaceOfRun(AgentEvalRunOptions $agentEvalRunOptions): array
    {
        $workspaceKey = $agentEvalRunOptions->workspaceKey;
        if ($workspaceKey === null) {
            return [];
        }
        $agentEvalRunOptions->workspaceKey = null;
        if ($agentEvalRunOptions->keepsWorkspace) {
            $keptLine = 'kept ' . $workspaceKey . ' (--keepWorkspace)';
            $agentEvalRunOptions->reportProgress(static::WORKSPACE_REPORT_LINE_PREFIX . $keptLine);
            return [$keptLine];
        }
        /** @var AgentEvalWorkspaceParticipantsService $agentEvalWorkspaceParticipantsService */
        $agentEvalWorkspaceParticipantsService = DDDService::instance()->getService(AgentEvalWorkspaceParticipantsService::class);
        $participantLines = $agentEvalWorkspaceParticipantsService->cleanUpWorkspaceOfCase($workspaceKey, $agentEvalRunOptions);
        try {
            $removedLines = [...$this->getAgentEvalRunHooksService()->cleanUpWorkspaceOfCase($workspaceKey, $agentEvalRunOptions), ...$participantLines];
        } catch (Throwable $throwable) {
            $agentEvalRunOptions->reportProgress(static::WORKSPACE_REPORT_LINE_PREFIX . '[ERROR] ' . $throwable->getMessage());
            $removedLines = $participantLines;
        }
        foreach ($removedLines as $removedLine) {
            $agentEvalRunOptions->reportProgress(static::WORKSPACE_REPORT_LINE_PREFIX . $removedLine);
        }
        return $removedLines;
    }

    /**
     * Closes what a stored conversation of a run (and its delegation children) left: the task schedules it created
     * (a schedule would keep firing), the standing permissions it was granted (a mandate would authorize the next
     * case's writes, and what it replaced stands again), then what the application's tools left
     * ({@see AgentEvalRunHooksService::tearDownHostResidueOfConversation()}). Every step is reported as a
     * `restore teardown:` line and never fails the case. Schedules and mandates belong to an agent space: an
     * account-level conversation (no space) has none to close, but the application's hook is called for it as well. A
     * TRANSIENT conversation has no id, so nothing it created carries its mark: its residue stays, which the report says.
     *
     * @param AIConversation $conversation
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return string[] what was closed
     */
    public function tearDownResidueOfConversation(AIConversation $conversation, AgentEvalRunOptions $agentEvalRunOptions): array
    {
        if (!isset($conversation->id)) {
            $agentEvalRunOptions->reportProgress(static::TEARDOWN_REPORT_LINE_PREFIX . 'skipped — a transient conversation leaves no mark on what it created');
            return [];
        }
        $conversationIdsOfTree = $this->collectConversationIdsOfTree($conversation);
        $closedLines = [];
        if (isset($conversation->agentSpaceId)) {
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $agentSpacesService = $this->getAgentSpacesService();
                $agentSpace = $agentSpacesService->find((int)$conversation->agentSpaceId);
                if ($agentSpace instanceof AgentSpace) {
                    $closedLines = array_merge(
                        $this->closeSchedulesCreatedByTree($agentSpace, $conversationIdsOfTree, $agentEvalRunOptions),
                        $this->closeMandatesGrantedToTree($conversation, $agentSpace, $conversationIdsOfTree, $agentEvalRunOptions)
                    );
                }
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        }
        return array_merge(
            $closedLines,
            $this->getAgentEvalRunHooksService()->tearDownHostResidueOfConversation($conversation, $conversationIdsOfTree, $agentEvalRunOptions)
        );
    }

    /**
     * The ids of a stored conversation and of every conversation of its delegation tree, unique.
     *
     * @param AIConversation $conversation
     * @return int[]
     */
    protected function collectConversationIdsOfTree(AIConversation $conversation): array
    {
        $conversationIdsOfTree = [(int)$conversation->id];
        foreach ($conversation->getAllMessagesIncludingDelegations()->getElements() as $treeMessage) {
            if (isset($treeMessage->aiConversationId)) {
                $conversationIdsOfTree[] = (int)$treeMessage->aiConversationId;
            }
        }
        return array_values(array_unique($conversationIdsOfTree));
    }

    /**
     * Deletes (by the archive policy) the schedules of the agent space that a conversation of the tree created.
     *
     * @param AgentSpace $agentSpace
     * @param int[] $conversationIdsOfTree
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return string[] what was closed
     */
    protected function closeSchedulesCreatedByTree(AgentSpace $agentSpace, array $conversationIdsOfTree, AgentEvalRunOptions $agentEvalRunOptions): array
    {
        $closedLines = [];
        try {
            /** @var AIConversationSchedulesService $aIConversationSchedulesService */
            $aIConversationSchedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
            foreach ($aIConversationSchedulesService->findSchedulesOfAgentSpace($agentSpace)->getElements() as $schedule) {
                if (in_array((int)($schedule->createdByConversationId ?? 0), $conversationIdsOfTree, true)) {
                    $closedLines[] = $this->reportTeardownLine(
                        $agentEvalRunOptions,
                        'schedule ' . (int)$schedule->id . ' ' . $aIConversationSchedulesService->deleteScheduleFollowingArchivePolicy($schedule),
                        false
                    );
                }
            }
        } catch (Throwable $scheduleTeardownError) {
            $closedLines[] = $this->reportTeardownLine($agentEvalRunOptions, 'schedules: ' . mb_substr($scheduleTeardownError->getMessage(), 0, static::REPORT_ERROR_MAX_LENGTH), true);
        }
        return $closedLines;
    }

    /**
     * Revokes the active mandates a conversation of the tree was granted, then makes every mandate one of them
     * superseded active again (a grant replaces the standing mandate of its class) while it is still valid and was not
     * granted to the tree itself. The account's mandates of the space are read once. Closed directly, not through the
     * revoke of the mandates service: a revoke also switches the automatic replies off, and restoring those settings
     * is the job of the case's restore block.
     *
     * @param AIConversation $conversation
     * @param AgentSpace $agentSpace
     * @param int[] $conversationIdsOfTree
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return string[] what was closed
     */
    protected function closeMandatesGrantedToTree(AIConversation $conversation, AgentSpace $agentSpace, array $conversationIdsOfTree, AgentEvalRunOptions $agentEvalRunOptions): array
    {
        $closedLines = [];
        try {
            /** @var AgentMandatesService $agentMandatesService */
            $agentMandatesService = DDDService::instance()->getService(AgentMandatesService::class);
            $mandatesOfSpace = $agentMandatesService->findMandatesForAccount((int)$conversation->accountId, $agentSpace)->getElements();
            $closedMandateIds = [];
            foreach ($mandatesOfSpace as $mandate) {
                if ($mandate->status === AgentMandate::STATUS_ACTIVE && in_array((int)($mandate->sourceConversationId ?? 0), $conversationIdsOfTree, true)) {
                    $mandate->status = AgentMandate::STATUS_REVOKED;
                    $mandate->revokedAt = new DateTime();
                    $agentMandatesService->update($mandate);
                    $closedLines[] = $this->reportTeardownLine($agentEvalRunOptions, 'mandate ' . (int)$mandate->id . ' ' . (string)$mandate->actionClass . ' closed', false);
                    $closedMandateIds[] = (int)$mandate->id;
                }
            }
            foreach ($closedMandateIds === [] ? [] : $mandatesOfSpace as $mandate) {
                if ($mandate->status === AgentMandate::STATUS_SUPERSEDED
                    && in_array((int)($mandate->supersededByMandateId ?? 0), $closedMandateIds, true)
                    && ($mandate->validUntil === null || $mandate->validUntil > new DateTime())
                    && !in_array((int)($mandate->sourceConversationId ?? 0), $conversationIdsOfTree, true)
                ) {
                    $mandate->status = AgentMandate::STATUS_ACTIVE;
                    $mandate->supersededByMandateId = null;
                    $agentMandatesService->update($mandate);
                    $closedLines[] = $this->reportTeardownLine($agentEvalRunOptions, 'mandate ' . (int)$mandate->id . ' ' . (string)$mandate->actionClass . ' active again', false);
                }
            }
        } catch (Throwable $mandateTeardownError) {
            $closedLines[] = $this->reportTeardownLine($agentEvalRunOptions, 'mandates: ' . mb_substr($mandateTeardownError->getMessage(), 0, static::REPORT_ERROR_MAX_LENGTH), true);
        }
        return $closedLines;
    }

    /**
     * Reports one teardown step (`restore teardown: [ERROR] …` for a failed one) and gives the line back for the list
     * of what was closed.
     *
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @param string $teardownLine
     * @param bool $isError
     * @return string
     */
    protected function reportTeardownLine(AgentEvalRunOptions $agentEvalRunOptions, string $teardownLine, bool $isError): string
    {
        $agentEvalRunOptions->reportProgress(static::TEARDOWN_REPORT_LINE_PREFIX . ($isError ? AgentEvalHarnessToolCall::REPORT_LINE_ERROR_MARKER : '') . $teardownLine);
        return $teardownLine;
    }

    /**
     * The owner was away `$idleMinutes` before the next turn: every recorded model call of the conversation is moved
     * that far into the past (its request snapshot's `sentAt`, written on a stored row), so the next call finds the
     * newest one older than the cold-prefix window and starts a cold state epoch like a resume after a real pause. 0 or
     * less changes nothing.
     *
     * @param AIConversation $conversation
     * @param int $idleMinutes
     * @return void
     */
    public function simulateOwnerIdleBeforeNextTurn(AIConversation $conversation, int $idleMinutes): void
    {
        if ($idleMinutes <= 0) {
            return;
        }
        foreach ($conversation->messages->getElements() as $message) {
            $requestSnapshot = isset($message->requestSnapshot) ? $message->requestSnapshot : null;
            if ($requestSnapshot?->sentAt === null) {
                continue;
            }
            $requestSnapshot->sentAt = DateTime::fromTimestamp($requestSnapshot->sentAt->getTimestamp() - $idleMinutes * 60);
            if (isset($conversation->id) && isset($message->id)) {
                $message->updatePartialIgnoringRights('requestSnapshot');
            }
        }
    }

    /**
     * Every owner turn of a STORED evaluation conversation starts on fresh process state, as a worker job does: one
     * process drives all turns of a case, and what an earlier turn left in the unit of work (tool calls held as
     * PENDING, without their result) would otherwise be what a later turn reads. The messages in memory get their
     * stored outcomes as well, so the next model call carries the earlier results.
     *
     * @param AIConversation $conversation
     * @return void
     */
    protected function startNextOwnerTurnOnFreshState(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $this->resetProcessStateLikeANewWorkerJob();
        $this->applyPersistedToolCallOutcomes($conversation->messages, $this->getMessagesService());
    }

    /**
     * Puts the process on the state a fresh worker job starts on (the unit of work cleared, the connections renewed,
     * the entity registries emptied). Fail-soft, like the worker middleware: a stale cache is the lesser evil next to
     * a lost case.
     *
     * @return void
     */
    protected function resetProcessStateLikeANewWorkerJob(): void
    {
        try {
            AppMessageHandler::resetWorkerStateForNewJob();
        } catch (Throwable) {
            // fail-soft, see the docblock
        }
    }

    /**
     * A STORED evaluation conversation is evaluated on its stored truth: the loop re-reads a conversation with an id
     * between turns, so the objects in memory of an earlier turn keep stale tool-call statuses (PENDING) while the rows
     * are COMPLETED. The row and its messages are read once more, after the last turn, without the entity registry
     * (the loop and the run hold different instances); the stored outcome of every tool call is written onto it and
     * the delegation children are loaded. A transient conversation is returned as it is.
     *
     * @param AIConversation $conversation
     * @return AgentEvalRunOutcome the conversation to evaluate and what the reload saw
     */
    public function reloadIfPersisted(AIConversation $conversation): AgentEvalRunOutcome
    {
        if (!isset($conversation->id)) {
            return AgentEvalRunOutcome::forConversation($conversation);
        }
        $this->resetProcessStateLikeANewWorkerJob();
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $reloadedConversation = $aIConversationsService->find((int)$conversation->id, useEntityRegistrCache: false);
        if (!$reloadedConversation instanceof AIConversation) {
            return AgentEvalRunOutcome::forConversation($conversation, ['found' => false]);
        }
        $messagesService = $this->getMessagesService();
        $reloadedConversation->messages = $messagesService->findAllMessagesForConversationInOrder($reloadedConversation, useEntityRegistrCache: false)
            ?? AIConversationMessages::newInstance();
        // isset() loads nothing: the space the run put on the conversation is handed to the row read back
        if (isset($conversation->agentSpace)) {
            $reloadedConversation->agentSpace = $conversation->agentSpace;
        }
        $toolCallStatuses = $this->applyPersistedToolCallOutcomes($reloadedConversation->messages, $messagesService);
        $this->hydrateDelegationChildren($reloadedConversation->messages, $messagesService);
        $agentWorkingState = $reloadedConversation->getAgentWorkingState();
        return AgentEvalRunOutcome::forConversation($reloadedConversation, [
            'found' => true,
            'sameInstance' => $reloadedConversation === $conversation,
            'messages' => $reloadedConversation->messages->count(),
            'toolCallStatuses' => $toolCallStatuses,
            'decisionRecords' => $agentWorkingState !== null ? count($agentWorkingState->decisions) : null,
        ]);
    }

    /**
     * Writes the stored status and result onto the tool calls of these messages, in place.
     *
     * @param AIConversationMessages $messages
     * @param AIConversationMessagesService $messagesService
     * @return string[] `tool:status` per tool call, for the reload diagnostics
     */
    protected function applyPersistedToolCallOutcomes(AIConversationMessages $messages, AIConversationMessagesService $messagesService): array
    {
        $toolCallStatuses = [];
        foreach ($messages->getElements() as $message) {
            if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_TOOL_CALL || !isset($message->toolCall)) {
                continue;
            }
            if (isset($message->id)) {
                $persistedOutcome = $messagesService->findPersistedToolCallOutcome((int)$message->id);
                $persistedOutcome?->applyToToolCall($message->toolCall);
            }
            $toolCallStatuses[] = ($message->toolCall->name ?? '?') . ':' . ($message->toolCall->status ?? '?');
        }
        return $toolCallStatuses;
    }

    /**
     * Loads the child conversations of every delegate call with their messages and stored tool outcomes,
     * recursively up to {@see DELEGATION_CHILDREN_MAX_DEPTH}: a reloaded tree has no edges in memory, and the
     * evaluation would miss every tool call a sub-agent made.
     *
     * @param AIConversationMessages $messages
     * @param AIConversationMessagesService $messagesService
     * @param int $depth
     * @return void
     */
    protected function hydrateDelegationChildren(AIConversationMessages $messages, AIConversationMessagesService $messagesService, int $depth = 0): void
    {
        if ($depth >= static::DELEGATION_CHILDREN_MAX_DEPTH) {
            return;
        }
        foreach ($messages->getElements() as $message) {
            if (!isset($message->id) || !$message->isDelegationCall()) {
                continue;
            }
            try {
                $childConversations = $message->delegatedConversations?->getElements() ?? [];
            } catch (Throwable) {
                $childConversations = []; // an unreadable child leaves the parent's own trace intact
            }
            foreach ($childConversations as $childConversation) {
                $childConversation->messages = $messagesService->findAllMessagesForConversationInOrder($childConversation, useEntityRegistrCache: false)
                    ?? AIConversationMessages::newInstance();
                $this->applyPersistedToolCallOutcomes($childConversation->messages, $messagesService);
                $this->hydrateDelegationChildren($childConversation->messages, $messagesService, $depth + 1);
            }
        }
    }

    /**
     * Drives a multi-turn eviction trajectory on ONE continuing conversation: each prompt is appended to the same
     * conversation (the loaded capabilities carry forward and the eviction sweep runs on the real telemetry), and after
     * every turn the loaded-capabilities eviction snapshot is captured with what was newly loaded and what was evicted
     * since the previous turn, and the turn's figures ({@see AgentEvalEvictionTurn}). The retention settings of the
     * trajectory (eager loading, low thresholds) make a few varied prompts grow the carried load fast.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param string[] $prompts the owner turns, in order; blank ones are skipped
     * @param ContextRetentionSettings $contextRetentionSettings
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AgentEvalRunOutcome the conversation, with its owner turns ({@see AgentEvalRunOutcome::getEvictionTurnSet()})
     * @throws RuntimeException when no prompt is left
     */
    public function runEvictionTrajectory(
        AgentEvalScope $agentEvalScope,
        array $prompts,
        ContextRetentionSettings $contextRetentionSettings,
        AgentEvalRunOptions $agentEvalRunOptions
    ): AgentEvalRunOutcome {
        $this->authenticateOwnerOfScope($agentEvalScope);
        $agentEvalEvictionTurns = AgentEvalEvictionTurns::newInstance();
        $previousCapabilityNames = [];
        $conversation = null;
        foreach (array_values($prompts) as $prompt) {
            $prompt = trim((string)$prompt);
            if ($prompt === '') {
                continue;
            }
            if ($conversation === null) {
                $conversation = $this->buildConversation($agentEvalScope, $prompt, $agentEvalRunOptions);
                $conversation->contextRetentionSettings = $contextRetentionSettings;
            } else {
                $ownerMessage = AIConversationMessage::newInstance();
                $ownerMessage->getChangeHistory()->createdTime = new DateTime();
                $conversation->appendMessage($ownerMessage, AIConversationMessage::MESSAGE_TEXT, $prompt, role: AIConversationMessage::ROLE_USER, authorType: AIConversationMessage::AUTHOR_USER);
            }
            $turnStartedAt = hrtime(true);
            $this->driveAgent($conversation, $agentEvalScope, $agentEvalRunOptions);
            $turnSeconds = (hrtime(true) - $turnStartedAt) / 1e9;
            $evictionSnapshot = $this->captureEvictionSnapshot($conversation, $contextRetentionSettings);
            $agentEvalEvictionTurn = AgentEvalEvictionTurn::forTurn(
                $prompt,
                $evictionSnapshot,
                $previousCapabilityNames,
                AgentEvalEvictionTurnFigures::fromCurrentTurnOfConversation($conversation, $turnSeconds)
            );
            $agentEvalEvictionTurns->add($agentEvalEvictionTurn);
            $previousCapabilityNames = $evictionSnapshot->getCapabilityNames();
        }
        if ($conversation === null) {
            throw new RuntimeException('Eviction trajectory needs at least one non-empty prompt');
        }
        $agentEvalRunOutcome = AgentEvalRunOutcome::forConversation($conversation);
        $agentEvalRunOutcome->recordEvictionTurnSet($agentEvalEvictionTurns);
        return $agentEvalRunOutcome;
    }

    /**
     * The loaded-capabilities eviction snapshot as of the conversation's last message (after the sweep, in memory);
     * an empty snapshot when nothing is loaded yet.
     *
     * @param AIConversation $conversation
     * @param ContextRetentionSettings $contextRetentionSettings
     * @return LoadedCapabilitiesEvictionSnapshot
     */
    protected function captureEvictionSnapshot(AIConversation $conversation, ContextRetentionSettings $contextRetentionSettings): LoadedCapabilitiesEvictionSnapshot
    {
        $loadedCapabilities = $conversation->messages?->last()?->getLoadedCapabilities();
        $ownerTurnCount = $conversation->buildOwnerTurns()->countOwnerTurns();
        $latestContextInputTokens = $conversation->getLatestContextInputTokens() ?? 0;
        if ($loadedCapabilities === null) {
            return LoadedCapabilitiesEvictionSnapshot::withNothingLoaded($ownerTurnCount, $latestContextInputTokens);
        }
        return $loadedCapabilities->buildEvictionSnapshot($latestContextInputTokens, $ownerTurnCount, $contextRetentionSettings);
    }

    /**
     * Recall across conversations: stores the case's `seedConversations` (each message embedded at once, so the vector
     * search finds them), then drives the agent on a FRESH transient conversation whose prompt refers back to what was
     * seeded — the only way to answer is the conversation search. The seeded conversations are deleted in a `finally`
     * unless the options keep them. Their ids are recorded on the options before the drive
     * ({@see AgentEvalRunOptions::getSeededConversationIds()}; the list starts empty with every call) and logged, so a
     * failed recall can be traced to the rows it created.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalCase $agentEvalCase a case with `prompt` and `seedConversations` ({@see CASE_KEY_SEED_CONVERSATIONS})
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AgentEvalRunOutcome the driven fresh conversation and the seeded ids
     */
    public function runCrossConversationRecallWithSeed(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalRunOutcome
    {
        $this->authenticateOwnerOfScope($agentEvalScope);
        $agentEvalRunOptions->forgetSeededConversationIds();
        $seedConversationBlocks = $agentEvalCase->getApplicationValue(static::CASE_KEY_SEED_CONVERSATIONS);
        $seededConversations = AIConversations::newInstance();
        try {
            foreach (is_array($seedConversationBlocks) ? $seedConversationBlocks : [] as $seedConversationBlock) {
                $seededConversation = $this->seedPersistedConversation(
                    $agentEvalScope,
                    is_array($seedConversationBlock['messages'] ?? null) ? $seedConversationBlock['messages'] : [],
                    $agentEvalRunOptions,
                    $this->resolveSeedAgentSpaceId($seedConversationBlock['agentSpaceId'] ?? null),
                    $this->resolveSeedOwnerAccountId($seedConversationBlock['ownerOperatorKind'] ?? null)
                );
                $agentEvalRunOptions->recordSeededConversationId((int)$seededConversation->id);
                $seededConversations->add($seededConversation);
            }
            DDDService::instance()->getLogger()->info(sprintf(
                '%s evaluation recall seed persisted: conversationIds=[%s] account=%d agentSpace=%s',
                AgentApplicationNames::getTraceLinePrefix(),
                implode(',', $agentEvalRunOptions->getSeededConversationIds()),
                $agentEvalScope->getOwnerAccountId(),
                (string)($agentEvalScope->getAgentSpaceId() ?? '-')
            ));
            // the turn runs on a fresh transient conversation: its own messages are never stored or embedded, so the
            // search sees only the seeded conversations
            $conversation = $this->buildConversation($agentEvalScope, $agentEvalCase->getPrompt(), $agentEvalRunOptions);
            $this->driveAgent($conversation, $agentEvalScope, $agentEvalRunOptions);
            $agentEvalRunOutcome = AgentEvalRunOutcome::forConversation($conversation);
            $agentEvalRunOutcome->recordSeededConversationIds($agentEvalRunOptions->getSeededConversationIds());
            return $agentEvalRunOutcome;
        } finally {
            if (!$agentEvalRunOptions->keepSeededConversations) {
                $this->deleteSeededConversations($seededConversations);
            }
        }
    }

    /**
     * The space of a seed block (`agentSpaceId`: an id or an agent space reference `{{ref:agentSpace:<name>}}`); null =
     * the case's space.
     *
     * @throws AgentEvalPreconditionNotEstablishedException when a reference resolves to no space
     */
    protected function resolveSeedAgentSpaceId(mixed $agentSpaceValue): ?int
    {
        if (is_int($agentSpaceValue) || (is_string($agentSpaceValue) && ctype_digit($agentSpaceValue))) {
            return (int)$agentSpaceValue;
        }
        if (!is_string($agentSpaceValue) || trim($agentSpaceValue) === '') {
            return null;
        }
        if (preg_match('/^\{\{ref:' . preg_quote(AgentEvalCase::AGENT_SPACE_REFERENCE_KIND, '/') . ':([^}]+)\}\}$/', trim($agentSpaceValue), $matches) !== 1) {
            throw new AgentEvalPreconditionNotEstablishedException("seed agentSpaceId '$agentSpaceValue' is neither an id nor an agent space reference");
        }
        $agentSpaceId = $this->getAgentEvalRunHooksService()->resolveAgentSpaceReference($matches[1]);
        if ($agentSpaceId === null) {
            throw new AgentEvalPreconditionNotEstablishedException("seed agentSpaceId: no agent space named '{$matches[1]}'");
        }
        return (int)$agentSpaceId;
    }

    /**
     * The owner of a seed block (`ownerOperatorKind`: another evaluation operator, through
     * {@see AgentEvalRunHooksService::resolveSeedOwnerAccountId()}); null = the scope's owner.
     *
     * @throws AgentEvalPreconditionNotEstablishedException when the application knows no such operator
     */
    protected function resolveSeedOwnerAccountId(mixed $ownerOperatorKind): ?int
    {
        if (!is_string($ownerOperatorKind) || trim($ownerOperatorKind) === '') {
            return null;
        }
        $ownerAccountId = $this->getAgentEvalRunHooksService()->resolveSeedOwnerAccountId(trim($ownerOperatorKind));
        if ($ownerAccountId === null) {
            throw new AgentEvalPreconditionNotEstablishedException("seed ownerOperatorKind: no evaluation operator of kind '$ownerOperatorKind'");
        }
        return $ownerAccountId;
    }

    /**
     * Deletes the seeded conversations (their messages go with them); best effort: a cleanup never masks the result.
     *
     * @param AIConversations $seededConversations
     * @return void
     */
    protected function deleteSeededConversations(AIConversations $seededConversations): void
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        foreach ($seededConversations->getElements() as $seededConversation) {
            try {
                $aIConversationsService->delete($seededConversation);
            } catch (Throwable) {
                // best effort, see the docblock
            }
        }
    }

    /**
     * Stores a conversation of the scope with the given text messages, each embedded at once (the embedding is what the
     * recall search ranks on), marked as seeded evaluation history so it stays findable. Blank texts are skipped; a
     * role other than ASSISTANT is the owner's.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param array<int, array<string, mixed>> $messageBlocks each `{role, text}`
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AIConversation the stored conversation
     */
    protected function seedPersistedConversation(
        AgentEvalScope $agentEvalScope,
        array $messageBlocks,
        AgentEvalRunOptions $agentEvalRunOptions,
        ?int $agentSpaceId = null,
        ?int $ownerAccountId = null
    ): AIConversation {
        $conversation = AIConversation::newInstance();
        $conversation->accountId = $ownerAccountId ?? $agentEvalScope->getOwnerAccountId();
        $this->getAgentEvalRunHooksService()->attachNewConversationToScope($conversation, $agentEvalScope);
        if ($agentSpaceId !== null) {
            // a seed of another space than the case's (recall across spaces)
            $conversation->agentSpaceId = $agentSpaceId;
            unset($conversation->agentSpace);
        }
        $conversation->messages = AIConversationMessages::newInstance();
        $seedRunSettings = RunSettings::newInstance();
        $seedRunSettings->evalRun = true;
        $seedRunSettings->evalSeededHistory = true;
        $conversation->runSettings = $seedRunSettings;
        $conversation->budgetBypass = $this->isConsoleBudgetBypassGranted();
        $this->storeAssembledConversation($conversation, $agentEvalScope);
        $messagesService = $this->getMessagesService();
        $indexInConversation = 0;
        foreach ($messageBlocks as $messageBlock) {
            $text = trim((string)($messageBlock['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $isAssistant = strtoupper((string)($messageBlock['role'] ?? 'USER')) === 'ASSISTANT';
            $message = AIConversationMessage::newInstance();
            $message->aiConversationId = (int)$conversation->id;
            $message->role = $isAssistant ? AIConversationMessage::ROLE_ASSISTANT : AIConversationMessage::ROLE_USER;
            $message->authorType = $isAssistant ? AIConversationMessage::AUTHOR_ASSISTANT : AIConversationMessage::AUTHOR_USER;
            $message->messageType = AIConversationMessage::MESSAGE_TEXT;
            $message->message = $text;
            $message->indexInConversation = $indexInConversation++;
            $message->update();
            $messagesService->embedMessage($message, async: false);
        }
        return $conversation;
    }

    /**
     * Builds a fresh conversation of the scope with one owner message for an evaluation turn: assembled like every
     * evaluated conversation ({@see assembleConversation()}); stored with its first message when the run stores its
     * conversations and `$persist` allows it.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param string $prompt
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @param bool $persist false = never stored, whatever the options say
     * @return AIConversation
     */
    protected function buildConversation(AgentEvalScope $agentEvalScope, string $prompt, AgentEvalRunOptions $agentEvalRunOptions, bool $persist = true): AIConversation
    {
        $conversation = $this->assembleConversation($agentEvalScope, $agentEvalRunOptions);
        if ($agentEvalRunOptions->persistConversations && $persist) {
            // stored before the first turn: the decision layer's sites need an id
            $this->storeAssembledConversation($conversation, $agentEvalScope);
        }
        $ownerMessage = AIConversationMessage::newInstance();
        $ownerMessage->getChangeHistory()->createdTime = new DateTime();
        $ownerMessage->role = AIConversationMessage::ROLE_USER;
        $ownerMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $ownerMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerMessage->message = $prompt;
        $ownerMessage->ownerDevice = $agentEvalRunOptions->simulatedOwnerDevice;
        $ownerMessage->indexInConversation = 0;
        // add() takes the element by reference: the typed handle is kept for the store
        $firstOwnerMessage = $ownerMessage;
        $conversation->messages->add($ownerMessage);
        $this->persistUserMessageIfPersisted($conversation, $firstOwnerMessage);
        return $conversation;
    }

    /**
     * Assembles a fresh, empty conversation of the scope as every evaluated conversation starts: recorded on the options
     * as the run's built conversation (what the teardown cleans up after), owned by the scope's owner, attached to the
     * scope without writing, without messages, carrying the run's settings (the evaluation marker, the arms and the
     * case's tool block), the creation stamps of the arms (with no arm chosen: the platform's defaults, as a live new
     * conversation gets them) and the run's budget lift. Nothing is stored.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AIConversation
     */
    protected function assembleConversation(AgentEvalScope $agentEvalScope, AgentEvalRunOptions $agentEvalRunOptions): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $agentEvalRunOptions->recordBuiltConversation($conversation);
        $conversation->accountId = $agentEvalScope->getOwnerAccountId();
        $this->getAgentEvalRunHooksService()->attachNewConversationToScope($conversation, $agentEvalScope);
        $conversation->messages = AIConversationMessages::newInstance();
        $conversation->runSettings = $this->buildEvalRunSettings($agentEvalRunOptions);
        // the case's own workspace: the conversation's tools (and its sub-agents) work there, never in the space's own
        $conversation->runSettings->workspaceKey = $agentEvalRunOptions->workspaceKey;
        $agentEvalArms = $agentEvalRunOptions->getAgentEvalArms();
        $conversation->contextRetentionSettings = ContextRetentionSettings::buildForNewConversation(
            null,
            $agentEvalArms->skillLoadingStrategy,
            $agentEvalArms->rebuildEpochOnSituationChange,
            $agentEvalArms->stateChangeDelivery
        );
        $agentEvalArms->applyToolDeliveryArmsTo($conversation->contextRetentionSettings);
        $conversation->budgetBypass = $this->isConsoleBudgetBypassGranted();
        return $conversation;
    }

    /**
     * The run settings every conversation of the run starts with: the evaluation marker, every arm of the options and
     * the tool block applied for the case ({@see applyToolBlockOfCase()}), plus the read-only switch.
     *
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return RunSettings
     */
    public function buildEvalRunSettings(AgentEvalRunOptions $agentEvalRunOptions): RunSettings
    {
        return $agentEvalRunOptions->getAgentEvalArms()->buildEvalRunSettings(
            $agentEvalRunOptions->blockedToolNames,
            $agentEvalRunOptions->blockedToolSimulatedErrors,
            $agentEvalRunOptions->everyWriteToolBlocked
        );
    }

    /**
     * Whether this console process lifts the budget cap by the console option: an evaluation conversation carries it as
     * its stored `budgetBypass`, the way a driven conversation does — the process grant does not cross the queue, so
     * without the flag the run's background jobs would run under the account's real budget.
     *
     * @return bool
     */
    public function isConsoleBudgetBypassGranted(): bool
    {
        return $this->getUsageEnvelopesService()->getBudgetBypassGrantForTheProcess()?->reason === AIBudgetBypassGrant::REASON_CONSOLE_OPTION;
    }

    /**
     * The case's run identity reaches the row of a STORED conversation — `purposeName`, `reportChannel`,
     * `initiatorType` and the run settings with the case's step cap — not only the object the drive works on: the
     * inspector, the owed-report stamp and every later reload read the row. Only the fields the case set; a transient
     * conversation keeps them in memory.
     *
     * @param AIConversation $conversation
     * @return void
     */
    protected function persistRunIdentityIfPersisted(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $setPropertyNames = array_values(array_filter(
            ['purposeName', 'reportChannel', 'initiatorType', 'runSettings'],
            static fn (string $propertyName): bool => isset($conversation->{$propertyName})
        ));
        if ($setPropertyNames !== []) {
            $conversation->updatePartialIgnoringRights(...$setPropertyNames);
        }
    }

    /**
     * A stored evaluation conversation stores its owner messages too — the loop stores only the messages it creates.
     *
     * @param AIConversation $conversation
     * @param AIConversationMessage $ownerMessage
     * @return void
     */
    protected function persistUserMessageIfPersisted(AIConversation $conversation, AIConversationMessage $ownerMessage): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $ownerMessage->aiConversationId = (int)$conversation->id;
        $ownerMessage->aiConversation = $conversation;
        $this->getMessagesService()->persistInboundRowIndexSafe($conversation, $ownerMessage);
    }

    /**
     * Stores the conversation when the run stores its conversations and it has no id yet.
     *
     * @param AIConversation $conversation
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     */
    protected function persistConversationIfRequested(AIConversation $conversation, AgentEvalScope $agentEvalScope, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        if ($agentEvalRunOptions->persistConversations && !isset($conversation->id)) {
            $this->storeAssembledConversation($conversation, $agentEvalScope);
        }
    }

    /**
     * Stores a conversation the run assembled in memory. Assembling wrote nothing, so the hooks store the scope's agent
     * space now, right before the conversation's own row references it.
     *
     * @param AIConversation $conversation
     * @param AgentEvalScope $agentEvalScope
     * @return void
     */
    protected function storeAssembledConversation(AIConversation $conversation, AgentEvalScope $agentEvalScope): void
    {
        $this->getAgentEvalRunHooksService()->storeAgentSpaceOfScopeBeforeConversationIsStored($conversation, $agentEvalScope);
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $aIConversationsService->update($conversation);
    }

    /**
     * Runs the conversation INSIDE `$purposeName`, as the purposed start does: the purpose's step cap, its run settings
     * and retention settings (the run's arms win), its context budget, its seeded task plan and its preloaded skills on
     * `$anchorMessage`. A stored conversation gets its run settings and retention column written, so every later turn
     * reads the same creation stamps.
     *
     * @param AIConversation $conversation
     * @param string $purposeName
     * @param AIConversationMessage|null $anchorMessage the message that carries the preloaded skills for the next turn
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     * @throws RuntimeException on an unknown purpose name
     */
    protected function applyEvalPurpose(AIConversation $conversation, string $purposeName, ?AIConversationMessage $anchorMessage, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        if (!AIConversationPurpose::isRegisteredPurposeName($purposeName)) {
            throw new RuntimeException("Unknown conversation purpose: $purposeName");
        }
        $conversation->purposeName = $purposeName;
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $aIConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
        if (!$aIConversationPurpose instanceof AIConversationPurpose) {
            return;
        }
        $this->applyPurposeStepCap($conversation, $aIConversationPurpose);
        $this->applyPurposeStartSettings($conversation, $aIConversationPurpose, $agentEvalRunOptions);
        if (isset($conversation->id)) {
            $conversation->updatePartialIgnoringRights('purposeName', 'runSettings', 'contextRetentionSettings');
        }
        $this->applyPurposeContextBudget($conversation, $aIConversationPurpose);
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        // without the seeded plan the purpose's conductor has no act to follow
        $aIConversationPurposeRunsService->seedPurposeTaskPlan($conversation, $purposeName);
        if ($anchorMessage !== null) {
            $this->preloadEntitledPurposeSkills($conversation, $aIConversationPurpose, $anchorMessage);
        }
    }

    /**
     * The purpose's step cap, merged into the run settings: only a cap set before wins over the purpose's.
     *
     * @param AIConversation $conversation
     * @param AIConversationPurpose $aIConversationPurpose
     * @return void
     */
    protected function applyPurposeStepCap(AIConversation $conversation, AIConversationPurpose $aIConversationPurpose): void
    {
        if ($aIConversationPurpose->maxStepsPerTurn === null) {
            return;
        }
        $runSettings = isset($conversation->runSettings) ? $conversation->runSettings : RunSettings::newInstance();
        $runSettings->maxStepsPerTurn ??= $aIConversationPurpose->maxStepsPerTurn;
        $conversation->runSettings = $runSettings;
    }

    /**
     * The purpose's run-settings defaults and retention settings, as a new purposed conversation starts with them: its
     * agent wire profile as a whole, or none of it for a model that cannot run it; the run's arms (already on the run
     * settings) win. The retention settings keep an act's sub-skill resident through a multi-turn case; they are set
     * when the conversation carries none but the strategy stamp.
     *
     * @param AIConversation $conversation
     * @param AIConversationPurpose $aIConversationPurpose
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     */
    protected function applyPurposeStartSettings(AIConversation $conversation, AIConversationPurpose $aIConversationPurpose, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        $purposeStartSettings = $aIConversationPurposeRunsService->buildStartSettingsForNewPurposedConversation(
            $aIConversationPurpose,
            $agentEvalRunOptions->aiModelName ?? ($conversation->aiModelNameOverride ?? $aIConversationPurpose->preferredModelName),
            isset($conversation->runSettings) ? $conversation->runSettings : null
        );
        if ($purposeStartSettings->runSettings !== null) {
            $conversation->runSettings = $purposeStartSettings->runSettings;
        }
        $purposeContextRetentionSettings = $purposeStartSettings->contextRetentionSettings;
        if ($purposeContextRetentionSettings !== null
            && (!isset($conversation->contextRetentionSettings) || $conversation->contextRetentionSettings->isOnlyTheSkillLoadingStrategyStamp())
        ) {
            $agentEvalArms = $agentEvalRunOptions->getAgentEvalArms();
            $conversation->contextRetentionSettings = ContextRetentionSettings::buildForNewConversation(
                $purposeContextRetentionSettings,
                $agentEvalArms->skillLoadingStrategy,
                $agentEvalArms->rebuildEpochOnSituationChange,
                $agentEvalArms->stateChangeDelivery
            );
            $agentEvalArms->applyToolDeliveryArmsTo($conversation->contextRetentionSettings);
        }
    }

    /**
     * The purpose's context budget as the compaction trigger (in memory), clamped to the model's window, when the
     * conversation has no compaction settings yet: a run on the default ceiling would not reproduce the live budget.
     *
     * @param AIConversation $conversation
     * @param AIConversationPurpose $aIConversationPurpose
     * @return void
     */
    protected function applyPurposeContextBudget(AIConversation $conversation, AIConversationPurpose $aIConversationPurpose): void
    {
        if ($aIConversationPurpose->contextBudgetTokens === null || isset($conversation->compactionSettings)) {
            return;
        }
        /** @var AIConversationCompactionService $aIConversationCompactionService */
        $aIConversationCompactionService = DDDService::instance()->getService(AIConversationCompactionService::class);
        $purposeCompactionSettings = $aIConversationPurpose->buildCompactionSettingsForContextBudget(
            $aIConversationCompactionService->clampContextBudgetToModelWindow(
                $aIConversationPurpose->contextBudgetTokens,
                $conversation->aiModelNameOverride ?? $aIConversationPurpose->preferredModelName
            )
        );
        if ($purposeCompactionSettings !== null) {
            $conversation->compactionSettings = $purposeCompactionSettings;
        }
    }

    /**
     * Preloads the purpose's skills onto `$anchorMessage`, filtered by the feature flags like the live seed (a purpose
     * never brings a locked skill into a run), pinned when the purpose says so. A stored anchor writes the preload
     * through the capability chain, as the worker path does: an anchor row with an id and an unstored set makes the
     * chain refuse to place every later row.
     *
     * @param AIConversation $conversation
     * @param AIConversationPurpose $aIConversationPurpose
     * @param AIConversationMessage $anchorMessage
     * @return void
     */
    protected function preloadEntitledPurposeSkills(AIConversation $conversation, AIConversationPurpose $aIConversationPurpose, AIConversationMessage $anchorMessage): void
    {
        if ($aIConversationPurpose->preloadSkillNames === []) {
            return;
        }
        $featureFlags = $conversation->findFeatureFlags();
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $entitledPreloadSkillNames = [];
        foreach ($aIConversationPurpose->preloadSkillNames as $preloadSkillName) {
            $preloadSkill = $aISkillsService->getAISkillByName($preloadSkillName);
            if ($preloadSkill !== null && $featureFlags !== null && !$preloadSkill->isIncludedInFeatureFlags($featureFlags)) {
                continue;
            }
            $entitledPreloadSkillNames[] = $preloadSkillName;
        }
        if ($entitledPreloadSkillNames === []) {
            return;
        }
        $preloadedCapabilities = $conversation->buildSeededCapabilities($entitledPreloadSkillNames, true, LoadedCapabilityOrigin::atTurn(0, 0));
        if ($aIConversationPurpose->pinPreloadedSkills) {
            $preloadedCapabilities->markAllNonEvictable();
        }
        $anchorMessage->loadedCapabilities = $preloadedCapabilities;
        if (isset($anchorMessage->id)) {
            $this->getMessagesService()->persistLoadedCapabilitiesUnderRunClaim($conversation, $anchorMessage, null);
        }
    }

    /**
     * Drives the agent for one turn inside a turn usage envelope billed to the scope's owner. The application first
     * prepares the channel of the simulated owner device ({@see AgentEvalRunHooksService::prepareConversationChannelBeforeDrive()}).
     * A stored purposed conversation then gets what the worker does after a settled turn and this synchronous drive
     * never reaches: the supervisor's settled beat and the close of a purpose whose goal is reached, so a later turn
     * meets the conversation as an owner would. With `$curateMemoryInline` the application curates the conversation's
     * memory in this process ({@see AgentEvalRunHooksService::curateMemoryAfterDrivenTurn()}).
     *
     * @param AIConversation $conversation
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @param bool $curateMemoryInline
     * @return void
     */
    protected function driveAgent(AIConversation $conversation, AgentEvalScope $agentEvalScope, AgentEvalRunOptions $agentEvalRunOptions, bool $curateMemoryInline = false): void
    {
        $agentEvalRunHooksService = $this->getAgentEvalRunHooksService();
        $agentEvalRunHooksService->prepareConversationChannelBeforeDrive($conversation, $agentEvalRunOptions->simulatedOwnerDevice);
        $aIUsageEnvelopesService = $this->getUsageEnvelopesService();
        $turnEnvelopeContext = $aIUsageEnvelopesService->openEnvelope(
            $agentEvalScope->getOwnerAccountId(),
            AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN,
            AIUsageInitiator::CLI
        );
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
        try {
            $aIConversationAgentService->generateAgentReply($conversation, null, false, false, false, $agentEvalRunOptions->aiModelName);
        } finally {
            $aIUsageEnvelopesService->closeEnvelope($turnEnvelopeContext);
        }
        // the turn end a worker's release runs: the host's settled-turn listeners (e.g. a workspace commit)
        /** @var AIConversationTurnEndFollowUpsService $aIConversationTurnEndFollowUpsService */
        $aIConversationTurnEndFollowUpsService = DDDService::instance()->getService(AIConversationTurnEndFollowUpsService::class);
        $aIConversationTurnEndFollowUpsService->runSettledTurnListenersAfterInlineTurn($conversation);
        if (isset($conversation->id) && ($conversation->purposeName ?? null)) {
            /** @var SupervisorCheckpointRunsService $supervisorCheckpointRunsService */
            $supervisorCheckpointRunsService = DDDService::instance()->getService(SupervisorCheckpointRunsService::class);
            $supervisorCheckpointRunsService->runSupervisorSettledBeatAfterSyncTurn($conversation);
            $aIConversationAgentService->settlePurposeConversationIfGoalReached($conversation);
        }
        if ($curateMemoryInline) {
            $agentEvalRunHooksService->curateMemoryAfterDrivenTurn($conversation);
        }
    }

    /**
     * Why the case cannot be judged in its scope, or null when it applies: the scope is not eligible, the scope is not
     * offered a skill or tool the case expects (the same gates the loop applies to its offered catalog), or a
     * requirement of the case is unmet. Such a case is not run and counts neither as a pass nor as a fail. Fail-soft:
     * a failed check never costs a case or the rest of a run — the case stays applicable, the run reports what is wrong
     * with it, and the check's report line says why it failed.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalCase $agentEvalCase
     * @param AgentEvalRunOptions $agentEvalRunOptions where the line of a failed check goes
     * @return AgentEvalNotApplicableReason|null
     */
    public function findNotApplicableReasonForCase(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): ?AgentEvalNotApplicableReason
    {
        try {
            return $this->buildApplicabilityFactsOfCase($agentEvalScope, $agentEvalCase)->findNotApplicableReason();
        } catch (Throwable $applicabilityError) {
            $this->reportFailedApplicabilityCheck($applicabilityError, $agentEvalRunOptions);
            return null;
        }
    }

    /**
     * {@see findNotApplicableReasonForCase()} for a caller that holds no scope yet: resolves the scope of the case
     * first ({@see resolveEvalScope()}) and counts a scope that cannot be resolved as a failed check like any other —
     * the case stays applicable, the check's report line says why it failed, and the run of the case then meets the
     * same error inside the case, where it costs that case and not the rest of the run.
     *
     * @param AgentEvalCase $agentEvalCase
     * @param int|null $fallbackAgentSpaceId the agent space the caller names for cases that name none, as for {@see resolveEvalScope()}
     * @param AgentEvalRunOptions $agentEvalRunOptions where the line of a failed check goes
     * @return AgentEvalNotApplicableReason|null
     */
    public function resolveScopeAndFindNotApplicableReasonForCase(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId, AgentEvalRunOptions $agentEvalRunOptions): ?AgentEvalNotApplicableReason
    {
        try {
            $agentEvalScope = $this->resolveEvalScope($agentEvalCase, $fallbackAgentSpaceId);
        } catch (Throwable $scopeResolutionError) {
            $this->reportFailedApplicabilityCheck($scopeResolutionError, $agentEvalRunOptions);
            return null;
        }
        return $this->findNotApplicableReasonForCase($agentEvalScope, $agentEvalCase, $agentEvalRunOptions);
    }

    /**
     * {@see resolveScopeAndFindNotApplicableReasonForCase()} for a run that may name an account (`--accountId`): the
     * scope is {@see resolveEvalScopeWithFallbackAccount()}'s. Without an account it is the method above, unchanged.
     *
     * @param AgentEvalCase $agentEvalCase
     * @param int|null $fallbackAgentSpaceId
     * @param int|null $fallbackAccountId the account of a run at account level or in a space without an owner
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return AgentEvalNotApplicableReason|null
     */
    public function resolveScopeWithFallbackAccountAndFindNotApplicableReasonForCase(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId, ?int $fallbackAccountId, AgentEvalRunOptions $agentEvalRunOptions): ?AgentEvalNotApplicableReason
    {
        if ($fallbackAccountId === null) {
            return $this->resolveScopeAndFindNotApplicableReasonForCase($agentEvalCase, $fallbackAgentSpaceId, $agentEvalRunOptions);
        }
        try {
            $agentEvalScope = $this->resolveEvalScopeWithFallbackAccount($agentEvalCase, $fallbackAgentSpaceId, $fallbackAccountId);
        } catch (Throwable $scopeResolutionError) {
            $this->reportFailedApplicabilityCheck($scopeResolutionError, $agentEvalRunOptions);
            return null;
        }
        return $this->findNotApplicableReasonForCase($agentEvalScope, $agentEvalCase, $agentEvalRunOptions);
    }

    /**
     * Reports the line of an applicability check that failed: the error's class and its message, cut to
     * {@see APPLICABILITY_ERROR_MAX_LENGTH} characters.
     *
     * @param Throwable $applicabilityError
     * @param AgentEvalRunOptions $agentEvalRunOptions
     * @return void
     */
    protected function reportFailedApplicabilityCheck(Throwable $applicabilityError, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        $agentEvalRunOptions->reportProgress(
            'applicability check failed (' . $applicabilityError::class . '): '
            . mb_substr($applicabilityError->getMessage(), 0, static::APPLICABILITY_ERROR_MAX_LENGTH)
        );
    }

    /**
     * The facts the applicability of a case is decided on, read in the name of the scope's owner; the account that was
     * authenticated before is authenticated again afterwards, also when a read threw.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalCase $agentEvalCase
     * @return AgentEvalApplicabilityFacts
     */
    public function buildApplicabilityFactsOfCase(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase): AgentEvalApplicabilityFacts
    {
        $authService = AuthService::instance();
        $previouslyAuthenticatedAccount = $authService->getAccount();
        $this->authenticateOwnerOfScope($agentEvalScope);
        try {
            $agentEvalRunHooksService = $this->getAgentEvalRunHooksService();
            $agentEvalApplicabilityFacts = AgentEvalApplicabilityFacts::forCase($agentEvalCase);
            $agentEvalApplicabilityFacts->ineligibleScopeReason = $agentEvalRunHooksService->findIneligibleScopeReason($agentEvalScope);
            $this->addCatalogFactsOfProbeConversation($agentEvalApplicabilityFacts, $this->buildProbeConversationOfCase($agentEvalScope, $agentEvalCase));
            $agentEvalApplicabilityFacts->unmetRequirementReasons = $agentEvalRunHooksService->findUnmetRequirementDetails($agentEvalScope, $agentEvalCase);
            return $agentEvalApplicabilityFacts;
        } finally {
            $authService->setAccount($previouslyAuthenticatedAccount);
        }
    }

    /**
     * The probe conversation of a case: a new conversation of the scope's owner, attached to the scope, in the case's
     * purpose, never stored — the loop's own offer then decides what the scope, the purpose and the owner get, with the
     * same policies in the same order as a live turn.
     *
     * @param AgentEvalScope $agentEvalScope
     * @param AgentEvalCase $agentEvalCase
     * @return AIConversation
     */
    protected function buildProbeConversationOfCase(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase): AIConversation
    {
        $probeConversation = AIConversation::newInstance();
        $probeConversation->accountId = $agentEvalScope->getOwnerAccountId();
        $this->getAgentEvalRunHooksService()->attachNewConversationToScope($probeConversation, $agentEvalScope);
        $probeConversation->messages = AIConversationMessages::newInstance();
        $purposeName = $agentEvalCase->getPurposeName();
        if ($purposeName !== null) {
            $probeConversation->purposeName = $purposeName;
        }
        return $probeConversation;
    }

    /**
     * Walks the skill catalog for the probe conversation: every catalog skill and tool, the tools of the skills it can
     * reach (by its feature flags and account rights), and why each other skill is withheld (the hooks describe it).
     *
     * @param AgentEvalApplicabilityFacts $agentEvalApplicabilityFacts
     * @param AIConversation $probeConversation
     * @return void
     */
    protected function addCatalogFactsOfProbeConversation(AgentEvalApplicabilityFacts $agentEvalApplicabilityFacts, AIConversation $probeConversation): void
    {
        $reachableSkillCatalog = $probeConversation->buildReachableSkillCatalog();
        $reachableSkillNames = $reachableSkillCatalog->getSkillNames();
        $entitlement = AIConversationEntitlement::forAxes($probeConversation->findFeatureFlags(), $probeConversation->getAccountRights());
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $catalogSkillNames = [];
        $catalogToolNames = [];
        $availableToolNames = [];
        $withheldSkills = AISkills::newInstance();
        foreach ($aISkillsService->getCatalog()->getElements() as $aISkill) {
            $catalogSkillNames[] = $aISkill->name;
            foreach ($aISkill->getToolNames() as $toolName) {
                $catalogToolNames[] = $toolName;
            }
            if (in_array($aISkill->name, $reachableSkillNames, true)) {
                foreach ($aISkill->getToolNames($entitlement) as $toolName) {
                    $availableToolNames[] = $toolName;
                }
                continue;
            }
            $withheldSkills->add($aISkill);
        }
        $agentEvalApplicabilityFacts->catalogSkillNames = $catalogSkillNames;
        $agentEvalApplicabilityFacts->catalogToolNames = array_values(array_unique($catalogToolNames));
        $agentEvalApplicabilityFacts->availableToolNames = array_values(array_unique($availableToolNames));
        $agentEvalApplicabilityFacts->withheldSkillReasons = $this->getAgentEvalRunHooksService()
            ->findWithheldSkillReasons($probeConversation, $reachableSkillCatalog, $withheldSkills);
    }

    /**
     * The messages trace for a case row, with each message's `aiConversation` back reference removed: it serializes as
     * the fully expanded conversation graph and makes up nearly all of a case file while the trace's own messages are
     * the conversation's content. Hidden properties stay exposed: the tool calls, their results and the per-message
     * internals are what the trace is for. The serializer gives the set as an array or as an object; the reference is
     * removed from either form.
     *
     * @param AIConversationMessages $messages
     * @return mixed the serialized messages
     */
    public function buildMessagesTraceWithoutConversationBackReference(AIConversationMessages $messages): mixed
    {
        $messagesTrace = $messages->toObject(ignoreHideAttributes: true);
        if (is_array($messagesTrace) && is_array($messagesTrace['elements'] ?? null)) {
            foreach (array_keys($messagesTrace['elements']) as $elementKey) {
                if (is_array($messagesTrace['elements'][$elementKey])) {
                    unset($messagesTrace['elements'][$elementKey]['aiConversation']);
                } elseif (is_object($messagesTrace['elements'][$elementKey])) {
                    unset($messagesTrace['elements'][$elementKey]->aiConversation);
                }
            }
            return $messagesTrace;
        }
        $messageObjects = is_object($messagesTrace) ? ($messagesTrace->elements ?? null) : null;
        if (is_array($messageObjects)) {
            foreach ($messageObjects as $messageObject) {
                if (is_object($messageObject)) {
                    unset($messageObject->aiConversation);
                }
            }
        }
        return $messagesTrace;
    }

    /**
     * Every tool package of every skill: the harness prepares and restores through any tool of the platform.
     *
     * @return array<int, class-string>
     */
    protected function getPackageClassesOfEverySkill(): array
    {
        $packageClasses = [];
        foreach (AISkill::getPackageClassesBySkillName() as $packageClassesOfSkill) {
            foreach ($packageClassesOfSkill as $packageClass) {
                $packageClasses[$packageClass] = true;
            }
        }
        return array_keys($packageClasses);
    }

    /** The application's hooks of an evaluation run, resolved through the container. */
    protected function getAgentEvalRunHooksService(): AgentEvalRunHooksService
    {
        /** @var AgentEvalRunHooksService $agentEvalRunHooksService */
        $agentEvalRunHooksService = DDDService::instance()->getService(AgentEvalRunHooksService::class);
        return $agentEvalRunHooksService;
    }

    /** The messages service, resolved through the container. */
    protected function getMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /** The agent-spaces service, resolved through the container. */
    protected function getAgentSpacesService(): AgentSpacesService
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService;
    }

    /** The usage envelopes service of the AI module, resolved through the container. */
    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        return $aIUsageEnvelopesService;
    }
}
