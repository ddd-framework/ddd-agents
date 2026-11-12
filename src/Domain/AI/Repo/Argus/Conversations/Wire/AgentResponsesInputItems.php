<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

/**
 * The Responses-API `input` items of one agent call, converted from the SAME Chat-Completions message list the Chat
 * composer sends (one history grouping, two renderings — both APIs send the same texts):
 *
 * - `system` → ONE `developer` message, its text parts as `input_text` (breakpoint markers kept);
 * - `developer` (an append-only skill row) → a `developer` message;
 * - `user` → a `user` message: text parts `input_text`, `image_url` parts `input_image`;
 * - `assistant` → the step's reasoning items (`reasoning_details` of the entry, same API) first, then an `assistant`
 *   message with `output_text` when it carries text, then one `function_call` per tool call (`call_id`, `name`,
 *   `arguments`; no `id`);
 * - `tool` → `function_call_output` with the result string, or an `input_text` / `input_image` array when the result
 *   carries a picture or a breakpoint marker;
 * - `{type: additional_tools}` (an append-only skill row's tools) → the same item with its tools in the flat form.
 *
 * Pure: no repository, no configuration.
 */
class AgentResponsesInputItems
{
    /** The breakpoint marker key the Chat parts carry and the Responses `input_text` parts carry the same way. */
    public const string PROMPT_CACHE_BREAKPOINT_KEY = 'prompt_cache_breakpoint';

    /**
     * @param array<int, array<string, mixed>> $chatCompletionsMessages
     * @return array<int, array<string, mixed>>
     */
    public static function fromChatCompletionsMessages(array $chatCompletionsMessages): array
    {
        $inputItems = [];
        foreach ($chatCompletionsMessages as $chatCompletionsMessage) {
            foreach (self::convertChatCompletionsMessage($chatCompletionsMessage) as $inputItem) {
                $inputItems[] = $inputItem;
            }
        }
        return $inputItems;
    }

    /**
     * The items of one Chat message (zero or more).
     *
     * @param array<string, mixed> $chatCompletionsMessage
     * @return array<int, array<string, mixed>>
     */
    public static function convertChatCompletionsMessage(array $chatCompletionsMessage): array
    {
        if (($chatCompletionsMessage['type'] ?? null) === 'additional_tools') {
            return [[
                'type' => 'additional_tools',
                'role' => 'developer',
                'tools' => AgentResponsesRequestComposer::convertToolSchemas((array)($chatCompletionsMessage['tools'] ?? [])),
            ]];
        }
        $role = (string)($chatCompletionsMessage['role'] ?? 'user');
        $content = $chatCompletionsMessage['content'] ?? '';
        if ($role === 'system' || $role === 'developer') {
            return [self::buildMessageItem('developer', self::convertInputContent($content))];
        }
        if ($role === 'assistant') {
            return self::convertAssistantMessage($chatCompletionsMessage);
        }
        if ($role === 'tool') {
            return [[
                'type' => 'function_call_output',
                'call_id' => (string)($chatCompletionsMessage['tool_call_id'] ?? ''),
                'output' => self::convertToolResultContent($content),
            ]];
        }
        return [self::buildMessageItem('user', self::convertInputContent($content))];
    }

    /**
     * An assistant entry: the step's reasoning items, the text (if any), the calls.
     *
     * @param array<string, mixed> $assistantMessage
     * @return array<int, array<string, mixed>>
     */
    protected static function convertAssistantMessage(array $assistantMessage): array
    {
        $inputItems = [];
        foreach ((array)($assistantMessage['reasoning_details'] ?? []) as $reasoningItem) {
            if (is_array($reasoningItem)) {
                $inputItems[] = $reasoningItem;
            }
        }
        $outputTextParts = self::convertOutputContent($assistantMessage['content'] ?? '');
        if ($outputTextParts !== []) {
            $inputItems[] = self::buildMessageItem('assistant', $outputTextParts);
        }
        foreach ((array)($assistantMessage['tool_calls'] ?? []) as $chatToolCall) {
            if (!is_array($chatToolCall)) {
                continue;
            }
            $inputItems[] = [
                'type' => 'function_call',
                'call_id' => (string)($chatToolCall['id'] ?? ''),
                'name' => (string)($chatToolCall['function']['name'] ?? ''),
                'arguments' => (string)($chatToolCall['function']['arguments'] ?? ''),
            ];
        }
        return $inputItems;
    }

    /**
     * @param array<int, array<string, mixed>> $contentParts
     * @return array<string, mixed>
     */
    protected static function buildMessageItem(string $role, array $contentParts): array
    {
        return ['type' => 'message', 'role' => $role, 'content' => $contentParts];
    }

    /**
     * A user / developer content (a string or Chat parts) as `input_text` / `input_image` parts.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function convertInputContent(mixed $content): array
    {
        if (is_string($content)) {
            return [['type' => 'input_text', 'text' => $content]];
        }
        $inputParts = [];
        foreach (is_array($content) ? $content : [] as $contentPart) {
            $inputPart = is_array($contentPart) ? self::convertInputPart($contentPart) : null;
            if ($inputPart !== null) {
                $inputParts[] = $inputPart;
            }
        }
        return $inputParts !== [] ? $inputParts : [['type' => 'input_text', 'text' => '']];
    }

    /**
     * One Chat part as a Responses input part; null for a part type that has no input form.
     *
     * @param array<string, mixed> $contentPart
     * @return array<string, mixed>|null
     */
    public static function convertInputPart(array $contentPart): ?array
    {
        $partType = $contentPart['type'] ?? null;
        if ($partType === 'text' || $partType === 'input_text') {
            $inputPart = ['type' => 'input_text', 'text' => (string)($contentPart['text'] ?? '')];
            if (isset($contentPart[self::PROMPT_CACHE_BREAKPOINT_KEY])) {
                $inputPart[self::PROMPT_CACHE_BREAKPOINT_KEY] = $contentPart[self::PROMPT_CACHE_BREAKPOINT_KEY];
            }
            return $inputPart;
        }
        if ($partType === 'image_url' && isset($contentPart['image_url']['url'])) {
            $inputPart = ['type' => 'input_image', 'image_url' => (string)$contentPart['image_url']['url']];
            if (isset($contentPart['image_url']['detail'])) {
                $inputPart['detail'] = (string)$contentPart['image_url']['detail'];
            }
            return $inputPart;
        }
        if ($partType === 'input_image' && isset($contentPart['image_url'])) {
            return $contentPart;
        }
        return null;
    }

    /**
     * An assistant content as `output_text` parts; empty for an empty text (a tool-calls-only step). A breakpoint marker
     * never rides an assistant part (not probed on Responses; the egress marks the newest non-assistant item instead).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function convertOutputContent(mixed $content): array
    {
        if (is_string($content)) {
            return $content !== '' ? [['type' => 'output_text', 'text' => $content]] : [];
        }
        $outputParts = [];
        foreach (is_array($content) ? $content : [] as $contentPart) {
            $partText = is_array($contentPart) ? (string)($contentPart['text'] ?? '') : '';
            if ($partText !== '') {
                $outputParts[] = ['type' => 'output_text', 'text' => $partText];
            }
        }
        return $outputParts;
    }

    /**
     * A tool result, ALWAYS as the `input_text` / `input_image` array — also a plain-string result. The breakpoint moves
     * on with every call, so the result that carried it on one call is a plain string on the next; one shape for both
     * keeps the item byte-identical (minus the marker key) and the cache entry written at it readable. A string here
     * and an array there made every history read miss (measured).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function convertToolResultContent(mixed $content): array
    {
        return self::convertInputContent($content);
    }
}
