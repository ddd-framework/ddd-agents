<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCalls;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One model turn's OUTPUT, transient — replaces performTurn's return array: the assistant text, the
 * turn's parallel {@see ToolCalls} (turn-scoped, not yet emitted as messages), and the usage/provenance. The INPUT
 * counterpart is the persisted {@see RequestSnapshot}.
 *
 * ValueObject, not Entity: no identity, never persisted (same rationale as {@see AgentModelInput}).
 */
class ModelResponse extends ValueObject
{
    /** The assistant's natural-language text this turn (may be empty alongside tool calls, or be the final answer). */
    public ?string $text = null;

    /** The parallel tool calls the model asked for this turn; null/empty = a pure text turn (or a stall). */
    public ?ToolCalls $toolCalls = null;

    /** The model that produced this turn (per-message model attribution; mid-conversation switches stay traceable). */
    public ?string $modelName = null;

    public ?int $inputTokens = null;
    public ?int $outputTokens = null;
    public ?int $webSearchCount = null;
    public ?float $costInUSD = null;

    /** OpenRouter cache usage this turn: tokens served from cache, tokens written to cache, and the $ discount. */
    public ?int $cachedInputTokens = null;
    public ?int $cacheWriteTokens = null;
    public ?float $cacheDiscount = null;

    /** The frozen forensic record of the EXACT request that produced this turn (attached to the assistant message). */
    public ?RequestSnapshot $requestSnapshot = null;

    /**
     * The FIXED overhead of this call's prompt: the system content without the compaction summary plus
     * the offered tool schemas, chars/4 — the part of the prompt compaction cannot shrink. Stamped on the row that
     * carries the usage; the compaction budget reads the newest stamp instead of the earliest measured input.
     */
    public ?int $fixedOverheadTokens = null;

    /**
     * The provider/proxy ERROR body when the model call FAILED (e.g. a Google 400 INVALID_ARGUMENT on a malformed tool
     * schema) instead of returning a completion. Non-null = the call failed — NOT an empty/stalled turn; the loop must
     * fail FAST (persist the error, FAILED, no stall-recovery nudge). Null on a normal response.
     */
    public ?string $providerError = null;

    /**
     * All fields optional so a caller assembles the turn output in one expression (no field-by-field assignment).
     * {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService::performModelCall()} populates every field from the egress conversation's getters.
     */
    public function __construct(
        ?string $text = null,
        ?ToolCalls $toolCalls = null,
        ?string $modelName = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $webSearchCount = null,
        ?float $costInUSD = null,
        ?int $cachedInputTokens = null,
        ?int $cacheWriteTokens = null,
        ?float $cacheDiscount = null,
        ?RequestSnapshot $requestSnapshot = null,
        ?string $providerError = null,
        ?int $fixedOverheadTokens = null,
    ) {
        parent::__construct();
        $this->providerError = $providerError;
        $this->fixedOverheadTokens = $fixedOverheadTokens;
        $this->text = $text;
        $this->toolCalls = $toolCalls;
        $this->modelName = $modelName;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->webSearchCount = $webSearchCount;
        $this->costInUSD = $costInUSD;
        $this->cachedInputTokens = $cachedInputTokens;
        $this->cacheWriteTokens = $cacheWriteTokens;
        $this->cacheDiscount = $cacheDiscount;
        $this->requestSnapshot = $requestSnapshot;
    }

    /** Whether the model asked to call at least one tool this turn (vs a pure text turn / an empty stall). */
    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== null && !$this->toolCalls->isEmpty();
    }

    /**
     * The text the owner would SEE: the answer without its fenced ``` blocks — a ```widget``` block renders as a component,
     * not as prose, so a text that is only such a block shows the owner nothing.
     */
    public function getVisibleProse(): string
    {
        return trim(preg_replace('/```.*?```/s', '', (string)($this->text ?? '')) ?? '');
    }

    /** The EMPTY stall: no tool call and no visible prose (the loop's stall classification). */
    public function emitsNoOutput(): bool
    {
        return !$this->hasToolCalls() && $this->getVisibleProse() === '';
    }

    /**
     * Whether the visible prose carries the model's own "I am about to call" protocol token — the {preCallMessage}
     * sentinel, or the MANGLED-CALL variant where the whole call leaked as text (`"preCallMessage":` in JSON-field form,
     * a `<|im_call:` serializer token). Deterministic protocol artifacts, never natural reply prose: with no
     * tool call made they mark a model-DECLARED stall.
     */
    public function visibleProseCarriesPreCallToken(): bool
    {
        $visibleProse = $this->getVisibleProse();
        return str_contains($visibleProse, AIConversationMessage::PRE_CALL_MESSAGE_MARKER)
            || str_contains($visibleProse, '"preCallMessage"')
            || str_contains($visibleProse, '<|im_call:');
    }

    /**
     * Whether the visible prose is the MANGLED-CALL variant of {@see visibleProseCarriesPreCallToken()}: the call itself
     * leaked as text (`"preCallMessage":` in JSON-field form, a `<|im_call:` serializer token). Never a
     * reply the owner may read, whatever widget the turn carries — the stall path discards it.
     */
    public function visibleProseCarriesLeakedToolCall(): bool
    {
        $visibleProse = $this->getVisibleProse();
        return str_contains($visibleProse, '"preCallMessage"') || str_contains($visibleProse, '<|im_call:');
    }
}
