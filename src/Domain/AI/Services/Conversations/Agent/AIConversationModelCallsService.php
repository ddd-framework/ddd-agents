<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolResultImagesOfToolCalls;
use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentModelCallSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCalls;
use DDD\Domain\AI\Exceptions\AIModelCallTransportFailedException;
use DDD\Domain\AI\Exceptions\AIModelCallFailedException;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendOnlyCall;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesInputItems;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesRequestComposer;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\Service;
use GuzzleHttp\Exception\TransferException;
use DDD\Domain\AI\Entities\Models\CustomerSelection\AgentCustomerSelectableModels;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Domain\AI\Services\SystemPrompts\AgentBaseSystemPromptCacheService;

/**
 * The model call of one loop step: the per-call context composed on the loop side and
 * handed to the egress {@see ArgusAIAgentConversation} with the step's tool schemas, the tool-call images resolved for
 * the wire (vision-gated on the resolved model), the frozen {@see RequestSnapshot} of what was sent, and the model's
 * output as a {@see ModelResponse}. It creates and persists no message — the loop owns those. What the call is sent
 * with beyond the conversation (effort, fast tier, the step's timings) arrives as {@see AgentModelCallSettings}.
 */
class AIConversationModelCallsService extends Service
{
    /**
     * The model call of one loop step with this step's call settings (moved from the agent loop): the owner turn's DECIDED reasoning effort (every step and chunk of the turn) wins over the
     * catalog value; a purpose may ask for the model's FAST service tier, and
     * every conversation with the CUSTOMER PRESENT runs fast (a live chat or a phone call and
     * the delegations forked from one; never a scheduled task, a background operation or a hidden platform run); the
     * step's pre-call timings of the run that makes this call — a collaborator's call (the supervisor) has none.
     *
     * @param array<int, array<string, mixed>> $toolSchemas
     * @param string|array<string, mixed>|null $forceToolChoice
     * @throws InternalErrorException the context range is stale (the wire would replay an earlier turn)
     * @throws AIModelCallTransportFailedException the HTTP transfer of the call failed (Guzzle TransferException: a timeout, a dropped connection, a response exception)
     */
    public function performModelCallOfStep(
        AIConversation $conversation,
        array $toolSchemas,
        string|array|null $forceToolChoice = null,
        ?string $modelNameOverride = null,
        string $baseSystemPrompt = '',
        ?AgentRun $agentRun = null
    ): ModelResponse {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        /** @var AgentReasoningEffortDecisionsService $agentReasoningEffortDecisionsService */
        $agentReasoningEffortDecisionsService = DDDService::instance()->getService(AgentReasoningEffortDecisionsService::class);
        $modelCallSettings = new AgentModelCallSettings();
        $modelCallSettings->reasoningEffortOverride = $agentReasoningEffortDecisionsService->resolveDecidedReasoningEffortForCurrentTurn(
            $conversation,
            !$conversation->isDelegation() && $aIConversationPurposeRunsService->conversationPurposeWorksAutonomously($conversation)
        );
        $modelCallSettings->fastModeRequested = $aIConversationPurposeRunsService->conversationPurposeAsksForFastMode($conversation) || $conversation->runsWithCustomerPresent();
        $modelCallSettings->preCallMs = $agentRun?->turnTimings->currentStepPreCallMs;
        $modelCallSettings->schemasMs = $agentRun?->turnTimings->currentStepSchemasMs;
        return $this->performModelCall($conversation, $toolSchemas, $forceToolChoice, $modelNameOverride, $baseSystemPrompt, $modelCallSettings, $agentRun);
    }

    /**
     * How the agent talks to the model for `$conversation`: the model the egress resolves for it (the
     * override name, else the agentic scope), its catalog wire API under the conversation's run settings, the stamped
     * skill-loading strategy, the carry-over row, and whether this process's egress is the direct OpenRouter one —
     * validated against the allowed combinations (a rejection carries its reason).
     */
    public function resolveAgentWireConfiguration(AIConversation $conversation, ?string $modelNameOverride): AgentWireConfiguration
    {
        $argusAIAgentConversation = new ArgusAIAgentConversation();
        $argusAIAgentConversation->aiModelNameOverride = $modelNameOverride ?? ($conversation->aiModelNameOverride ?? null);
        return AgentWireConfiguration::resolveFor(
            $this->findAgentModelOfEgress($argusAIAgentConversation),
            isset($conversation->runSettings) ? $conversation->runSettings : null,
            isset($conversation->contextRetentionSettings) ? $conversation->contextRetentionSettings : null,
            $argusAIAgentConversation->targetsDirectOpenRouterEgress()
        );
    }

    /**
     * {@see resolveAgentWireConfiguration()} for a conversation about to be created: from the model
     * name the start names (null = the agentic scope), the run settings and the context retention settings it will be
     * stamped with — so an invalid start is refused before anything is persisted.
     */
    public function resolveAgentWireConfigurationForNewConversation(?string $aiModelName, ?RunSettings $runSettings, ContextRetentionSettings $contextRetentionSettings): AgentWireConfiguration
    {
        $argusAIAgentConversation = new ArgusAIAgentConversation();
        $argusAIAgentConversation->aiModelNameOverride = $aiModelName;
        return AgentWireConfiguration::resolveFor(
            $this->findAgentModelOfEgress($argusAIAgentConversation),
            $runSettings,
            $contextRetentionSettings,
            $argusAIAgentConversation->targetsDirectOpenRouterEgress()
        );
    }

    /**
     * The wire API a conversation with the model `$aiModelName` (null = the agentic scope) and `$runSettings` speaks
     * ({@see AgentWireConfiguration::resolveWireApiFor()}) — what the creation stamp of the skill-loading strategy reads
     * ({@see ContextRetentionSettings::resolveDefaultSkillLoadingStrategyForAgentWireApi()}).
     */
    public function resolveAgentWireApi(?string $aiModelName, ?RunSettings $runSettings): string
    {
        $argusAIAgentConversation = new ArgusAIAgentConversation();
        $argusAIAgentConversation->aiModelNameOverride = $aiModelName;
        return AgentWireConfiguration::resolveWireApiFor($this->findAgentModelOfEgress($argusAIAgentConversation), $runSettings);
    }

