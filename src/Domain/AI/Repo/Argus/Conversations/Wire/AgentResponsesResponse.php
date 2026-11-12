<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

/**
 * The decoded answer of one Responses-API call: the `function_call` output items as
 * tool calls in the Chat-Completions shape `{id, type: function, function: {name, arguments}}` — so the loop's call
 * decoding ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::fromResponse()}) stays unchanged —, the
 * `reasoning` items (sent back on later calls) and the usage. Pure, read-only over the object.
 *
 * @phpstan-consistent-constructor
 */
class AgentResponsesResponse
{
    /** The decoded response object. */
    public object $responseObject;

    public static function fromResponseObject(object $responseObject): static
    {
        $agentResponsesResponse = new static();
        $agentResponsesResponse->responseObject = $responseObject;
        return $agentResponsesResponse;
    }

    /** Whether a decoded answer is a Responses-API object (`object: response`, or an `output` list without `choices`). */
    public static function isResponsesObject(object $responseObject): bool
    {
        return ($responseObject->object ?? null) === 'response'
            || (isset($responseObject->output) && is_array($responseObject->output) && !isset($responseObject->choices));
    }

    /**
     * The `function_call` items in the Chat-Completions shape; null when the answer called no function.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function getToolCallsInChatCompletionsShape(): ?array
    {
        $chatCompletionsToolCalls = [];
        foreach ($this->getOutputItems() as $outputItem) {
            if (($outputItem->type ?? null) !== 'function_call') {
                continue;
            }
            $chatCompletionsToolCalls[] = [
                'id' => (string)($outputItem->call_id ?? ''),
                'type' => 'function',
                'function' => [
                    'name' => (string)($outputItem->name ?? ''),
                    'arguments' => is_string($outputItem->arguments ?? null) ? $outputItem->arguments : (string)json_encode($outputItem->arguments ?? new \stdClass()),
                ],
            ];
        }
        return $chatCompletionsToolCalls !== [] ? $chatCompletionsToolCalls : null;
    }

    /**
     * The `reasoning` items exactly as returned (encrypted content, summary, format), as arrays — what a later call
     * sends back in place (probed: accepted with `store: false`, ≈ 34 input tokens per item).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReasoningItems(): array
    {
        $reasoningItems = [];
        foreach ($this->getOutputItems() as $outputItem) {
            if (($outputItem->type ?? null) === 'reasoning') {
                $reasoningItems[] = (array)json_decode((string)json_encode($outputItem), true);
            }
        }
        return $reasoningItems;
    }

    /**
     * The usage: input, output, cached input, written cache and reasoning tokens; null without a usage block.
     *
     * @return AgentResponsesUsage|null
     */
    public function getUsage(): ?AgentResponsesUsage
    {
        $usage = $this->responseObject->usage ?? null;
        if (!is_object($usage) || (!isset($usage->input_tokens) && !isset($usage->output_tokens))) {
            return null;
        }
        return AgentResponsesUsage::fromUsageBlock($usage);
    }

    /** The named outcome of an answer the provider REFUSED (a `refusal` content part, no text, no call). */
    public const string UNUSABLE_ANSWER_REFUSAL = 'refusal';

    /** The named outcome of an answer the provider cut off (`status: incomplete`, no text, no call). */
    public const string UNUSABLE_ANSWER_INCOMPLETE = 'incomplete';

    /**
     * Why this answer carries neither an `output_text` part nor a `function_call` item: the
     * provider refused (`refusal: <its text>`) or cut the answer off (`incomplete: <incomplete_details.reason>`, e.g. an
     * answer that ended after its reasoning item at `max_output_tokens`). Null for an answer with text or a call, and for
     * an empty answer of any other kind (that stays the loop's stall, as on Chat Completions).
     */
    public function findUnusableAnswerReason(): ?string
    {
        $refusalText = null;
        foreach ($this->getOutputItems() as $outputItem) {
            if (($outputItem->type ?? null) === 'function_call') {
                return null;
            }
            foreach (is_array($outputItem->content ?? null) ? $outputItem->content : [] as $contentPart) {
                if (!is_object($contentPart)) {
                    continue;
                }
                if (($contentPart->type ?? null) === 'output_text' && trim((string)($contentPart->text ?? '')) !== '') {
                    return null;
                }
                if (($contentPart->type ?? null) === 'refusal') {
                    $refusalText ??= trim((string)($contentPart->refusal ?? ''));
                }
            }
        }
        if ($refusalText !== null) {
            return self::UNUSABLE_ANSWER_REFUSAL . ': ' . ($refusalText !== '' ? mb_substr($refusalText, 0, 300) : '(no text)');
        }
        if (($this->responseObject->status ?? null) === 'incomplete') {
            return self::UNUSABLE_ANSWER_INCOMPLETE . ': ' . (string)($this->responseObject->incomplete_details->reason ?? 'unknown');
        }
        return null;
    }

    /** @return array<int, object> */
    protected function getOutputItems(): array
    {
        $outputItems = $this->responseObject->output ?? [];
        return is_array($outputItems) ? array_values(array_filter($outputItems, 'is_object')) : [];
    }
}
