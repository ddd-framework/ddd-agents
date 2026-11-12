<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

/**
 * The OpenAI Chat-Completions body of one agent model call: the body the shared trait path built for the
 * agent egress before the split, key for key and in the same order (`model`, `max_tokens`, `temperature`, `messages`,
 * `service_tier`, `provider`, `response_format`, `reasoning` / `reasoning_effort`, `tools`, `tool_choice`,
 * `parallel_tool_calls`, `prompt_cache_key`). The authentication is added by the egress afterwards, as before. One key
 * beyond that body: `reasoning.context` inside the `reasoning` object when the catalog pins one for the model
 * (the egress sets it only over the direct OpenRouter egress, which documents the field on Chat Completions).
 */
class AgentChatCompletionsRequestComposer
{
    /**
     * @return array<string, mixed>
     */
    public static function composeBody(AgentWireRequestParameters $agentWireRequestParameters): array
    {
        $body = [
            'model' => $agentWireRequestParameters->modelId,
            'max_tokens' => $agentWireRequestParameters->maxOutputTokens,
            'temperature' => $agentWireRequestParameters->temperature,
            'messages' => $agentWireRequestParameters->chatCompletionsMessages,
        ];
        if ($agentWireRequestParameters->serviceTier !== null) {
            $body['service_tier'] = $agentWireRequestParameters->serviceTier;
        }
        if ($agentWireRequestParameters->providerPreference !== null) {
            $body['provider'] = $agentWireRequestParameters->providerPreference;
        }
        if ($agentWireRequestParameters->responseFormat !== null) {
            $body['response_format'] = $agentWireRequestParameters->responseFormat;
        }
        if ($agentWireRequestParameters->reasoningEffort !== null && !$agentWireRequestParameters->reasoningEffortAsReasoningObject) {
            $body['reasoning_effort'] = $agentWireRequestParameters->reasoningEffort;
        } else {
            $reasoning = AgentResponsesRequestComposer::buildReasoningObject($agentWireRequestParameters);
            if ($reasoning !== null) {
                $body['reasoning'] = $reasoning;
            }
        }
        if ($agentWireRequestParameters->chatCompletionsTools !== []) {
            $body['tools'] = $agentWireRequestParameters->chatCompletionsTools;
            if ($agentWireRequestParameters->chatCompletionsToolChoice !== null) {
                $body['tool_choice'] = $agentWireRequestParameters->chatCompletionsToolChoice;
            }
            $body['parallel_tool_calls'] = true;
        }
        if ($agentWireRequestParameters->promptCacheKey !== null) {
            $body['prompt_cache_key'] = $agentWireRequestParameters->promptCacheKey;
        }
        return $body;
    }
}