    /**
     * The append-only split of one model call ({@see AgentAppendOnlyCall::buildFor()}) with its world
     * reads: the schemas an appended item may need (the offer before the loop's per-step filters; `buildFor()` adds the
     * static schemas of the conditional meta tools, so a recorded item keeps a tool whose condition ended), and the skills the
     * items render (the loaded ones and the ones the epoch's earlier calls appended), resolved from the catalog.
     * `$appendsForThisCall` false = a point-in-time rebuild (only the recorded items).
     *
     * @param array<int, array<string, mixed>> $offeredToolSchemas
     */
    public function buildAppendOnlyCall(AIConversation $conversation, AgentModelInput $agentModelInput, array $offeredToolSchemas, bool $appendsForThisCall, ?AgentRun $agentRun = null): AgentAppendOnlyCall
    {
        $toolSchemasByName = [];
        foreach ([...$conversation->buildOfferedTools(!$conversation->isDelegation(), null, $agentRun)->toOpenAiToolSchemas(), ...$offeredToolSchemas] as $toolSchema) {
            $toolName = (string)($toolSchema['function']['name'] ?? '');
            if ($toolName !== '') {
                $toolSchemasByName[$toolName] = $toolSchema;
            }
        }
        $skillNamesToResolve = $agentModelInput->loadedCapabilities?->getLoadedSkillNames() ?? [];
        foreach ($agentModelInput->messages as $contextMessage) {
            $contextRequestSnapshot = isset($contextMessage->requestSnapshot) ? $contextMessage->requestSnapshot : null;
            foreach ($contextRequestSnapshot->appendedSkillNames ?? [] as $appendedSkillName) {
                $skillNamesToResolve[] = (string)$appendedSkillName;
            }
            // the skills a recorded `# Situation` entry carries the sections of
            foreach ($contextRequestSnapshot->appendedSituationNames ?? [] as $appendedSituationName) {
                $skillNamesToResolve[] = (string)$appendedSituationName;
            }
        }
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $resolvedSkills = new AISkills();
        foreach (array_values(array_unique($skillNamesToResolve)) as $skillNameToResolve) {
            $resolvedSkill = $aISkillsService->getAISkillByName($skillNameToResolve);
            if ($resolvedSkill !== null) {
                $resolvedSkills->add($resolvedSkill);
            }
        }
        return AgentAppendOnlyCall::buildFor($agentModelInput, $offeredToolSchemas, $toolSchemasByName, $resolvedSkills, $appendsForThisCall);
    }

