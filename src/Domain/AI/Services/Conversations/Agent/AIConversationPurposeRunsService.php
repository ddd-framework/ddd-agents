<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilityOrigin;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurposeStartSettings;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariantTransition;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\Virtual\VirtualEntityRegistry;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * A purposed conversation's run (layer L2): its seeded task plan (the seed, a re-seed for the next
 * run, the owner-input markers of an older plan), the purpose's context budget and step budget on the conversation,
 * its variants, the purpose's turn-start notice, the consequences of a persisted plan and the close of a conversation
 * whose purpose goal is reached. What a purpose MEANS lives on
 * its class ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose}); rows are written
 * under the run the loop passes in.
 */
class AIConversationPurposeRunsService extends Service
{
    use AITraceLogTrait;

    /**
     * Apply a purpose's WORKING-CONTEXT budget (`contextBudgetTokens` in the purpose frontmatter) to a
     * conversation: the budget — clamped to 80 % of the model's window by
     * {@see AIConversationCompactionService::clampContextBudgetToModelWindow()} — becomes the conversation's
     * `CompactionSettings::$triggerTokens` (the ONE absolute compaction knob, replacing the 50k global ceiling for this
     * conversation), and the purpose's context-retention policy is stamped with its token-denominated eviction knobs
     * scaled by the same factor ({@see AIConversationPurpose::buildContextRetentionSettingsForConversation()}), so the
     * history budget and the capability layers grow TOGETHER — a doubled compaction threshold with the default 8k
     * pressure target would still evict a loaded skill mid-turn.
     *
     * `$onlyWhereUnset` is the RESUME mode: heal a conversation that predates the budget, but never overwrite a value
     * that is already there (an admin's explicit per-conversation override, or a retention policy already stamped).
     * A purpose WITHOUT a budget stamps only its plain retention policy (unchanged behaviour). Persists the touched
     * columns on a persisted conversation; a transient one (eval / CLI) keeps them in memory.
     */
    public function applyPurposeContextBudgetToConversation(
        AIConversation $conversation,
        AIConversationPurpose $purpose,
        ?string $aiModelName,
        bool $onlyWhereUnset
    ): void {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);

        // RESUME heal of a conversation without a retention column: the purpose's eviction knobs, never its agent wire
        // profile — the creation choices shape the stored history and are never applied mid-history
        $purposeContextRetentionSettings = $onlyWhereUnset
            ? $purpose->buildContextRetentionSettingsForConversationWithoutAgentWireProfile()
            : $purpose->buildContextRetentionSettingsForConversation();
        if ($purposeContextRetentionSettings !== null
            && (!$onlyWhereUnset || !isset($conversation->contextRetentionSettings))) {
            $conversation->contextRetentionSettings = $purposeContextRetentionSettings;
            $aIConversationsService->persistContextRetentionSettings($conversation);
        }

