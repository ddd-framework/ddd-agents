<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

/**
 * Everything one agent model call sends, independent of the wire API: the egress
 * {@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation} fills it from the model, the attribute, the
 * loop's per-call settings and the composed Chat-form messages; {@see AgentChatCompletionsRequestComposer} and
 * {@see AgentResponsesRequestComposer} turn it into the HTTP body of their API. Pure data, no behaviour.
 */
class AgentWireRequestParameters
{
    /** @var string The model id on the wire (the bare OpenRouter slug, or `openrouter/<slug>` on the LiteLLM proxy). */
    public string $modelId = '';

    /** @var int|null The catalog's output-token ceiling (`max_tokens` / `max_output_tokens`). */
    public ?int $maxOutputTokens = null;

    /** @var float The sampling temperature. */
    public float $temperature = 0.0;

    /**
     * @var array<int, array<string, mixed>> The complete Chat-Completions message list: the system entry first (a string
     *      or text parts with breakpoint markers), then the history and the volatile tail entries.
     */
    public array $chatCompletionsMessages = [];

    /** @var string|null The service tier (`priority` = fast mode) or null for the provider's default. */
    public ?string $serviceTier = null;

    /** @var array<string, mixed>|null The OpenRouter `provider` routing block, or null when none is sent. */
    public ?array $providerPreference = null;

    /** @var array<string, mixed>|null The Chat-Completions `response_format` value, or null for free text. */
    public ?array $responseFormat = null;

    /** @var string|null The reasoning effort sent, or null when the model gets none. */
    public ?string $reasoningEffort = null;

    /**
     * @var bool True = the effort rides the unified OpenRouter object `reasoning: {effort}`; false = the native
     *      OpenAI `reasoning_effort` field (a direct OpenAI Chat-Completions endpoint).
     */
    public bool $reasoningEffortAsReasoningObject = true;

    /**
     * @var string|null The `reasoning.context` pinned for the model (`all_turns`, from the catalog —
     *      {@see \DDD\Domain\AI\Entities\Models\AIModel::getAgentReasoningContext()}), or null when none is sent: the
     *      catalog names none, or the egress has no `reasoning` object (the LiteLLM proxy, a direct OpenAI
     *      Chat-Completions endpoint). Rides `reasoning: {effort, context}` on both wire APIs.
     */
    public ?string $reasoningContext = null;

    /** @var array<int, array<string, mixed>> The tool schemas in the Chat-Completions form `{type:function, function:{…}}`. */
    public array $chatCompletionsTools = [];

    /** @var string|array<string, mixed>|null The Chat-Completions `tool_choice` (`auto`, `required`, `none` or a named pin). */
    public string|array|null $chatCompletionsToolChoice = null;

    /**
     * @var string|null The prompt-cache routing key: one per space, else per account
     *      ({@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::findPromptCacheRoutingKeyOfConversation()}), or
     *      null when none is sent.
     */
    public ?string $promptCacheKey = null;

    /** @var bool True when the request carries explicit prompt-cache breakpoints (the catalog mode over the direct egress). */
    public bool $usesExplicitPromptCacheBreakpoints = false;

    /**
     * @var bool True when the Responses request asks for the encrypted reasoning items back
     *      (`include: ["reasoning.encrypted_content"]`).
     */
    public bool $includesEncryptedReasoning = false;
}
