<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;

/**
 * Where the carry-over `capped` cuts the previous steps' reasoning items for one model call. Pure, no repository contact; built by the egress over the rows of the call's context.
 *
 * The rule:
 * 1. The ANCHOR is the cut the newest earlier call recorded on its request snapshot
 *    ({@see RequestSnapshot::$previousReasoningCutFromMessageIndex}); none in the context (the first call, or the rows
 *    of the recording call are behind a compaction cover) = message index 0, every step.
 * 2. The steps that send items are the step-marker rows at or above the anchor whose snapshot holds reasoning items of
 *    this call's wire API. Their size is summed in reasoning tokens ({@see estimateReasoningTokensOfStep()}).
 * 3. Sum at or below the cap: the anchor stays the cut — the same reasoning items as on the previous call.
 * 4. Sum above the cap and more than {@see KEPT_NEWEST_STEPS_AT_A_CUT} steps: the cut moves to the marker index of the
 *    oldest kept step — every older step loses its items IN ONE BLOCK, the newest {@see KEPT_NEWEST_STEPS_AT_A_CUT}
 *    steps keep theirs. The call records the new cut, so every later call renders the same cut until the next crossing
 *    (a sawtooth: the carried block grows from the kept steps up to the cap, then drops back).
 *
 * The cut is a message INDEX, never a wire position: rows written later (a signal, an owner message) never move it, so
 * it holds within one long turn and across turns alike, and on both wire APIs (the items ride the same assistant
 * entries). A call whose snapshot is lost (a discarded response under the system-prompt swap) leaves the anchor of the
 * call before it; the next call derives the same cut from that anchor, since the same rows hold the same items.
 * When the two kept steps alone exceed the cap, every further step moves the cut by one step (each call a partial
 * cache miss) — at the measured 300–650 reasoning tokens per step and the 4,000 default that needs > 2,000 per step.
 *
 * @phpstan-consistent-constructor
 */
class AgentPreviousReasoningCut
{
    /**
     * The steps whose items survive a cut. Two: the step that issued the calls whose results the model reads now and
     * the one before it — the reasoning the model's current plan rests on. Keeping none is fast, but in a measured comparison
     * its pass rate fell to 10/19; keeping more shortens the time to the next crossing: at the
     * measured 300–650 reasoning tokens per step, two steps restart the block at ≈ 0.6–1.3k of the 4k cap, so a cut is
     * followed by about 5–10 steps with a stable prefix. A constant, not a setting: the cap is the one knob.
     */
    public const int KEPT_NEWEST_STEPS_AT_A_CUT = 2;

    /**
     * The size estimate of a step's items when its snapshot names no reasoning-token count (a snapshot written before
     * the count was recorded): the JSON of the items has a fixed part of ≈ 1,600 bytes (id, type, the encryption
     * envelope) and ≈ 13 bytes per reasoning token — a linear fit over the 36 reasoning items of the replay probe
     * (37 tokens → 1,892 bytes, 258 tokens → 5,012 bytes).
     */
    public const int ESTIMATED_REASONING_ITEMS_JSON_OVERHEAD_BYTES = 1600;

    /** See {@see ESTIMATED_REASONING_ITEMS_JSON_OVERHEAD_BYTES}. */
    public const int ESTIMATED_REASONING_ITEMS_JSON_BYTES_PER_TOKEN = 13;

    /** @var int The steps whose marker row's index is at or above this send their items; every older step sends none. */
    public int $cutFromMessageIndex = 0;

    /** @var int|null The cut the newest earlier call recorded (the anchor); null = none in the context. */
    public ?int $anchorCutFromMessageIndex = null;

    /** @var bool Whether this call moved the cut (the sum since the anchor crossed the cap). */
    public bool $movedOnThisCall = false;

    /** @var int The reasoning tokens of the items this call sends back (the steps at or above the cut). */
    public int $sentReasoningTokens = 0;

    /** @var int The cap the decision compared with. */
    public int $capTokens = 0;

