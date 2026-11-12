<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentResponsesServerStateChain;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Arr;

/**
 * The SLIM point-in-time metadata of an assistant turn's model call — the model, the reasoning effort, and the send
 * time. Persisted on {@see AIConversationMessage} (a tiny JSON column). It NO LONGER stores the outgoing payload
 * (system prompt + tools): the whole request is now rebuilt DYNAMICALLY on demand from the immutable message rows +
 * the point-in-time compaction tree + the current prompt/skills/memory (see
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService::reconstructSentPayloadForDisplay()} and
 * {@see AIConversationMessage::buildContextComposition()}). The deliberate trade-off: a rebuilt old
 * context is content-faithful for the immutable rows + the compaction cover, but the system prompt / customer data /
 * memories may have drifted since the turn — so it is "what this message's context looks like NOW", not a byte-exact
 * record of what was sent. The stored `payload` blob (a few KB per assistant message) is gone in exchange.
 *
 * `sentAt` is the ONE thing that cannot be rebuilt and is kept: the forensic time reference for the point-in-time
 * cover — a compaction anchor applies to this message's recomputation iff `compaction.createdAt < sentAt`
 * (the message's own createdTime is stamped post-response and therefore too late).
 */
class RequestSnapshot extends ValueObject
{
    /** @var string|null The model the request was sent to. */
    public ?string $modelName = null;

    /** @var string|null The reasoning effort applied for this request (vendor-normalised), if any. */
    public ?string $reasoningEffort = null;

    /** @var DateTime|null Stamped when the wire call returned — the point-in-time key for the compaction cover (asOf). */
    public ?DateTime $sentAt = null;

    /**
     * @var DateTime|null The instant the model was told is "now" for this call (the clock signal); a
     * historical reconstruction renders THIS, never a fresh now. Null for pre-rollout snapshots.
     */
    public ?DateTime $clockAt = null;

    /**
     * @var string|null The IANA zone the model was told to think in for this call (the space's zone
     * as read for this request, or a schedule-bound run's frozen zone); a historical reconstruction renders THIS,
     * never the space's current zone. Null for pre-rollout snapshots and conversations without a space.
     */
    public ?string $timezoneIdentifier = null;

    /** @var bool|null Whether that zone was a country default the owner had not confirmed. Null when no zone. */
    public ?bool $timezoneUnverified = null;

    /**
     * @var string|null DEBUG capture (only when {@see ContextRetentionSettings::$storeRequestSnapshot} is on) — the
     * FULL outgoing request as JSON (the OpenAI Chat-Completions body: system + messages + `tools` + `tool_choice`),
     * i.e. exactly what we sent the model. Null in the production slim default. This is the data the slim snapshot
     * deliberately drops for O(n²) reasons — re-enabled per-conversation to debug what was actually on the wire.
     */
    public ?string $sentPayloadJson = null;

    /**
     * @var string|null DEBUG capture (same gate) — the RAW model response as JSON (before our parsing into text +
     * tool_calls), so a "model returned empty" can be told apart from "model returned a call we failed to parse". Null
     * in the slim default.
     */
    public ?string $rawResponseJson = null;

    /**
     * @var array<string, int>|null The loop's OWN milliseconds around this model call
     *      (never on the wire): `preCall` (heartbeat, preload, eviction, plan dynamics before the schemas),
     *      `schemas` (the tool schema build), `contextBuild` (the agent context walk + image resolution), `wire`
     *      (the wire composition inside the egress), `egress` (the whole argusLoad incl. the HTTP call — minus `wire`
     *      = HTTP + parse), `snapshot` (this snapshot's JSON encode).
     */
    public ?array $loopStepOverheadMs = null;

    /**
     * @var array<string, string>|null The skill variants — the conversation's resolved situation this call
     *      was rendered with, `dimension => "value(provenance)"` (`walkIn(proposed,record@8)`): a reconstruction
     *      renders THESE sections, the judge trace prints the transitions between calls. Null for pre-rollout
     *      snapshots and purposes without dimensions.
     */
    public ?array $variants = null;

    /**
     * @var string[]|null The conditional skill sections the loaded-skills block of this call contained, skill-prefixed
     *      (`Setup.Profile[businessClass=walkIn]`) — a misapplied instruction is attributable to a section.
     */
    public ?array $skillSections = null;

