<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The timing measurements of one turn invocation — a plain mutable runtime object, never
 * persisted or serialized: the turn-start phases, the current step's pre-call and schema milliseconds, and the
 * persist breakdowns carried from one tool call to the next. Its values only ever reach telemetry (the turn-end event,
 * a call's loop overhead, the request-snapshot stamps), never a decision.
 */
class AIConversationTurnTimings
{
    /**
     * @var array<string, int|float|array<string, int>|null>|null The turn-start phases in milliseconds, in order:
     *      `handlerLoad` (job received → resume entered), `claim`, `agentSpaceLoad`, `messagesLoad`, `prepare`,
     *      `systemPrompt`, `preFirstStep`; `preFirstStepParts` splits the last phases by the work done in them (the
     *      decision inputs, the decision batch, its application, the signals, …); plus the job's and the owner row's
     *      wall-clock origins. Written onto the turn-end event payload as `turnStartMs`. Reset per resume.
     */
    public ?array $turnStartStamps = null;

    /** @var float|null The hrtime the previous turn-start stamp was taken at. */
    public ?float $turnStartStampLastHr = null;

    /** @var float|null The hrtime the previous pre-first-step part (or phase) stamp was taken at. */
    public ?float $preFirstStepPartStampLastHr = null;

    /** @var int|null The current step's loop milliseconds before the schema build — for the request-snapshot stamps. */
    public ?int $currentStepPreCallMs = null;

    /** @var int|null The current step's tool-schema build milliseconds — for the request-snapshot stamps. */
    public ?int $currentStepSchemasMs = null;

    /**
     * @var array<string, int|bool>|null The phase breakdown of the most recent message persist: `claim`, `row` (the
     *      new-row allocation under the index lock, or the update of an existing row — `newRow` tells which), `embed`,
     *      `signal` with its `firebase` round trip and `a2aCount` COUNT. Milliseconds.
     */
    public ?array $lastPersistBreakdownMs = null;

    /** @var int|null The result-persist milliseconds of the previous tool call — stamped onto the NEXT call's loop overhead. */
    public ?int $previousToolCallPersistAfterMs = null;

    /** @var array<string, int|bool>|null The breakdown of that previous result persist, carried beside it. */
    public ?array $previousToolCallPersistAfterBreakdownMs = null;

    /** Turn start: no persist of a previous turn (or a previous chunk) may land on this turn's first call. */
    public function resetPersistAfterCarry(): void
    {
        $this->previousToolCallPersistAfterMs = null;
        $this->previousToolCallPersistAfterBreakdownMs = null;
    }

    /**
     * Merge the carried result-persist stamp of the PREVIOUS tool call into this call's loop overhead and clear the
     * carry — one landing per stamp, on every call (forked ones included).
     *
     * @param array<string, mixed> $loopOverheadMs
     * @return array<string, mixed>
     */
    public function mergePreviousPersistAfterIntoLoopOverhead(array $loopOverheadMs): array
    {
        if ($this->previousToolCallPersistAfterMs !== null) {
            $loopOverheadMs['previousPersistAfter'] = $this->previousToolCallPersistAfterMs;
            if ($this->previousToolCallPersistAfterBreakdownMs !== null) {
                $loopOverheadMs['previousPersistAfterBreakdown'] = $this->previousToolCallPersistAfterBreakdownMs;
            }
        }
        $this->resetPersistAfterCarry();
        return $loopOverheadMs;
    }

    /**
     * Stamp the result persist of a tool call onto its (already written) row in memory and carry it for the next call;
     * the turn's LAST call has no successor, so its carry rides the turn-end event.
     *
     * @param int|float $persistStartedAt the hrtime(true) stamp taken right before the result persist
     */
    public function stampToolCallPersistAfter(ToolCall $toolCall, int|float $persistStartedAt): void
    {
        $persistAfterMs = (int)round((hrtime(true) - $persistStartedAt) / 1e6);
        $toolCall->loopOverheadMs['persistAfter'] = $persistAfterMs;
        if ($this->lastPersistBreakdownMs !== null) {
            $toolCall->loopOverheadMs['persistAfterBreakdown'] = $this->lastPersistBreakdownMs;
        }
        $this->previousToolCallPersistAfterMs = $persistAfterMs;
        $this->previousToolCallPersistAfterBreakdownMs = $this->lastPersistBreakdownMs;
    }

    /**
     * The turn-start phases for the turn-end event: the stamped milliseconds plus `ownerMessageToJob` (the owner row's
     * created time → the job received, DB clock against the worker clock, so ±1 s), and — when the dispatching process
     * stamped the saved owner row in microseconds — `ownerRowToSend` and `ownerRowToJob` without that second bias. Null
     * when no resume stamped this turn.
     *
     * @return array<string, int|array<string, int>|null>|null
     */
    public function buildTurnStartStampsForEvent(mixed $initiatorCreated): ?array
    {
        if ($this->turnStartStamps === null) {
            return null;
        }
        $stamps = $this->turnStartStamps;
        $jobReceivedWallUs = $stamps['jobReceivedWallUs'] ?? null;
        $dispatchedAtWallUs = $stamps['dispatchedAtWallUs'] ?? null;
        $ownerRowSavedWallUs = $stamps['ownerRowSavedWallUs'] ?? null;
        unset($stamps['jobReceivedWallUs'], $stamps['dispatchedAtWallUs'], $stamps['ownerRowSavedWallUs']);
        $initiatorCreatedUs = $initiatorCreated instanceof DateTime ? (float)$initiatorCreated->format('U.u') : null;
        $stamps['ownerMessageToJob'] = ($jobReceivedWallUs !== null && $initiatorCreatedUs !== null)
            ? (int)round(((float)$jobReceivedWallUs - $initiatorCreatedUs) * 1000)
            : null;
        // the API-side split: the dispatching process's clock right before the transport send (same host as the
        // worker on a dev workspace; on prod the API and the worker hosts' clocks are NTP-aligned to the millisecond)
        $stamps['apiAppendToSend'] = ($dispatchedAtWallUs !== null && $initiatorCreatedUs !== null)
            ? (int)round(((float)$dispatchedAtWallUs - $initiatorCreatedUs) * 1000)
            : null;
        $stamps['sendToJob'] = ($dispatchedAtWallUs !== null && $jobReceivedWallUs !== null)
            ? (int)round(((float)$jobReceivedWallUs - (float)$dispatchedAtWallUs) * 1000)
            : null;
        // the same two gaps from the microsecond owner-row stamp (one clock, the API's, for the send side)
        $stamps['ownerRowToSend'] = ($dispatchedAtWallUs !== null && $ownerRowSavedWallUs !== null)
            ? (int)round(((float)$dispatchedAtWallUs - (float)$ownerRowSavedWallUs) * 1000)
            : null;
        $stamps['ownerRowToJob'] = ($jobReceivedWallUs !== null && $ownerRowSavedWallUs !== null)
            ? (int)round(((float)$jobReceivedWallUs - (float)$ownerRowSavedWallUs) * 1000)
            : null;
        return $stamps;
    }
}
