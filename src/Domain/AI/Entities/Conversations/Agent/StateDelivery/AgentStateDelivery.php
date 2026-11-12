<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendOnlyCall;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendedHistoryInsertion;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendedHistoryItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;

/**
 * What one model call of a `stateChangeDelivery = onceWhenChanged` conversation renders of the volatile values: the state epoch, the epoch record the system content and the base-prompt placeholders render from, the
 * deltas the epoch's earlier calls recorded — each placed before the first row above the newest row its call sent (the
 * anchor of {@see AgentAppendOnlyCall::findInsertionMessageIndex()}) — and the deltas THIS call owes, at the end of
 * its history. The wire form is a user entry per position ({@see AgentAppendedHistoryInsertion::$userTexts}), folded by
 * the egress on both skill-loading strategies. Brackets render in the zone in effect at their position
 * ({@see resolveTimezoneIdentifierAt()}). Pure over the context rows and the values the context build gathered.
 *
 * @phpstan-consistent-constructor
 */
class AgentStateDelivery
{
    public AgentStateEpoch $epoch;

    /** The values at the epoch's start — the system content renders these for the whole epoch. */
    public AgentConversationState $record;

    /** What the model has seen before this call: the record with every recorded delta applied. */
    public AgentConversationState $seenBeforeThisCall;

    /** @var AgentStateDelta[][] message index => the recorded deltas placed right before that row, in history order */
    public array $recordedDeltasBeforeMessageIndex = [];

    /** @var AgentStateDelta[] The deltas this call delivers (recorded on its request snapshot). */
    public array $deltasOfThisCall = [];

    /** @var string[] message index => the zone from that row on (a zone delta placed before it), ascending */
    public array $zoneChangesFromMessageIndex = [];

    /**
     * The delivery of a model call about to be sent: the recorded deltas of the epoch, and this call's deltas against
     * what the model has seen (a new epoch's record is the current values, its tail-only values delivered now).
     *
     * @param AIConversationMessage[] $contextMessages
     */
    public static function buildForNewCall(
        AgentStateEpoch $agentStateEpoch,
        array $contextMessages,
        AgentConversationState $currentState
    ): static {
        $agentStateDelivery = new static();
        $agentStateDelivery->epoch = $agentStateEpoch;
        $agentStateDelivery->record = $agentStateEpoch->record ?? $currentState->buildEpochRecord();
        $agentStateDelivery->walkRecordedDeltas($contextMessages, null);
        $agentStateDelivery->deltasOfThisCall = AgentStateChangeDetection::buildDeltasFor(
            $agentStateDelivery->seenBeforeThisCall,
            $currentState,
            $agentStateDelivery->record
        );
        return $agentStateDelivery;
    }

    /**
     * The delivery of a recorded model call, rebuilt from the snapshots alone: its own deltas from its snapshot, the
     * earlier calls' deltas from theirs. Null when the call recorded no state epoch.
     *
     * @param AIConversationMessage[] $contextMessages the history up to and including the recorded call's row
     */
    public static function buildForRecordedCall(array $contextMessages, AIConversationMessage $recordedCallRow): ?static
    {
        $recordedRequestSnapshot = isset($recordedCallRow->requestSnapshot) ? $recordedCallRow->requestSnapshot : null;
        if (!$recordedRequestSnapshot instanceof RequestSnapshot) {
            return null;
        }
        $agentStateEpoch = AgentStateEpoch::readForRecordedCall($contextMessages, $recordedRequestSnapshot);
        if ($agentStateEpoch === null || $agentStateEpoch->record === null) {
            return null;
        }
        $agentStateDelivery = new static();
        $agentStateDelivery->epoch = $agentStateEpoch;
        $agentStateDelivery->record = $agentStateEpoch->record;
        $agentStateDelivery->walkRecordedDeltas($contextMessages, $recordedCallRow);
        $agentStateDelivery->deltasOfThisCall = self::readDeltasOf($recordedRequestSnapshot);
        return $agentStateDelivery;
    }

    /**
     * The deltas the epoch's calls recorded, at their positions, and the zone changes among them; `$skippedRow` (the
     * rebuilt call's own row) delivers its deltas as this call's.
     *
     * @param AIConversationMessage[] $contextMessages
     */
    protected function walkRecordedDeltas(array $contextMessages, ?AIConversationMessage $skippedRow): void
    {
        $seenState = clone $this->record;
        $zoneInEffect = $this->record->timezoneIdentifier;
        foreach ($contextMessages as $contextMessage) {
            if ($contextMessage === $skippedRow || ($contextMessage->indexInConversation ?? -1) < $this->epoch->startIndex
                || !AgentStateEpoch::isModelCallRow($contextMessage)
            ) {
                continue;
            }
            $requestSnapshot = $contextMessage->requestSnapshot;
            if ($requestSnapshot->stateEpochStartIndex !== $this->epoch->startIndex) {
                continue;
            }
            $recordedDeltas = self::readDeltasOf($requestSnapshot);
            if ($recordedDeltas === []) {
                continue;
            }
            $insertionMessageIndex = AgentAppendOnlyCall::findInsertionMessageIndex($contextMessages, $requestSnapshot->appendedAfterMessageIndex, (int)$contextMessage->indexInConversation);
            foreach ($recordedDeltas as $recordedDelta) {
                $this->recordedDeltasBeforeMessageIndex[$insertionMessageIndex][] = $recordedDelta;
                $seenState = $seenState->withDeltaApplied($recordedDelta);
                if ($recordedDelta->kind === AgentStateDelta::KIND_TIME_ZONE && $seenState->timezoneIdentifier !== null && $seenState->timezoneIdentifier !== $zoneInEffect) {
                    $zoneInEffect = $seenState->timezoneIdentifier;
                    $this->zoneChangesFromMessageIndex[$insertionMessageIndex] = $zoneInEffect;
                }
            }
        }
        ksort($this->recordedDeltasBeforeMessageIndex);
        ksort($this->zoneChangesFromMessageIndex);
        $this->seenBeforeThisCall = $seenState;
    }