    /**
     * @var string|null The tool choice this call FORCED: `none` (a tool-less answer
     *      step — the wire tail then carries the `[SIGNAL:answer-step]` line), `required`, or the JSON of a
     *      named-function choice; null = the model chose freely (`auto`). The admin composition view
     *      ({@see AIConversationMessage::buildContextComposition()}) passes it to the one tail list, so the view shows
     *      the answer-step line exactly when the wire carried it. Null on snapshots written before the field existed.
     */
    public ?string $forceToolChoice = null;

    /**
     * @var string[]|null One `<segment type>:<hash>` per system segment of this call, in wire order
     *      (`BASE_PROMPT:9f2c…`, `LOADED_SKILLS:41ab…`) — the cache diff ({@see \DDD\Domain\AI\Entities\Conversations\Diagnostics\PromptCacheDiffReport})
     *      names the first segment that changed against the previous call on every call, without the debug payload.
     *      Null on snapshots written before the field existed.
     */
    public ?array $systemSegmentHashes = null;

    /** @var string|null The hash of the `tools` array this call sent (same use); null before the field existed or without tools. */
    public ?string $toolsHash = null;

    /**
     * @var string|null The wire API this call spoke: `chatCompletions` / `responses`; null on rows stored before
     *      the field existed (= Chat Completions). A reconstruction composes the body in this API's shape.
     */
    public ?string $wireApi = null;

    /** @var string|null The conversation's skill-loading strategy at this call: `systemPromptSwap` / `conversationAppend`. */
    public ?string $skillLoadingStrategy = null;

    /**
     * @var string|null The hash of the `additional_tools` items of this call's history, in history order:
     *      the cache diff names `toolsAppended` when the top-level tools stayed and this hash moved. Null without such items.
     */
    public ?string $appendedToolsHash = null;

    /** @var string|null The fingerprint of the compaction cover this call's prefix epoch was built on. */
    public ?string $epochCoverFingerprint = null;

    /** @var int|null The first message index of this call's prefix epoch (0 or the cover edge + 1). */
    public ?int $epochStartIndex = null;

    /**
     * @var int|null Under `rebuildEpochOnSituationChange`: the first message index of the situation
     *      epoch this call ran in — set by the call whose situation change started it and by every later call of it;
     *      later calls and a reconstruction read the newest call's value. Null = no situation change inside the epoch.
     */
    public ?int $epochSituationChangeStartIndex = null;

    /**
     * @var string|null Which tools this call's epoch carried top-level — `reachableTools` (the purpose's
     *      whole reachable domain tool set, loads append text only) or `loadedSkillTools` (the base set's tools, loads
     *      append an `additional_tools` item); null on a system-prompt-swap call or on rows stored before the field existed.
     */
    public ?string $epochTopLevelToolSet = null;

    /** @var int|null The estimated tokens (chars/4) of the purpose's reachable domain tool set the decision compared. */
    public ?int $epochReachableToolSetTokens = null;

    /** @var int|null The ceiling the reachable tool set was compared with. */
    public ?int $epochReachableToolSetCeilingTokens = null;

    /**
     * @var string[]|null The skills this call APPENDED to the history (their instruction blocks) — every
     *      later call of the epoch renders the same item at its {@see $appendedAfterMessageIndex}, with this call's {@see $variants}.
     */
    public ?array $appendedSkillNames = null;

    /** @var string[]|null The tools this call appended (the `additional_tools` item), same use. */
    public ?array $appendedToolNames = null;

    /** @var bool|null This call appended a `# Situation` entry (the sections for its {@see $variants}). */
    public ?bool $appendedSituationChange = null;

    /**
     * @var string|null The kind of this call's `# Situation` entry — `full` (every cut skill and the cut
     *      purpose directive for {@see $variants}, replacing the earlier entries) or `added` (the cut skills loaded now);
     *      a later call renders the same entry from it at {@see $appendedAfterMessageIndex}. Null = none.
     */
    public ?string $appendedSituationKind = null;

    /** @var string[]|null The skills (and `#purposeDirective`) whose situation-dependent sections this call's entry carries. */
    public ?array $appendedSituationNames = null;

    /**
     * @var int|null The index of the newest history row this call's appended item followed (the call sent
     *      it at the end of its stable history) — every later call renders the item right after that row, before the
     *      first row above it, so a row written in between (a nudge, a bounce, an owner row that landed while the call
     *      ran) never moves it. Null without an appended item; a snapshot without it anchors before its own row.
     */
    public ?int $appendedAfterMessageIndex = null;

