<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentPersonaNames;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolAttributeCatalog;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolNewestResultIsNeverStubbed;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPreviousReasoningCut;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentResponsesServerStateChain;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentChatCompletionsRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesResponse;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesServerStateRequestComposer;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentWireRequestParameters;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Services\AIPromptsService;
use Throwable;
use DDD\Infrastructure\Libs\Config;
use DDD\Domain\AI\Entities\Environment\AgentEnvironmentVariables;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;

/**
 * Agent conversation egress — TRUE direct `openrouter.ai` with native function tool-calling.
 *
 * Same Argus stack as {@see ArgusAIConversation} (cost envelope, budget, prompt machinery) but pointed DIRECTLY at
 * `openrouter.ai/api/v1/chat/completions`, which speaks the modern OpenAI tools API natively — `tools`/`tool_choice`,
 * assistant `tool_calls`, and `role:"tool"` results. Auth is the `Authorization: Bearer` header (from
 * `OPENROUTER_KEY`, attached by the trait's egress-auth seam); the model id is the BARE OpenRouter slug. Egress
 * history: originally the arg-ai openrouter passthrough (whose request validator only accepted the legacy `function`
 * role → no modern tools), then the LiteLLM proxy (tools worked, but it added jitter + the recurring 401
 * 'Missing Authentication header' key-config failures), now direct (fastest + fewest moving parts in a three-way
 * benchmark). Drives the synchronous
 * loop in {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService}: the loop sets {@see $toolSchemas} before each
 * turn and reads {@see $capturedToolCalls} after.
 *
 * Tool-calling rides the additive trait hooks (getChatCompletionTools / getChatCompletionToolChoice /
 * handleToolCalls) — see ArgusAILanguageModelTrait.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: 0
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_ALIBABA_QWEN3_235B_INSTRUCT,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_AGENT_AGENTIC,
    temperature: 0.6,
    systemPromptName: AgentAIPromptNames::APP_AI_SYSTEM_PROMPT_PUBLIC_FACING,
    aiUsageScope: AgentAIUsageScope::AI_CONVERSATION_SEND_MESSAGE,
    modelScope: ModelScope::AGENTIC,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
)]
class ArgusAIAgentConversation extends ArgusAIConversation
{
    /**
     * The config key of the LiteLLM-proxy egress — the A/B alternative to the direct default (see __construct): the
     * Argus load endpoint (`POST:https://…/chat/completions`) in `config/app/AI/agentEgress.php`; null = no proxy.
     */
    public const string CONFIG_KEY_LITE_LLM_ENDPOINT = 'AI.agentEgress.liteLlmEndpoint';

    /** The value of the egress environment variable ({@see AgentEnvironmentVariables::EGRESS}) that selects the proxy. */
    public const string EGRESS_ENVIRONMENT_VALUE_LITE_LLM = 'litellm';

    /** The config key of the agent's persona names (`config/app/AI/agentPersona.php`: `agentName`, `productName`). */
    public const string CONFIG_KEY_AGENT_PERSONA = 'AI.agentPersona';

    /** The persona names when no config file names them (the package ships `config/app/AI/agentPersona.php`). */
    public const string DEFAULT_AGENT_NAME = 'Assistant';
    public const string DEFAULT_PRODUCT_NAME = 'this product';

    /** The direct OpenRouter Responses API — the endpoint of a call whose wire API is `responses`. */
    public const string EGRESS_OPENROUTER_RESPONSES = 'POST:https://openrouter.ai/api/v1/responses';

    /**
     * TEST-ONLY: the Responses API directly at OpenAI — the endpoint of the server-state arm
     * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE}); OpenRouter rejects `store: true` / `previous_response_id`.
     */
    public const string EGRESS_OPENAI_RESPONSES = 'POST:https://api.openai.com/v1/responses';

    /** The env keys of the direct OpenAI egress, in order: the general key, else the realtime key (the voice adapter's). */
    public const array OPENAI_DIRECT_API_KEY_ENV_NAMES = ['API_OPENAI_KEY', 'API_OPENAI_REALTIME_API_KEY'];

    /**
     * The cURL options of this call's low-speed abort (`config/app/AI/agentEgress.php`, `transferStallAbort`): the
     * transfer ends as a transport failure when fewer than `bytesPerSecond` bytes per second arrive over `seconds`
     * seconds, instead of waiting out the whole timeout. [] when switched off.
     * Read by the Argus transport for every call of this repo ({@see \DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations}).
     *
     * @return int[]
     */
    public function buildTransferStallCurlOptions(): array
    {
        $transferStallAbort = Config::get('AI.agentEgress.transferStallAbort');
        return self::buildTransferStallCurlOptionsFor(
            (int)(is_array($transferStallAbort) ? ($transferStallAbort['bytesPerSecond'] ?? 0) : 0),
            (int)(is_array($transferStallAbort) ? ($transferStallAbort['seconds'] ?? 0) : 0)
        );
    }

    /**
     * `CURLOPT_LOW_SPEED_LIMIT` => `$bytesPerSecond`, `CURLOPT_LOW_SPEED_TIME` => `$seconds`; [] when either is not
     * positive (the abort is off). Pure.
     *
     * @return int[]
     */
    public static function buildTransferStallCurlOptionsFor(int $bytesPerSecond, int $seconds): array
    {
        if ($bytesPerSecond <= 0 || $seconds <= 0) {
            return [];
        }
        return [\CURLOPT_LOW_SPEED_LIMIT => $bytesPerSecond, \CURLOPT_LOW_SPEED_TIME => $seconds];
    }

    /**
     * @var AgentResponsesServerStateChain|null The chain decision of the LAST server-state request (what it sent, which
     *      response it continued or why it restarted); null on every other arm. The loop records it on the snapshot.
     */
    public ?AgentResponsesServerStateChain $lastResponsesServerStateChain = null;

    /**
     * The base prompt is built as the TEMPLATE of a `onceWhenChanged` conversation — `{%AGENT_SPACE_CONTEXT%}`
     * and `{%OWNER_ADDRESSING%}` stay placeholders (the epoch record fills them per call,
     * {@see AgentModelInput::fillStateDeliveryPlaceholdersOf()}), so the cached prompt holds no data of the
     * conversation's space.
     */
    public bool $buildsStateDeliveryTemplate = false;

    /** @var string|null The response id OpenAI returned for the LAST server-state call (`store: true`); null otherwise. */
    public ?string $capturedResponsesResponseId = null;

    /** @var string|null A fresh chain the next server-state request must start (the fallback after a rejected chain). */
    public ?string $forcedResponsesServerStateRestartReason = null;

    /** @var string|null The error OpenAI answered on the chained attempt the fallback replaced. */
    public ?string $responsesServerStateFallbackError = null;

    /**
     * @var AgentWireConfiguration|null How THIS call talks to the model: the wire API, the skill-loading
     *      strategy, the reasoning carry-over — set by the loop through {@see applyAgentWireConfiguration()}; null = Chat
     *      Completions with the system-prompt swap (today's behaviour).
     */
    public ?AgentWireConfiguration $agentWireConfiguration = null;

    /**
     * Sets how this call talks to the model. A `responses` configuration points the per-instance load endpoint at the
     * Responses API (the same per-instance mutation as the LiteLLM flip in {@see __construct()}); it must be applied
     * before {@see argusLoad()}, which reads the endpoint before the payload.
     */
    public function applyAgentWireConfiguration(AgentWireConfiguration $agentWireConfiguration): void
    {
        $this->agentWireConfiguration = $agentWireConfiguration;
        $argusLoad = $this->getArgusSettings()->argusLoad;
        if ($agentWireConfiguration->usesResponsesServerState()) {
            $argusLoad->loadEndpoint = self::EGRESS_OPENAI_RESPONSES;
            return;
        }
        if ($agentWireConfiguration->usesResponsesApi()) {
            $argusLoad->loadEndpoint = self::EGRESS_OPENROUTER_RESPONSES;
            return;
        }
        // a Chat call on an instance a Responses call used before goes back to the declared endpoint
        if ($argusLoad->loadEndpoint === self::EGRESS_OPENROUTER_RESPONSES || $argusLoad->loadEndpoint === self::EGRESS_OPENAI_RESPONSES) {
            /** @var ArgusLoad $declaredArgusLoad */
            $declaredArgusLoad = self::getAttributeInstance(ArgusLoad::class);
            $argusLoad->loadEndpoint = self::findRequestedLiteLlmEgressEndpoint() ?? $declaredArgusLoad->loadEndpoint;
        }
    }

    /** Whether this egress talks to openrouter.ai directly (not the LiteLLM proxy) — the Responses API needs it. */
    public function targetsDirectOpenRouterEgress(): bool
    {
        return $this->loadEndpointTargetsOpenRouterDirect();
    }

    /**
     * TEST-ONLY egress flip: `AGENT_EGRESS=litellm` (env, per CLI process; the further name the application declares is read after it) routes
     * THIS process's agent turns through the configured LiteLLM proxy ({@see CONFIG_KEY_LITE_LLM_ENDPOINT}) instead of
     * the direct openrouter.ai default — the A/B seam for benchmarking the loop over both egresses with an identical
     * harness (`AGENT_EGRESS=litellm php bin/console <an agent command of the application>`).
     * The shared trait switches auth + model-id namespace off the endpoint automatically. Mutating the per-instance
     * ArgusLoad attribute is the established pattern (ArgusMediaManagerImages::useGalleryEndpoint()). The
     * parent::__construct() is LOAD-BEARING: the base constructor is composed in via DefaultObjectTrait (a per-file
     * grep misses it), and shadowing it leaves typed entity properties uninitialized ("$account must not be
     * accessed before initialization", found live).
     */
    public function __construct()
    {
        parent::__construct();
        $liteLlmEgressEndpoint = self::findRequestedLiteLlmEgressEndpoint();
        if ($liteLlmEgressEndpoint !== null) {
            $this->getArgusSettings()->argusLoad->loadEndpoint = $liteLlmEgressEndpoint;
        }
    }

    /**
     * The LiteLLM-proxy endpoint when this process asks for it (`AGENT_EGRESS=litellm`, then the further name the application declares) AND the
     * config names one ({@see CONFIG_KEY_LITE_LLM_ENDPOINT}); null otherwise. Asked without a configured endpoint, the
     * flip is ignored with a log line — the call keeps the direct endpoint.
     */
    public static function findRequestedLiteLlmEgressEndpoint(): ?string
    {
        if (!self::isLiteLlmEgressRequested()) {
            return null;
        }
        $liteLlmEndpoint = Config::get(self::CONFIG_KEY_LITE_LLM_ENDPOINT);
        if (!is_string($liteLlmEndpoint) || $liteLlmEndpoint === '') {
            DDDService::instance()->getLogger()->warning(sprintf(
                'The agent egress %s=%s is set, but %s names no endpoint; the direct endpoint is kept.',
                AgentEnvironmentVariables::EGRESS,
                self::EGRESS_ENVIRONMENT_VALUE_LITE_LLM,
                self::CONFIG_KEY_LITE_LLM_ENDPOINT
            ));
            return null;
        }
        return $liteLlmEndpoint;
    }

    /** Whether this process asks for the LiteLLM-proxy egress: the first set name of {@see AgentEnvironmentVariables::EGRESS} is `litellm`. */
    public static function isLiteLlmEgressRequested(): bool
    {
        foreach (AgentEnvironmentVariables::getNamesInReadOrder(AgentEnvironmentVariables::EGRESS) as $environmentVariableName) {
            $egressValue = Config::getEnv($environmentVariableName);
            if ($egressValue !== null && $egressValue !== '') {
                return $egressValue === self::EGRESS_ENVIRONMENT_VALUE_LITE_LLM;
            }
        }
        return false;
    }

    /**
     * The persona names of the agent (`config/app/AI/agentPersona.php`): `agentName` and `productName`, each falling back
     * to the built-in default when the config names none.
     *
     * @return AgentPersonaNames
     */
    public static function findAgentPersonaNames(): AgentPersonaNames
    {
        $configuredPersona = Config::get(self::CONFIG_KEY_AGENT_PERSONA);
        $configuredPersona = is_array($configuredPersona) ? $configuredPersona : [];
        $agentName = $configuredPersona['agentName'] ?? null;
        $productName = $configuredPersona['productName'] ?? null;
        return AgentPersonaNames::forAgentAndProductName(
            is_string($agentName) && $agentName !== '' ? $agentName : self::DEFAULT_AGENT_NAME,
            is_string($productName) && $productName !== '' ? $productName : self::DEFAULT_PRODUCT_NAME
        );
    }

    /**
     * The character prompt text with its persona placeholders filled (`{%PRODUCT_NAME%}`, `{%AGENT_NAME%}`) — the value
     * of the base prompt's `{%AGENT_CHARACTER%}`. Substituted here deterministically: relying on parameter-application
     * order across nested placeholders would be fragile. Pure.
     */
    public static function buildAgentCharacterText(string $characterPromptText, string $productName, string $agentName): string
    {
        return str_replace(['{%PRODUCT_NAME%}', '{%AGENT_NAME%}'], [$productName, $agentName], $characterPromptText);
    }

    /**
     * OpenAI-form tool definitions for this turn (load_skills + the loaded skills' tools), set by the loop.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $toolSchemas = [];

    /**
     * The model's tool_calls captured from the last response (OpenAI form), or null if the turn was plain text.
     *
     * @var array<int, mixed>|null
     */
    public ?array $capturedToolCalls = null;

    /**
     * Optional AIModel-name override for this turn (CLI `--model`, A/B testing). When set, {@see getAIModel()}
     * resolves it and applies it as the instance model, winning over the {@see ArgusLanguageModel} attribute
     * default. Null = resolve via the AIModelsService AGENTIC scope (the modelScope attribute), with the
     * defaultAIModelName as the fallback when the scope resolves nothing eligible.
     */
    public ?string $aiModelNameOverride = null;

    /**
     * Apply the per-turn {@see $aiModelNameOverride} (if set) before the trait resolves the model: resolve the named
     * AIModel and set it as the instance model, which the parent's getAIModel() then returns (it checks the instance
     * model before falling back to the #[ArgusLanguageModel] default). No override → unchanged default behaviour.
     */
    public function getAIModel(): ?AIModel
    {
        if (
            $this->aiModelNameOverride !== null
            && $this->aiModelNameOverride !== ''
            && !isset($this->aiModel)
        ) {
            /** @var AIModelsService $aiModelsService */
            $aiModelsService = DDDService::instance()->getService(AIModelsService::class);
            $resolved = $aiModelsService->getAIModelByName($this->aiModelNameOverride);
            if ($resolved !== null) {
                $this->aiModel = $resolved;
            }
        }
        return parent::getAIModel();
    }

    /**
     * Opt the agentic egress IN to the SELECTED model's agentic reasoning effort by injecting it onto the egress
     * language-model instance (the established pattern — mirrors ArgusAIVisibilityOpenAISearchResult). The trait then
     * applies it (`reasoning`/`reasoning_effort`). This scopes the override to the agent loop, and because the model
     * is resolved dynamically (AIModelsService / --model), the config of WHATEVER model is chosen rides along.
     *
     * The model is resolved directly from the override name here (NOT via getAIModel(), which itself reads this
     * instance → would recurse).
     */
    public function getArgusLanguageModelInstance(): ?ArgusLanguageModel
    {
        $instance = parent::getArgusLanguageModelInstance();
        if ($instance === null) {
            return null;
        }
        $modelName = ($this->aiModelNameOverride !== null && $this->aiModelNameOverride !== '')
            ? $this->aiModelNameOverride
            : ($instance->defaultAIModelName ?? null);
        if ($modelName !== null && $modelName !== '') {
            /** @var AIModelsService $aiModelsService */
            $aiModelsService = DDDService::instance()->getService(AIModelsService::class);
            $model = $aiModelsService->getAIModelByName($modelName);
            $effort = $model?->agenticUseCase?->reasoningEffort;
            if ($effort !== null && $effort !== '') {
                $instance->reasoningEffort = $effort;
            }
        }
        // the turn's DECIDED effort wins over the catalog value — set by the loop from the owner turn's
        // reasoning-effort decision (already clamped to the ceiling and raised to the row's pin there). Null = the
        // catalog value as before.
        if ($this->reasoningEffortOverride !== null && $this->reasoningEffortOverride !== '') {
            $instance->reasoningEffort = $this->reasoningEffortOverride;
        }
        return $instance;
    }

    /**
     * @var string|null The effort (`low` / `medium` / `high`) THIS call is sent with, when the owner
     *      turn's reasoning-effort decision applies; null = the model's configured agentic effort.
     */
    public ?string $reasoningEffortOverride = null;

    /**
     * @var bool The conversation's purpose asks for the model's FAST service tier: the
     *      request carries `service_tier: "priority"` when the selected model declares `supportsFastMode`. Set by the
     *      loop from the purpose before every call; false = the default tier.
     */
    public bool $fastModeRequested = false;

    /** @var int|null The milliseconds the wire composition of the LAST request took (the loop reads it for its overhead stamps). */
    public ?int $wireBuildMs = null;

    /**
     * A breakpoint with the default (30 min) cache lifetime — every breakpoint of the request, the base prompt's
     * included: a conversation that writes to its space's data rewrites the base prompt on every such write, and a
     * 12 h entry costs 2.5× the input price per write instead of 1.25×.
     */
    public const array PROMPT_CACHE_BREAKPOINT = ['mode' => 'explicit'];

    /** @var int|null How many wire messages (system excluded) of the LAST build come before the volatile tail entries. */
    public ?int $stableWireMessageCount = null;

    /**
     * @var AgentContextSegment[] The system segments of the LAST build, in order — the request snapshot records a hash
     *      per segment from them ({@see \DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot::recordSystemSegmentAndToolsHashes()}).
     */
    public array $lastSystemSegments = [];

    protected function resolveServiceTierForRequest(\DDD\Domain\AI\Entities\Models\AIModel $aiModel): ?string
    {
        return $this->fastModeRequested && $aiModel->supportsFastMode ? self::SERVICE_TIER_PRIORITY : null;
    }

    /**
     * Per-turn tool_choice override set by the loop: 'required' forces a tool call (the LiteLLM proxy maps it to
     * Gemini's function-calling mode ANY) to break a stall where the model replies in prose instead of acting;
     * a named-function pin array `{"type":"function","function":{"name":"<tool>"}}` forces exactly ONE tool (the
     * supervisor steer's expected tool). Null → the 'auto' default, which lets the model also produce a
     * final natural-language answer.
     */
    public string|array|null $forceToolChoice = null;

    /** The loop-built per-turn context (resolved system prompt + this partition's messages). When set, it is the
     *  source for {@see buildModelContext()} (the inversion of the egress-side build); null = self-build fallback. */
    public ?AgentModelInput $modelInput = null;

    protected function getChatCompletionTools(): array
    {
        return $this->toolSchemas;
    }

    protected function getChatCompletionToolChoice(): string|array|null
    {
        if ($this->toolSchemas === []) {
            return null;
        }
        return $this->forceToolChoice ?? 'auto';
    }

    /**
     * The agent's own request composition: the same gates as every model call
     * ({@see runModelCallGates()}), the user content prepared once, then ONE composer turns the API-neutral
     * {@see AgentWireRequestParameters} into the HTTP body, then the egress authentication. The Chat-Completions body is
     * the one the shared trait path built for this egress before the split, key for key.
     *
     * @return array<string, mixed>|null
     */
    protected function getLoadPayloadInternal(): ?array
    {
        $aiModel = $this->runModelCallGates();
        $userContentHasCompleteFormat = $this->prepareUserContentForPayload();
        $agentWireRequestParameters = $this->buildAgentWireRequestParameters($aiModel, $userContentHasCompleteFormat);
        if ($this->agentWireConfiguration?->usesResponsesServerState() === true) {
            return $this->composeResponsesServerStatePayload($aiModel, $agentWireRequestParameters);
        }
        $body = $this->agentWireConfiguration?->usesResponsesApi() === true
            ? AgentResponsesRequestComposer::composeBody($agentWireRequestParameters)
            : AgentChatCompletionsRequestComposer::composeBody($agentWireRequestParameters);
        $payload = $this->applyEgressAuthentication(['body' => $body]);
        $this->lastRequestPayload = $payload; // capture the exact outgoing payload for CLI/debug visibility
        return $payload;
    }

    /**
     * TEST-ONLY server-state arm: decide the chain ({@see AgentResponsesServerStateChain::decideFor()} over the composed
     * Chat-form input, the stored link of the newest step-marker row, this call's tools and model), compose the body with
     * `store: true` and — on a continued chain — `previous_response_id` and only the items after that response, and
     * authenticate against OpenAI directly. The prompt-cache key routes like on the other arms (one per space, else
     * per account).
     *
     * @return array<string, mixed>
     * @throws InternalErrorException no OpenAI key in the environment
     */
    protected function composeResponsesServerStatePayload(?\DDD\Domain\AI\Entities\Models\AIModel $aiModel, AgentWireRequestParameters $agentWireRequestParameters): array
    {
        $agentWireRequestParameters->promptCacheKey = $this->resolvePromptCacheRoutingKey();
        $this->capturedResponsesResponseId = null;
        $this->lastResponsesServerStateChain = AgentResponsesServerStateChain::decideFor(
            $agentWireRequestParameters->chatCompletionsMessages,
            $this->stableWireMessageCount ?? max(0, count($agentWireRequestParameters->chatCompletionsMessages) - 1),
            RequestSnapshot::hashToolsArray(AgentResponsesRequestComposer::convertToolSchemas($agentWireRequestParameters->chatCompletionsTools)),
            $aiModel?->name,
            $this->modelInput !== null ? AgentResponsesServerStateChain::findPreviousLinkSnapshot($this->modelInput->messages) : null,
            $this->forcedResponsesServerStateRestartReason
        );
        $body = AgentResponsesServerStateRequestComposer::composeBody($agentWireRequestParameters, $this->lastResponsesServerStateChain);
        $payload = $this->applyOpenAIDirectAuthHeader(['body' => $body]);
        $this->lastRequestPayload = $payload;
        return $payload;
    }

    /**
     * The direct OpenAI authentication: `Authorization: Bearer` from the first set key of
     * {@see OPENAI_DIRECT_API_KEY_ENV_NAMES}; fails loud without one (an unauthenticated call only yields a 401).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws InternalErrorException
     */
    protected function applyOpenAIDirectAuthHeader(array $payload): array
    {
        foreach (self::OPENAI_DIRECT_API_KEY_ENV_NAMES as $apiKeyEnvName) {
            $openAiApiKey = (string)Config::getEnv($apiKeyEnvName);
            if ($openAiApiKey !== '') {
                $payload['headers']['Authorization'] = 'Bearer ' . $openAiApiKey;
                return $payload;
            }
        }
        throw new InternalErrorException('The direct OpenAI egress needs one of ' . implode(', ', self::OPENAI_DIRECT_API_KEY_ENV_NAMES) . ' in the environment.');
    }

    /**
     * Whether the LAST server-state call continued a chain and OpenAI answered it with an error (`previous_response_not_found`,
     * an expired response, any 4xx on the chain) — the caller then resends once as a fresh chain
     * ({@see prepareFreshResponsesServerStateChainAfterRejection()}). False on every other arm and after the fallback.
     */
    public function isRejectedResponsesServerStateChain(): bool
    {
        return $this->agentWireConfiguration?->usesResponsesServerState() === true
            && $this->forcedResponsesServerStateRestartReason === null
            && $this->lastResponsesServerStateChain?->previousResponseId !== null
            && is_object($this->lastRawResponse)
            && isset($this->lastRawResponse->error);
    }

    /** Orders a fresh chain for the next request and keeps the rejected attempt's error for the snapshot. */
    public function prepareFreshResponsesServerStateChainAfterRejection(): void
    {
        $this->responsesServerStateFallbackError = mb_substr((string)json_encode($this->lastRawResponse->error ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 500);
        $this->forcedResponsesServerStateRestartReason = AgentResponsesServerStateChain::RESTART_PREVIOUS_RESPONSE_REJECTED;
        $this->capturedToolCalls = null;
        $this->capturedResponsesResponseId = null;
        $this->setAiGeneratedResponse(null);
    }

    /**
     * What this call sends, independent of the wire API: the model id, the output ceiling, the temperature, the
     * composed Chat-form messages, the service tier (remembered for the cost booking), the provider routing, the
     * response format, the reasoning effort, the tools with their tool choice and the prompt-cache key — read in the
     * order the shared trait path read them.
     */
    public function buildAgentWireRequestParameters(AIModel $aiModel, bool $userContentHasCompleteFormat): AgentWireRequestParameters
    {
        $agentWireRequestParameters = new AgentWireRequestParameters();
        $agentWireRequestParameters->modelId = $this->resolveEgressModelId($aiModel);
        $agentWireRequestParameters->maxOutputTokens = $aiModel->settings->maxOutputTokens;
        $agentWireRequestParameters->temperature = $this->getTemperature();
        $agentWireRequestParameters->chatCompletionsMessages = ($userContentHasCompleteFormat && is_array($this->userContent))
            ? $this->userContent
            : $this->buildOpenAIChatCompletionSystemAndUserMessages();
        $serviceTier = $this->resolveServiceTierForRequest($aiModel);
        $this->lastRequestServiceTier = $serviceTier;
        $agentWireRequestParameters->serviceTier = $serviceTier;

        $argusLanguageModel = $this->getArgusLanguageModelInstance();
        $providerPreference = $this->resolveOpenRouterProviderPreference($argusLanguageModel, $aiModel);
        if ($providerPreference !== null && ($this->loadEndpointTargetsLiteLLM() || $this->loadEndpointTargetsOpenRouter())) {
            $agentWireRequestParameters->providerPreference = $providerPreference;
        }
        if ($argusLanguageModel?->responseFormat === ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT) {
            $agentWireRequestParameters->responseFormat = ['type' => 'json_object'];
        }
        if ($argusLanguageModel?->reasoningEffort !== null && $aiModel->isReasoningModel) {
            if ($this->loadEndpointTargetsOpenRouter()) {
                $agentWireRequestParameters->reasoningEffort = $argusLanguageModel->reasoningEffort;
                $agentWireRequestParameters->reasoningEffortAsReasoningObject = true;
            } elseif ($aiModel->vendor === AIModel::VENDOR_OPENAI) {
                $agentWireRequestParameters->reasoningEffort = $argusLanguageModel->reasoningEffort;
                $agentWireRequestParameters->reasoningEffortAsReasoningObject = false;
            }
        }
        $agentWireRequestParameters->reasoningContext = $this->resolveReasoningContextForRequest($aiModel);
        $agentWireRequestParameters->chatCompletionsTools = $this->getChatCompletionTools();
        $agentWireRequestParameters->chatCompletionsToolChoice = $this->getChatCompletionToolChoice();
        $agentWireRequestParameters->usesExplicitPromptCacheBreakpoints = $this->usesExplicitPromptCacheBreakpoints();
        $agentWireRequestParameters->promptCacheKey = $this->resolvePromptCacheKey();
        // a Responses call asks for its encrypted reasoning items back when the carry-over is on
        $agentWireRequestParameters->includesEncryptedReasoning = $this->agentWireConfiguration?->usesResponsesApi() === true
            && $this->agentWireConfiguration->carriesPreviousReasoning();
        return $agentWireRequestParameters;
    }

    /**
     * The `reasoning.context` this call pins: the catalog value of the model
     * ({@see AIModel::getAgentReasoningContext()}, `all_turns` for GPT-6 Luna / Sol and GPT-5.6 Luna) on the Responses
     * API — both arms: OpenRouter and the direct OpenAI server-state arm accept it — and on Chat Completions over the
     * direct OpenRouter egress (OpenRouter documents the field on both endpoints). Null on the LiteLLM proxy and a direct
     * OpenAI Chat-Completions endpoint, which carry the effort as the native `reasoning_effort` field and have no
     * `reasoning` object to carry it.
     *
     * The value does not follow the carry-over mode. Under `currentTurn` the egress itself leaves the earlier turns'
     * reasoning items off the wire ({@see AgentWireConfiguration::carriesOnlyTheCurrentTurnsReasoning()}), so `all_turns`
     * renders exactly the items sent — the same set the provider's `current_turn` would render — and with the carry-over
     * off no item is sent at all. One fixed value per model also keeps the request prefix the same when the carry-over
     * is switched off mid-conversation after a refused item.
     */
    protected function resolveReasoningContextForRequest(AIModel $aiModel): ?string
    {
        if ($this->agentWireConfiguration?->usesResponsesApi() !== true && !$this->loadEndpointTargetsOpenRouterDirect()) {
            return null;
        }
        return $aiModel->getAgentReasoningContext();
    }

    /**
     * The provider pin of an OpenAI model's agent call over the direct OpenRouter egress: only the
     * `openai` provider, no fallback. Encrypted reasoning items are bound to the provider and the organisation that
     * minted them; a call routed to another provider (Azure) or to OpenRouter's shared capacity answers
     * `invalid_encrypted_content`.
     */
    public const array OPENAI_PROVIDER_PIN = ['only' => ['openai'], 'allow_fallbacks' => false];

    /**
     * The routing block of the trait ({@see ArgusAILanguageModelTrait::resolveOpenRouterProviderPreference()}: the sort of
     * the prioritization strategy, the catalog filters) with {@see OPENAI_PROVIDER_PIN} merged in for an OpenAI model over
     * the direct OpenRouter egress — on both wire APIs. A console provider-order pin (an explicit debug override) is kept
     * as it is; every other model and the LiteLLM egress get the trait's block unchanged.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveOpenRouterProviderPreference(?ArgusLanguageModel $argusLanguageModel, ?\DDD\Domain\AI\Entities\Models\AIModel $aiModel = null): ?array
    {
        $providerPreference = parent::resolveOpenRouterProviderPreference($argusLanguageModel, $aiModel);
        if ($aiModel?->vendor !== AIModel::VENDOR_OPENAI || !$this->loadEndpointTargetsOpenRouterDirect()
            || static::$providerOrderOverrideStatic !== null
        ) {
            return $providerPreference;
        }
        return array_merge($providerPreference ?? [], self::OPENAI_PROVIDER_PIN);
    }

    /**
     * Always emit the OpenAI Chat-Completions shape — the LiteLLM proxy speaks the OpenAI tools API for every model
     * (it normalises all providers to the OpenAI form), so vendor-native payload paths must be bypassed.
     */
    protected function forcesOpenAIChatCompletionPayload(): bool
    {
        return true;
    }

    /**
     * Gemini hedges (asks instead of calling tools) and is documented to loop / degrade below its default
     * temperature of 1.0 (Google's Gemini 3.x guidance). The shared egress default is tuned lower for other
     * vendors, so for Google/Gemini models bump it to 1.0; every other vendor keeps its configured temperature.
     * Part of the Gemini tool-use-stall fix (with the 'required' tool_choice escalation in the agent loop).
     */
    public function getTemperature(): float
    {
        if ($this->getAIModel()?->vendor === AIModel::VENDOR_GOOGLE) {
            return 1.0;
        }
        return parent::getTemperature();
    }

    protected function handleToolCalls(?array $toolCalls): void
    {
        $this->capturedToolCalls = $toolCalls;
    }

    /**
     * @var array<int, array<string, mixed>>|null The provider's reasoning items of the LAST answer:
     *      the Responses `reasoning` output items, or the Chat-Completions `choices[0].message.reasoning_details`; null
     *      when the answer carried none. The loop stores them on the step's marker row.
     */
    public ?array $capturedReasoningItems = null;

    /** @var string|null The wire API {@see $capturedReasoningItems} came from (`chatCompletions` / `responses`). */
    public ?string $capturedReasoningItemsWireApi = null;

    /**
     * @var int|null The reasoning tokens the provider reported for the LAST answer, captured with {@see $capturedReasoningItems}
     *      (the size the carry-over `capped` sums later); null when no items were captured or the usage names none.
     */
    public ?int $capturedReasoningTokens = null;

    /**
     * @var AgentPreviousReasoningCut|null The cut of the LAST fold under the carry-over `capped` (which steps went with
     *      their reasoning items); null under every other mode. The loop records it on the call's request snapshot.
     */
    public ?AgentPreviousReasoningCut $lastPreviousReasoningCut = null;

    /**
     * @var string|null Why the LAST Responses answer carries neither text nor a call — a refusal or an answer cut off
     *      (`status: incomplete`; {@see AgentResponsesResponse::findUnusableAnswerReason()}); null otherwise and on Chat
     *      Completions. The loop fails the call with it, named, instead of reading an empty answer as a stall.
     */
    public ?string $unusableResponsesAnswerReason = null;

    /**
     * A Responses-API answer carries its calls as `function_call` output items — read in the Chat-Completions shape,
     * so the loop's call decoding is the same on both APIs.
     *
     * @return array<int, mixed>|null
     */
    protected function extractToolCallsFromResponse(object $responseObject): ?array
    {
        if (AgentResponsesResponse::isResponsesObject($responseObject)) {
            return AgentResponsesResponse::fromResponseObject($responseObject)->getToolCallsInChatCompletionsShape();
        }
        return parent::extractToolCallsFromResponse($responseObject);
    }

    /**
     * Captures the answer's reasoning items with the API they belong to ({@see $capturedReasoningItems}) — only while
     * this call carries the reasoning over: OpenRouter returns `reasoning_details` on Chat Completions unasked (probe
     * C2Ra), and items no later call sends back would only fill the step row's snapshot (the baseline arm stores nothing).
     */
    protected function extractVendorSpecificResponseData(object $responseObject): void
    {
        $this->capturedReasoningItems = null;
        $this->capturedReasoningItemsWireApi = null;
        $this->capturedReasoningTokens = null;
        // the server-state arm continues this response on the next call
        $this->capturedResponsesResponseId = $this->agentWireConfiguration?->usesResponsesServerState() === true && AgentResponsesResponse::isResponsesObject($responseObject)
            ? (is_string($responseObject->id ?? null) ? $responseObject->id : null)
            : null;
        $this->unusableResponsesAnswerReason = AgentResponsesResponse::isResponsesObject($responseObject)
            ? AgentResponsesResponse::fromResponseObject($responseObject)->findUnusableAnswerReason()
            : null;
        if ($this->agentWireConfiguration?->carriesPreviousReasoning() !== true) {
            return;
        }
        if (AgentResponsesResponse::isResponsesObject($responseObject)) {
            $reasoningItems = AgentResponsesResponse::fromResponseObject($responseObject)->getReasoningItems();
            if ($reasoningItems !== []) {
                $this->capturedReasoningItems = $reasoningItems;
                $this->capturedReasoningItemsWireApi = AIModel::AGENT_WIRE_API_RESPONSES;
                $reportedReasoningTokens = $responseObject->usage->output_tokens_details->reasoning_tokens ?? null;
                $this->capturedReasoningTokens = is_numeric($reportedReasoningTokens) ? (int)$reportedReasoningTokens : null;
            }
            return;
        }
        $reasoningDetails = $responseObject->choices[0]->message->reasoning_details ?? null;
        if (is_array($reasoningDetails) && $reasoningDetails !== []) {
            $this->capturedReasoningItems = (array)json_decode((string)json_encode($reasoningDetails), true);
            $this->capturedReasoningItemsWireApi = AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS;
            $reportedReasoningTokens = $responseObject->usage->completion_tokens_details->reasoning_tokens ?? null;
            $this->capturedReasoningTokens = is_numeric($reportedReasoningTokens) ? (int)$reportedReasoningTokens : null;
        }
    }

    /**
     * Expose the final assistant text via getAiGeneratedResponse() so the loop reads it directly. We deliberately
     * do NOT call parent::applyLoadResult() (which would append a reply message to $this->messages): the agent
     * loop owns message creation and appends its own assistant turn — letting the parent append here produced a
     * duplicate, null-partition assistant message that toEntity() wrote back into the conversation.
     */
    protected function applyLoadResult(string $resultText): void
    {
        $this->setAiGeneratedResponse($resultText);
    }

    /**
     * The agentic prompt's own parameters on top of those the parent applies. What the conversation's space
     * contributes — the id the prompt names it by included — is set by the parent through
     * {@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::applyAgentSpacePromptParameters()}, so an
     * application that names the space by an id of its own sets it there.
     */
    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        $prompt = parent::getAIPromptWithParametersApplied();
        // {%TASK_PLAN_TRIGGER%}: the plan-trigger bullet of the base prompt — today's
        // wording byte-for-byte unless the plan-dynamics site GUIDES this conversation (switch 2), where the trigger
        // is the honest one: a plan only for several separately accountable changes, the per-turn `[SIGNAL:plan]`
        // line decides, a question / one action / a preference / a follow-up never gets a plan. Conversation-stable
        // (the mode is a setting); the base-prompt cache key carries the mode.
        // the plan is shown each step under every delivery mode — one trigger text per guide mode
        $prompt->setParameter('TASK_PLAN_TRIGGER', self::buildTaskPlanTriggerText(
            AgentDecisionModelSettings::current()->siteModeForConversation(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $this) === AgentDecisionModelSettings::MODE_ON
        ));
        // Product identity: the base prompt names the PRODUCT the customer is on. The name resolves from the
        // account ({@see AgentSpacesService::findProductNameOfConversation()}: e.g. the active subscription's display name — the SAME source e-mails use as
        // sender name); without one, the configured persona product name (`config/app/AI/agentPersona.php`). Account-stable → the Redis-cached base prompt stays byte-stable per conversation
        // (prompt-cache safe).
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $customProductName = $agentSpacesService->findProductNameOfConversation($this);
        $agentPersonaNames = self::findAgentPersonaNames();
        $resolvedProductName = ($customProductName !== null && $customProductName !== '') ? $customProductName : $agentPersonaNames->productName;
        $prompt->setParameter('PRODUCT_NAME', $resolvedProductName);
        $prompt->setParameter('AGENT_NAME', $agentPersonaNames->agentName);
        // {%PRODUCT_OPERATOR_SECTION%}: the WHOLE "who operates this product" section, or nothing at all for an
        // account without an operator — header included, so such an account never reads a section about an operator
        // that does not exist. It is a SEPARATE section on purpose: the operator name glued into the identity sentence
        // made the model recite it in every greeting ("<agent>, your assistant in <product> by <operator>"), which is
        // exactly the background fact it should state only on request. Account-stable → cache-safe.
        // DEFENSIVE: the operator name is a lookup of the application that may fail; a failure must cost only this
        // section, never the prompt.
        $productOperatorSection = '';
        try {
            $productOperatorName = $agentSpacesService->findProductOperatorNameOfConversation($this);
            if ($productOperatorName !== null) {
                // Leading single "\n": the .md carries the placeholder on its own line right after the intro
                // paragraph, so this completes the blank line — and an EMPTY value leaves the file's own spacing intact.
                $productOperatorSection = "\n## Who operates this product\n\n"
                    . '**' . $resolvedProductName . '** is operated by **' . $productOperatorName . '** — the'
                    . " customer's contract and billing are with them. Name them ONLY when the customer asks who is"
                    . ' behind the product, who bills them, or with whom their contract is: never in a greeting,'
                    . ' never in a self-introduction, never glued to the product name. And never route the customer'
                    . ' to them for something you can do here — their support, their website, their phone number are'
                    . ' not an answer to a task you have a tool for.';
            }
        } catch (Throwable) {
            // Section omitted; the product name alone still identifies the app.
        }
        $prompt->setParameter('PRODUCT_OPERATOR_SECTION', $productOperatorSection);
        // The SHARED identity core (chat + phone read the same file, so the two channels cannot diverge). Its own
        // {%PRODUCT_NAME%} is substituted HERE deterministically — relying on parameter-application order across
        // nested placeholders would be fragile. Account-stable → the cached base prompt stays byte-stable.
        // DEFENSIVE: a missing/unresolvable character file (stale config cache) must cost ONLY the character
        // block, never the whole base prompt — getAIPromptByName throws under throwErrors.
        $characterPromptText = '';
        try {
            /** @var AIPromptsService $aIPromptsService */
            $aIPromptsService = AIPrompt::getService();
            $characterPromptText = $aIPromptsService
                ->getAIPromptByName(AgentAIPromptNames::APP_AI_AGENT_CHARACTER)?->promptText ?? '';
        } catch (Throwable) {
            // Character block omitted; the base prompt still ships.
        }
        $prompt->setParameter(
            'AGENT_CHARACTER',
            self::buildAgentCharacterText($characterPromptText, $resolvedProductName, $agentPersonaNames->agentName)
        );
        // OWNER ADDRESSING (shared with the voice briefing — the SAME Account builder): the owner's name, the
        // register preset (agent setting > Translatable chain) and the memory-directive override note.
        // Account-stable → the cached base prompt stays byte-stable; '' when the account carries no name.
        // The state-delivery template keeps the placeholder (the epoch record fills it).
        $prompt->setParameter(
            'OWNER_ADDRESSING',
            $this->buildsStateDeliveryTemplate ? AgentModelInput::OWNER_ADDRESSING_PLACEHOLDER : $this->buildOwnerAddressingInstructionText()
        );
        // {%STATE_NOTES_RULE%}: the System-signals bullet of the state notes — '' outside the template,
        // so an `everyCall` base prompt keeps its bytes
        $prompt->setParameter('STATE_NOTES_RULE', $this->buildsStateDeliveryTemplate ? static::buildStateNotesRule() : '');
        // {%COMPOSED_VIEW_KIT%} is deliberately NOT set here: whether the kit is taught depends
        // on who reads the call (a person in the chat, an in-app call, a scheduled report run — never a sub-agent or a
        // platform run), so {@see AgentModelInput::buildSystemSegments()} fills it per model call and the cached base
        // prompt keeps the placeholder.
        // No CURRENT_DATETIME in the system prompt: a per-build `date()` at the FRONT of the prefix changed the cached
        // bytes every minute → broke prompt caching across turns. The agent reads "now" from the cache-stable
        // `[YYYY-MM-DD HH:MM]` prefix the NEWEST user message already carries ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::getOpenAiMessageContent()}).
        return $prompt;
    }

    /**
     * The `{%OWNER_ADDRESSING%}` text: the account's addressing instruction for the owner addressing arguments of this
     * conversation; '' when the account carries no name or the resolution fails (never breaks the prompt).
     */
    public function buildOwnerAddressingInstructionText(): string
    {
        try {
            return $this->buildOwnerAddressingInstructionTextOrFail();
        } catch (Throwable) {
            // Best-effort: a failed resolution omits the line, never breaks the prompt.
            return '';
        }
    }

    /**
     * {@see buildOwnerAddressingInstructionText()} that throws when the resolution fails — '' means the account carries no
     * name. The state delivery tells the two apart (a swallowed failure read as "no name"
     * and sent "address the owner neutrally").
     *
     * @throws Throwable the account, its settings or the register of the conversation's space could not be read
     */
    public function buildOwnerAddressingInstructionTextOrFail(): string
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->buildOwnerAddressingInstruction($this) ?? '';
    }

    /**
     * The System-signals bullet of a `onceWhenChanged` conversation — what a state note is, which note
     * of a kind counts (a whole-value note replaces the earlier ones, a plan-of-the-product note updates only what it
     * names; the task plan is no note — it rides every call) and when a note is background (filled into `{%STATE_NOTES_RULE%}` of `Agentic.md`
     * at the end of the signals list). The kind of the note about the space's context text and the label of its line
     * are the application's words ({@see AgentApplicationWording}).
     */
    public static function buildStateNotesRule(): string
    {
        $agentApplicationWording = AgentApplicationWording::current();
        $kindOfAgentSpaceContext = $agentApplicationWording->agentSpaceContextStateKind;
        return "\n- State notes — `[SIGNAL:" . $kindOfAgentSpaceContext . "]`, `[SIGNAL:owner-addressing]`, `[SIGNAL:time-zone]`,"
            . "\n  `[SIGNAL:standing-instructions]`, `[SIGNAL:plan-of-the-product]`, `[SIGNAL:situation]`: a value you work with"
            . "\n  changed. The \"" . $agentApplicationWording->agentSpaceContextLabel . "\" line, the owner addressing, the `# Time` zone, the standing instructions and"
            . "\n  the plan blocks of your instructions are a snapshot from before; the situation reaches you only as these notes."
            . "\n  Which note counts: the newest " . $kindOfAgentSpaceContext . ", owner-addressing, time-zone, standing-instructions or situation"
            . "\n  note REPLACES the instructions' value and every earlier note of its kind — the newest one is the whole current"
            . "\n  state. A plan-of-the-product note replaces only the blocks it shows."
            . "\n  Tools stay the source of the current data. A note is background unless it asks something of you: never reply to"
            . "\n  it, never announce it — use the new value when the work needs it. `[SIGNAL:owner-addressing]` and"
            . "\n  `[SIGNAL:standing-instructions]` are rules you follow from then on, and a time-zone or situation note that tells"
            . "\n  you to ask the owner is an instruction like any other.";
    }

    /** The template leaves `{%AGENT_SPACE_CONTEXT%}` to the epoch record. */
    protected function buildsAgentSpaceContextIntoThePrompt(): bool
    {
        return !$this->buildsStateDeliveryTemplate;
    }

    /**
     * Message types that are never sent to the model: internal bookkeeping (routing decisions, errors) and a TOOL's
     * own words to the customer ({@see AIConversationMessage::MESSAGE_TOOL_MESSAGE} — the tool result already told
     * the model the same facts; an assistant entry it never produced would break the tool_use → tool_result adjacency).
     *
     * @var string[]
     */
    protected const array NON_WIRE_MESSAGE_TYPES = [
        AIConversationMessage::MESSAGE_CHECKPOINT,
        AIConversationMessage::MESSAGE_ERROR,
        AIConversationMessage::MESSAGE_TOOL_MESSAGE,
    ];

    /**
     * Serialize the conversation into OpenAI Chat messages. Each TOOL_CALL message carries both the call
     * (toolName/toolArguments) and its result (toolResult), so one assistant turn folds into:
     *   {role:assistant, content, tool_calls:[…]}  followed by one {role:tool, tool_call_id, content} per call.
     * Internal bookkeeping (routing decisions, errors, thread markers) is not sent to the model.
     *
     * @return string|array<int, array<string, mixed>>
     */
    public function getUserContent(): string|array
    {
        return $this->buildModelContext();
    }

    /**
     * The full model context: the authoritative system prompt at index 0 — ALWAYS, thread-aware (main orchestrator
     * vs sub-agent) — followed by the wire messages. The single assembly point and the future context-optimization /
     * compaction hook; whatever compaction does to the wire messages, the system message is non-negotiable (it
     * carries the id of the conversation's space + the do-not-ask / never-fabricate rules). Message serialization
     * lives in {@see buildOpenAiChatMessagesArray()} (pure, unit-testable without prompt resolution).
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildModelContext(): array
    {
        // The loop builds the AgentModelInput (this conversation's messages + the resolved base prompt) and sets it
        // before the model call; the egress only folds it. No self-build fallback — a missing AgentModelInput is a bug.
        if ($this->modelInput === null) {
            throw new \LogicException('buildModelContext requires an AgentModelInput set by the loop before the model call.');
        }
        $wireBuildStartedAt = hrtime(true);
        $messages = $this->buildWireMessagesFromModelInput($this->modelInput);
        $this->wireBuildMs = (int)round((hrtime(true) - $wireBuildStartedAt) / 1e6);
        // composeSystemContent = base prompt (+ sub-agent directive for delegations) + skill instructions +
        // compaction summary, in the cache-aware order. With explicit cache breakpoints the same text
        // rides as text parts split at the segment boundaries a later call shares.
        $this->lastSystemSegments = $this->modelInput->buildSystemSegments();
        if ($this->usesExplicitPromptCacheBreakpoints()) {
            // on the Responses API the wire marker goes on the newest stable USER / TOOL / developer item — a marker on
            // an assistant `output_text` part is not probed (a tool result's part is)
            $usesResponsesApi = $this->agentWireConfiguration?->usesResponsesApi() === true;
            $this->addPromptCacheBreakpointToLastStableWireMessage($messages, $usesResponsesApi);
            if ($usesResponsesApi) {
                // a Responses request reads the cache only at breakpoints it carries itself: it re-marks the
                // item the previous call marked, so the entry that call wrote is looked up
                $this->addPromptCacheBreakpointToPreviousCallsWireMessage($messages);
            }
            // an append-only epoch's loaded-skills segment is fixed — no marker before it (the slot the
            // state delivery needs); on the Responses API the two wire markers take that slot under the cap of four
            array_unshift($messages, ['role' => 'system', 'content' => $this->buildSystemContentPartsWithPromptCacheBreakpoints(
                $this->lastSystemSegments,
                !$usesResponsesApi && $this->agentWireConfiguration?->usesConversationAppend() !== true
            )]);
            return $messages;
        }
        $systemContent = implode('', array_map(static fn (AgentContextSegment $systemSegment): string => $systemSegment->text, $this->lastSystemSegments));
        array_unshift($messages, ['role' => 'system', 'content' => $systemContent]);
        return $messages;
    }

    /**
     * Whether this request carries explicit prompt-cache breakpoints: the model's catalog entry names the mode
     * ({@see AIModel::usesExplicitPromptCacheBreakpoints()}, `settings.promptCacheMode`) and the request goes over the
     * direct OpenRouter egress. Without them the provider's only cache breakpoint sits at the end of the LAST user
     * message — the volatile tail (plan, clock, reply-language signals) the next call replaces — so the conversation
     * history is never read from the cache.
     */
    public function usesExplicitPromptCacheBreakpoints(): bool
    {
        $aiModel = $this->getAIModel();
        return $this->loadEndpointTargetsOpenRouterDirect()
            && $aiModel !== null
            && $aiModel->usesExplicitPromptCacheBreakpoints();
    }

    /**
     * The system content as text parts whose concatenation is {@see AgentModelInput::composeSystemContent()} byte for
     * byte, with a cache breakpoint after each part a later call shares: the base prompt (the same for the space),
     * everything up to the loaded skills (the same for the conversation; a time-zone change changes it), and the
     * whole system content (a skill load, a variant value change or a compaction changes the last part only; the
     * provenance of the zone and of the variants rides the volatile tail).
     *
     * @param AgentContextSegment[] $systemSegments {@see AgentModelInput::buildSystemSegments()} of this call
     * @return array<int, array<string, mixed>>
     */
    public function buildSystemContentPartsWithPromptCacheBreakpoints(array $systemSegments, bool $marksBeforeLoadedSkills = true): array
    {
        $systemContentParts = [];
        $currentPartText = '';
        foreach ($systemSegments as $systemSegment) {
            if ($marksBeforeLoadedSkills && $systemSegment->type === AgentContextSegmentType::LOADED_SKILLS && $currentPartText !== '') {
                $systemContentParts[] = ['type' => 'text', 'text' => $currentPartText, 'prompt_cache_breakpoint' => self::PROMPT_CACHE_BREAKPOINT];
                $currentPartText = '';
            }
            $currentPartText .= $systemSegment->text;
            if ($systemSegment->type === AgentContextSegmentType::BASE_PROMPT && $currentPartText !== '') {
                $systemContentParts[] = ['type' => 'text', 'text' => $currentPartText, 'prompt_cache_breakpoint' => self::PROMPT_CACHE_BREAKPOINT];
                $currentPartText = '';
            }
        }
        if ($currentPartText !== '') {
            $systemContentParts[] = ['type' => 'text', 'text' => $currentPartText, 'prompt_cache_breakpoint' => self::PROMPT_CACHE_BREAKPOINT];
        }
        return $systemContentParts;
    }

    /**
     * A cache breakpoint at the end of the newest wire message before the volatile tail that carries text (a tool
     * result or an owner message; an assistant entry with only tool calls has no text part to mark), so the next call
     * reads the whole conversation up to it from the cache.
     *
     * @param array<int, array<string, mixed>> $wireMessages
     */
    public function addPromptCacheBreakpointToLastStableWireMessage(array &$wireMessages, bool $skipsAssistantMessages = false): void
    {
        $stableWireMessageCount = min($this->stableWireMessageCount ?? count($wireMessages), count($wireMessages));
        $this->addPromptCacheBreakpointToLastTextMessageBefore($wireMessages, $stableWireMessageCount, $skipsAssistantMessages);
    }

    /**
     * A second wire breakpoint on the Responses API: the item the PREVIOUS model call marked. That call's input was the
     * history before its own output — the newest assistant entry of this call's stable messages — so the same rule
     * ({@see addPromptCacheBreakpointToLastStableWireMessage()}) applied to the messages before that entry finds the
     * item it marked. A Responses request reads the cache only at the breakpoints it carries (measured: an entry
     * written at an item the next call no longer marks is never looked up). No assistant entry yet = no previous call.
     *
     * @param array<int, array<string, mixed>> $wireMessages
     */
    public function addPromptCacheBreakpointToPreviousCallsWireMessage(array &$wireMessages): void
    {
        $stableWireMessageCount = min($this->stableWireMessageCount ?? count($wireMessages), count($wireMessages));
        for ($position = $stableWireMessageCount - 1; $position >= 0; $position--) {
            if (($wireMessages[$position]['role'] ?? null) === 'assistant') {
                $this->addPromptCacheBreakpointToLastTextMessageBefore($wireMessages, $position, true);
                return;
            }
        }
    }

    /**
     * The breakpoint on the newest text-bearing message before `$endPosition` (exclusive); a message already marked
     * keeps its one marker.
     *
     * @param array<int, array<string, mixed>> $wireMessages
     */
    public function addPromptCacheBreakpointToLastTextMessageBefore(array &$wireMessages, int $endPosition, bool $skipsAssistantMessages): void
    {
        for ($position = $endPosition - 1; $position >= 0; $position--) {
            if ($skipsAssistantMessages && ($wireMessages[$position]['role'] ?? null) === 'assistant') {
                continue;
            }
            $content = $wireMessages[$position]['content'] ?? null;
            if (is_string($content) && $content !== '') {
                $wireMessages[$position]['content'] = [['type' => 'text', 'text' => $content, 'prompt_cache_breakpoint' => self::PROMPT_CACHE_BREAKPOINT]];
                return;
            }
            if (!is_array($content)) {
                continue;
            }
            for ($partPosition = count($content) - 1; $partPosition >= 0; $partPosition--) {
                if (($content[$partPosition]['type'] ?? null) === 'text' && ($content[$partPosition]['text'] ?? '') !== '') {
                    $wireMessages[$position]['content'][$partPosition]['prompt_cache_breakpoint'] = self::PROMPT_CACHE_BREAKPOINT;
                    return;
                }
            }
        }
    }

    /** The requests with explicit breakpoints write entries of the default lifetime; the others write none of their own. */
    protected function getPromptCacheWriteTtl(): ?string
    {
        // the server-state arm caches implicitly, and OpenAI prices those writes at the write multiple too (GPT-5.6+;
        // the probe's usage reports `cache_write_tokens`)
        return $this->usesExplicitPromptCacheBreakpoints() || $this->agentWireConfiguration?->usesResponsesServerState() === true
            ? AIModel::PROMPT_CACHE_WRITE_TTL_DEFAULT
            : null;
    }

    /**
     * The prompt cache key: one per space (else per account), so the calls of a space share one cache and the
     * OpenRouter session routing (it takes this key as its sticky-routing key) keeps them on one provider.
     */
    protected function applyPromptCacheRoutingToChatCompletionBody(array $body, \DDD\Domain\AI\Entities\Models\AIModel $aiModel): array
    {
        $promptCacheKey = $this->resolvePromptCacheKey();
        if ($promptCacheKey !== null) {
            $body['prompt_cache_key'] = $promptCacheKey;
        }
        return $body;
    }

    /** The prompt cache key of this request ({@see applyPromptCacheRoutingToChatCompletionBody()}), null when none is sent. */
    public function resolvePromptCacheKey(): ?string
    {
        if (!$this->usesExplicitPromptCacheBreakpoints()) {
            return null;
        }
        return $this->resolvePromptCacheRoutingKey();
    }

    /**
     * The prompt-cache routing key of this conversation: one per space; a conversation without a space (account
     * level) shares the key of its account; null without either. The space service builds it
     * ({@see AgentSpacesService::findPromptCacheRoutingKeyOfConversation()}), so an application keeps the keys it
     * has always sent.
     */
    public function resolvePromptCacheRoutingKey(): ?string
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService->findPromptCacheRoutingKeyOfConversation($this);
    }

    /**
     * The wire messages WITHOUT the system entry, INCLUDING the volatile tail entries — shared by the live turn
     * ({@see buildModelContext()}) and the admin forensic reconstruction
     * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService::reconstructSentPayloadForDisplay()}),
     * so the traceability shows exactly what the model received, tail cues included (the
     * reconstruction previously merged the folded rows itself and silently missed the tail hints).
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildWireMessagesFromModelInput(AgentModelInput $agentContext): array
    {
        $messages = $this->buildOpenAiChatMessagesArray($agentContext->messages, $agentContext->keepToolResultsFromIndex, $agentContext);
        $this->stableWireMessageCount = count($messages);
        // The volatile tail: ONE list built by the context, read here and by the admin
        // composition view. This call's own inputs: the step's tool choice (`'none'` adds the answer-step line) and
        // whether the context THIS repository holds ends on the budget-closing signal (its own `modelInput`, the
        // object {@see buildModelContext()} passes in on the live wire).
        $toolLessStepIsTheBudgetClosingStep = $this->modelInput !== null && $this->modelInput->isNewestMessageABudgetClosingSignal();
        foreach ($agentContext->buildTailEntries($this->forceToolChoice, $toolLessStepIsTheBudgetClosingStep)->toWireMessages() as $tailWireMessage) {
            $messages[] = $tailWireMessage;
        }
        return $messages;
    }

    /**
     * The conversation's messages as the OpenAI Chat-Completions `messages` ARRAY (WITHOUT the system entry, which
     * {@see buildModelContext()} prepends): user / assistant+tool_calls / tool results. **Each message renders ITSELF**
     * ({@see AIConversationMessage::toOpenAiChatMessageArray()} / `toOpenAiToolCallEntryArray()` /
     * `toOpenAiToolResultArray()`) — this egress only COMPOSES them + owns the one MULTI-message decision: folding an
     * assistant turn together with its following TOOL_CALL messages into one `{role:assistant, tool_calls:[…]}` + a
     * `{role:tool}` per call (skipping interleaved bookkeeping / a stray SIGNAL so it never splits a call from its
     * result, which the wire forbids). Internal-bookkeeping + role=SYSTEM messages are dropped. `$messages` defaults to
     * this conversation's own messages. Pure (no prompt resolution) → unit-testable.
     *
     * `$keepToolResultsFromIndex`: a tool RESULT whose message index is BELOW it is emitted as a STUB
     * (content cleared, `tool_call_id` kept); at/above it goes verbatim. PHP_INT_MIN (default) = keep all. The boundary is
     * precomputed over the bounded tail in {@see AIConversation::getToolResultRetentionFromIndex()} (carried on the AgentModelInput).
     *
     * `$agentContext` carries the per-turn resolved images keyed by `tool_call_id` — when a folded
     * call's id has resolved bytes, its `role:tool` entry rides as a content-PARTS array with the image inside;
     * a blind model gets the downgrade note; the admin reconstruction (`renderImageReferencePlaceholders`)
     * gets the persisted-reference placeholder. Null = no images (the default; unit tests that fold a bare messages
     * array).
     *
     * @param AIConversationMessage[]|null $messages
     * @return array<int, array<string, mixed>>
     */
    public function buildOpenAiChatMessagesArray(?array $messages = null, int $keepToolResultsFromIndex = PHP_INT_MIN, ?AgentModelInput $agentContext = null): array
    {
        // ONE conversation = ONE context window: the egress folds exactly the messages it was given — no
        // partition filtering (a sub-agent delegation is its own conversation). Default = this conversation's own.
        $messages ??= $this->messages->getElements();
        $count = count($messages);
        $entries = [];

        // The NEWEST genuine user turn (role USER + TEXT + authored by the user — not a SIGNAL, not an agent nudge)
        // gets the reply-language cue in its [time] bracket ({@see AIConversationMessage::getOpenAiMessageContent()}).
        $newestUserTurnIndex = -1;
        for ($k = $count - 1; $k >= 0; $k--) {
            $candidate = $messages[$k];
            if ($candidate->isOwnerText()) {
                $newestUserTurnIndex = $k;
                break;
            }
        }

        // every `update_task_plan` call BELOW the newest one emits stubbed ARGUMENTS
        // ({@see AIConversationMessage::toOpenAiToolCallEntryArray()}) — K plan updates would otherwise ride as K
        // near-identical full plan copies in every later step's input. The newest call keeps its arguments verbatim.
        $taskPlanCallPositions = [];
        foreach ($messages as $k => $candidate) {
            if ($candidate->messageType === AIConversationMessage::MESSAGE_TOOL_CALL
                && (isset($candidate->toolCall) ? ($candidate->toolCall->name ?? null) : null) === UpdateTaskPlanTool::NAME) {
                $taskPlanCallPositions[spl_object_id($candidate)] = $k;
            }
        }
        $newestTaskPlanCallPosition = $taskPlanCallPositions === [] ? -1 : max($taskPlanCallPositions);
        // carry-over `currentTurn`: the position of the newest turn initiator — a step before it belongs to
        // an earlier turn and goes without its reasoning items (none found = every step is the current turn's)
        $currentTurnInitiatorPosition = -1;
        for ($k = $count - 1; $k >= 0; $k--) {
            if (AIConversationMessages::isTurnInitiatorMessage($messages[$k])) {
                $currentTurnInitiatorPosition = $k;
                break;
            }
        }
        // carry-over `capped`: the steps below the cut go without their reasoning items — the cut is
        // decided once per fold over these rows, starting from the cut the previous call recorded
        $this->lastPreviousReasoningCut = $this->agentWireConfiguration?->carriesCappedPreviousReasoning() === true
            ? AgentPreviousReasoningCut::decideFor(array_values($messages), $this->agentWireConfiguration->wireApi, $this->agentWireConfiguration->previousReasoningCapTokens)
            : null;
        $isSupersededTaskPlanCall = static fn (AIConversationMessage $callMessage): bool =>
            isset($taskPlanCallPositions[spl_object_id($callMessage)])
            && $taskPlanCallPositions[spl_object_id($callMessage)] < $newestTaskPlanCallPosition;
        // a send_owner_report call's ARGUMENTS (the full report body) are ALWAYS stubbed on the wire —
        // the delivered report rides as the visible OWNER_REPORT message and would otherwise be re-billed
        // every remaining step. The PERSISTED row keeps the args full (email composer + forensics).
        $isArgumentStubbedCall = static fn (AIConversationMessage $callMessage): bool =>
            $isSupersededTaskPlanCall($callMessage)
            || (isset($callMessage->toolCall) ? ($callMessage->toolCall->name ?? null) : null) === SendOwnerReportTool::NAME;
        // Plan-tool RESULTS are exempt from the tool-result clearing stub: the plan
        // CREATION result carries the maintenance rules (the lazy-decomposition Carrier A) and every tick result is
        // a one-line count — both tiny and load-bearing for the whole run, while the stub's "re-call the tool"
        // invitation would prompt exactly the full-plan resend the discipline forbids.
        // a TINY result (a `load_skills` / `load_tool` acknowledgement, a one-line count) is never stubbed —
        // the stub text is longer than the result, so the "saving" was negative AND the rewrite broke the prompt-cache
        // prefix (a 10-token result replaced by a 40-token stub, the call credited 0 cached tokens).
        // Budget by fit: a result above the boundary the budget could not hold is stubbed by itself.
        $toolResultIndexesStubbedByBudget = $agentContext !== null ? $agentContext->toolResultIndexesStubbedByBudget : [];
        // The NEWEST call of a tool declaring {@see MCPToolNewestResultIsNeverStubbed} keeps its result: such a result
        // holds the ids a following write needs, and its stub would leave the model only a label to guess an
        // id from (and a guessed id saves the wrong thing).
        $newestNeverStubbedCallIndexesByToolName = [];
        foreach ($messages as $candidate) {
            $candidateToolName = isset($candidate->toolCall) ? ($candidate->toolCall->name ?? null) : null;
            if ($candidateToolName !== null && MCPToolAttributeCatalog::hasAttributeOnToolName($candidateToolName, MCPToolNewestResultIsNeverStubbed::class)) {
                $newestNeverStubbedCallIndexesByToolName[$candidateToolName] = $candidate->indexInConversation ?? null;
            }
        }
        $newestNeverStubbedCallIndexes = array_values(array_filter($newestNeverStubbedCallIndexesByToolName, 'is_int'));
        $keepsFullToolResult = static fn (AIConversationMessage $callMessage): bool =>
            (($callMessage->indexInConversation ?? null) !== null && in_array($callMessage->indexInConversation, $newestNeverStubbedCallIndexes, true)) ||
            (($callMessage->indexInConversation ?? PHP_INT_MAX) >= $keepToolResultsFromIndex
                && !isset($toolResultIndexesStubbedByBudget[$callMessage->indexInConversation ?? PHP_INT_MAX]))
            || in_array((isset($callMessage->toolCall) ? ($callMessage->toolCall->name ?? null) : null), TaskPlan::PLAN_TOOL_NAMES, true)
            || (isset($callMessage->toolCall) && $callMessage->toolCall->resultIsShorterThanItsStub());
        // render one call's tool-result entry, embedding its resolved image (keyed by tool_call_id on
        // the AgentModelInput) as a content-parts array on a vision model, the downgrade note on a blind one, or
        // the reference placeholder in the admin reconstruction. Without an AgentModelInput (bare-messages fold)
        // it is plain text — byte-identical to the pre-image behaviour.
        $toolResultEntry = static function (AIConversationMessage $callMessage) use ($agentContext, $keepsFullToolResult): array {
            $resolvedImages = [];
            $capturedUnsentImage = false;
            $renderReferencePlaceholders = false;
            $imagesDroppedFromWire = false;
            // the kit's `$from` paths and entity references ride where the kit is taught
            $rendersEntityReferences = false;
            if ($agentContext !== null) {
                $rendersEntityReferences = $agentContext->teachesComposedViewKit();
                $toolCallId = isset($callMessage->toolCall) ? ($callMessage->toolCall->toolCallId ?? '') : '';
                $resolvedImages = $agentContext->getResolvedImagesForToolResult($toolCallId);
                $capturedUnsentImage = $agentContext->toolResultCapturedUnsentImage($toolCallId);
                $renderReferencePlaceholders = $agentContext->renderImageReferencePlaceholders;
                $imagesDroppedFromWire = $agentContext->toolResultImagesDropped($toolCallId);
            }
            $toolResultArray = $callMessage->toOpenAiToolResultArray(
                $keepsFullToolResult($callMessage),
                $resolvedImages,
                $capturedUnsentImage,
                $renderReferencePlaceholders,
                $imagesDroppedFromWire,
                $rendersEntityReferences,
            );
            // while the owner is on the phone line, a result's widget dictation never reaches the model —
            // the ONE place every tool result passes (the stored result stays untouched)
            if ($agentContext !== null && $agentContext->isOwnerOnThePhoneLine()) {
                $toolResultArray['content'] = is_array($toolResultArray['content'])
                    ? array_map(
                        static fn (array $contentPart): array => ($contentPart['type'] ?? null) === 'text'
                            ? ['type' => 'text', 'text' => AIConversationMessage::replaceWidgetFencesForThePhoneLine((string)$contentPart['text'])] + $contentPart
                            : $contentPart,
                        $toolResultArray['content']
                    )
                    : AIConversationMessage::replaceWidgetFencesForThePhoneLine((string)$toolResultArray['content']);
            }
            return $toolResultArray;
        };

        // the items an append-only epoch's calls appended, each where the call sent it — before the first
        // row this fold visits whose index reaches the item's place, ahead of the row's own skip checks (a dropped row
        // never drops its item; a row folded into the group before it emits the item after that group)
        $appendedHistoryItems = $agentContext?->appendedHistoryItems;
        $pendingInsertionMessageIndexes = $appendedHistoryItems?->getInsertionMessageIndexesInOrder() ?? [];
        for ($i = 0; $i < $count; $i++) {
            $message = $messages[$i];
            $messageIndex = $message->indexInConversation ?? null;
            while ($appendedHistoryItems !== null && $messageIndex !== null && $pendingInsertionMessageIndexes !== [] && $pendingInsertionMessageIndexes[0] <= $messageIndex) {
                foreach ($appendedHistoryItems->buildWireEntriesBeforeMessageIndex(array_shift($pendingInsertionMessageIndexes)) as $appendedWireEntry) {
                    $entries[] = $appendedWireEntry;
                }
            }

            if (in_array($message->messageType, self::NON_WIRE_MESSAGE_TYPES, true)) {
                continue;
            }

            // SIGNAL → a user entry (handled by the message itself). BEFORE the role==='system' skip below
            // (a SIGNAL is stored role=SYSTEM and would otherwise be dropped).
            if ($message->messageType === AIConversationMessage::MESSAGE_SIGNAL) {
                // A form widget's submit is the owner's words — blocked by the injection check, it
                // reaches the model as the filter notice only (the dedicated source; no other signal is screened)
                if ($agentContext !== null
                    && AIConversationMessages::isOwnerFormSubmitInitiator($message)
                    && in_array($message->indexInConversation ?? null, $agentContext->securityFilteredOwnerMessageIndexes, true)
                ) {
                    $entries[] = $message->toSecurityFilteredOpenAiChatMessageArray($agentContext->resolveTimezoneForMessageIndex($messageIndex));
                    continue;
                }
                $entries[] = $message->toOpenAiChatMessageArray();
                continue;
            }

            // A TOOL_CALL row with no preceding assistant TEXT head — since the prose-less step remodel this is the
            // NORMAL shape of a PROSE-LESS step (no empty TEXT row is persisted anymore; the step's FIRST call row
            // carries the usage stamp = the step marker). Fold the step's CONSECUTIVE call rows into ONE assistant
            // entry (content '' — byte-identical to what the legacy empty-TEXT-head fold emitted), and STOP at the
            // next STAMPED call row: that row starts the NEXT step, and merging two steps would rewrite the turn's
            // wire head retroactively (intra-turn prompt-cache break every step) and falsify causality (step 2's
            // calls were derived from step 1's results — they were never one parallel batch). A SIGNAL terminates
            // the group (same contract as the assistant-head fold); only NON_WIRE bookkeeping rows are skipped.
            if ($message->messageType === AIConversationMessage::MESSAGE_TOOL_CALL) {
                $stepCallMessages = [$message];
                $j = $i + 1;
                while ($j < $count) {
                    $next = $messages[$j];
                    if ($next->messageType === AIConversationMessage::MESSAGE_TOOL_CALL && ($next->inputTokens ?? 0) <= 0) {
                        $stepCallMessages[] = $next;
                        $j++;
                    } elseif (in_array($next->messageType, self::NON_WIRE_MESSAGE_TYPES, true)) {
                        $j++;
                    } else {
                        break;
                    }
                }
                $entries[] = $this->withReasoningItemsOfStep([
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => array_map(fn (AIConversationMessage $c): array => $c->toOpenAiToolCallEntryArray($isArgumentStubbedCall($c)), $stepCallMessages),
                ], $message, $i > $currentTurnInitiatorPosition);
                foreach ($stepCallMessages as $stepCallMessage) {
                    $entries[] = $toolResultEntry($stepCallMessage);
                }
                $i = $j - 1;
                continue;
            }

            $role = strtolower($message->role ?? AIConversationMessage::ROLE_USER);

            // role=SYSTEM conversation messages are NOT wired through — the authoritative system prompt is prepended
            // unconditionally below; a stored system message must never override or duplicate it.
            if ($role === 'system') {
                continue;
            }

            // The one MULTI-message orchestration the egress owns: fold an assistant turn with its following TOOL_CALL
            // messages (skipping only interleaved NON-WIRE bookkeeping between them) into one assistant entry + a tool
            // result each. A SIGNAL must NOT be consumed here — it TERMINATES the fold so the outer loop emits it as its
            // own `[SIGNAL:…]` user entry (else a stall-recovery / memory-conflict SIGNAL that lands right after a
            // tool-call turn — the common case — is swallowed by the `$i = $j - 1` jump below and never reaches the model).
            if ($role === 'assistant') {
                $calls = [];
                $j = $i + 1;
                while ($j < $count) {
                    $next = $messages[$j];
                    // Consume only UNSTAMPED call rows: a STAMPED one (`inputTokens > 0`) is the head of the NEXT,
                    // prose-less step (the head fold must not swallow it; the grouped-orphan
                    // branch above emits it as its own assistant entry). Legacy conversations are unaffected: their
                    // steps stamp the TEXT head, never the call rows, so every call row here is unstamped.
                    if ($next->messageType === AIConversationMessage::MESSAGE_TOOL_CALL && ($next->inputTokens ?? 0) <= 0) {
                        $calls[] = $next;
                        $j++;
                    } elseif (in_array($next->messageType, self::NON_WIRE_MESSAGE_TYPES, true)) {
                        $j++;
                    } else {
                        break;
                    }
                }
                if ($calls !== []) {
                    $entries[] = $this->withReasoningItemsOfStep([
                        'role' => 'assistant',
                        'content' => $message->getOpenAiMessageContent(timezone: $agentContext?->resolveTimezoneForMessageIndex($messageIndex)),
                        'tool_calls' => array_map(fn (AIConversationMessage $c): array => $c->toOpenAiToolCallEntryArray($isArgumentStubbedCall($c)), $calls),
                    ], $message, $i > $currentTurnInitiatorPosition);
                    foreach ($calls as $call) {
                        $entries[] = $toolResultEntry($call);
                    }
                    $i = $j - 1;
                    continue;
                }
            }

            // An owner message the injection check blocked reaches the model as the filter notice only.
            if ($role === 'user' && ($message->messageType ?? null) === AIConversationMessage::MESSAGE_TEXT
                && $agentContext !== null && in_array($message->indexInConversation ?? null, $agentContext->securityFilteredOwnerMessageIndexes, true)
            ) {
                $entries[] = $message->toSecurityFilteredOpenAiChatMessageArray($agentContext->resolveTimezoneForMessageIndex($messageIndex));
                continue;
            }
            // each bracket in the zone in effect at its position (the call's zone without a state delivery)
            $chatMessageEntry = $message->toOpenAiChatMessageArray($i === $newestUserTurnIndex, $agentContext?->resolveTimezoneForMessageIndex($messageIndex));
            $entries[] = $role === 'assistant' ? $this->withReasoningItemsOfStep($chatMessageEntry, $message, $i > $currentTurnInitiatorPosition) : $chatMessageEntry;
        }
        // what THIS call appends, after the last history row (it is stable history from the next call on) —
        // after any recorded item whose place no visited row reached
        if ($appendedHistoryItems !== null) {
            foreach ($pendingInsertionMessageIndexes as $pendingInsertionMessageIndex) {
                foreach ($appendedHistoryItems->buildWireEntriesBeforeMessageIndex($pendingInsertionMessageIndex) as $appendedWireEntry) {
                    $entries[] = $appendedWireEntry;
                }
            }
            foreach ($appendedHistoryItems->buildWireEntriesOfThisCall() as $appendedWireEntry) {
                $entries[] = $appendedWireEntry;
            }
        }

        return $entries;
    }

    /**
     * An assistant entry with the provider's reasoning items of its step: when this call carries the
     * reasoning over and the step's marker row stored items of THIS call's wire API, they ride the entry as
     * `reasoning_details` (Chat Completions sends them back as is; the Responses converter places them before the
     * step's first item). Otherwise the entry is unchanged — byte for byte without the carry-over. Under the carry-over
     * `currentTurn` ({@see AgentWireConfiguration::carriesOnlyTheCurrentTurnsReasoning()}) a step of an earlier turn
     * (`$stepIsInTheCurrentTurn` false) goes without its items; under `capped` a step below the fold's cut
     * ({@see $lastPreviousReasoningCut}) goes without them. The request's `reasoning.context` stays the catalog pin
     * either way ({@see resolveReasoningContextForRequest()}).
     *
     * @param array<string, mixed> $assistantEntry
     * @return array<string, mixed>
     */
    protected function withReasoningItemsOfStep(array $assistantEntry, AIConversationMessage $stepMarkerRow, bool $stepIsInTheCurrentTurn = true): array
    {
        if ($this->agentWireConfiguration === null || !$this->agentWireConfiguration->carriesPreviousReasoning()
            || ($this->agentWireConfiguration->carriesOnlyTheCurrentTurnsReasoning() && !$stepIsInTheCurrentTurn)
            || ($this->lastPreviousReasoningCut !== null && !$this->lastPreviousReasoningCut->sendsTheItemsOfTheStepAt($stepMarkerRow->indexInConversation ?? null))
            || ($stepMarkerRow->inputTokens ?? 0) <= 0 || !isset($stepMarkerRow->requestSnapshot)
        ) {
            return $assistantEntry;
        }
        $wireReasoningItems = $stepMarkerRow->requestSnapshot->findWireReasoningItemsFor($this->agentWireConfiguration->wireApi);
        if ($wireReasoningItems !== null) {
            $assistantEntry['reasoning_details'] = $wireReasoningItems;
        }
        return $assistantEntry;
    }

    /**
     * The BASE system-prompt content: the resolved AIPrompt (with the parameters of the conversation's space applied),
     * nothing else. The sub-agent directive
     * is NO longer keyed on threadId here — it lives in {@see AgentModelInput::composeSystemContent()} (driven by the
     * conversation's isDelegation), as do the skill instructions and the compaction summary — so the live loop and
     * the debug recomputation share ONE composition.
     */
    public function getSystemPromptContent(): string
    {
        return $this->getAIPromptWithParametersAppliedCached()->getPromptTextWithParametersApplied();
    }

    /**
     * The base prompt's plan-trigger bullet. `$decisionModelGuidesThePlan` false = today's text,
     * byte-identical (the "before" arm); true = the rewritten trigger the decision model's per-turn directive sits on.
     * The plan is shown each step under both delivery modes.
     */
    public static function buildTaskPlanTriggerText(bool $decisionModelGuidesThePlan): string
    {
        if (!$decisionModelGuidesThePlan) {
            return '- For a MULTI-PART task, maintain a task plan. The trigger is the REQUEST ITSELF: whenever it asks for more than'
                . "\n  one thing (several actions, a list, \"and\" chains) — CREATE the plan BEFORE any other tool call, whether or not"
                . "\n  the user mentions a plan: `update_task_plan` as your FIRST call (one item per part, sub-steps as subtasks). The"
                . "\n  plan tools teach you the maintenance rules when you use them; your current plan is shown to you each step, and"
                . "\n  before your final answer no item may remain open.";
        }
        return '- A task plan is for JOBS, not for requests: keep one only when the work has several separately accountable,'
            . "\n  tool-backed changes or deliverables (each made by its own call and reported on its own), or must survive a"
            . "\n  pause, a delegation or a scheduled run. A `[SIGNAL:plan]` line at the end of your context tells you per turn"
            . "\n  whether this request needs the plan, answers directly, or starts without one — follow it. Never a plan for a"
            . "\n  question (however many reads it takes), one action with its check, a preference, a steering follow-up, or"
            . "\n  one deliverable with several sections. When a READ reveals several separate things to fix, create the plan"
            . "\n  then (`update_task_plan`, one item per change) and work it. While a plan is open, your current plan is shown"
            . "\n  each step and no item may remain open before your final answer; tick items in the same response as the next"
            . "\n  call or your answer, never as a step of its own. A finished plan is closed for you; `get_task_plans` shows"
            . "\n  the finished ones when you must report on them.";
    }
}
