<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionSummary;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
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

/**
 * The cheap-model COMPACTION summarizer: given a pre-built input (previous summary + tail messages, or the
 * raw span from source), produce the recall-first `<summary>` gist. Goes through the SAME egress the agent uses —
 * the DIRECT openrouter.ai passthrough declared in the `#[ArgusLoad]` below, NOT the LiteLLM proxy: an
 * earlier version of this docblock claimed the provider gateway host's `/chat/completions` and was WRONG, which is
 * worth stating because it caused a release checklist to chase a LiteLLM team grant this path never needs.
 * {@see ArgusAILanguageModelTrait::loadEndpointTargetsLiteLLM()} matches on the provider gateway host, so it is false
 * here and the model goes out under its BARE OpenRouter slug with the OPENROUTER_KEY. The
 * {@see ModelScope::COMPACTION}-selected model (any vendor: Gemini / Qwen / gpt-oss / OpenAI) is still routed by
 * name, not pinned to a vendor endpoint. Invoked
 * IMPERATIVELY by {@see \DDD\Domain\AI\Services\Conversations\AIConversationCompactionService} (not a lazy-load),
 * exactly like the agent's egress in `AIConversationModelCallsService::performModelCall`: set parent + input → `argusLoad(...)`
 * → read `getAiGeneratedResponse()` / `getLastInputTokens()` / `getLastOutputTokens()` / `getAIModel()`.
 *
 * Output extraction is via the `<summary>…</summary>` tag regex (NOT `json_decode` like the display summary) — the
 * compaction prompt emits raw tagged text. Extends {@see AIConversationCompactionSummary} — the value the service
 * returns — for its `summaryText`; the instance is transient (never persisted, cache disabled at call time).
 *
 * @method AIConversation getParent()
 */
// direct openrouter.ai egress (OpenAI-form chat completions) — direct OpenRouter egress, no LiteLLM hop;
// the trait detects it (loadEndpointContains('openrouter')) and attaches the OPENROUTER_KEY as the Authorization header.
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: 0
)]
// Model resolved centrally by ModelScope::COMPACTION via AIModelsService (the cheapest summarizer for the band), routed by
// name through the direct OpenRouter endpoint above — NOT a hardcoded model. defaultAIModelName is the fallback when
// scope resolution finds nothing eligible.
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT6_LUNA,
    defaultAIPromptName: AgentAIPromptNames::APP_AI_CONVERSATION_COMPACTION,
    temperature: 0.3,
    aiUsageScope: AgentAIUsageScope::AI_CONVERSATION_COMPACTION,
    modelScope: ModelScope::COMPACTION,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    // `low`: a compaction replaces the history for good; what it leaves out is gone,
    // and a mistake is carried on by every level above it
    reasoningEffort: ArgusOpenAIReasoningEffort::LOW,
)]
class ArgusAIConversationCompaction extends AIConversationCompactionSummary
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    /** @var string The pre-built summarizer input (IDENTIFIERS block + optional PREVIOUS SUMMARY + the transcript). */
    public string $compactionInput = '';

    /**
     * Always emit the OpenAI Chat-Completions shape — the model is scope-selected (any vendor) and sent by name to the
     * direct OpenRouter endpoint, which takes the OpenAI form for every provider. Without this, a Google model would
     * take the trait's vendor-native Gemini payload path and get sent to the OpenAI-form endpoint → empty response. Mirrors
     * {@see ArgusAIAgentConversation::forcesOpenAIChatCompletionPayload()}.
     */
    protected function forcesOpenAIChatCompletionPayload(): bool
    {
        return true;
    }

    public function getUserContent(): string|array
    {
        return $this->compactionInput;
    }

    /**
     * The compaction prompt; the call is billed to the billing entity {@see AgentSpacesService} names for the
     * conversation — asked for every conversation, one without a space gets its account-level answer (by default
     * none: the account is billed).
     */
    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        $conversation = $this->getParent();
        if ($conversation instanceof AIConversation) {
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            $billingEntity = $agentSpacesService->findBillingEntityOfConversation($conversation);
            if ($billingEntity !== null) {
                $this->billAICostsTo(entityWithinAccount: $billingEntity);
            }
        }
        return $this->getAIPrompt();
    }

    /** Extract the `<summary>…</summary>` block (fallback: the whole body); the compaction prompt forbids JSON/fences. */
    protected function applyLoadResult(string $resultText): void
    {
        if ($resultText === '') {
            return;
        }
        if (preg_match('/<summary>(.*?)<\/summary>/s', $resultText, $matches) === 1) {
            $this->summaryText = trim($matches[1]);
            return;
        }
        $this->summaryText = trim($resultText);
    }
}