    /**
     * @var int|null Under `stateChangeDelivery = onceWhenChanged`: the first message index of the STATE EPOCH this
     *      call ran in ({@see \DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateEpoch}) — a live call
     *      reads the newest call's, a reconstruction this one. Null = the conversation renders every value per call.
     */
    public ?int $stateEpochStartIndex = null;

    /** @var string|null Why this call started a new state epoch (`AgentStateEpoch::CAUSE_*`); null = it continued one. */
    public ?string $stateEpochCause = null;

    /** @var string|null The fingerprint of the compaction cover the state epoch was decided on; null without a cover. */
    public ?string $stateEpochCoverFingerprint = null;

    /**
     * @var array<string, mixed>|null The EPOCH RECORD — the volatile values at the state epoch's start
     *      ({@see \DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentConversationState::toArray()}), stored on
     *      the epoch's first call only; the system content of every call of the epoch renders from it.
     */
    public ?array $stateAtEpochStart = null;

    /**
     * @var array<int, array<string, mixed>>|null The state notes this call delivered (`{kind, text, valueAfter}`,
     *      {@see \DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDelta}) — sent at the end of its
     *      history and re-rendered byte for byte by every later call of the epoch after {@see $appendedAfterMessageIndex}.
     *      Null = none.
     */
    public ?array $stateDeltas = null;

    /**
     * @var string|null The wire API the call's {@see $wireReasoningItemsJson} came from
     *      (`chatCompletions` / `responses`) — a later call sends them back only on the same API.
     */
    public ?string $wireReasoningApi = null;

    /**
     * @var string|null The provider's opaque reasoning items this call returned, as JSON (Responses
     *      `reasoning` items with their encrypted content, or Chat-Completions `reasoning_details`) — sent back in place
     *      on every later call of the conversation while the carry-over is on; ≈ 2–3 KB. Stored on the step's marker
     *      row (this snapshot) instead of a new column (an existing JSON column), hidden from
     *      the API output (size); compaction drops them with the covered rows.
     */
    #[HideProperty]
    public ?string $wireReasoningItemsJson = null;

    /**
     * @var int|null The reasoning tokens the provider reported for this call (Responses
     *      `usage.output_tokens_details.reasoning_tokens`, Chat Completions `usage.completion_tokens_details.reasoning_tokens`),
     *      stored with {@see $wireReasoningItemsJson} — the size the carry-over `capped` sums
     *      ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPreviousReasoningCut::estimateReasoningTokensOfStep()}).
     *      Null without items or when the provider reported none.
     */
    public ?int $wireReasoningTokens = null;

    /**
     * @var int|null Carry-over `capped`: the cut this call sent — the steps whose marker row's index is at
     *      or above it went with their reasoning items, every older step without. The next call starts from it (the
     *      anchor, {@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPreviousReasoningCut}), so the cut
     *      stays where it is until the carried items cross the cap again. Null under every other mode.
     */
    public ?int $previousReasoningCutFromMessageIndex = null;

    /** @var int|null Carry-over `capped`: the reasoning tokens of the items this call sent back (diagnostics). */
    public ?int $previousReasoningSentTokens = null;

    /**
     * @var string|null TEST-ONLY server-state arm: the id of the response OpenAI stored for this
     *      call (`store: true`) — the next call of the conversation continues it with `previous_response_id`. Null on
     *      every other arm and on a failed call.
     */
    public ?string $serverStateResponseId = null;

    /** @var string|null The stored response this call continued; null = it started a fresh chain. */
    public ?string $serverStatePreviousResponseId = null;

    /**
     * @var string|null Why this call started a fresh chain (whole input, no `previous_response_id`) — one of
     *      `AgentResponsesServerStateChain::RESTART_*`; null = it continued the chain.
     */
    public ?string $serverStateChainRestartReason = null;

    /** @var int|null The history entries (system excluded) of this call before its volatile tail — where its output sits next time. */
    public ?int $serverStateStableMessageCount = null;

    /** @var string|null The hash of this call's system content (a change restarts the chain). */
    public ?string $serverStateSystemContentHash = null;

    /** @var string[]|null The call ids of this call's output (the next call checks its history still has them at the recorded position). */
    public ?array $serverStateOutputCallIds = null;

    /** @var int|null The volatile tail entries this call sent. */
    public ?int $serverStateSentTailEntryCount = null;

    /** @var int|null The volatile tail entries OpenAI's stored history carries after this call (they accumulate along a chain). */
    public ?int $serverStateTailEntriesInServerHistory = null;