    /** @return AgentStateDelta[] the deltas a snapshot recorded */
    public static function readDeltasOf(RequestSnapshot $requestSnapshot): array
    {
        $agentStateDeltas = [];
        foreach ($requestSnapshot->stateDeltas ?? [] as $deltaArray) {
            $agentStateDelta = AgentStateDelta::fromArray($deltaArray);
            if ($agentStateDelta !== null) {
                $agentStateDeltas[] = $agentStateDelta;
            }
        }
        return $agentStateDeltas;
    }

    /**
     * Put the deltas onto the history items of the call (the ones the append-only walk built, or new ones under
     * the system-prompt swap): each recorded position gets a user entry after its tools and developer text, this call's
     * deltas join the insertion of this call. Returns the items (null = there is nothing to insert and none existed).
     */
    public function addInsertionsTo(?AgentAppendedHistoryItems $agentAppendedHistoryItems): ?AgentAppendedHistoryItems
    {
        if ($this->recordedDeltasBeforeMessageIndex === [] && $this->deltasOfThisCall === []) {
            return $agentAppendedHistoryItems;
        }
        $agentAppendedHistoryItems ??= new AgentAppendedHistoryItems();
        foreach ($this->recordedDeltasBeforeMessageIndex as $messageIndex => $recordedDeltas) {
            $agentAppendedHistoryItems->addInsertionBeforeMessageIndex((int)$messageIndex, self::buildInsertionOf($recordedDeltas));
        }
        if ($this->deltasOfThisCall !== []) {
            $insertionOfThisCall = $agentAppendedHistoryItems->insertionOfThisCall ?? new AgentAppendedHistoryInsertion();
            $insertionOfThisCall->userTexts = [...$insertionOfThisCall->userTexts, ...array_map(static fn (AgentStateDelta $agentStateDelta): string => $agentStateDelta->text, $this->deltasOfThisCall)];
            $agentAppendedHistoryItems->insertionOfThisCall = $insertionOfThisCall;
        }
        return $agentAppendedHistoryItems;
    }

    /** @param AgentStateDelta[] $agentStateDeltas */
    protected static function buildInsertionOf(array $agentStateDeltas): AgentAppendedHistoryInsertion
    {
        $agentAppendedHistoryInsertion = new AgentAppendedHistoryInsertion();
        $agentAppendedHistoryInsertion->userTexts = array_map(static fn (AgentStateDelta $agentStateDelta): string => $agentStateDelta->text, $agentStateDeltas);
        return $agentAppendedHistoryInsertion;
    }

    /**
     * The zone the bracket of the row with `$messageIndex` renders in: the record's zone, or the zone a recorded zone
     * delta placed at or before that row named. Null = the record has no zone (the caller keeps the call's zone).
     */
    public function resolveTimezoneIdentifierAt(?int $messageIndex): ?string
    {
        $zoneInEffect = $this->record->timezoneIdentifier;
        if ($messageIndex === null) {
            return $zoneInEffect;
        }
        foreach ($this->zoneChangesFromMessageIndex as $changeMessageIndex => $changedZone) {
            if ($messageIndex < $changeMessageIndex) {
                break;
            }
            $zoneInEffect = $changedZone;
        }
        return $zoneInEffect;
    }

    /** @return array<int, array<string, mixed>> this call's deltas in the snapshot form */
    public function buildSnapshotDeltasOfThisCall(): array
    {
        return array_map(static fn (AgentStateDelta $agentStateDelta): array => $agentStateDelta->toArray(), $this->deltasOfThisCall);
    }

    /**
     * Record this call's part on its request snapshot: the epoch (every call), the epoch record and the cause (the
     * epoch's first call), the deltas it delivered and their anchor — the newest row the call sent (`$contextTailIndex`),
     * the same anchor the append-only history items use, so later calls render them right after that row.
     */
    public function recordOnRequestSnapshot(RequestSnapshot $requestSnapshot, ?int $contextTailIndex): void
    {
        $requestSnapshot->stateEpochStartIndex = $this->epoch->startIndex;
        $requestSnapshot->stateEpochCoverFingerprint = $this->epoch->coverFingerprint;
        if ($this->epoch->startsWithThisCall) {
            $requestSnapshot->stateEpochCause = $this->epoch->cause;
            $requestSnapshot->stateAtEpochStart = $this->record->toArray();
        }
        if ($this->deltasOfThisCall !== []) {
            $requestSnapshot->stateDeltas = $this->buildSnapshotDeltasOfThisCall();
            $requestSnapshot->appendedAfterMessageIndex = $contextTailIndex;
        }
    }
}
