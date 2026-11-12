<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

use LogicException;

/**
 * The OpenAI Responses-API body of one agent model call through OpenRouter (each shape probed):
 * `model`, `input` (the Chat messages converted by {@see AgentResponsesInputItems}), the flat `tools`, `tool_choice`,
 * `parallel_tool_calls`, `max_output_tokens`, `temperature`, `reasoning` (`effort`, and `context` when the catalog pins one),
 * `store: false`, `include` (encrypted reasoning,
 * when the carry-over is on), `prompt_cache_key`, `prompt_cache_options` (explicit breakpoints), `provider`,
 * `service_tier`. Never `instructions` (it cannot carry a breakpoint), never `previous_response_id` (OpenRouter is
 * stateless). At most {@see MAX_PROMPT_CACHE_BREAKPOINTS} breakpoint markers — OpenAI writes at most four entries per
 * request.
 */
class AgentResponsesRequestComposer
{
    /** OpenAI's cap of explicit cache writes per request (probed: a fifth marker is accepted, the cap stays). */
    public const int MAX_PROMPT_CACHE_BREAKPOINTS = 4;

    /** The explicit prompt-cache mode of a request that carries breakpoint markers (probed). */
    public const array PROMPT_CACHE_OPTIONS_EXPLICIT = ['mode' => 'explicit'];

    /**
     * @return array<string, mixed>
     * @throws LogicException more breakpoint markers than OpenAI writes
     */
    public static function composeBody(AgentWireRequestParameters $agentWireRequestParameters): array
    {
        $inputItems = AgentResponsesInputItems::fromChatCompletionsMessages($agentWireRequestParameters->chatCompletionsMessages);
        $breakpointCount = self::countPromptCacheBreakpoints($inputItems);
        if ($breakpointCount > self::MAX_PROMPT_CACHE_BREAKPOINTS) {
            throw new LogicException('A Responses request carries ' . $breakpointCount . ' prompt-cache breakpoints; OpenAI writes at most ' . self::MAX_PROMPT_CACHE_BREAKPOINTS . '.');
        }
        $body = [
            'model' => $agentWireRequestParameters->modelId,
            'input' => $inputItems,
        ];
        if ($agentWireRequestParameters->chatCompletionsTools !== []) {
            $body['tools'] = self::convertToolSchemas($agentWireRequestParameters->chatCompletionsTools);
            $responsesToolChoice = self::convertToolChoice($agentWireRequestParameters->chatCompletionsToolChoice);
            if ($responsesToolChoice !== null) {
                $body['tool_choice'] = $responsesToolChoice;
            }
            $body['parallel_tool_calls'] = true;
        }
        if ($agentWireRequestParameters->maxOutputTokens !== null) {
            $body['max_output_tokens'] = $agentWireRequestParameters->maxOutputTokens;
        }
        $body['temperature'] = $agentWireRequestParameters->temperature;
        $reasoning = self::buildReasoningObject($agentWireRequestParameters);
        if ($reasoning !== null) {
            $body['reasoning'] = $reasoning;
        }
        if ($agentWireRequestParameters->responseFormat !== null) {
            $body['text'] = ['format' => $agentWireRequestParameters->responseFormat];
        }
        $body['store'] = false;
        if ($agentWireRequestParameters->includesEncryptedReasoning) {
            $body['include'] = ['reasoning.encrypted_content'];
        }
        if ($agentWireRequestParameters->promptCacheKey !== null) {
            $body['prompt_cache_key'] = $agentWireRequestParameters->promptCacheKey;
        }
        if ($agentWireRequestParameters->usesExplicitPromptCacheBreakpoints) {
            $body['prompt_cache_options'] = self::PROMPT_CACHE_OPTIONS_EXPLICIT;
        }
        if ($agentWireRequestParameters->providerPreference !== null) {
            $body['provider'] = $agentWireRequestParameters->providerPreference;
        }
        if ($agentWireRequestParameters->serviceTier !== null) {
            $body['service_tier'] = $agentWireRequestParameters->serviceTier;
        }
        return $body;
    }