    /** @var int|null The `input` items this call sent. */
    public ?int $serverStateSentInputItemCount = null;

    /** @var int|null The history entries (system excluded) a stateless call would have sent — the comparison to {@see $serverStateSentInputItemCount}. */
    public ?int $serverStateFullHistoryMessageCount = null;

    /** @var string|null The error OpenAI answered on the chained attempt before this call fell back to a fresh chain. */
    public ?string $serverStateFallbackError = null;

    /**
     * Hydration from a stored or posted form, then the two state-delivery values turned back into plain
     * arrays all the way down: the loader keeps a list's ELEMENTS as the objects it decoded them to, so every stored
     * state note came back as an object — and {@see \DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDelta::fromArray()},
     * the epoch walk and the cache diff read arrays. A loaded snapshot carries the same form a freshly built one does.
     */
    public function setPropertiesFromObject(&$object, $throwErrors = true, bool $rootCall = true, bool $sanitizeInput = false): void
    {
        parent::setPropertiesFromObject($object, $throwErrors, $rootCall, $sanitizeInput);
        $this->stateAtEpochStart = self::buildArrayFormOfStateValue($this->stateAtEpochStart);
        $this->stateDeltas = self::buildArrayFormOfStateValue($this->stateDeltas);
    }

    /**
     * `$stateValue` with every object inside it turned into an array (a loaded note, its `valueAfter`, a task plan's
     * `{key, line}` entries); null stays null.
     *
     * @param array<int|string, mixed>|null $stateValue
     * @return array<int|string, mixed>|null
     */
    public static function buildArrayFormOfStateValue(?array $stateValue): ?array
    {
        return $stateValue === null ? null : Arr::fromObject($stateValue);
    }

    /**
     * The reasoning items to send back with the call's step when the next call speaks `$wireApi`;
     * null when there are none or they belong to the other API.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function findWireReasoningItemsFor(string $wireApi): ?array
    {
        if ($this->wireReasoningApi !== $wireApi || $this->wireReasoningItemsJson === null || $this->wireReasoningItemsJson === '') {
            return null;
        }
        $wireReasoningItems = json_decode($this->wireReasoningItemsJson, true);
        return is_array($wireReasoningItems) && $wireReasoningItems !== [] ? array_values($wireReasoningItems) : null;
    }

    /** Whether this call ran under append-only skill loading: later calls render from its record. */
    public function recordsAppendOnlyCall(): bool
    {
        return $this->skillLoadingStrategy === ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
    }

    /**
     * The snapshot a row stores whose step response was discarded — the loop signal of a stall, a bounce, a retry or a
     * stalled closing step that carries the step's usage: the whole snapshot when the caller
     * keeps the debug capture (`$keepsDebugCapture`, today's opt-in); else, for an append-only call, a copy without the
     * debug capture and without reasoning items (they ride an assistant entry only) — the record of what the call
     * appended, its anchor, its variants and its carry-over cut, so the next call renders the item at the same position — and,
     * for a call of a state epoch (any strategy), the epoch and the state notes it delivered; null otherwise
     * (nothing new on the row under the system-prompt swap).
     */
    public function buildRecordForRowOfDiscardedResponse(bool $keepsDebugCapture): ?self
    {
        if ($keepsDebugCapture) {
            return $this;
        }
        // a call of a state epoch keeps its record too — its epoch and the notes it delivered
        if (!$this->recordsAppendOnlyCall() && $this->stateEpochStartIndex === null) {
            return null;
        }
        $appendOnlyRecord = clone $this;
        $appendOnlyRecord->sentPayloadJson = null;
        $appendOnlyRecord->rawResponseJson = null;
        $appendOnlyRecord->wireReasoningApi = null;
        $appendOnlyRecord->wireReasoningItemsJson = null;
        $appendOnlyRecord->wireReasoningTokens = null;
        return $appendOnlyRecord;
    }

    /** The hash algorithm of {@see $systemSegmentHashes} and {@see $toolsHash}: fast, 16 hex characters. */
    public const string WIRE_HASH_ALGORITHM = 'xxh64';

    /**
     * Record a hash per system segment and one of the tools array (the light per-call diagnostics of the prompt cache).
     *
     * @param AgentContextSegment[] $systemSegments
     * @param array<int, mixed>|null $tools the `tools` array of the sent body
     */
    public function recordSystemSegmentAndToolsHashes(array $systemSegments, ?array $tools): void
    {
        $this->systemSegmentHashes = array_values(array_map(
            static fn (AgentContextSegment $systemSegment): string => $systemSegment->type->value . ':' . hash(self::WIRE_HASH_ALGORITHM, $systemSegment->text),
            $systemSegments
        ));
        $this->toolsHash = self::hashToolsArray($tools);
    }