    /**
     * The cut of a call on `$wireApi` over the rows of its context (oldest first), with the cap `$capTokens`.
     *
     * @param AIConversationMessage[] $messages
     */
    public static function decideFor(array $messages, string $wireApi, int $capTokens): static
    {
        $agentPreviousReasoningCut = new static();
        $agentPreviousReasoningCut->capTokens = $capTokens;
        $agentPreviousReasoningCut->anchorCutFromMessageIndex = self::findRecordedCutOfTheNewestEarlierCall($messages);
        $anchorCutFromMessageIndex = $agentPreviousReasoningCut->anchorCutFromMessageIndex ?? 0;
        /** @var int[] $reasoningTokensByStepMarkerIndex the steps at or above the anchor, oldest first */
        $reasoningTokensByStepMarkerIndex = [];
        foreach ($messages as $message) {
            $messageIndex = $message->indexInConversation ?? null;
            if ($messageIndex === null || $messageIndex < $anchorCutFromMessageIndex || !self::isStepMarkerRowSentWithItsReasoning($message)) {
                continue;
            }
            $stepReasoningTokens = self::estimateReasoningTokensOfStep($message->requestSnapshot, $wireApi);
            if ($stepReasoningTokens !== null) {
                $reasoningTokensByStepMarkerIndex[$messageIndex] = $stepReasoningTokens;
            }
        }
        $agentPreviousReasoningCut->cutFromMessageIndex = $anchorCutFromMessageIndex;
        $agentPreviousReasoningCut->sentReasoningTokens = array_sum($reasoningTokensByStepMarkerIndex);
        if ($agentPreviousReasoningCut->sentReasoningTokens > $capTokens && count($reasoningTokensByStepMarkerIndex) > self::KEPT_NEWEST_STEPS_AT_A_CUT) {
            $keptReasoningTokensByStepMarkerIndex = array_slice($reasoningTokensByStepMarkerIndex, -self::KEPT_NEWEST_STEPS_AT_A_CUT, null, true);
            $agentPreviousReasoningCut->cutFromMessageIndex = (int)array_key_first($keptReasoningTokensByStepMarkerIndex);
            $agentPreviousReasoningCut->sentReasoningTokens = array_sum($keptReasoningTokensByStepMarkerIndex);
            $agentPreviousReasoningCut->movedOnThisCall = true;
        }
        return $agentPreviousReasoningCut;
    }

    /** Whether the step whose marker row has `$stepMarkerMessageIndex` sends its items on this call. */
    public function sendsTheItemsOfTheStepAt(?int $stepMarkerMessageIndex): bool
    {
        return $stepMarkerMessageIndex !== null && $stepMarkerMessageIndex >= $this->cutFromMessageIndex;
    }

    /**
     * The cut the newest earlier call of the context recorded, scanning from the newest row: a step marker, or the loop
     * signal that carries the record of a discarded response; null when no row of the context carries one.
     *
     * @param AIConversationMessage[] $messages
     */
    public static function findRecordedCutOfTheNewestEarlierCall(array $messages): ?int
    {
        for ($position = count($messages) - 1; $position >= 0; $position--) {
            $requestSnapshot = isset($messages[$position]->requestSnapshot) ? $messages[$position]->requestSnapshot : null;
            if ($requestSnapshot?->previousReasoningCutFromMessageIndex !== null) {
                return $requestSnapshot->previousReasoningCutFromMessageIndex;
            }
        }
        return null;
    }

    /**
     * Whether `$message` is a step marker the egress sends with its reasoning items: the first tool-call row of a step or
     * an assistant row on the wire, stamped with the step's usage (`inputTokens > 0`) and carrying a snapshot — the rows
     * {@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation::withReasoningItemsOfStep()} reads (a
     * signal and the rows the egress never sends carry no items there).
     */
    public static function isStepMarkerRowSentWithItsReasoning(AIConversationMessage $message): bool
    {
        if (($message->inputTokens ?? 0) <= 0 || !isset($message->requestSnapshot)) {
            return false;
        }
        $messageType = $message->messageType ?? null;
        if ($messageType === AIConversationMessage::MESSAGE_TOOL_CALL) {
            return true;
        }
        if (in_array($messageType, self::MESSAGE_TYPES_SENT_WITHOUT_REASONING_ITEMS, true)) {
            return false;
        }
        return strtolower($message->role ?? '') === 'assistant';
    }

    /**
     * The message types that never ride the wire as an assistant entry: the signal (a user entry) and the egress's
     * non-wire rows ({@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation::NON_WIRE_MESSAGE_TYPES}).
     */
    public const array MESSAGE_TYPES_SENT_WITHOUT_REASONING_ITEMS = [
        AIConversationMessage::MESSAGE_SIGNAL,
        AIConversationMessage::MESSAGE_CHECKPOINT,
        AIConversationMessage::MESSAGE_ERROR,
        AIConversationMessage::MESSAGE_TOOL_MESSAGE,
    ];

    /**
     * The size of one step's reasoning items for a call on `$wireApi`, in reasoning tokens: the count the provider
     * reported for that call ({@see RequestSnapshot::$wireReasoningTokens}), else the estimate from the items' JSON
     * length ({@see ESTIMATED_REASONING_ITEMS_JSON_OVERHEAD_BYTES}); null when the step holds no items of that API.
     */
    public static function estimateReasoningTokensOfStep(RequestSnapshot $requestSnapshot, string $wireApi): ?int
    {
        if ($requestSnapshot->wireReasoningApi !== $wireApi || $requestSnapshot->wireReasoningItemsJson === null || $requestSnapshot->wireReasoningItemsJson === '') {
            return null;
        }
        if ($requestSnapshot->wireReasoningTokens !== null) {
            return max(0, $requestSnapshot->wireReasoningTokens);
        }
        $itemsJsonBytes = strlen($requestSnapshot->wireReasoningItemsJson);
        return max(0, intdiv($itemsJsonBytes - self::ESTIMATED_REASONING_ITEMS_JSON_OVERHEAD_BYTES + self::ESTIMATED_REASONING_ITEMS_JSON_BYTES_PER_TOKEN - 1, self::ESTIMATED_REASONING_ITEMS_JSON_BYTES_PER_TOKEN));
    }
}
