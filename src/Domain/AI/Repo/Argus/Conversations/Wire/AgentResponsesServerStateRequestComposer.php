<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentResponsesServerStateChain;

/**
 * TEST-ONLY (the server-state arm): the Responses-API body of one agent call sent DIRECTLY to OpenAI
 * (`POST https://api.openai.com/v1/responses`) with the conversation state kept by OpenAI: `store: true`, and on a
 * continued chain `previous_response_id` plus only the input items after that response
 * ({@see AgentResponsesServerStateChain}). The items are converted by the same {@see AgentResponsesInputItems} as the
 * stateless arms, so both send the same texts.
 *
 * Differences to {@see AgentResponsesRequestComposer} (the OpenRouter arm), each probed
 * against the live API: no `temperature` (OpenAI answers 400 "not supported with this model" for
 * GPT-6 Luna — OpenRouter drops it silently), no `provider` block, no `include` (the reasoning items stay in the stored
 * state), no explicit breakpoints (implicit caching: the probe's chained call read the whole stored prefix).
 */
class AgentResponsesServerStateRequestComposer
{
    /**
     * @return array<string, mixed>
     */
    public static function composeBody(AgentWireRequestParameters $agentWireRequestParameters, AgentResponsesServerStateChain $agentResponsesServerStateChain): array
    {
        $body = [
            'model' => $agentWireRequestParameters->modelId,
            'input' => AgentResponsesInputItems::fromChatCompletionsMessages($agentResponsesServerStateChain->chatCompletionsMessagesToSend),
        ];
        if ($agentResponsesServerStateChain->previousResponseId !== null) {
            $body['previous_response_id'] = $agentResponsesServerStateChain->previousResponseId;
        }
        if ($agentWireRequestParameters->chatCompletionsTools !== []) {
            $body['tools'] = AgentResponsesRequestComposer::convertToolSchemas($agentWireRequestParameters->chatCompletionsTools);
            $responsesToolChoice = AgentResponsesRequestComposer::convertToolChoice($agentWireRequestParameters->chatCompletionsToolChoice);
            if ($responsesToolChoice !== null) {
                $body['tool_choice'] = $responsesToolChoice;
            }
            $body['parallel_tool_calls'] = true;
        }
        if ($agentWireRequestParameters->maxOutputTokens !== null) {
            $body['max_output_tokens'] = $agentWireRequestParameters->maxOutputTokens;
        }
        $reasoning = AgentResponsesRequestComposer::buildReasoningObject($agentWireRequestParameters);
        if ($reasoning !== null) {
            $body['reasoning'] = $reasoning;
        }
        if ($agentWireRequestParameters->responseFormat !== null) {
            $body['text'] = ['format' => $agentWireRequestParameters->responseFormat];
        }
        $body['store'] = true;
        if ($agentWireRequestParameters->promptCacheKey !== null) {
            $body['prompt_cache_key'] = $agentWireRequestParameters->promptCacheKey;
        }
        if ($agentWireRequestParameters->serviceTier !== null) {
            $body['service_tier'] = $agentWireRequestParameters->serviceTier;
        }
        return $body;
    }
}