        if ($purpose->contextBudgetTokens === null || $purpose->contextBudgetTokens <= 0) {
            return;
        }
        $existingTriggerTokens = isset($conversation->compactionSettings) ? ($conversation->compactionSettings->triggerTokens ?? null) : null;
        if ($onlyWhereUnset && $existingTriggerTokens !== null && $existingTriggerTokens > 0) {
            return;
        }
        /** @var AIConversationCompactionService $aIConversationCompactionService */
        $aIConversationCompactionService = DDDService::instance()->getService(AIConversationCompactionService::class);
        $effectiveContextBudgetTokens = $aIConversationCompactionService->clampContextBudgetToModelWindow($purpose->contextBudgetTokens, $aiModelName);
        // Keep the conversation's OTHER compaction knobs (the degenerate-fallback message counts) if any are stored —
        // only the trigger is the purpose's.
        $compactionSettings = isset($conversation->compactionSettings) ? $conversation->compactionSettings : new CompactionSettings();
        if ($compactionSettings->triggerTokens === $effectiveContextBudgetTokens) {
            return;
        }
        $compactionSettings->triggerTokens = $effectiveContextBudgetTokens;
        $conversation->compactionSettings = $compactionSettings;
        $aIConversationsService->persistCompactionSettings($conversation);
        $this->aiTrace('purpose.contextBudget.applied', [
            'conversationId' => $conversation->id ?? null,
            'purposeName' => $purpose->name,
            'contextBudgetTokens' => $purpose->contextBudgetTokens,
            'effectiveContextBudgetTokens' => $effectiveContextBudgetTokens,
            'aiModelName' => $aiModelName,
        ]);
    }

    /**
     * The settings a NEW conversation of `$aIConversationPurpose` is created with: the start's
     * `$requestedRunSettings` with the purpose's `runSettings:` defaults filled into the fields it leaves unset, and the
     * purpose's retention map ({@see AIConversationPurpose::buildContextRetentionSettingsForConversation()}). The
     * purpose's AGENT WIRE PROFILE (the `runSettings:` map + the three creation choices of the retention map) applies AS
     * A WHOLE OR NOT AT ALL: the combination is validated for the conversation's model `$aiModelName` (null = the
     * agentic scope) exactly as the creation validates it ({@see AIConversationModelCallsService::resolveAgentWireConfigurationForNewConversation()});
     * a rejection — a non-OpenAI model, the LiteLLM egress — FALLS BACK to the start's own run settings and the retention
     * map without its creation choices (the model catalog's wire API, the global strategy and delivery: the purpose as it
     * ran before the profile), with the reason on the result and in the trace. A combination the START itself asks for
     * (an eval arm) is not rescued: the creation still refuses it. Writes nothing.
     */
    public function buildStartSettingsForNewPurposedConversation(AIConversationPurpose $aIConversationPurpose, ?string $aiModelName, ?RunSettings $requestedRunSettings): AIConversationPurposeStartSettings
    {
        $purposeStartSettings = new AIConversationPurposeStartSettings();
        $purposeStartSettings->runSettings = $aIConversationPurpose->buildRunSettingsForNewConversation($requestedRunSettings);
        $purposeStartSettings->contextRetentionSettings = $aIConversationPurpose->buildContextRetentionSettingsForConversation();
        if (!$aIConversationPurpose->declaresAgentWireProfile()) {
            return $purposeStartSettings;
        }
        /** @var AIConversationModelCallsService $aIConversationModelCallsService */
        $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
        $agentWireConfiguration = $aIConversationModelCallsService->resolveAgentWireConfigurationForNewConversation(
            $aiModelName,
            $purposeStartSettings->runSettings,
            ContextRetentionSettings::buildForNewConversation(
                $purposeStartSettings->contextRetentionSettings,
                $requestedRunSettings?->requestedSkillLoadingStrategy,
                $requestedRunSettings?->requestedRebuildEpochOnSituationChange,
                $requestedRunSettings?->requestedStateChangeDelivery,
                $aIConversationModelCallsService->resolveAgentWireApi($aiModelName, $purposeStartSettings->runSettings)
            )
        );
        if (!$agentWireConfiguration->isRejected()) {
            return $purposeStartSettings;
        }
        $purposeStartSettings->runSettings = $requestedRunSettings;
        $purposeStartSettings->contextRetentionSettings = $aIConversationPurpose->buildContextRetentionSettingsForConversationWithoutAgentWireProfile();
        $purposeStartSettings->agentWireProfileRejectionReason = $agentWireConfiguration->rejectionReason;
        $this->aiTrace('purpose.agentWireProfile.fellBack', [
            'purposeName' => $aIConversationPurpose->name,
            'aiModelName' => $aiModelName,
            'rejectionReason' => $agentWireConfiguration->rejectionReason,
        ]);
        return $purposeStartSettings;
    }

    /**
     * A re-seeded purposed conversation (the next run of a singleton that never closes) takes the
     * purpose's CURRENT step budget: `runSettings.maxStepsPerTurn` is persisted once at the conversation's start, so a
     * raised frontmatter value never reached existing conversations (every later run
     * still paused at the old value). Raise-only — an explicit higher per-conversation setting stays.
     */
    public function refreshPurposeRunSettings(AIConversation $conversation, string $purposeName): void
    {
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $aIConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
        if (!$aIConversationPurpose instanceof AIConversationPurpose || $aIConversationPurpose->maxStepsPerTurn === null || !isset($conversation->id)) {
            return;
        }
        $runSettings = $conversation->runSettings ?? null;
        if ($runSettings === null || ($runSettings->maxStepsPerTurn ?? 0) >= $aIConversationPurpose->maxStepsPerTurn) {
            return;
        }
        $runSettings->maxStepsPerTurn = $aIConversationPurpose->maxStepsPerTurn;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $aIConversationsService->persistRunSettings($conversation);
    }

    /**
     * RE-SEED a purposed conversation's task plan from its purpose config — for a purpose whose singleton
     * conversation never closes and takes a NEXT run of its work (a new request or a retry
     * lands in the space's existing conversation of the purpose, whose seeded plan the previous run ticked;
     * without a reset the agent would find nothing to do). REPLACES the plan wholesale (a model-authored plan of
     * the previous run is history, like its run) with the same write as the creation seed — unfenced, the caller
     * guarantees the conversation is at rest (IDLE) before delivering the new request. The purpose's live-state
     * pre-resolution runs again; where it reads the purpose's ACTIVE run, the caller has just proven that run
     * absent, so the re-seeded plan starts fully open. No-op for a conversation without a purpose or seed items.
     */
    public function reseedPurposeTaskPlan(AIConversation $conversation): void
    {
        $purposeName = $conversation->purposeName ?? null;
        if ($purposeName === null || $purposeName === '') {
            return;
        }
        $this->seedPurposeTaskPlan($conversation, $purposeName);
        $this->refreshPurposeRunSettings($conversation, $purposeName);
    }

    /**
     * A plan stored before its purpose dropped a done criterion carries keys no class decides any more
     * (the key of the dropped criterion, keys of earlier versions) — once per run they are removed in
     * memory ({@see TaskPlanItems::dropDoneCriteriaWithoutClass()}; the next plan persist writes them), so those items
     * are plain model items and their plan line never says they close themselves. A key a criterion decided under before a
     * rename ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion::LEGACY_KEYS}) is
     * renamed to the current key first and keeps its gate. Only for a purpose that seeds its plan
     * (a model's own items carry no criterion); fail-soft.
     */
    public function dropDoneCriteriaWithoutClassFromStoredTaskPlan(AIConversation $conversation): void
    {
        $taskPlanItems = $conversation->getTaskPlan()?->items;
        if ($taskPlanItems === null || $taskPlanItems->count() === 0) {
            return;
        }
        try {
            $aIConversationPurpose = $conversation->findPurpose();
            if (!$aIConversationPurpose instanceof AIConversationPurpose || !$aIConversationPurpose->seedsTaskPlan()) {
                return;
            }
            $taskPlanDoneCriteria = $aIConversationPurpose->getDoneCriteria();
            // a key renamed since the plan was stored keeps its gate under the current key; only a key no class decides drops
            $renamedCount = $taskPlanItems->renameLegacyDoneCriterionKeys($taskPlanDoneCriteria->getCurrentKeysByLegacyKey());
            if ($renamedCount > 0) {
                $this->aiTrace('turn.taskPlan.legacyDoneCriterionKeysRenamed', ['conversationId' => $conversation->id ?? null, 'renamedCount' => $renamedCount]);
            }
            $droppedCount = $taskPlanItems->dropDoneCriteriaWithoutClass($taskPlanDoneCriteria->getKeys());
            if ($droppedCount > 0) {
                $this->aiTrace('turn.taskPlan.doneCriteriaWithoutClassDropped', ['conversationId' => $conversation->id ?? null, 'droppedCount' => $droppedCount]);
            }
        } catch (Throwable) {
            // a stored key without a class stays: its gate decides nothing, only its plan line over-promises
        }
    }

    /**
     * SEED the purpose's task plan onto a freshly-created purposed conversation — the seeded items
     * (act-grouped) become the initial `agentWorkingState.taskPlan` so the agent FINDS the plan (the tail render
     * shows it from turn 1) instead of writing it. Deliberately NOT stamped touched-this-turn:
     * `updatedAtTurnInitiatorMessageIndex` stays null so the checked-stop bounce (which arms only when that index
     * EQUALS the current turn's initiator index) never fires against turn 1's first reply — the seed is FOUND, not
     * written this turn. Persisted unfenced (creation, no run claim held; a transient conversation keeps it in
     * memory only); no-op for a purpose with no seed items. Public for the eval runner's purposed transient
     * conversation (the host's eval runner).
     */
    public function seedPurposeTaskPlan(AIConversation $conversation, string $purposeName): void
    {
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $aIConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
        if (!$aIConversationPurpose instanceof AIConversationPurpose || !$aIConversationPurpose->seedsTaskPlan()) {
            return;
        }

        // The hierarchical seed lives in the PURPOSE CONFIG (config data belongs in
        // config files — the code-side seed tree is gone). Items are strings or {text, doneCriterion, subtasks}.
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition($aIConversationPurpose->buildSeedTaskPlanDefinition());
        if ($taskPlanItems->count() === 0) {
            return;
        }

        // Seed-time pre-resolution: the PURPOSE CLASS decides which seeded leaves its live data
        // already answers (done / not_applicable) — the criterion keys are purpose vocabulary, owned by the
        // purpose entity ({@see AIConversationPurpose::preResolveSeedTaskPlanItems()}), never a generic registry.
        $this->taskPlanRecordService()->preResolveSeededLeavesForPurpose($conversation, $aIConversationPurpose, $taskPlanItems);

        $taskPlan = new TaskPlan();
        $taskPlan->items = $taskPlanItems;
        $taskPlan->updatedAtTurnInitiatorMessageIndex = null; // NOT armed against turn 1 (see the docblock)

        $agentWorkingState = $conversation->getAgentWorkingState() ?? new AgentWorkingState();
        $agentWorkingState->taskPlan = $taskPlan;
        $conversation->agentWorkingState = $agentWorkingState;

        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $aIConversationsService->persistTaskPlanSlice($conversation, $taskPlan, null);
    }

    /**
     * The creation stamps of a purposed conversation (shared by the drive's creation
     * path, which every purposed start reaches through {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::startOrResumePurposedConversation()},
     * and the phone attach): the purpose name is stamped ONCE (it drives the PURPOSE_DIRECTIVE segment, the RunSettings
     * copy and the seed; targeted persist), then the purpose's task plan is seeded. The caller stamps the rest in its own
     * order.
     */
    public function stampPurposeOnNewConversation(AIConversation $conversation, string $purposeName): void
    {
        $conversation->purposeName = $purposeName;
        $conversation->updatePartialIgnoringRights('purposeName');
        $this->seedPurposeTaskPlan($conversation, $purposeName);
    }

    /**
     * The capabilities a purposed conversation's KICKOFF signal carries: the purpose's preloaded
     * skills — pinned when the purpose pins them — so their tools and instruction bodies are live from turn 1 with no
     * load_skills hop and cannot be evicted mid-flow (the weak-model reload-hop failure class). The kickoff signal is the
     * last message before turn 1, so the loop reads THIS set as the cumulative capabilities. Mirrors the delegation-child
     * seed; entitlement-filtered, so a purpose can never smuggle a locked skill. Null for a purpose without preloads.
     */
    public function buildPurposeKickoffCapabilities(AIConversation $conversation, AIConversationPurpose $purpose): ?LoadedCapabilities
    {
        if ($purpose->preloadSkillNames === []) {
            return null;
        }
        $entitledPreloadSkillNames = $conversation->findPermittedSkillNames($purpose->preloadSkillNames);
        $preloadedCapabilities = $conversation->buildSeededCapabilities($entitledPreloadSkillNames, true, LoadedCapabilityOrigin::atTurn(0, 0));
        if ($purpose->pinPreloadedSkills) {
            $preloadedCapabilities->markAllNonEvictable();
        }
        return $preloadedCapabilities;
    }

    /**
     * Resolve the conversation's VARIANTS through its purpose and
     * persist them as the working state's `variants` slice. `$completedToolName === null` = the turn-start resolve
     * (every dimension); otherwise only the dimensions the purpose names for that tool (`[]` = nothing, no read).
     * A purpose without dimensions is a no-op. Owner-decided values survive a record re-read (the service keeps
     * them); a change is traced per dimension. Never throws into the loop — a variant is instruction selection, a
     * failed resolve keeps the previous values.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function resolveAndPersistVariants(AIConversation $conversation, ?string $completedToolName): void
    {
        try {
            if ($conversation->isDelegation()) {
                return;
            }
            $aIConversationPurpose = $conversation->findPurpose();
            if (!$aIConversationPurpose instanceof AIConversationPurpose || $aIConversationPurpose->getVariantDimensions()->count() === 0) {
                return;
            }
            $onlyDimensions = $completedToolName !== null ? $aIConversationPurpose->variantDimensionsChangedByTool($completedToolName) : null;
            if ($onlyDimensions === []) {
                return;
            }
            $agentWorkingState = $conversation->getAgentWorkingState() ?? new AgentWorkingState();
            $previousVariants = $agentWorkingState->variants;
            // Fresh, not the registry copies: the dimensions read columns AND lazy sets the turn's
            // own writes just changed — the registry's SET entry would still hold the empty set from before the write,
            // so a dimension reading it never proposed. A purpose that reads the record of the conversation's space
            // loads it inside this block (the purpose reads the space from the conversation; without one it resolves
            // at account level). The locally saved flags are restored in `finally` (never the global caches snapshot).
            $previousDoctrineRegistryClearCache = DoctrineEntityRegistry::$clearCache;
            $previousVirtualRegistryClearCache = VirtualEntityRegistry::$clearCache;
            DoctrineEntityRegistry::$clearCache = true;
            VirtualEntityRegistry::$clearCache = true;
            try {
                $resolvedVariants = $aIConversationPurpose->resolveVariants($conversation, $previousVariants, $onlyDimensions);
            } finally {
                DoctrineEntityRegistry::$clearCache = $previousDoctrineRegistryClearCache;
                VirtualEntityRegistry::$clearCache = $previousVirtualRegistryClearCache;
            }
            $transitions = $resolvedVariants->transitionsSince($previousVariants);
            $agentWorkingState->variants = $resolvedVariants;
            $conversation->agentWorkingState = $agentWorkingState;
            if ($transitions === [] && $previousVariants !== null) {
                return; // nothing changed — no write
            }
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'variants');
            $this->aiTrace('variants.resolved', [
                'conversationId' => $conversation->id ?? null,
                'afterTool' => $completedToolName,
                'variants' => $resolvedVariants->toTraceLine(),
                'transitions' => array_map(static fn (AIConversationVariantTransition $transition): array => $transition->toTraceArray(), $transitions),
            ]);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $throwable) {
            $this->aiTrace('variants.resolveFailed', ['conversationId' => $conversation->id ?? null, 'afterTool' => $completedToolName, 'error' => $throwable->getMessage()]);
        }
    }

    /** Whether the conversation's purpose SEEDS its task plan ({@see AIConversationPurpose::seedsTaskPlan()}); false without a purpose. */
    public function conversationPurposeSeedsItsTaskPlan(AIConversation $conversation): bool
    {
        try {
            return $conversation->findPurpose()?->seedsTaskPlan() ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the conversation's PURPOSE declares its runs autonomous after the kickoff
     * ({@see AIConversationPurpose::$worksAutonomouslyAfterKickoff}) — the checked stop reads this to decide whether the
     * progress exemption applies, the kickoff fold whether an opener is owed. False for a purpose-less conversation or an
     * unknown purpose.
     */
    public function conversationPurposeWorksAutonomously(AIConversation $conversation): bool
    {
        $aIConversationPurpose = $conversation->findPurpose();
        return $aIConversationPurpose instanceof AIConversationPurpose && $aIConversationPurpose->worksAutonomouslyAfterKickoff;
    }

    /** The loop stopped without finishing — claimed turns only — through the purpose's run hook ({@see AIConversationPurpose::recordStopOfRun()}). */
    public function recordPurposeRunStop(AIConversation $conversation, string $agentStopReason, ?string $runToken): void
    {
        if ($runToken === null) {
            return; // the sync CLI / eval path is not a customer run
        }
        $conversation->findPurpose()?->recordStopOfRun($conversation, $agentStopReason, $runToken);
    }

    /** The plan closed blocked without an owner question — claimed turns only — through {@see AIConversationPurpose::recordPlanBlockedStopOfRun()}. */
    public function recordPurposeRunPlanBlockedStop(AIConversation $conversation, ?string $runToken): void
    {
        if ($runToken === null) {
            return;
        }
        $conversation->findPurpose()?->recordPlanBlockedStopOfRun($conversation, $runToken);
    }

    /** The resumption event — a claimed turn's first model call — through {@see AIConversationPurpose::clearStopOfRunOnResumption()}. */
    public function clearPurposeRunStopOnResumption(AIConversation $conversation, ?string $runToken): void
    {
        if ($runToken === null) {
            return;
        }
        $conversation->findPurpose()?->clearStopOfRunOnResumption($conversation, $runToken);
    }

    /** Whether the conversation's purpose declares `fastMode` ({@see AIConversationPurpose::$fastMode}); false without a purpose. */
    public function conversationPurposeAsksForFastMode(AIConversation $conversation): bool
    {
        try {
            return $conversation->findPurpose()?->fastMode ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The purpose's notice for the START of an owner turn ({@see AIConversationPurpose::buildTurnStartNotice()}), appended
     * as a PURPOSE_CONSEQUENCE SIGNAL before the first model call. Only for a root conversation; fail-soft.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function appendPurposeTurnStartNotice(
        AgentRun $agentRun,
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        try {
            if ($conversation->isDelegation()) {
                return;
            }
            $aIConversationPurpose = $conversation->findPurpose();
            if (!$aIConversationPurpose instanceof AIConversationPurpose) {
                return;
            }
            $notice = $aIConversationPurpose->buildTurnStartNotice($conversation);
            if ($notice === null || trim($notice) === '') {
                return;
            }
            $noticeSignal = $this->messagesService()->appendNewMessage(
                $conversation,
                $transientMessageIds,
                AIConversationMessage::MESSAGE_SIGNAL,
                $notice,
                role: AIConversationMessage::ROLE_SYSTEM,
                authorType: AIConversationMessage::AUTHOR_AGENT,
                signalSource: AIConversationMessage::SIGNAL_SOURCE_PURPOSE_CONSEQUENCE
            );
            $this->messagesService()->persistMessageUnderRun($agentRun, $noticeSignal, $consoleStream, $emitRealtimeSignal);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $throwable) {
            $this->aiTrace('purpose.turnStartNotice.failed', ['conversationId' => $conversation->id ?? null, 'error' => $throwable->getMessage()]);
        }
    }

    /**
     * The purpose consequence-hook call every plan-slice persist site shares
     * ({@see AIConversationPurpose::applyConsequencesOfPersistedTaskPlan()}): resolve the conversation's purpose,
     * run its consequences, and when the purpose returns a NOTICE (e.g. an automatic switch of the interface
     * language that the agent must announce), append + persist it as a model-visible SIGNAL so the model reads it
     * on its very next step.
     */
    public function applyPurposeConsequencesAfterPlanPersist(
        AgentRun $agentRun,
        AIConversation $conversation,
        ?TaskPlan $currentTaskPlan,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        $purposeConsequenceNotice = $conversation->findPurpose()
            ?->applyConsequencesOfPersistedTaskPlan($conversation, $currentTaskPlan);
        if ($purposeConsequenceNotice === null || trim($purposeConsequenceNotice) === '') {
            return;
        }
        $purposeConsequenceSignal = $this->messagesService()->appendNewMessage(
            $conversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_SIGNAL,
            $purposeConsequenceNotice,
            role: AIConversationMessage::ROLE_SYSTEM,
            authorType: AIConversationMessage::AUTHOR_AGENT,
            signalSource: AIConversationMessage::SIGNAL_SOURCE_PURPOSE_CONSEQUENCE
        );
        $this->messagesService()->persistMessageUnderRun($agentRun, $purposeConsequenceSignal, $consoleStream, $emitRealtimeSignal);
    }

    /**
     * CLOSE the conversation when its purpose declares the goal reached — called at
     * the fully-settled release point (no checkpoint just dispatched) AND from the supervisor completion path
     * after a healthy (steer-less) milestone verdict. Defers while supervisor work is still PENDING: a seed
     * briefing newer than the newest verdict (checkpoint in flight) or an unconsumed steer-bearing verdict (the
     * correction must materialize + run first — that turn's settle re-evaluates). The close itself is the
     * guarded IDLE → COMPLETED flip ({@see AIConversationRunClaimsService::closeConversation()}); the realtime signal
     * makes the frontend swap its input for the closed-conversation bar (published with the run's push-config memo).
     */
    public function closeConversationIfPurposeGoalReached(AgentRun $agentRun, AIConversation $conversation, bool $allowClose = true): void
    {
        if (!isset($conversation->id) || $conversation->isDelegation() || !($conversation->purposeName ?? null)) {
            return;
        }
        $aIConversationPurpose = $conversation->findPurpose();
        if ($aIConversationPurpose === null) {
            return;
        }
        /** @var AIConversationsService $conversationsService */
        $conversationsService = AIConversation::getService();
        // RECONCILIATION after a finished turn (a purpose defers its unmade offers once its goal is reached): the
        // reconciled plan is written IDLE-guarded — no run claim is held here; a new run taking over means nothing
        // is written and the next settled turn re-evaluates.
        $agentWorkingState = $conversation->getAgentWorkingState();
        $taskPlanBeforeReconciliation = $agentWorkingState?->taskPlan?->clone();
        if ($aIConversationPurpose->reconcileTaskPlanAtSettledTurn($conversation)) {
            $reconciledTaskPlan = $agentWorkingState?->taskPlan;
            if ($reconciledTaskPlan === null || !$conversationsService->persistTaskPlanSliceWhileIdle($conversation, $reconciledTaskPlan)) {
                // Nothing was written (a new run owns the conversation, or the row is gone): the loaded plan must
                // not diverge from the persisted one — an inline drive still serializes it into its response.
                if ($agentWorkingState !== null && $taskPlanBeforeReconciliation !== null) {
                    $agentWorkingState->taskPlan = $taskPlanBeforeReconciliation;
                }
                $this->aiTrace('conversation.reconcileNotPersisted', ['conversationId' => $conversation->id]);
                return;
            }
            $this->aiTrace('conversation.planReconciledAtSettledTurn', ['conversationId' => $conversation->id, 'purposeName' => $conversation->purposeName]);
        }
        if (!$aIConversationPurpose->shouldCloseConversationAfterSettledTurn($conversation)) {
            return;
        }
        // DURABLE goal state FIRST, independent of the supervisor: the purpose's side effect beyond the
        // conversation (e.g. a completion record of the application) is what the owner's next click reads —
        // it must not wait for a close review. The CLOSE below is what the supervisor gates. A false answer
        // defers both to the next settled turn / the reaper's settle sweep.
        if (!$aIConversationPurpose->completePurposeGoalAndConfirmItIsDurable($conversation)) {
            $this->aiTrace('conversation.closeDeferred.goalNotDurable', ['conversationId' => $conversation->id, 'purposeName' => $conversation->purposeName]);
            return;
        }
        if (!$allowClose || $this->supervisorCheckpointRunsService()->supervisorWorkIsPendingFor($conversation)) {
            return;
        }
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        if ($aIConversationRunClaimsService->closeConversation($conversation)) {
            $this->aiTrace('conversation.closed', ['conversationId' => $conversation->id, 'purposeName' => $conversation->purposeName]);
            $this->realtimeSignalService()->publishChangeSignal($conversation, AIConversationRealtimeSignalService::STATUS_IDLE, $agentRun->pushConfigMemo);
        }
    }

    protected function taskPlanRecordService(): AIConversationTaskPlanRecordService
    {
        /** @var AIConversationTaskPlanRecordService $aIConversationTaskPlanRecordService */
        $aIConversationTaskPlanRecordService = DDDService::instance()->getService(AIConversationTaskPlanRecordService::class);
        return $aIConversationTaskPlanRecordService;
    }

    protected function supervisorCheckpointRunsService(): SupervisorCheckpointRunsService
    {
        /** @var SupervisorCheckpointRunsService $supervisorCheckpointRunsService */
        $supervisorCheckpointRunsService = DDDService::instance()->getService(SupervisorCheckpointRunsService::class);
        return $supervisorCheckpointRunsService;
    }

    /** The realtime change-signal service — resolved inline (DI rule); a test double replaces it. */
    protected function realtimeSignalService(): AIConversationRealtimeSignalService
    {
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        return $aIConversationRealtimeSignalService;
    }

    /** The messages service — resolved inline (DI rule); a test double replaces it. */
    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /**
     * The purpose a purposed start asks for, validated (moved from the agent loop's purposed start): a registered, configured purpose that may be started through the API — reserved system purposes (e.g.
     * Background.MechanicalOperation) are spawned by the loop only. For a CUSTOMER start the purpose's
     * `requiredFeatureFlags` must all be available to the space the conversation is started for; ADMIN starts stay
     * ungated so the console can drive any purpose against any account.
     *
     * @throws BadRequestException on an unknown, unconfigured, reserved or not-included purpose
     */
    public function findStartablePurposeForAgentSpace(string $scope, AgentSpace $agentSpace, string $purposeName): AIConversationPurpose
    {
        if (!AIConversationPurpose::isRegisteredPurposeName($purposeName)) {
            throw new BadRequestException("Unknown conversation purpose: {$purposeName}");
        }
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $aIConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
        if (!$aIConversationPurpose instanceof AIConversationPurpose) {
            throw new BadRequestException("Conversation purpose has no configuration: {$purposeName}");
        }
        // Reserved system purposes (e.g. Background.MechanicalOperation) are spawned by the loop only — a
        // standalone start has no batch/parent to settle into and would just wedge.
        if (!$aIConversationPurpose->startableViaApi) {
            throw new BadRequestException("Conversation purpose cannot be started via the API: {$purposeName}");
        }
        // Entitlement gate (CUSTOMER only — the admin console may start any purpose against any account): the
        // purpose's requiredFeatureFlags must all be available to the space. Same semantics as
        // {@see AIConversation::findPermittedSkillNames()}: an unresolvable flag set stays ungated.
        if ($scope === AIConversationDriveScope::CUSTOMER && $aIConversationPurpose->requiredFeatureFlags !== []) {
            /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
            $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
            $featureFlagsOfAgentSpace = $aIConversationEntitlementsService->findFeatureFlagsOfAgentSpace($agentSpace);
            if ($featureFlagsOfAgentSpace !== null
                && !$featureFlagsOfAgentSpace->hasAllAvailableFeatureFlags($aIConversationPurpose->requiredFeatureFlags)) {
                throw new BadRequestException(
                    "Conversation purpose '{$purposeName}' is not included in this customer's plan."
                );
            }
        }
        return $aIConversationPurpose;
    }

    /**
     * The singleton of a purposed start, resumed (moved from the agent loop's purposed start): the newest STILL-ACTIVE purposed conversation of the space (rights-gated find → the customer's own
     * only), with the purpose's start hook run and its context budget healed where nothing is set yet; null when
     * none exists and the caller starts a fresh one. The singleton is kept per space, so the space is required: a
     * purposed conversation at account level keeps none and is never resumed here.
     */
    public function resumeSingletonPurposedConversation(AgentSpace $agentSpace, string $purposeName, AIConversationPurpose $aIConversationPurpose): ?AIConversation
    {
        // Singleton: RESUME the existing active purposed conversation (rights-gated find → the customer's own only).
        // A purpose whose finished conversation stays the singleton returns
        // its COMPLETED conversation too: the owner reopens the finished conversation to read it, nothing is started.
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $existingPurposedConversation = $aIConversationsService->findNewestPurposedConversationOfAgentSpace(
            $agentSpace,
            $purposeName,
            includeCompleted: $aIConversationPurpose->completedConversationStaysSingleton
        );
        if ($existingPurposedConversation instanceof AIConversation) {
            $aIConversationPurpose->afterPurposedStart($agentSpace);
            // a singleton started BEFORE its purpose declared a context budget (or before the budget was
            // raised) still runs on the global ceiling — heal it on resume, but only where nothing is set yet: an
            // admin's explicit per-conversation override is never overwritten.
            $this->applyPurposeContextBudgetToConversation(
                $existingPurposedConversation,
                $aIConversationPurpose,
                $existingPurposedConversation->aiModelNameOverride ?? $aIConversationPurpose->preferredModelName,
                onlyWhereUnset: true
            );
            return $existingPurposedConversation;
        }
        return null;
    }

    /**
     * The context retention settings a phone conversation runs with once `$aIConversationPurpose` is attached: the purpose's retention map with the skill-loading strategy the conversation was stamped with at its
     * creation; a conversation without a stamp takes the purpose's frontmatter value, else the global default for the
     * wire API its model resolves to — the resolution of a purposed creation ({@see ContextRetentionSettings::buildForNewConversation()}).
     * Writes nothing.
     */
    public function buildContextRetentionSettingsForPhoneAttach(AIConversation $conversation, AIConversationPurpose $aIConversationPurpose): ContextRetentionSettings
    {
        /** @var AIConversationModelCallsService $aIConversationModelCallsService */
        $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
        $stampedSkillLoadingStrategy = isset($conversation->contextRetentionSettings)
            ? ContextRetentionSettings::canonicalSkillLoadingStrategy($conversation->contextRetentionSettings->skillLoadingStrategy)
            : null;
        // the creation stamp of rebuildEpochOnSituationChange survives the purpose's map too
        $stampedRebuildEpochOnSituationChange = isset($conversation->contextRetentionSettings) ? $conversation->contextRetentionSettings->rebuildEpochOnSituationChange : null;
        // and the creation stamp of stateChangeDelivery
        $stampedStateChangeDelivery = isset($conversation->contextRetentionSettings) ? $conversation->contextRetentionSettings->stateChangeDelivery : null;
        return ContextRetentionSettings::buildForNewConversation(
            $aIConversationPurpose->buildContextRetentionSettingsForConversation(),
            $stampedSkillLoadingStrategy,
            $stampedRebuildEpochOnSituationChange,
            $stampedStateChangeDelivery,
            $aIConversationModelCallsService->resolveAgentWireApi($conversation->aiModelNameOverride ?? null, isset($conversation->runSettings) ? $conversation->runSettings : null)
        );
    }

    /**
     * Turn an EXISTING, freshly created conversation into a PURPOSED one WITHOUT driving a kickoff turn
     * (an intake by phone): a phone call's conversation is born at the door, and its first turn is driven by the call
     * worker's first delegation on the TRANSCRIPT — an opening message nobody speaks would be a wasted turn. So this
     * does exactly the creation-time stamps the purposed start does through the drive
     * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::driveConversation()}; moved from the
     * agent loop) — the purpose name, the seeded task plan with its pre-resolution, the purpose's
     * step cap and context-retention policy — and appends the kickoff SIGNAL with the purpose's preloaded (and
     * pinned) skills as the message's capabilities, so turn 1 finds the conductor loaded. The trailing signal is
     * owed work; the next inbound (a transcript row plus the delegation signal) drives the real turn.
     *
     * The conversation must be persisted (it needs an id) and must not carry a purpose yet. Its stamped skill-loading
     * strategy is kept ({@see buildContextRetentionSettingsForPhoneAttach()}) and the wire combination is validated like
     * a creation.
     *
     * @throws BadRequestException on an unknown, unconfigured or already purposed conversation, or a rejected wire combination
     */
    public function attachPurposeToPhoneConversation(AIConversation $conversation, string $purposeName): void
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        if (!AIConversationPurpose::isRegisteredPurposeName($purposeName)) {
            throw new BadRequestException("Unknown conversation purpose: {$purposeName}");
        }
        if (!isset($conversation->id)) {
            throw new BadRequestException('A purpose can only be attached to a persisted conversation.');
        }
        if (($conversation->purposeName ?? null) !== null && $conversation->purposeName !== '') {
            throw new BadRequestException("Conversation {$conversation->id} already carries the purpose {$conversation->purposeName}.");
        }
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $aIConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
        if (!$aIConversationPurpose instanceof AIConversationPurpose) {
            throw new BadRequestException("Conversation purpose has no configuration: {$purposeName}");
        }

        // the skill-loading strategy the conversation was stamped with at its creation survives
        // the purpose's retention map, and the combination is validated like a creation — before anything is written
        $contextRetentionSettingsForThePurpose = $this->buildContextRetentionSettingsForPhoneAttach($conversation, $aIConversationPurpose);
        /** @var AIConversationModelCallsService $aIConversationModelCallsService */
        $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
        $agentWireConfiguration = $aIConversationModelCallsService->resolveAgentWireConfigurationForNewConversation(
            $conversation->aiModelNameOverride ?? null,
            isset($conversation->runSettings) ? $conversation->runSettings : null,
            $contextRetentionSettingsForThePurpose
        );
        if ($agentWireConfiguration->isRejected()) {
            throw new BadRequestException((string)$agentWireConfiguration->rejectionReason);
        }

        $this->stampPurposeOnNewConversation($conversation, $purposeName);
        if ($aIConversationPurpose->maxStepsPerTurn !== null) {
            $phoneRunSettings = isset($conversation->runSettings) ? $conversation->runSettings : RunSettings::newInstance();
            $phoneRunSettings->maxStepsPerTurn = $aIConversationPurpose->maxStepsPerTurn;
            $conversation->runSettings = $phoneRunSettings;
            $conversation->updatePartialIgnoringRights('runSettings');
        }
        // The purpose's context-retention policy AND its context budget — the same stamps the creation path
        // applies; the phone conversation has no model override yet, so the purpose's preferred model clamps the budget.
        $this->applyPurposeContextBudgetToConversation(
            $conversation,
            $aIConversationPurpose,
            $conversation->aiModelNameOverride ?? $aIConversationPurpose->preferredModelName,
            onlyWhereUnset: false
        );
        $contextRetentionSettingsAfterThePurpose = isset($conversation->contextRetentionSettings) ? $conversation->contextRetentionSettings : new ContextRetentionSettings();
        if ($contextRetentionSettingsAfterThePurpose->skillLoadingStrategy !== $contextRetentionSettingsForThePurpose->skillLoadingStrategy
            || $contextRetentionSettingsAfterThePurpose->rebuildEpochOnSituationChange !== $contextRetentionSettingsForThePurpose->rebuildEpochOnSituationChange
            || $contextRetentionSettingsAfterThePurpose->stateChangeDelivery !== $contextRetentionSettingsForThePurpose->stateChangeDelivery
        ) {
            $contextRetentionSettingsAfterThePurpose->skillLoadingStrategy = $contextRetentionSettingsForThePurpose->skillLoadingStrategy;
            $contextRetentionSettingsAfterThePurpose->rebuildEpochOnSituationChange = $contextRetentionSettingsForThePurpose->rebuildEpochOnSituationChange;
            $contextRetentionSettingsAfterThePurpose->stateChangeDelivery = $contextRetentionSettingsForThePurpose->stateChangeDelivery;
            $conversation->contextRetentionSettings = $contextRetentionSettingsAfterThePurpose;
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
            $aIConversationsService->persistContextRetentionSettings($conversation);
        }

        // The kickoff SIGNAL carries the preloaded skills exactly like driveConversation's creation path — the loop
        // reads the LAST message's capabilities as the cumulative set, so the conductor is live from turn 1.
        if (!isset($conversation->messages)) {
            $conversation->messages = AIConversationMessages::newInstance();
        }
        $aIConversationMessagesService->appendInboundSignal(
            $conversation,
            AIConversationMessage::sanitizeUserText($aIConversationPurpose->kickoffSignalText ?? 'Begin this purposed conversation.'),
            AIConversationMessage::SIGNAL_SOURCE_PURPOSE_KICKOFF,
            loadedCapabilities: $this->buildPurposeKickoffCapabilities($conversation, $aIConversationPurpose),
            isStartSignal: true
        );
    }
}
