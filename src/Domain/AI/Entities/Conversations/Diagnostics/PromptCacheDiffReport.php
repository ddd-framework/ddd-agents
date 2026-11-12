<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The prompt-cache DIFF of one conversation: for every model call, WHERE its request first differs from
 * the previous call's — the `tools` array, the system content (and its first changed segment), or which wire message —
 * plus the provider's cache credit, the send gap and a CAUSE classification, so the mutations that bust the cache can
 * be counted by class instead of guessed. Every call's light snapshot carries a hash per system segment and one of the
 * tools ({@see RequestSnapshot::$systemSegmentHashes}); the wire-message diff needs the debug payload
 * (`contextRetentionSettings.storeRequestSnapshot`). Pure over the loaded messages (no repo, no model call);
 * a console command of the application renders it.
 *
 * @phpstan-consistent-constructor
 */
class PromptCacheDiffReport extends ValueObject
{
    public const string CAUSE_FIRST_CALL = 'firstCall';
    public const string CAUSE_APPEND = 'append';
    public const string CAUSE_SYSTEM = 'system';
    public const string CAUSE_TOOLS = 'tools';
    public const string CAUSE_OWNER_MESSAGE_REWRITE = 'ownerMessageRewrite';
    public const string CAUSE_RESULT_STUB = 'resultStub';
    public const string CAUSE_ARGUMENT_STUB = 'argumentStub';
    public const string CAUSE_HISTORY_REWRITE = 'historyRewrite';
    /** System and tools unchanged, no payload to diff the wire messages (a call without the debug snapshot). */
    public const string CAUSE_SYSTEM_AND_TOOLS_UNCHANGED = 'systemAndToolsUnchanged';
    /**
     * The top-level tools and the system content unchanged, a new `additional_tools` item appended to the
     * history (an append-only skill load) — the earlier prefix stays cached.
     */
    public const string CAUSE_TOOLS_APPENDED = 'toolsAppended';

    /** @var array<int, array<string, mixed>> one row per snapshot-bearing model call */
    public array $rows = [];

    public int $calls = 0;
    public int $inputTokens = 0;
    public int $cachedInputTokens = 0;
    public int $zeroCreditCalls = 0;
    /** @var array<string, int> cause → number of calls whose first diff has that cause */
    public array $callsByCause = [];
    /** @var array<string, int> cause → cached tokens credited on those calls */
    public array $cachedByCause = [];
    /** @var array<string, int> cause → input tokens of those calls */
    public array $inputByCause = [];

    /** @var array<string, int> state-epoch start cause (`AgentStateEpoch::CAUSE_*`) → number of calls that started an epoch */
    public array $stateEpochStartsByCause = [];

    /** @var array<string, int> state-note kind → number of notes the calls delivered */
    public array $stateNotesByKind = [];

    /** The characters of every state note the calls delivered (each note is sent once, then cached). */
    public int $stateNoteChars = 0;

    /** Cache hit share over every call, 0–100. */
    public function hitPercent(): int
    {
        return $this->inputTokens > 0 ? (int)round(100 * $this->cachedInputTokens / $this->inputTokens) : 0;
    }