    /**
     * The hash of a `tools` array as {@see $toolsHash} records it; null without tools.
     *
     * @param array<int, mixed>|null $tools
     */
    public static function hashToolsArray(?array $tools): ?string
    {
        return $tools !== null && $tools !== []
            ? hash(self::WIRE_HASH_ALGORITHM, (string)json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            : null;
    }

    /**
     * Record the server-state chain of this call (the server-state arm): the stored response id, the link it
     * continued or why it restarted, where its output sits in the history, and the tail counts.
     *
     * @param string[] $outputCallIds
     */
    public function recordResponsesServerStateChain(
        AgentResponsesServerStateChain $agentResponsesServerStateChain,
        ?string $responseId,
        array $outputCallIds,
        int $sentInputItemCount,
        ?string $fallbackError
    ): void {
        $this->serverStateResponseId = $responseId;
        $this->serverStatePreviousResponseId = $agentResponsesServerStateChain->previousResponseId;
        $this->serverStateChainRestartReason = $agentResponsesServerStateChain->restartReason;
        $this->serverStateStableMessageCount = $agentResponsesServerStateChain->stableMessageCount;
        $this->serverStateSystemContentHash = $agentResponsesServerStateChain->systemContentHash;
        $this->serverStateOutputCallIds = array_values($outputCallIds);
        $this->serverStateSentTailEntryCount = $agentResponsesServerStateChain->sentTailEntryCount;
        $this->serverStateTailEntriesInServerHistory = $agentResponsesServerStateChain->tailEntriesInServerHistory;
        $this->serverStateSentInputItemCount = $sentInputItemCount;
        $this->serverStateFullHistoryMessageCount = $agentResponsesServerStateChain->fullHistoryMessageCount;
        $this->serverStateFallbackError = $fallbackError;
    }

    /**
     * Record the hash of the `additional_tools` items of a Responses body's `input` ({@see $appendedToolsHash}); null
     * when the input carries none (every Chat-Completions call).
     *
     * @param array<int, mixed>|null $inputItems the `input` array of the sent body
     */
    public function recordAppendedToolsHash(?array $inputItems): void
    {
        $appendedToolsItems = [];
        foreach ($inputItems ?? [] as $inputItem) {
            if (is_array($inputItem) && ($inputItem['type'] ?? null) === 'additional_tools') {
                $appendedToolsItems[] = $inputItem;
            }
        }
        $this->appendedToolsHash = $appendedToolsItems !== []
            ? hash(self::WIRE_HASH_ALGORITHM, (string)json_encode($appendedToolsItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            : null;
    }

    /**
     * Record the tool choice the egress sent ({@see $forceToolChoice}): a string choice as it is, a named-function
     * choice as its JSON.
     *
     * @param string|array<string, mixed>|null $forceToolChoice
     */
    public function recordForceToolChoice(string|array|null $forceToolChoice): void
    {
        $this->forceToolChoice = is_array($forceToolChoice)
            ? (json_encode($forceToolChoice, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null)
            : $forceToolChoice;
    }

    /** @var string[] Lower-cased header names whose value is a credential. */
    public const array SECRET_HEADER_NAMES = ['authorization', 'x-litellm-api-key', 'x-api-key', 'api-key'];

    /** @var string[] Body keys whose value is a credential. */
    public const array SECRET_BODY_KEYS = ['api_key'];

    /**
     * Replace every image `data:` URI in an outgoing request payload with a short byte-count marker before the debug
     * snapshot is JSON-encoded — the snapshot must not persist base64 image bytes into the database
     * (the byte-persistence the design forbids, reached through the one debug setting a browser session turns on).
     * Walks `messages[].content[]` and redacts any `{type:image_url, image_url:{url:data:…}}` part in place; every
     * other payload shape passes through untouched. Pure over a copy.
     *
     * @param mixed $requestPayload the OpenAI request payload ({@see ArgusAIAgentConversation::$lastRequestPayload})
     */
    public static function redactImagePayload(mixed $requestPayload): mixed
    {
        if (!is_array($requestPayload)) {
            return $requestPayload;
        }
        // the egress payload nests the body: `{body: {messages | input}, headers}` (the Responses `input` too)
        if (isset($requestPayload['body']) && is_array($requestPayload['body'])) {
            $requestPayload['body'] = self::redactImagePayload($requestPayload['body']);
            return $requestPayload;
        }
        if (isset($requestPayload['input']) && is_array($requestPayload['input'])) {
            $requestPayload['input'] = self::redactResponsesInputImages($requestPayload['input']);
        }
        if (!isset($requestPayload['messages']) || !is_array($requestPayload['messages'])) {
            return $requestPayload;
        }
        foreach ($requestPayload['messages'] as $messageIndex => $message) {
            if (!is_array($message) || !isset($message['content']) || !is_array($message['content'])) {
                continue;
            }
            foreach ($message['content'] as $partIndex => $part) {
                if (is_array($part)
                    && ($part['type'] ?? null) === 'image_url'
                    && isset($part['image_url']['url'])
                    && is_string($part['image_url']['url'])
                    && str_starts_with($part['image_url']['url'], 'data:')
                ) {
                    $byteLength = strlen($part['image_url']['url']);
                    $requestPayload['messages'][$messageIndex]['content'][$partIndex]['image_url']['url']
                        = '[image data-URI redacted — ' . $byteLength . ' bytes, not persisted]';
                }
            }
        }
        return $requestPayload;
    }

    /**
     * The Responses `input` items with every `input_image` data URI (a message part or a function-call-output part)
     * replaced by the byte-count marker of {@see redactImagePayload()}.
     *
     * @param array<int, mixed> $inputItems
     * @return array<int, mixed>
     */
    public static function redactResponsesInputImages(array $inputItems): array
    {
        foreach ($inputItems as $itemIndex => $inputItem) {
            if (!is_array($inputItem)) {
                continue;
            }
            foreach (['content', 'output'] as $partsKey) {
                if (!isset($inputItem[$partsKey]) || !is_array($inputItem[$partsKey])) {
                    continue;
                }
                foreach ($inputItem[$partsKey] as $partIndex => $part) {
                    if (is_array($part) && ($part['type'] ?? null) === 'input_image'
                        && is_string($part['image_url'] ?? null) && str_starts_with($part['image_url'], 'data:')
                    ) {
                        $inputItems[$itemIndex][$partsKey][$partIndex]['image_url']
                            = '[image data-URI redacted — ' . strlen($part['image_url']) . ' bytes, not persisted]';
                    }
                }
            }
        }
        return $inputItems;
    }

    /**
     * The outgoing payload with every credential shortened to a recognisable stub (`Bearer sk-or-v1-…a1b2`) — the
     * debug capture must show WHICH key was sent, never the key itself. Covers the three auth shapes the egress writes
     * (`headers.Authorization`, `headers.x-litellm-api-key`, `body.api_key`). Pure over a copy.
     *
     * @param array<string, mixed> $requestPayload
     * @return array<string, mixed>
     */
    public static function shortenSecretsInPayload(array $requestPayload): array
    {
        if (isset($requestPayload['headers']) && is_array($requestPayload['headers'])) {
            foreach ($requestPayload['headers'] as $headerName => $headerValue) {
                if (is_string($headerValue) && in_array(strtolower((string)$headerName), self::SECRET_HEADER_NAMES, true)) {
                    $requestPayload['headers'][$headerName] = self::shortenSecret($headerValue);
                }
            }
        }
        if (isset($requestPayload['body']) && is_array($requestPayload['body'])) {
            foreach (self::SECRET_BODY_KEYS as $secretBodyKey) {
                if (isset($requestPayload['body'][$secretBodyKey]) && is_string($requestPayload['body'][$secretBodyKey])) {
                    $requestPayload['body'][$secretBodyKey] = self::shortenSecret($requestPayload['body'][$secretBodyKey]);
                }
            }
        }

        return $requestPayload;
    }

    /** `Bearer sk-or-v1-abcdef…xyz9` → `Bearer sk-or-v1-…xyz9`; a short value becomes `…`. The auth scheme stays readable. */
    public static function shortenSecret(string $secretValue): string
    {
        $authScheme = '';
        if (preg_match('/^(Bearer|Basic|Token)\s+/i', $secretValue, $schemeMatch) === 1) {
            $authScheme = $schemeMatch[0];
            $secretValue = substr($secretValue, strlen($authScheme));
        }
        if (strlen($secretValue) <= 16) {
            return $authScheme . '…';
        }

        return $authScheme . substr($secretValue, 0, 9) . '…' . substr($secretValue, -4);
    }
}