    /**
     * The `reasoning` object: `effort` when the model gets one, `context` when the catalog pins one
     * ({@see AgentWireRequestParameters::$reasoningContext}); null when neither is sent. Shared by both Responses arms and
     * the Chat-Completions body over OpenRouter.
     *
     * @return array<string, string>|null
     */
    public static function buildReasoningObject(AgentWireRequestParameters $agentWireRequestParameters): ?array
    {
        $reasoning = [];
        if ($agentWireRequestParameters->reasoningEffort !== null) {
            $reasoning['effort'] = $agentWireRequestParameters->reasoningEffort;
        }
        if ($agentWireRequestParameters->reasoningContext !== null) {
            $reasoning['context'] = $agentWireRequestParameters->reasoningContext;
        }
        return $reasoning !== [] ? $reasoning : null;
    }

    /**
     * Chat-Completions tool schemas `{type: function, function: {name, description, parameters, strict?}}` in the flat
     * Responses form `{type: function, name, description, parameters, strict}` (probed). A schema already flat stays.
     *
     * Every function tool carries `strict: false` unless its schema declares `strict` itself: the Responses API treats
     * a function tool WITHOUT the key as strict (Chat Completions as not strict), and strict mode makes every optional
     * property required — the model then fills each optional argument with an empty default (`""`, `false`, `[]`),
     * which our tools read as meaningful (a partial-update write switches off settings the owner never named). Every
     * Responses tool entry goes through here: the top-level `tools` of both Responses arms and the `additional_tools`
     * item of the append path ({@see AgentResponsesInputItems}). The Chat-Completions body is not touched.
     *
     * @param array<int, array<string, mixed>> $chatCompletionsTools
     * @return array<int, array<string, mixed>>
     */
    public static function convertToolSchemas(array $chatCompletionsTools): array
    {
        $responsesTools = [];
        foreach ($chatCompletionsTools as $chatCompletionsTool) {
            if (!isset($chatCompletionsTool['function']) || !is_array($chatCompletionsTool['function'])) {
                $responsesTools[] = self::withNonStrictDefault($chatCompletionsTool);
                continue;
            }
            $responsesTool = ['type' => 'function'];
            foreach ($chatCompletionsTool['function'] as $functionKey => $functionValue) {
                $responsesTool[$functionKey] = $functionValue;
            }
            $responsesTools[] = self::withNonStrictDefault($responsesTool);
        }
        return $responsesTools;
    }

    /**
     * A flat Responses tool entry with `strict: false` when it is a function tool that does not declare `strict`; an
     * explicit `strict` (true or false) and every non-function entry stay as they are. Pure.
     *
     * @param array<string, mixed> $responsesTool
     * @return array<string, mixed>
     */
    public static function withNonStrictDefault(array $responsesTool): array
    {
        if (($responsesTool['type'] ?? null) === 'function' && !array_key_exists('strict', $responsesTool)) {
            $responsesTool['strict'] = false;
        }
        return $responsesTool;
    }

    /**
     * The Chat `tool_choice` in the Responses form: `auto` / `required` / `none` unchanged, the named pin
     * `{type: function, function: {name}}` → `{type: function, name}` (probed).
     *
     * @param string|array<string, mixed>|null $chatCompletionsToolChoice
     * @return string|array<string, mixed>|null
     */
    public static function convertToolChoice(string|array|null $chatCompletionsToolChoice): string|array|null
    {
        if (!is_array($chatCompletionsToolChoice)) {
            return $chatCompletionsToolChoice;
        }
        $pinnedToolName = $chatCompletionsToolChoice['function']['name'] ?? $chatCompletionsToolChoice['name'] ?? null;
        return $pinnedToolName !== null ? ['type' => 'function', 'name' => (string)$pinnedToolName] : $chatCompletionsToolChoice;
    }

    /**
     * The breakpoint markers of the input items (message parts and function-call-output parts).
     *
     * @param array<int, array<string, mixed>> $inputItems
     */
    public static function countPromptCacheBreakpoints(array $inputItems): int
    {
        $breakpointCount = 0;
        foreach ($inputItems as $inputItem) {
            $itemParts = $inputItem['content'] ?? ($inputItem['output'] ?? null);
            if (!is_array($itemParts)) {
                continue;
            }
            foreach ($itemParts as $itemPart) {
                if (is_array($itemPart) && isset($itemPart[AgentResponsesInputItems::PROMPT_CACHE_BREAKPOINT_KEY])) {
                    $breakpointCount++;
                }
            }
        }
        return $breakpointCount;
    }
}