    /** The report over a conversation's messages (chronological). */
    public static function fromMessages(AIConversationMessages $messages): static
    {
        $report = new static();
        $previousSnapshot = null;
        $previousRegions = null;
        foreach ($messages->getElements() as $message) {
            $snapshot = isset($message->requestSnapshot) ? $message->requestSnapshot : null;
            if ($snapshot === null) {
                continue;
            }
            $currentRegions = self::regionsOfSnapshot($snapshot);
            if ($currentRegions === null && $snapshot->systemSegmentHashes === null) {
                continue;
            }
            $sentAt = $snapshot->sentAt ?? null;
            $previousSentAt = $previousSnapshot?->sentAt;
            $rawResponse = json_decode((string)($snapshot->rawResponseJson ?? ''), true);
            $provider = is_array($rawResponse) ? ($rawResponse['provider'] ?? null) : null;
            $row = [
                'index' => $message->indexInConversation ?? null,
                'inputTokens' => (int)($message->inputTokens ?? 0),
                'cachedInputTokens' => (int)($message->cachedInputTokens ?? 0),
                'cacheWriteTokens' => (int)($message->cacheWriteTokens ?? 0),
                'provider' => is_string($provider) ? $provider : null,
                'gapSeconds' => ($sentAt !== null && $previousSentAt !== null) ? max(0, $sentAt->getTimestamp() - $previousSentAt->getTimestamp()) : null,
                'toolsChanged' => false,
                'toolsAppended' => false,
                'systemChanged' => false,
                'firstChangedSystemSegment' => null,
                'firstDiff' => null,
                'cause' => self::CAUSE_FIRST_CALL,
                // the state epoch of the call, the cause when it started one, and the notes it delivered
                'stateEpochStartIndex' => $snapshot->stateEpochStartIndex,
                'stateEpochCause' => $snapshot->stateEpochCause,
                'stateNoteKinds' => array_values(array_map(static fn (array $stateDelta): string => (string)($stateDelta['kind'] ?? '?'), $snapshot->stateDeltas ?? [])),
            ];
            if ($previousSnapshot !== null) {
                $row['firstChangedSystemSegment'] = self::firstChangedSystemSegment($previousSnapshot->systemSegmentHashes, $snapshot->systemSegmentHashes);
                $row['toolsChanged'] = $previousSnapshot->toolsHash !== null && $snapshot->toolsHash !== null
                    ? $previousSnapshot->toolsHash !== $snapshot->toolsHash
                    : ($previousRegions !== null && $currentRegions !== null && $previousRegions->tools !== $currentRegions->tools);
                $row['systemChanged'] = $previousSnapshot->systemSegmentHashes !== null && $snapshot->systemSegmentHashes !== null
                    ? $previousSnapshot->systemSegmentHashes !== $snapshot->systemSegmentHashes
                    : ($previousRegions !== null && $currentRegions !== null && $previousRegions->system !== $currentRegions->system);
                if ($previousRegions !== null && $currentRegions !== null) {
                    $firstMessageDifference = self::firstMessageDiff($previousRegions->messages, $currentRegions->messages);
                    $row['firstDiff'] = $firstMessageDifference?->toReportArray();
                    $row['cause'] = self::classify($row['systemChanged'], $row['toolsChanged'], $firstMessageDifference);
                } else {
                    $row['cause'] = $row['systemChanged']
                        ? self::CAUSE_SYSTEM
                        : ($row['toolsChanged'] ? self::CAUSE_TOOLS : self::CAUSE_SYSTEM_AND_TOOLS_UNCHANGED);
                }
                // an appended `additional_tools` item with the system content and the top-level tools
                // unchanged. The first wire difference of the call after a load is usually a CHANGE, not an append: the
                // previous call's input ended on the volatile tail where this call has the load's items
                // — a generic history rewrite there is the append; a named stub or owner rewrite keeps its cause.
                $row['toolsAppended'] = $snapshot->appendedToolsHash !== null && $snapshot->appendedToolsHash !== $previousSnapshot->appendedToolsHash;
                if ($row['toolsAppended'] && !$row['systemChanged'] && !$row['toolsChanged']
                    && in_array($row['cause'], [self::CAUSE_APPEND, self::CAUSE_SYSTEM_AND_TOOLS_UNCHANGED, self::CAUSE_HISTORY_REWRITE], true)
                ) {
                    $row['cause'] = self::CAUSE_TOOLS_APPENDED;
                }
            }
            $report->rows[] = $row;
            $report->calls++;
            $report->inputTokens += $row['inputTokens'];
            $report->cachedInputTokens += $row['cachedInputTokens'];
            if ($row['cachedInputTokens'] === 0) {
                $report->zeroCreditCalls++;
            }
            $report->callsByCause[$row['cause']] = ($report->callsByCause[$row['cause']] ?? 0) + 1;
            $report->cachedByCause[$row['cause']] = ($report->cachedByCause[$row['cause']] ?? 0) + $row['cachedInputTokens'];
            $report->inputByCause[$row['cause']] = ($report->inputByCause[$row['cause']] ?? 0) + $row['inputTokens'];
            if ($snapshot->stateEpochCause !== null) {
                $report->stateEpochStartsByCause[$snapshot->stateEpochCause] = ($report->stateEpochStartsByCause[$snapshot->stateEpochCause] ?? 0) + 1;
            }
            foreach ($snapshot->stateDeltas ?? [] as $stateDelta) {
                $stateNoteKind = (string)($stateDelta['kind'] ?? '?');
                $report->stateNotesByKind[$stateNoteKind] = ($report->stateNotesByKind[$stateNoteKind] ?? 0) + 1;
                $report->stateNoteChars += mb_strlen((string)($stateDelta['text'] ?? ''));
            }
            $previousSnapshot = $snapshot;
            $previousRegions = $currentRegions;
        }
        return $report;
    }

