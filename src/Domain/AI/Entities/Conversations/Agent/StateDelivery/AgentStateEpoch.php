<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The STATE EPOCH a model call runs in: from its start index on, the system content renders
 * the epoch record and every change reaches the model as a recorded delta. A new epoch starts at the conversation start,
 * after a new compaction cover, when the newest model call is {@see COLD_AFTER_MINUTES} minutes old or more (its prefix
 * is cold anyway) and when the surface changed (who reads the call on which device — the base prompt's `[surface=…]`
 * sections and the kit are rendered per surface). A new epoch starts after the newest model-call row (its start = the
 * later of the cover epoch's start and that row + 1), so no item an earlier epoch recorded renders in it.
 *
 * Every call records its epoch on its request snapshot ({@see RequestSnapshot::$stateEpochStartIndex}); a live call reads
 * the newest call's, a point-in-time rebuild its own — never re-derived from timestamps. Pure.
 *
 * @phpstan-consistent-constructor
 */
class AgentStateEpoch
{
    public const string CAUSE_CONVERSATION_START = 'conversationStart';
    public const string CAUSE_COMPACTION = 'compaction';
    public const string CAUSE_COLD_PREFIX = 'coldPrefix';
    public const string CAUSE_SURFACE_CHANGE = 'surfaceChange';
    public const string CAUSE_RECORD_MISSING = 'recordMissing';

    /** A model call this many minutes after the newest one starts a new epoch: its prefix is cold. */
    public const int COLD_AFTER_MINUTES = 30;

    /** @var int The first message index of the epoch. */
    public int $startIndex = 0;

    /** Whether the call this epoch was decided for starts it (it then records the epoch record). */
    public bool $startsWithThisCall = false;

    /** @var string|null Why the call starts a new epoch (a CAUSE_* constant); null when it continues one. */
    public ?string $cause = null;

    /** @var string|null The fingerprint of the compaction cover the epoch was decided on; null without a cover. */
    public ?string $coverFingerprint = null;

    /** @var AgentConversationState|null The epoch record of a continued epoch (from its first call); null for a new one. */
    public ?AgentConversationState $record = null;

    /** Whether this is the epoch a recorded call ran in, read from its own snapshot (a point-in-time rebuild). */
    public bool $isOfARecordedCall = false;

    /**
     * The epoch of a model call about to be sent: the newest model call's epoch, continued, unless a start rule fires.
     *
     * @param AIConversationMessage[] $contextMessages the raw history of the call (the rows after the compaction cover)
     * @param int $coverEpochStartIndex the first raw index: 0, or the cover edge + 1
     */
    public static function decideForNewCall(array $contextMessages, ?string $coverFingerprint, int $coverEpochStartIndex, DateTime $now, ?string $currentSurfaceKey): static
    {
        $newestModelCallRow = self::findNewestModelCallRow($contextMessages);
        if ($newestModelCallRow === null) {
            return self::startNew($coverEpochStartIndex, $coverEpochStartIndex > 0 ? self::CAUSE_COMPACTION : self::CAUSE_CONVERSATION_START, $coverFingerprint);
        }
        $newEpochStartIndex = max($coverEpochStartIndex, (int)($newestModelCallRow->indexInConversation ?? -1) + 1);
        $newestRequestSnapshot = $newestModelCallRow->requestSnapshot;
        $recordedStartIndex = $newestRequestSnapshot->stateEpochStartIndex;
        if ($recordedStartIndex === null || $recordedStartIndex < $coverEpochStartIndex) {
            return self::startNew($newEpochStartIndex, $recordedStartIndex === null ? self::CAUSE_RECORD_MISSING : self::CAUSE_COMPACTION, $coverFingerprint);
        }
        if ($newestRequestSnapshot->stateEpochCoverFingerprint !== $coverFingerprint) {
            return self::startNew($newEpochStartIndex, self::CAUSE_COMPACTION, $coverFingerprint);
        }
        $newestSentAt = $newestRequestSnapshot->sentAt;
        if ($newestSentAt === null || $now->getTimestamp() - $newestSentAt->getTimestamp() >= self::COLD_AFTER_MINUTES * 60) {
            return self::startNew($newEpochStartIndex, self::CAUSE_COLD_PREFIX, $coverFingerprint);
        }
        $epochRecord = self::findRecordOfEpoch($contextMessages, $recordedStartIndex);
        if ($epochRecord === null) {
            return self::startNew($newEpochStartIndex, self::CAUSE_RECORD_MISSING, $coverFingerprint);
        }
        if ($epochRecord->surfaceKey !== $currentSurfaceKey) {
            return self::startNew($newEpochStartIndex, self::CAUSE_SURFACE_CHANGE, $coverFingerprint);
        }
        $agentStateEpoch = new static();
        $agentStateEpoch->startIndex = $recordedStartIndex;
        $agentStateEpoch->coverFingerprint = $coverFingerprint;
        $agentStateEpoch->record = $epochRecord;
        return $agentStateEpoch;
    }

    /**
     * The epoch a recorded model call ran in, read from its own snapshot — the epoch record from the epoch's first call
     * (the rebuilt call itself when it started the epoch). Null when the snapshot records no state epoch or the record
     * is not in the context.
     *
     * @param AIConversationMessage[] $contextMessages the history up to and including the recorded call's row
     */
    public static function readForRecordedCall(array $contextMessages, RequestSnapshot $recordedRequestSnapshot): ?static
    {
        $recordedStartIndex = $recordedRequestSnapshot->stateEpochStartIndex;
        if ($recordedStartIndex === null) {
            return null;
        }
        $epochRecord = self::findRecordOfEpoch($contextMessages, $recordedStartIndex);
        if ($epochRecord === null) {
            return null;
        }
        $agentStateEpoch = new static();
        $agentStateEpoch->startIndex = $recordedStartIndex;
        $agentStateEpoch->coverFingerprint = $recordedRequestSnapshot->stateEpochCoverFingerprint;
        $agentStateEpoch->cause = $recordedRequestSnapshot->stateEpochCause;
        $agentStateEpoch->record = $epochRecord;
        $agentStateEpoch->isOfARecordedCall = true;
        return $agentStateEpoch;
    }

    protected static function startNew(int $startIndex, string $cause, ?string $coverFingerprint): static
    {
        $agentStateEpoch = new static();
        $agentStateEpoch->startIndex = $startIndex;
        $agentStateEpoch->startsWithThisCall = true;
        $agentStateEpoch->cause = $cause;
        $agentStateEpoch->coverFingerprint = $coverFingerprint;
        return $agentStateEpoch;
    }

    /**
     * The newest row that carries a model call's request snapshot (a call's first output row, `inputTokens > 0`).
     *
     * @param AIConversationMessage[] $contextMessages
     */
    public static function findNewestModelCallRow(array $contextMessages): ?AIConversationMessage
    {
        for ($messagePosition = count($contextMessages) - 1; $messagePosition >= 0; $messagePosition--) {
            if (self::isModelCallRow($contextMessages[$messagePosition])) {
                return $contextMessages[$messagePosition];
            }
        }
        return null;
    }

    /** Whether `$message` is a model call's first output row with its request snapshot. */
    public static function isModelCallRow(AIConversationMessage $message): bool
    {
        return ($message->inputTokens ?? 0) > 0 && isset($message->requestSnapshot);
    }

    /**
     * The epoch record the first call of the epoch starting at `$startIndex` stored; null when no row of the context holds it.
     *
     * @param AIConversationMessage[] $contextMessages
     */
    public static function findRecordOfEpoch(array $contextMessages, int $startIndex): ?AgentConversationState
    {
        foreach ($contextMessages as $contextMessage) {
            if (($contextMessage->indexInConversation ?? -1) < $startIndex || !self::isModelCallRow($contextMessage)) {
                continue;
            }
            $requestSnapshot = $contextMessage->requestSnapshot;
            if ($requestSnapshot->stateEpochStartIndex === $startIndex && is_array($requestSnapshot->stateAtEpochStart)) {
                return AgentConversationState::fromArray($requestSnapshot->stateAtEpochStart);
            }
        }
        return null;
    }
}