    /**
     * The model the egress resolves (the override name, else the agentic scope, else the attribute default); null when
     * it resolves none — an unknown name then reads as an unknown model (Chat Completions), the call itself reports it.
     */
    protected function findAgentModelOfEgress(ArgusAIAgentConversation $argusAIAgentConversation): ?AIModel
    {
        try {
            return $argusAIAgentConversation->getAIModel();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The wire tool schemas of one model call ({@see AIConversation::buildOfferedTools()}).
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildOfferedToolSchemas(AIConversation $conversation, bool $allowThreads = true, ?AIConversationMessage $anchorMessage = null, ?AgentRun $agentRun = null): array
    {
        return $conversation->buildOfferedTools($allowThreads, $anchorMessage, $agentRun)->toOpenAiToolSchemas();
    }

    /**
     * Resolve the BASE system prompt for this conversation (it knows the conversation's space) — ONCE per run (nothing
     * volatile is in it — "now" comes from the newest user message's [time] prefix). The prompt is cached by
     * {@see AgentBaseSystemPromptCacheService} under a key built from what it is made of (space and account inputs
     * versions, prompt files), so a turn reads it instead of building it. The admin/CLI context-composition inspection
     * re-resolves the SAME base prompt the turn used and passes it to
     * {@see AIConversationMessage::buildContextComposition()}.
     */
    public function resolveBaseSystemPrompt(AIConversation $conversation): string
    {
        /** @var AgentBaseSystemPromptCacheService $agentBaseSystemPromptCacheService */
        $agentBaseSystemPromptCacheService = DDDService::instance()->getService(AgentBaseSystemPromptCacheService::class);
        return $agentBaseSystemPromptCacheService->resolveBaseSystemPrompt($conversation);
    }

    /**
     * Run ONE model turn: build this conversation's per-call context (base prompt + the message history up to and
     * including the newest message, the anchor's loaded skills + delegation flag), send it through the egress
     * {@see ArgusAIAgentConversation} with the turn's tool schemas, and return the model's output as a
     * {@see ModelResponse} — the assistant text, the turn's parallel {@see ToolCalls} decoded from the captured wire
     * calls, the usage/cost, and the frozen {@see RequestSnapshot} of the EXACT request sent. No message is created
     * or persisted here (deliberately NOT `toEntity()`): the loop owns message creation and reads these outputs back.
     *
     * @param array<int, array<string, mixed>> $toolSchemas
     * @param string|array<string, mixed>|null $forceToolChoice
     * @param AgentRun|null $agentRun the run that makes the call (the context build reads its per-run memos), null for a collaborator's call
     * @throws InternalErrorException the context range is stale (the wire would replay an earlier turn)
     * @throws AIModelCallTransportFailedException the HTTP transfer of the call failed (Guzzle TransferException: a timeout, a dropped connection, a response exception)
     */
    public function performModelCall(
        AIConversation $conversation,
        array $toolSchemas,
        string|array|null $forceToolChoice,
        ?string $modelNameOverride,
        string $baseSystemPrompt,
        AgentModelCallSettings $modelCallSettings,
        ?AgentRun $agentRun = null
    ): ModelResponse
    {
        // the run's resolved wire configuration (validated at the loop start); a collaborator's call
        // without a run resolves its own — a rejected combination fails the call, never a silent switch of API
        $agentWireConfiguration = $agentRun->agentWireConfiguration ?? $this->resolveAgentWireConfiguration($conversation, $modelNameOverride);
        if ($agentWireConfiguration->isRejected()) {
            throw new AIModelCallFailedException('Agent wire configuration rejected: ' . $agentWireConfiguration->rejectionReason);
        }
        $modelResponse = $this->sendModelCallWithWireConfiguration($conversation, $toolSchemas, $forceToolChoice, $modelNameOverride, $baseSystemPrompt, $modelCallSettings, $agentWireConfiguration, $agentRun);
        // the provider refused the carried reasoning items (`invalid_encrypted_content`, an
        // organisation mismatch) — the carry-over goes off for the rest of the conversation, and the same call is sent
        // once more without any reasoning item
        if ($agentWireConfiguration->carriesPreviousReasoning()
            && AgentWireConfiguration::providerErrorRejectsTheCarriedReasoningItems($modelResponse->providerError)
        ) {
            $agentWireConfigurationWithoutCarryOver = $agentWireConfiguration->withoutPreviousReasoningCarryOver();
            $this->switchPreviousReasoningCarryOverOffAfterRejection($conversation, $agentRun, $agentWireConfigurationWithoutCarryOver, (string)$modelResponse->providerError);
            $modelResponse = $this->sendModelCallWithWireConfiguration($conversation, $toolSchemas, $forceToolChoice, $modelNameOverride, $baseSystemPrompt, $modelCallSettings, $agentWireConfigurationWithoutCarryOver, $agentRun);
        }
        return $modelResponse;
    }

    /**
     * The carry-over of `$conversation` off after the provider refused its reasoning items: the run settings row
     * `previousReasoningCarryOver = off` (no admin account — the platform set it; {@see RunSettings::switchPreviousReasoningCarryOverOffAfterRejection()})
     * is persisted, so every later run resolves it, and the run's resolved configuration is replaced, so the later calls
     * of this run send none either. A warning names the conversation and the provider's error.
     */
    protected function switchPreviousReasoningCarryOverOffAfterRejection(
        AIConversation $conversation,
        ?AgentRun $agentRun,
        AgentWireConfiguration $agentWireConfigurationWithoutCarryOver,
        string $providerError
    ): void {
        $runSettings = isset($conversation->runSettings) ? $conversation->runSettings : RunSettings::newInstance();
        $conversation->runSettings = $runSettings->switchPreviousReasoningCarryOverOffAfterRejection(new DateTime());
        $this->persistRunSettingsOfConversation($conversation);
        if ($agentRun !== null) {
            $agentRun->agentWireConfiguration = $agentWireConfigurationWithoutCarryOver;
        }
        \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->warning(
            'AIConversationModelCallsService: the provider refused the carried reasoning items of conversation '
            . ($conversation->id ?? 'transient') . ' — carry-over switched off for the conversation, the call is sent again without them',
            ['providerError' => mb_substr($providerError, 0, 500)]
        );
    }

    /** Persists the run settings column of `$conversation` ({@see AIConversationsService::persistRunSettings()}). */
    protected function persistRunSettingsOfConversation(AIConversation $conversation): void
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $aIConversationsService->persistRunSettings($conversation);
    }

    /**
     * One model call of {@see performModelCall()} with the given wire configuration: the egress built and sent, the
     * request snapshot of what was sent, the answer read into a {@see ModelResponse}.
     *
     * @param array<int, array<string, mixed>> $toolSchemas
     * @param string|array<string, mixed>|null $forceToolChoice
     * @throws InternalErrorException the context range is stale (the wire would replay an earlier turn)
     * @throws AIModelCallTransportFailedException the HTTP transfer of the call failed
     */
    protected function sendModelCallWithWireConfiguration(
        AIConversation $conversation,
        array $toolSchemas,
        string|array|null $forceToolChoice,
        ?string $modelNameOverride,
        string $baseSystemPrompt,
        AgentModelCallSettings $modelCallSettings,
        AgentWireConfiguration $agentWireConfiguration,
        ?AgentRun $agentRun
    ): ModelResponse {
        $argusAIAgentConversation = new ArgusAIAgentConversation();
        $argusAIAgentConversation->fromEntity($conversation);
        /** @var AIConversation $conversation fromEntity()'s by-ref `DefaultObject` param widens the type for PHPStan; it does not replace the object. */
        $argusAIAgentConversation->applyAgentWireConfiguration($agentWireConfiguration);
        // ONE row read per model request — the zone of the conversation's space as of NOW (an address or country
        // written a step earlier is what this request sees). Schedule-bound runs keep their frozen zone (no-op).
        $conversation->refreshAgentSpaceTimezone();
        $argusAIAgentConversation->toolSchemas = $toolSchemas;
        $argusAIAgentConversation->forceToolChoice = $forceToolChoice;
        $argusAIAgentConversation->capturedToolCalls = null;
        // Per-turn model override (CLI --model / A-B testing): pass the model NAME; the egress conversation
        // resolves + applies it (winning over the #[ArgusLanguageModel] default). Null → attribute default.
        $argusAIAgentConversation->aiModelNameOverride = $modelNameOverride;
        $argusAIAgentConversation->reasoningEffortOverride = $modelCallSettings->reasoningEffortOverride;
        $argusAIAgentConversation->fastModeRequested = $modelCallSettings->fastModeRequested;
        // Build the per-call context on the loop side and hand it to the egress: the loop passes the
        // ONCE-PER-RUN resolved base prompt, buildModelInputUpToAndIncludingMessage() walks THIS conversation's messages backward (anchor = the
        // newest message; null = fresh conversation → base prompt + empty history) and carries the anchor's loaded
        // skills + the delegation flag; the egress composes the system content and folds the wire messages.
        $loopStepOverheadMs = ['preCall' => $modelCallSettings->preCallMs, 'schemas' => $modelCallSettings->schemasMs];
        $stampStartedAt = hrtime(true);
        $argusAIAgentConversation->modelInput = $conversation->buildModelInputUpToAndIncludingMessage(
            $conversation->messages->last(),
            $baseSystemPrompt,
            null,
            $agentRun
        );
        // ONE clock per model call — captured here, rendered by the egress as the tail clock signal and
        // persisted on the request snapshot below so a reconstruction shows exactly this instant.
        $argusAIAgentConversation->modelInput->clockInstant = new DateTime();
        // WIRE-RANGE GUARD: the context range MUST end on the conversation's newest
        // message — a stale range makes the model replay an earlier turn (it answered user [115] with its reply to
        // [99]) and every server-side guard then fights a model that sees old state. A wrong wire is strictly worse
        // than a failed turn, so fail LOUDLY; the resume path retries with a fresh load.
        // getNextIndex()-1, NOT last(): last() reads ObjectSet's internal count, which a desynced set (the
        // direct-elements-assignment class) pins mid-window — comparing against it made this guard tautological.
        // The max-scan over the full elements array is immune to that desync.
        $newestConversationIndex = $conversation->messages->count() > 0
            ? $conversation->messages->getNextIndex() - 1
            : null;
        $contextTailMessage = $argusAIAgentConversation->modelInput->getNewestMessage();
        $contextTailIndex = $contextTailMessage !== null && isset($contextTailMessage->indexInConversation)
            ? $contextTailMessage->indexInConversation
            : null;
        if ($newestConversationIndex !== null && $contextTailIndex !== null && $contextTailIndex !== $newestConversationIndex) {
            throw new InternalErrorException(
                "Agent context range is STALE: context tail index {$contextTailIndex} != newest conversation message "
                . "index {$newestConversationIndex} (conversation {$conversation->id}) — refusing to send a rolled-back wire."
            );
        }
        // Image resolution: the wire is composed HERE, so this is the only place the resolved model
        // AND the images are both known. resolveToolCallImages reads the transient bytes captured this turn and,
        // vision-gated on the resolved model, populates the AgentModelInput so the egress fold embeds them in the
        // matching tool result (or emits the downgrade note on a blind model). getAIModel() resolves the
        // per-turn override before argusLoad; a null model (unresolved) is treated as blind.
        $this->resolveToolCallImages($conversation, $argusAIAgentConversation->modelInput, $argusAIAgentConversation->getAIModel());
        // an append-only call keeps the epoch's top-level tools and carries the rest in the history
        $agentAppendOnlyCall = null;
        if ($agentWireConfiguration->usesConversationAppend() && $argusAIAgentConversation->modelInput->prefixEpoch !== null) {
            // A situation change starts a new epoch at this call (recorded on its snapshot below)
            $conversation->startSituationEpochIfTheSituationChanged($argusAIAgentConversation->modelInput, $contextTailIndex, $agentRun);
            $agentAppendOnlyCall = $this->buildAppendOnlyCall($conversation, $argusAIAgentConversation->modelInput, $toolSchemas, true, $agentRun);
            $argusAIAgentConversation->toolSchemas = $agentAppendOnlyCall->topLevelToolSchemas;
            $argusAIAgentConversation->modelInput->appendedHistoryItems = $agentAppendOnlyCall->appendedHistoryItems;
        }
        // the state notes ride the history on both strategies — after the append-only history items at the same position
        $stateDeliveryOfCall = $argusAIAgentConversation->modelInput->stateDelivery;
        if ($stateDeliveryOfCall !== null) {
            $argusAIAgentConversation->modelInput->appendedHistoryItems = $stateDeliveryOfCall->addInsertionsTo($argusAIAgentConversation->modelInput->appendedHistoryItems);
        }
        $loopStepOverheadMs['contextBuild'] = (int)round((hrtime(true) - $stampStartedAt) / 1e6);
        $stampStartedAt = hrtime(true);
        $this->sendModelCallThroughEgress($argusAIAgentConversation, false);
        // TEST-ONLY server-state arm: OpenAI rejected the chain (an unknown or expired `previous_response_id`, any 4xx on
        // it) — resend ONCE as a fresh chain (the whole input, no `previous_response_id`); the snapshot records it
        if ($argusAIAgentConversation->isRejectedResponsesServerStateChain()) {
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->warning(
                'AIConversationModelCallsService: server-state chain rejected for conversation ' . ($conversation->id ?? 'transient')
                . ' — resending as a fresh chain'
            );
            $argusAIAgentConversation->prepareFreshResponsesServerStateChainAfterRejection();
            $this->sendModelCallThroughEgress($argusAIAgentConversation, true);
        }
        $loopStepOverheadMs['egress'] = (int)round((hrtime(true) - $stampStartedAt) / 1e6);
        $loopStepOverheadMs['wire'] = $argusAIAgentConversation->wireBuildMs ?? 0;
        $stampStartedAt = hrtime(true);
        // Deliberately NOT $argusAIAgentConversation->toEntity(): the loop owns message creation and reads the turn's outputs
        // directly below. toEntity() would write the egress repo's (duplicate, null-partition) messages back
        // into the shared conversation collection.

        // SLIM point-in-time metadata of this turn's model call (dynamic-context plan) — model + effort + sentAt only,
        // NOT the multi-KB payload. The full outgoing request is rebuilt ON DEMAND from the immutable rows + the
        // point-in-time compaction tree (reconstructSentPayloadForDisplay / buildContextComposition). `sentAt` is the
        // one non-reconstructable bit kept: the asOf key (a compaction anchor applies iff createdAt < sentAt).
        // `lastRequestPayload !== null` = a real wire call happened, so a snapshot is owed.
        $requestPayload = $argusAIAgentConversation->lastRequestPayload;
        $requestSnapshot = null;
        if ($requestPayload !== null) {
            $requestSnapshot = new RequestSnapshot();
            $requestSnapshot->modelName = $argusAIAgentConversation->getAIModel()?->name;
            // the effort actually SENT (the decided effort when one applies, else the catalog value)
            $argusLanguageModelInstance = $argusAIAgentConversation->getArgusLanguageModelInstance();
            $requestSnapshot->reasoningEffort = ($argusLanguageModelInstance !== null ? $argusLanguageModelInstance->reasoningEffort : null)
                ?? $argusAIAgentConversation->getAIModel()?->agenticUseCase?->reasoningEffort;
            $requestSnapshot->sentAt = new DateTime();
            $requestSnapshot->clockAt = $argusAIAgentConversation->modelInput->clockInstant;
            // the zone this call was rendered in — a reconstruction renders THIS, not the current one.
            $requestSnapshot->timezoneIdentifier = $argusAIAgentConversation->modelInput->timezone?->getName();
            $requestSnapshot->timezoneUnverified = $argusAIAgentConversation->modelInput->timezone !== null
                ? $argusAIAgentConversation->modelInput->timezoneUnverified
                : null;
            // the tool choice the egress sent — the admin composition rebuilds the
            // answer-step tail line from it (snapshot JSON only; the wire is unchanged).
            $requestSnapshot->recordForceToolChoice($argusAIAgentConversation->forceToolChoice);
            // DEBUG (per-conversation opt-in): store the FULL outgoing request (system + messages + tools + tool_choice)
            // and the raw model response, the data the slim snapshot drops for O(n²). Stamped on whatever message this
            // turn produces (assistant turn OR the loop's empty-turn nudge), so a stall is inspectable too. Captures the
            // forced re-drive's request as well — its tool_choice='required' is exactly what we need to verify.
            if ($conversation->storesFullRequestSnapshots()) {
                // the debug snapshot must REDACT image bytes before persisting — otherwise the one
                // setting a browser-debug session turns on would write base64 into the DB, the exact byte-persistence
                // this plan forbids, through the back door. Replace every image_url data-URI with its short marker.
                // Credentials (the egress auth header / body key) are shortened to a stub before anything is stored.
                $requestSnapshot->sentPayloadJson = json_encode(
                    $this->redactImagePayloadForSnapshot(RequestSnapshot::shortenSecretsInPayload($requestPayload)),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) ?: null;
                $requestSnapshot->rawResponseJson = json_encode($argusAIAgentConversation->lastRawResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
            }
            // A hash per system segment and one of the tools: the cache diff names what changed on every call.
            $requestSnapshot->recordSystemSegmentAndToolsHashes(
                $argusAIAgentConversation->lastSystemSegments,
                is_array($requestPayload['body']['tools'] ?? null) ? $requestPayload['body']['tools'] : null
            );
            // the wire API and the skill-loading strategy of this call, and the hash of its appended tools
            $requestSnapshot->wireApi = $agentWireConfiguration->wireApi;
            $requestSnapshot->skillLoadingStrategy = $agentWireConfiguration->skillLoadingStrategy;
            $requestSnapshot->recordAppendedToolsHash(is_array($requestPayload['body']['input'] ?? null) ? $requestPayload['body']['input'] : null);
            // TEST-ONLY server-state arm: the stored response this call produced and the chain link it made
            if ($agentWireConfiguration->usesResponsesServerState() && $argusAIAgentConversation->lastResponsesServerStateChain !== null) {
                $requestSnapshot->recordResponsesServerStateChain(
                    $argusAIAgentConversation->lastResponsesServerStateChain,
                    $argusAIAgentConversation->capturedResponsesResponseId,
                    $this->readToolCallIdsOfCapturedCalls($argusAIAgentConversation->capturedToolCalls),
                    is_array($requestPayload['body']['input'] ?? null) ? count($requestPayload['body']['input']) : 0,
                    $argusAIAgentConversation->responsesServerStateFallbackError
                );
            }
            // the reasoning items the provider returned ride the step's marker row (this snapshot)
            if ($argusAIAgentConversation->capturedReasoningItems !== null) {
                $requestSnapshot->wireReasoningApi = $argusAIAgentConversation->capturedReasoningItemsWireApi;
                $requestSnapshot->wireReasoningItemsJson = json_encode($argusAIAgentConversation->capturedReasoningItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
                $requestSnapshot->wireReasoningTokens = $argusAIAgentConversation->capturedReasoningTokens;
            }
            // carry-over `capped`: the cut this call sent — the next call starts from it
            if ($argusAIAgentConversation->lastPreviousReasoningCut !== null) {
                $requestSnapshot->previousReasoningCutFromMessageIndex = $argusAIAgentConversation->lastPreviousReasoningCut->cutFromMessageIndex;
                $requestSnapshot->previousReasoningSentTokens = $argusAIAgentConversation->lastPreviousReasoningCut->sentReasoningTokens;
            }
            $prefixEpochOfCall = $argusAIAgentConversation->modelInput->prefixEpoch;
            if ($prefixEpochOfCall !== null) {
                $requestSnapshot->epochCoverFingerprint = $prefixEpochOfCall->coverFingerprint;
                $requestSnapshot->epochStartIndex = $prefixEpochOfCall->startIndex;
                $requestSnapshot->epochSituationChangeStartIndex = $prefixEpochOfCall->situationChangeStartIndex;
                // the epoch's top-level tool decision, so the A/B can tell the two mechanisms apart
                $appendOnlyToolSetOfCall = $prefixEpochOfCall->appendOnlyToolSet;
                if ($appendOnlyToolSetOfCall !== null) {
                    $requestSnapshot->epochTopLevelToolSet = $appendOnlyToolSetOfCall->topLevelToolSet;
                    $requestSnapshot->epochReachableToolSetTokens = $appendOnlyToolSetOfCall->reachableToolSetEstimatedTokens;
                    $requestSnapshot->epochReachableToolSetCeilingTokens = $appendOnlyToolSetOfCall->ceilingTokens;
                }
            }
            if ($agentAppendOnlyCall !== null && $agentAppendOnlyCall->appendedHistoryItems->insertionOfThisCall !== null) {
                $requestSnapshot->appendedSkillNames = $agentAppendOnlyCall->appendedSkillNamesOfThisCall !== [] ? $agentAppendOnlyCall->appendedSkillNamesOfThisCall : null;
                $requestSnapshot->appendedToolNames = $agentAppendOnlyCall->appendedToolNamesOfThisCall !== [] ? $agentAppendOnlyCall->appendedToolNamesOfThisCall : null;
                $requestSnapshot->appendedSituationChange = $agentAppendOnlyCall->appendedSituationChangeOfThisCall ? true : null;
                $requestSnapshot->appendedSituationKind = $agentAppendOnlyCall->appendedSituationKindOfThisCall;
                $requestSnapshot->appendedSituationNames = $agentAppendOnlyCall->appendedSituationNamesOfThisCall !== [] ? $agentAppendOnlyCall->appendedSituationNamesOfThisCall : null;
                // the item followed the newest history row of this call: later calls render it right after that row
                $requestSnapshot->appendedAfterMessageIndex = $contextTailIndex;
            }
            // the state epoch of this call, its record (the epoch's first call) and the notes it delivered —
            // anchored like the append-only history items: later calls render them right after the newest row this call sent
            $stateDeliveryOfCall?->recordOnRequestSnapshot($requestSnapshot, $contextTailIndex);
            // Skill variants: the situation this call was rendered with and the sections it saw.
            $requestSnapshot->variants = $argusAIAgentConversation->modelInput->variants?->toSnapshotArray();
            $requestSnapshot->skillSections = $argusAIAgentConversation->modelInput->renderedSkillSectionIds !== []
                ? $argusAIAgentConversation->modelInput->renderedSkillSectionIds
                : null;
            $loopStepOverheadMs['snapshot'] = (int)round((hrtime(true) - $stampStartedAt) / 1e6);
            $requestSnapshot->loopStepOverheadMs = $loopStepOverheadMs;
        }

        // Decode the captured wire tool-calls into the turn's ToolCalls Set (one ToolCall VO per call). The real
        // performTurn runs only in the booted container; the unit tests stub performTurn entirely and return their
        // own ModelResponse, so this `new` never touches a container-less test.
        $toolCalls = null;
        $rawToolCalls = $argusAIAgentConversation->capturedToolCalls;
        if (is_array($rawToolCalls) && $rawToolCalls !== []) {
            $toolCalls = new ToolCalls();
            foreach ($rawToolCalls as $rawCall) {
                $toolCall = ToolCall::fromResponse($rawCall);
                // the call's own record of its tool's effect, before any guard reads it
                $toolCall->stampEffect(MCPToolEffectCatalog::getEffectForToolName((string)($toolCall->name ?? '')));
                $toolCalls->add($toolCall);
            }
        }

        // Provider/proxy ERROR body (e.g. a Google 400 INVALID_ARGUMENT on a malformed tool schema): the raw response is
        // an `{error: …}` object instead of a completion. Surface it so the loop fails FAST instead of mis-reading the
        // resulting empty response as a stall and nudging it. Null on a normal response.
        $lastRawResponse = $argusAIAgentConversation->lastRawResponse;
        $providerError = (is_object($lastRawResponse) && isset($lastRawResponse->error))
            ? (string)json_encode($lastRawResponse->error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
        // A Responses answer the provider refused or cut off (`status: incomplete`) carries neither text nor a call: a
        // named outcome on the existing provider-error path (the ERROR row, the report, the failed run), logged — never
        // an empty answer the loop would nudge as a stall (Chat Completions is unchanged)
        if ($providerError === null && $argusAIAgentConversation->unusableResponsesAnswerReason !== null) {
            $providerError = (string)json_encode(
                ['outcome' => 'responsesAnswerUnusable', 'reason' => $argusAIAgentConversation->unusableResponsesAnswerReason],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->warning(
                'AIConversationModelCallsService: unusable Responses answer for conversation ' . ($conversation->id ?? 'transient')
                . ' — ' . $argusAIAgentConversation->unusableResponsesAnswerReason
            );
        }

        return new ModelResponse(
            text: $argusAIAgentConversation->getAiGeneratedResponse(),
            toolCalls: $toolCalls,
            modelName: $argusAIAgentConversation->getAIModel()?->name,
            inputTokens: $argusAIAgentConversation->getLastInputTokens(),
            outputTokens: $argusAIAgentConversation->getLastOutputTokens(),
            webSearchCount: $argusAIAgentConversation->getLastWebSearchCount() ?: null,
            costInUSD: $argusAIAgentConversation->getLastCostInUSD(),
            cachedInputTokens: $argusAIAgentConversation->getLastCachedInputTokens(),
            cacheWriteTokens: $argusAIAgentConversation->getLastCacheWriteTokens(),
            cacheDiscount: $argusAIAgentConversation->getLastCacheDiscount(),
            requestSnapshot: $requestSnapshot,
            providerError: $providerError,
            // the part of THIS prompt compaction cannot shrink — read by the compaction budget
            // under append-only loading the fixed part is the epoch's top-level tools (the appended ones are history)
            fixedOverheadTokens: $argusAIAgentConversation->modelInput->estimateFixedOverheadTokens($argusAIAgentConversation->toolSchemas),
        );
    }

    /**
     * Resolve the pictures of the tool results on this model call's wire onto the per-call {@see AgentModelInput}
     * — the ONE image IO/selection step,
     * run where the wire is composed (the only point both the resolved model and the images are known).
     *
     * Candidates are the COMPLETED tool calls in the context window whose result carried pictures (their persisted
     * {@see ToolCall::$imageReferences}) and whose result rides FULL (a stubbed result carries no picture). The bytes
     * come from the transient carry this invocation captured ({@see AIConversation::$transientToolCallImages}) or —
     * for a DURABLE picture — are re-resolved through the loaded {@see \DDD\Domain\AI\Entities\MCPPackages\ToolCallImageStore}
     * package on every call, so a catalog sheet survives the 12-step worker chunk, the turn boundary and a resumed
     * worker. An inline image (a browser viewport) outside the carry is gone.
     *
     * The wire cap counts PICTURES (`maxToolCallImagesOnWire`): newest results first, whole, until the cap is reached;
     * the newest result always rides (truncated to the cap). Every candidate that does NOT ride gets the dropped marker
     * in its result — a caption that keeps saying "look" while nothing is attached invites a hallucination. On a blind
     * model no bytes ride and every candidate carries the downgrade note.
     */
    protected function resolveToolCallImages(AIConversation $conversation, AgentModelInput $agentContext, ?AIModel $resolvedModel): void
    {
        $transientToolCallImages = $conversation->getTransientToolCallImages();
        $keepToolResultsFromIndex = $agentContext->keepToolResultsFromIndex;
        // Pass 1 — the picture-bearing results on this wire, newest first, with their PICTURE COUNT (references, or the
        // carry) but without touching any bytes yet: re-resolving a durable picture costs a registry read + a
        // composition, so only the results that will actually ride are resolved (pass 2).
        /** @var ToolCall[] $candidateCallsNewestFirst keyed by tool call id */
        $candidateCallsNewestFirst = [];
        /** @var array<string, int> $candidatePictureCounts */
        $candidatePictureCounts = [];
        for ($position = count($agentContext->messages) - 1; $position >= 0; $position--) {
            $message = $agentContext->messages[$position];
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            $toolCallId = $toolCall !== null ? (string)($toolCall->toolCallId ?? '') : '';
            if ($toolCall === null || $toolCallId === '' || $toolCall->status !== AIConversationMessage::STATUS_COMPLETED) {
                continue;
            }
            $carriedThisInvocation = $transientToolCallImages[$toolCallId] ?? [];
            if ($toolCall->imageReferences === [] && $carriedThisInvocation === []) {
                continue;
            }
            if (($message->indexInConversation ?? PHP_INT_MAX) < $keepToolResultsFromIndex
                || isset($agentContext->toolResultIndexesStubbedByBudget[$message->indexInConversation ?? PHP_INT_MAX])
            ) {
                continue; // the result itself is a stub on this wire — no picture rides, no marker owed
            }
            $candidateCallsNewestFirst[$toolCallId] = $toolCall;
            $candidatePictureCounts[$toolCallId] = $carriedThisInvocation !== [] ? count($carriedThisInvocation) : count($toolCall->imageReferences);
        }
        if ($candidateCallsNewestFirst === []) {
            return;
        }
        if ($resolvedModel?->hasVisionCapabilities !== true) {
            // Blind (or unresolved) model: the bytes never ride; every picture-bearing result states so.
            $agentContext->blindCapturedToolCallIds = array_keys($candidateCallsNewestFirst);
            return;
        }
        $maxImagesOnWire = isset($conversation->contextRetentionSettings)
            ? $conversation->contextRetentionSettings->getMaxToolCallImagesOnWire()
            : ContextRetentionSettings::DEFAULT_MAX_TOOL_CALL_IMAGES_ON_WIRE;
        $toolCallImageWireSelection = AgentModelInput::selectToolCallImagesForWire(
            array_map(static fn (int $count): array => array_fill(0, $count, true), $candidatePictureCounts),
            $maxImagesOnWire
        );
        $selectedCounts = $toolCallImageWireSelection->imagesOnWireByToolCallId;
        $dropped = $toolCallImageWireSelection->droppedToolCallIds;
        // Pass 2 — bytes for the selected results only: the carry, or the durable store through the loaded packages. A
        // selected result whose pictures cannot be produced any more joins the dropped set (its marker says so).
        $toolResultImagesOnWire = new AgentToolResultImagesOfToolCalls();
        $loadedPackages = null;
        $toolExecutor = null;
        foreach ($selectedCounts as $toolCallId => $selectedSlots) {
            $carriedThisInvocation = $transientToolCallImages[$toolCallId] ?? [];
            if ($carriedThisInvocation !== []) {
                $toolResultImagesOnWire->addImagesOfToolCall((string)$toolCallId, array_slice(array_values($carriedThisInvocation), 0, count($selectedSlots)));
                continue;
            }
            $reResolved = [];
            foreach ($candidateCallsNewestFirst[$toolCallId]->imageReferences as $toolCallImageReference) {
                if (count($reResolved) >= count($selectedSlots)) {
                    break;
                }
                if (!$toolCallImageReference->isReResolvable()) {
                    continue;
                }
                $loadedPackages ??= $conversation->findLoadedPackages();
                /** @var MCPToolExecutorService $toolExecutor */
                $toolExecutor ??= DDDService::instance()->getService(MCPToolExecutorService::class);
                $toolResultImage = $toolExecutor->resolveToolCallImage($toolCallImageReference, $loadedPackages);
                if ($toolResultImage !== null) {
                    $reResolved[] = $toolResultImage;
                }
            }
            if ($reResolved !== []) {
                $toolResultImagesOnWire->addImagesOfToolCall((string)$toolCallId, $reResolved);
            } else {
                $dropped[] = (string)$toolCallId;
            }
        }
        $agentContext->resolvedToolImages = $toolResultImagesOnWire->isEmpty() ? null : $toolResultImagesOnWire;
        $agentContext->droppedImageToolCallIds = array_values(array_unique($dropped));
    }

    /**
     * One `argusLoad()` of the model call; a failed HTTP transfer becomes {@see AIModelCallTransportFailedException}.
     * `$bypassesCaches` for a resend of the same call (the server-state fallback): no answer of the first attempt is reused.
     *
     * @throws AIModelCallTransportFailedException
     */
    protected function sendModelCallThroughEgress(ArgusAIAgentConversation $argusAIAgentConversation, bool $bypassesCaches): void
    {
        try {
            $argusAIAgentConversation->argusLoad(useArgusEntityCache: !$bypassesCaches, useApiACallCache: !$bypassesCaches);
        } catch (TransferException $transportFailure) {
            // the transfer failed (a connect or read timeout, a dropped connection, a Guzzle response exception): transient,
            // released for the owed-turn recovery pass — never the provider-error fail-fast and never the generic turn error
            throw new AIModelCallTransportFailedException($transportFailure);
        }
    }

    /**
     * The call ids of the captured wire calls (Chat shape `{id, …}`), in order.
     *
     * @param array<int, mixed>|null $capturedToolCalls
     * @return string[]
     */
    protected function readToolCallIdsOfCapturedCalls(?array $capturedToolCalls): array
    {
        $toolCallIds = [];
        foreach ($capturedToolCalls ?? [] as $capturedToolCall) {
            $toolCallId = is_array($capturedToolCall) ? ($capturedToolCall['id'] ?? null) : (is_object($capturedToolCall) ? ($capturedToolCall->id ?? null) : null);
            if (is_string($toolCallId)) {
                $toolCallIds[] = $toolCallId;
            }
        }
        return $toolCallIds;
    }

    /** {@see RequestSnapshot::redactImagePayload()} */
    protected function redactImagePayloadForSnapshot(mixed $requestPayload): mixed
    {
        return RequestSnapshot::redactImagePayload($requestPayload);
    }

    /**
     * Rebuild the FULL outgoing payload of one assistant turn for the forensic "sent payload" view — fully DYNAMIC:
     * there is NO stored wire payload anymore (the dynamic-context plan dropped {@see RequestSnapshot} to a slim
     * model-name/reasoning-effort/sentAt stamp). This recomposes, asOf the snapshot's sentAt: the system content
     * ({@see AgentModelInput::composeSystemContent()}), the point-in-time message rows
     * ({@see AIConversationMessage::buildModelInputThatProducedThisMessage()}) folded into `body.messages[1..]` via
     * the same egress folding the live turn used, and the tool schemas reconstructed from THIS message's loadedSkills.
     * Content-faithful for the immutable rows; the system prompt / customer data / memories / skills MAY have drifted
     * since the turn (no frozen truth to diff against) — the FE labels this "reconstructed (may differ from sent)".
     * Returns [] when there is nothing to rebuild (no snapshot). On-demand only — never call it per-row while listing
     * (that would re-introduce the O(N²) the slim removed); reconstruct one message at a time.
     *
     * @return array<string, mixed>
     */
    public function reconstructSentPayloadForDisplay(AIConversation $conversation, AIConversationMessage $message, string $baseSystemPrompt = ''): array
    {
        if (!isset($message->requestSnapshot)) {
            return [];
        }
        // Fully DYNAMIC rebuild — there is NO stored payload anymore. Rebuild the context that PRODUCED this assistant
        // turn (asOf = the slim snapshot's sentAt) into the SAME wire shape the live turn sent: the composed system
        // content, the point-in-time message rows, and the tool schemas reconstructed from THIS message's loadedSkills.
        // Best-effort: content-faithful for the immutable rows + the compaction cover; the system prompt / tools /
        // memory may have drifted since the turn (the dynamic-context trade-off). `reconstructed: true` flags it for the FE.
        $producingContext = $message->buildModelInputThatProducedThisMessage($conversation, $baseSystemPrompt);
        // the reconstruction must NOT re-resolve image bytes (that would show today's viewport as if it
        // had been sent). The producing context has no resolved bytes; render each persisted ToolCall image reference
        // as a visible placeholder at its wire position instead.
        $producingContext->renderImageReferencePlaceholders = true;
        $argusAIAgentConversation = new ArgusAIAgentConversation();
        // an append-only call's history carries the items the epoch's calls appended
        $reconstructedToolSchemas = $this->buildToolSchemasForMessage($conversation, $message);
        if ($producingContext->prefixEpoch !== null) {
            $reconstructedAppendOnlyCall = $this->buildAppendOnlyCall($conversation, $producingContext, $reconstructedToolSchemas, false);
            $producingContext->appendedHistoryItems = $reconstructedAppendOnlyCall->appendedHistoryItems;
            $reconstructedToolSchemas = $reconstructedAppendOnlyCall->topLevelToolSchemas;
        }
        // the state notes of the epoch's calls and of this call, from their snapshots
        if ($producingContext->stateDelivery !== null) {
            $producingContext->appendedHistoryItems = $producingContext->stateDelivery->addInsertionsTo($producingContext->appendedHistoryItems);
        }
        // buildWireMessagesFromModelInput — the SAME composition the live turn sends, incl. the volatile tail
        // entries (recent-tool-calls hint, reply-language tail cue): the forensic view must show exactly what the
        // model received (the previous inline merge silently dropped the tail hints).
        $requestBodyMessages = array_merge(
            [['role' => 'system', 'content' => $producingContext->composeSystemContent()]],
            $argusAIAgentConversation->buildWireMessagesFromModelInput($producingContext)
        );
        $reconstructedBody = [
            'model' => $message->requestSnapshot->modelName,
            'reasoning_effort' => $message->requestSnapshot->reasoningEffort,
            'messages' => $requestBodyMessages,
            'tools' => $reconstructedToolSchemas,
        ];
        // a Responses call is shown in its own shape too — the `input` items and the flat tools (the Chat
        // `messages` stay for the views that read them)
        if (RunSettings::isResponsesAgentWireApi($message->requestSnapshot->wireApi ?? null)) {
            $reconstructedBody['wireApi'] = $message->requestSnapshot->wireApi;
            $reconstructedBody['input'] = AgentResponsesInputItems::fromChatCompletionsMessages($requestBodyMessages);
            $reconstructedBody['responsesTools'] = AgentResponsesRequestComposer::convertToolSchemas($reconstructedBody['tools']);
        }
        return [
            'reconstructed' => true,
            'body' => $reconstructedBody,
        ];
    }

    /**
     * The wire tool schemas AS OF $message (point-in-time) — rebuilt from that message's own cumulative loadedSkills,
     * the on-demand replacement for the dropped `requestSnapshot.payload.body.tools`. Public so the per-message
     * composition view ({@see AIConversationMessage::buildContextComposition()}) can be handed the rebuilt schemas
     * (the entity stays container-free; the catalog/tool-VO resolution lives here in the service). `allowThreads` =
     * the conversation is an orchestrator (not itself a delegation child). The offer of one model call is
     * {@see AIConversation::buildOfferedTools()}, read here at that message.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildToolSchemasForMessage(AIConversation $conversation, AIConversationMessage $message): array
    {
        return $conversation->buildOfferedTools(!$conversation->isDelegation(), $message, null)->toOpenAiToolSchemas();
    }

    /**
     * The AI models a CUSTOMER may pick in the agent chat's model picker — the agent-eligible catalog narrowed to the
     * models curated via the `agentCustomerSelection` block of the `AI.models` config, projected into customer
     * vocabulary (strength/speed 1–5 levels + credit-consumption multiplier; never internal tier names). Serves the
     * client selectable-models endpoint AND is the fail-closed whitelist {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::driveConversation()} validates a
     * customer-sent `aiModelName` against. Model availability is deliberately NOT plan-gated — the per-plan
     * differentiation happens through the existing AI budget (a pricier model burns it faster); see the config block.
     *
     * @return AgentCustomerSelectableModels
     * @throws BadRequestException
     */
    public function getCustomerSelectableAgentModels(): AgentCustomerSelectableModels
    {
        /** @var AIModelsService $aIModelsService */
        $aIModelsService = DDDService::instance()->getService(AIModelsService::class);
        // the container's class (an application's same-path subclass) — its API documentation and wire type stay its own
        /** @var AgentCustomerSelectableModels $agentCustomerSelectableModelsClass */
        $agentCustomerSelectableModelsClass = DDDService::instance()->getContainerServiceClassNameForClass(AgentCustomerSelectableModels::class);
        return $agentCustomerSelectableModelsClass::fromAgentEligibleModels(
            $aIModelsService->getAgentEligibleModels(),
            Config::get('AI.models') ?? []
        );
    }
}
