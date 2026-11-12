<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationSummary;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIReasoningEffort;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use ReflectionException;

/**
 * @method AIConversation|AIConversationMessage getParent()
 * @method AIConversationSummary toEntity(array $callPath = [], DefaultObject|null &$entityInstance = null)
 */
// direct openrouter.ai egress (OpenAI-form chat completions) — direct OpenRouter egress, no LiteLLM hop; mirrors
// ArgusAIConversationCompaction so a ModelScope::COMPACTION-selected model (any vendor) routes by name, not just Gemini.
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: 0
)]
// Model resolved centrally by ModelScope::COMPACTION via AIModelsService (the cheapest summarizer for the band) — same
// task family as the node-gist summarizer. defaultAIModelName is only the fallback when the scope cannot resolve.
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT6_LUNA,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_CONVERSATION_SUMMARY,
    temperature: 0.6,
    systemPromptName: AgentAIPromptNames::APP_AI_SYSTEM_PROMPT_PUBLIC_FACING,
    aiUsageScope: AgentAIUsageScope::AI_CONVERSATION_SUMMARIZE,
    modelScope: ModelScope::COMPACTION,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    // `none`: a summary shortens, it does not judge; it is written thousands of times a
    // day, and the next run overwrites it
    reasoningEffort: ArgusOpenAIReasoningEffort::NONE,
)]
class ArgusAIConversationSummary extends AIConversationSummary
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    /**
     * @var AIConversationSummary|null The PREVIOUS persisted summary for an INCREMENTAL refresh — set by
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::generateAndPersistSummary()} on the explicit
     * service-side invocation; {@see getUserContent()} then feeds the previous summary + only the messages past its
     * `coveredUpToMessageIndex` marker instead of the whole bounded view. Null (the lazy-load path / first
     * generation) = full input. Transient loader state, never persisted.
     */
    public ?AIConversationSummary $previousSummary = null;

    /**
     * @param AIConversation $aiConversation
     * @param LazyLoad $lazyloadAttributeInstance
     * @return AIConversationSummary|null
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function lazyload(
        AIConversation &$aiConversation,
        LazyLoad &$lazyloadAttributeInstance,
    ): ?AIConversationSummary
    {
        $this->setParent($aiConversation);
        $this->argusLoad(
            useArgusEntityCache: $lazyloadAttributeInstance->useCache,
            useApiACallCache: $lazyloadAttributeInstance->useCache
        );
        return $this->toEntity();
    }

    /**
     * The summary prompt with what the conversation's space contributes: the billing entity of the call and the
     * context segment in the summary's own shape. {@see AgentSpacesService} is asked for every conversation — one
     * without a space gets its account-level answer (by default: the account is billed, no context segment).
     */
    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        $prompt = $this->getAIPrompt();
        $conversation = $this->getParent();
        if ($conversation instanceof AIConversation) {
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            $billingEntity = $agentSpacesService->findBillingEntityOfConversation($conversation);
            if ($billingEntity !== null) {
                $this->billAICostsTo(entityWithinAccount: $billingEntity);
            }
            // the context segment of the space in the summary's own shape (`{%AGENT_SPACE_CONTEXT%}` of the summary prompt)
            $agentSpaceContext = $agentSpacesService->buildConversationSummaryAgentSpaceContextPromptSegment($conversation);
            if ($agentSpaceContext !== null) {
                $prompt->setParameter('AGENT_SPACE_CONTEXT', $agentSpaceContext);
            }
        }
        return $prompt;
    }

    public function getUserContent(): string|array
    {
        $conversation = $this->getParent();
        if (!$conversation instanceof AIConversation) {
            return '';
        }
        // Load the SAME bounded context a normal answer loads — cover-only nodes + raw tail — BEFORE building, so the
        // shared build path ({@see AIConversation::buildDisplaySummaryInput} → buildModelInputUpToAndIncludingMessage)
        // sees the recency-graded cover + tail. Without this preload the summary is triggered lazily (e.g. the
        // conversation-list read) on a conversation whose compactionNodes are NOT loaded → the isset guard yields NO
        // cover and the lazy `messages` relation loads an unbounded oldest-first window → the "BOUNDED cover + tail"
        // contract silently degrades to a full raw transcript. Persisted conversations only (an ephemeral one already
        // holds its in-memory tree + messages). Mirrors the turn-prep load in AIConversationAgentService.
        // SKIP the load when the caller already prepared the bounded context (the compaction round's summary-refresh
        // arrives with the frontier nodes + the raw tail in memory — the cover is derivable from the loaded frontier
        // in-memory, so re-querying cover + tail there is pure waste). isset() stays in-memory (no lazy-load fire) and is
        // true only for an explicitly-loaded set. Both must be present — load both otherwise.
        if (isset($conversation->id) && !(isset($conversation->compactionNodes) && isset($conversation->messages))) {
            $conversation->compactionNodes = $conversation->findCompactionCoverNodes() ?? AIConversationCompactionNodes::newInstance();
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = AIConversationMessages::getService();
            $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
        }
        // INCREMENTAL refresh: with a previous summary, feed it + ONLY the slice past its staleness marker —
        // bounded cost per refresh regardless of conversation length. Falls back to the full input inside the build
        // when the previous summary carries no marker (pre-field summaries).
        if ($this->previousSummary !== null) {
            return $conversation->buildDisplaySummaryDeltaInput($this->previousSummary);
        }
        // BOUNDED whole-conversation view: the already-compacted old history as its recency-graded cover + the raw
        // verbatim tail (NOT the full raw transcript, which grows without bound on a long conversation). Lives on the
        // entity so the summary refresh on every compaction stays cheap + scalable.
        // {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildDisplaySummaryInput}
        return $conversation->buildDisplaySummaryInput();
    }

    protected function applyLoadResult(string $resultText): void
    {
        $generatedVersions = null;
        if ($resultText) {
            $cleaned = $this->removeProgrammingLanguageMarkup($resultText);
            $resultObject = json_decode($cleaned);
        } else {
            return;
        }
        if (isset($resultObject->title) && isset($resultObject->text)) {
            $this->title = $resultObject->title;
            $this->text = $resultObject->text;
            // the episodic result line + the importance rubric — tolerant of models that omit them (null).
            $this->outcome = isset($resultObject->outcome) && is_string($resultObject->outcome) && trim($resultObject->outcome) !== ''
                ? $resultObject->outcome
                : null;
            $this->importance = isset($resultObject->importance) && is_numeric($resultObject->importance)
                ? max(1, min(5, (int)$resultObject->importance))
                : null;
        }
    }
}