    /**
     * The segment type of the first system segment whose hash differs between two calls (`LOADED_SKILLS`), or of the
     * first one only one of them has; null when they are equal or a call has no hashes.
     *
     * @param string[]|null $previousSystemSegmentHashes
     * @param string[]|null $currentSystemSegmentHashes
     */
    protected static function firstChangedSystemSegment(?array $previousSystemSegmentHashes, ?array $currentSystemSegmentHashes): ?string
    {
        if ($previousSystemSegmentHashes === null || $currentSystemSegmentHashes === null) {
            return null;
        }
        $count = max(count($previousSystemSegmentHashes), count($currentSystemSegmentHashes));
        for ($position = 0; $position < $count; $position++) {
            $previousSegmentHash = $previousSystemSegmentHashes[$position] ?? null;
            $currentSegmentHash = $currentSystemSegmentHashes[$position] ?? null;
            if ($previousSegmentHash !== $currentSegmentHash) {
                return strstr((string)($currentSegmentHash ?? $previousSegmentHash), ':', true) ?: null;
            }
        }
        return null;
    }

    /**
     * The regions of a snapshot's debug payload; null when the call stored none.
     *
     * @param RequestSnapshot $snapshot
     * @return PromptCacheRequestRegions|null
     */
    protected static function regionsOfSnapshot(RequestSnapshot $snapshot): ?PromptCacheRequestRegions
    {
        $sentPayloadJson = $snapshot->sentPayloadJson ?? null;
        if ($sentPayloadJson === null || $sentPayloadJson === '') {
            return null;
        }
        $decoded = json_decode($sentPayloadJson, true);
        $body = is_array($decoded) ? ($decoded['body'] ?? $decoded) : [];
        return self::regionsOf(is_array($body) ? $body : []);
    }

