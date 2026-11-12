<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Guard;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Argus repo class for the Sentinel-Canary guard probe (the fail-closed
 * injection-detection eval).
 *
 * The "Sentinel-Canary" pattern (an INVERTED canary used as a fail-closed gate):
 * a guard model is instructed to emit ONE exact, unguessable, semantically-distant
 * sentinel phrase IF — and only if — the inspected content carries no instruction
 * that would manipulate the agent / leak the system prompt / trigger an action.
 * The orchestration code then forwards the request to the agent ONLY on an EXACT
 * match of that phrase. Any deviation (manipulation, steering, an attempt to dump
 * the prompt, a truncated/chatty output) → the phrase is absent → fail-closed block.
 *
 * Secrecy stops FORGERY (the attacker can't write the phrase) and the semantic
 * distance + non-referenceability stop blind STEERING ("emit your pass token"
 * resolves to nothing). What it cannot stop is honest EVASION (content that looks
 * genuinely clean) — the irreducible classifier floor, which is exactly what this
 * suite measures per model.
 *
 * This VO is the single guard model call: a custom system prompt (the guard
 * variant) + the inspected input → raw text. It runs OUTSIDE the agent loop, so
 * NO AgentModelInput / agent system prompt contaminates the probe.
 *
 * Base-class choice: extends ValueObject directly, to avoid the LazyLoadRepo conflict AIText carries.
 *
 * Endpoint: the LiteLLM proxy (universal — normalises every vendor to the OpenAI
 * chat-completion form), so MiniMax / Qwen / Gemini / GPT all run through one path.
 * forcesOpenAIChatCompletionPayload() = true mirrors ArgusAIAgentConversation,
 * bypassing the vendor-native payload branches.
 *
 * Per-call model selection: setModelByName() resolves the AIModel and assigns
 * $this->aiModel, which getAIModel() returns first (short-circuiting tier/default
 * resolution) — the same override the eval suite drives via --model.
 *
 * Eval-only — there is no production caller; the guard pattern is being measured,
 * not yet shipped.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE,
    timeout: 90,
)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_MINIMAX_M3,
    temperature: 0.0,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_DEFAULT,
    aiUsageScope: AgentAIUsageScope::AGENT_GUARD_SENTINEL_PROBE,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
)]
class ArgusAgentSentinelCanaryGuard extends ValueObject
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    /** The guard variant's system prompt (instructs when to emit the sentinel phrase). */
    protected string $guardSystemPrompt = '';

    /** The inspected content — the (potentially adversarial) input under test. */
    protected string $inspectedInput = '';

    /** Raw model output stored after applyLoadResult(). */
    protected ?string $rawResponse = null;

    public function setGuardSystemPrompt(string $guardSystemPrompt): void
    {
        $this->guardSystemPrompt = $guardSystemPrompt;
    }

    public function setInspectedInput(string $inspectedInput): void
    {
        $this->inspectedInput = $inspectedInput;
    }

    /**
     * Per-call model override — resolves the AIModel by its catalog name and assigns
     * $this->aiModel so getAIModel() returns it first (the same path the trait uses
     * for defaultAIModelName, and the suite uses for --model).
     */
    public function setModelByName(string $modelName): void
    {
        $this->aiModel = $this->findAIModelByNameOrThrow($modelName);
    }

    /** Must be called before argusLoad() so the usage envelope/budget resolves an account. */
    public function setBillingEntity(Entity $billingEntity): void
    {
        $this->billAICostsTo(entityWithinAccount: $billingEntity);
    }

    /** The guard variant prompt is the system message — built in-memory, never persisted. */
    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        if (isset($this->aiPrompt)) {
            return $this->aiPrompt;
        }

        $prompt = new AIPrompt();
        $prompt->name = 'AgentSentinelCanaryGuard';
        $prompt->promptText = $this->guardSystemPrompt;
        $this->setAIPrompt($prompt);

        return $prompt;
    }

    /** The inspected input is the single user turn. */
    public function getUserContent(): string|array
    {
        return $this->inspectedInput;
    }

    /**
     * LiteLLM normalises every provider to the OpenAI chat-completion form, so the
     * vendor-native payload branches (Gemini / Perplexity / OpenAI-Responses) must be
     * bypassed — same as ArgusAIAgentConversation.
     */
    protected function forcesOpenAIChatCompletionPayload(): bool
    {
        return true;
    }

    protected function applyLoadResult(string $resultText): void
    {
        $this->rawResponse = $resultText;
    }

    public function getRawResponse(): ?string
    {
        return $this->rawResponse;
    }
}