    /**
     * The three cache regions of a sent body: the tools array (as JSON), the system content, the wire messages after it.
     * The system content and every message are compared by their TEXT: a content-parts array of text parts reads as
     * the joined text and a `prompt_cache_breakpoint` marker is dropped — the breakpoint moves to the newest stable
     * message on every call, so the message that carried it last time is not a change.
     *
     * @param array<string, mixed> $body
     * @return PromptCacheRequestRegions
     */
    protected static function regionsOf(array $body): PromptCacheRequestRegions
    {
        // a Responses body carries `input` items — the first `developer` message is the system content
        $wireMessages = is_array($body['messages'] ?? null) ? array_values($body['messages']) : (is_array($body['input'] ?? null) ? array_values($body['input']) : []);
        $system = '';
        if ($wireMessages !== [] && in_array($wireMessages[0]['role'] ?? null, ['system', 'developer'], true)) {
            $systemContent = self::normaliseContent($wireMessages[0]['content'] ?? '');
            $system = is_string($systemContent) ? $systemContent : (string)json_encode($systemContent);
            array_shift($wireMessages);
        }
        foreach ($wireMessages as $position => $wireMessage) {
            if (is_array($wireMessage) && array_key_exists('content', $wireMessage)) {
                $wireMessages[$position]['content'] = self::normaliseContent($wireMessage['content']);
            }
            if (is_array($wireMessage) && is_array($wireMessage['output'] ?? null)) {
                $wireMessages[$position]['output'] = self::normaliseContent($wireMessage['output']);
            }
        }
        return PromptCacheRequestRegions::forToolsSystemAndMessages(
            (string)json_encode($body['tools'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $system,
            $wireMessages
        );
    }

    /** A message content without its cache markers: text parts only → the joined text; other parts keep their shape. */
    protected static function normaliseContent(mixed $content): mixed
    {
        if (!is_array($content)) {
            return $content;
        }
        $contentText = '';
        $isTextOnly = true;
        foreach ($content as $partPosition => $contentPart) {
            if (!is_array($contentPart)) {
                $isTextOnly = false;
                continue;
            }
            unset($content[$partPosition]['prompt_cache_breakpoint']);
            if (in_array($contentPart['type'] ?? null, ['text', 'input_text', 'output_text'], true)) {
                $contentText .= (string)($contentPart['text'] ?? '');
            } else {
                $isTextOnly = false;
            }
        }
        return $isTextOnly ? $contentText : $content;
    }

    /**
     * The first wire message that differs between two calls: its position, role, whether it is an APPEND (the previous
     * call had no message there) or a CHANGE of an existing one, and a short head of the new content.
     *
     * @param list<array<string, mixed>> $previous
     * @param list<array<string, mixed>> $current
     * @return PromptCacheMessageDifference|null null = identical up to the previous length
     */
    protected static function firstMessageDiff(array $previous, array $current): ?PromptCacheMessageDifference
    {
        $count = max(count($previous), count($current));
        for ($position = 0; $position < $count; $position++) {
            $before = $previous[$position] ?? null;
            $after = $current[$position] ?? null;
            if ($before === $after) {
                continue;
            }
            $subject = $after ?? $before ?? [];
            $content = $subject['content'] ?? ($subject['output'] ?? ($subject['tool_calls'][0]['function']['name'] ?? ($subject['name'] ?? '')));
            $head = is_string($content) ? $content : self::buildPartsHead($content);
            return PromptCacheMessageDifference::forPosition(
                $position,
                // a Responses item without a role (function_call / function_call_output) reads as its type
                (string)($subject['role'] ?? ($subject['type'] ?? '?')),
                $before === null
                    ? PromptCacheMessageDifference::KIND_APPEND
                    : ($after === null ? PromptCacheMessageDifference::KIND_REMOVED : PromptCacheMessageDifference::KIND_CHANGE),
                mb_substr(preg_replace('/\s+/', ' ', $head) ?? $head, 0, 80)
            );
        }
        return null;
    }

    /**
     * The CAUSE of a call's first difference — the class the cache measurements count.
     *
     * @param bool $systemChanged
     * @param bool $toolsChanged
     * @param PromptCacheMessageDifference|null $firstMessageDifference
     * @return string
     */
    protected static function classify(bool $systemChanged, bool $toolsChanged, ?PromptCacheMessageDifference $firstMessageDifference): string
    {
        if ($systemChanged) {
            return self::CAUSE_SYSTEM;
        }
        if ($firstMessageDifference === null) {
            return $toolsChanged ? self::CAUSE_TOOLS : self::CAUSE_APPEND;
        }
        if ($firstMessageDifference->isAppend()) {
            return $toolsChanged ? self::CAUSE_TOOLS : self::CAUSE_APPEND;
        }
        $head = $firstMessageDifference->head;
        if (in_array($firstMessageDifference->role, ['tool', 'function_call_output'], true) && str_starts_with($head, '[earlier tool result cleared')) {
            return self::CAUSE_RESULT_STUB;
        }
        if (in_array($firstMessageDifference->role, ['assistant', 'function_call'], true) && str_contains($head, '"superseded"')) {
            return self::CAUSE_ARGUMENT_STUB;
        }
        if ($firstMessageDifference->role === 'user' && !str_starts_with($head, '[SIGNAL')) {
            return self::CAUSE_OWNER_MESSAGE_REWRITE;
        }
        return $toolsChanged ? self::CAUSE_TOOLS : self::CAUSE_HISTORY_REWRITE;
    }

    /**
     * The head text of a parts content (a Responses `input_text` array, also every tool result): the joined part texts,
     * so a stubbed result still reads `[earlier tool result cleared…`; JSON only for parts without text.
     */
    public static function buildPartsHead(mixed $content): string
    {
        $partTexts = [];
        foreach (is_array($content) ? $content : [] as $contentPart) {
            if (is_array($contentPart) && isset($contentPart['text'])) {
                $partTexts[] = (string)$contentPart['text'];
            }
        }
        return $partTexts !== [] ? implode(' ', $partTexts) : (string)json_encode($content, JSON_UNESCAPED_UNICODE);
    }
}
