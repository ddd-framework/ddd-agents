<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionToolTelemetry;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The beat a supervisor tandem OWES its supervised conversation: the beat class, its reason (written on the briefing header), the supervised index
 * the last checkpoint covered and the newest supervised index. {@see buildOwed()} decides it from the supervised rows
 * and the tandem's history; the two reason builders decide whether a range of rows carries material at all.
 *
 * @phpstan-consistent-constructor
 */
class SupervisorCheckpointTrigger extends ValueObject
{
    /** Step beats per owner turn — beyond it the settled beat covers the rest. */
    public const int MAX_STEP_BEATS_PER_TURN = 8;

    /** The beat class ({@see SupervisorCheckpointBriefingHeader::BEAT_STEP} / `BEAT_SETTLED`). */
    public string $beat = SupervisorCheckpointBriefingHeader::BEAT_SETTLED;

    /** Why the beat is owed (`step beat: 1 write(s) in this batch`, `settled beat: 1 owner row(s), …`). */
    public string $reason = '';

    /** The supervised index the last checkpoint covered (-1 without one) — the briefing's delta starts after it. */
    public int $coveredMessageIndex = -1;

    /** The newest supervised index — the briefing covers up to it. */
    public int $newestMessageIndex = -1;

    public static function create(string $beat, string $reason, int $coveredMessageIndex, int $newestMessageIndex): static
    {
        $supervisorCheckpointTrigger = new static();
        $supervisorCheckpointTrigger->beat = $beat;
        $supervisorCheckpointTrigger->reason = $reason;
        $supervisorCheckpointTrigger->coveredMessageIndex = $coveredMessageIndex;
        $supervisorCheckpointTrigger->newestMessageIndex = $newestMessageIndex;
        return $supervisorCheckpointTrigger;
    }

    /**
     * The beat the tandem owes, or null: rows past its covered index carry material ({@see buildStepBeatReason()} /
     * {@see buildSettledBeatReason()} for the supervised conversation's current beat class) and the beat's cap is not
     * reached — the settled decisions against `$runCap`, the step beats of the current owner turn against
     * {@see MAX_STEP_BEATS_PER_TURN}. A run cap of zero owes nothing.
     */
    public static function buildOwed(SupervisorCheckpointHistory $tandemHistory, AIConversation $supervisedConversation, int $runCap): ?self
    {
        if ($runCap <= 0) {
            return null;
        }
        $coveredMessageIndex = $tandemHistory->getNewestCoveredMessageIndex();
        $newestMessageIndex = $supervisedConversation->messages->getNextIndex() - 1;
        if ($newestMessageIndex <= $coveredMessageIndex) {
            return null;
        }
        $beat = $supervisedConversation->getSupervisorBeat();
        $reason = $beat === SupervisorCheckpointBriefingHeader::BEAT_SETTLED
            ? self::buildSettledBeatReason($supervisedConversation->messages, $coveredMessageIndex)
            : self::buildStepBeatReason($supervisedConversation->messages, $coveredMessageIndex);
        if ($reason === null) {
            return null;
        }
        if ($beat === SupervisorCheckpointBriefingHeader::BEAT_SETTLED && $tandemHistory->countSettledDecisions() >= $runCap) {
            return null;
        }
        if ($beat === SupervisorCheckpointBriefingHeader::BEAT_STEP
            && $tandemHistory->countStepBeatsSinceMessageIndex($supervisedConversation->messages->getNewestInitiatorMessageIndex() ?? 0) >= self::MAX_STEP_BEATS_PER_TURN
        ) {
            return null;
        }
        return self::create($beat, $reason, $coveredMessageIndex, $newestMessageIndex);
    }

    /**
     * STEP BEAT material after the tool batch persisted above `$highestIndexBeforeStep`: a completed write, a failed or
     * rejected call, a rendered widget. The reason, or null without material.
     */
    public static function buildStepBeatReason(AIConversationMessages $supervisedMessages, int $highestIndexBeforeStep): ?string
    {
        $writeCount = 0;
        $failedCallCount = 0;
        $widgetCount = 0;
        foreach ($supervisedMessages->getElements() as $supervisedMessage) {
            if (($supervisedMessage->indexInConversation ?? -1) <= $highestIndexBeforeStep) {
                continue;
            }
            if (($supervisedMessage->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_MESSAGE) {
                $widgetCount++;
                continue;
            }
            $toolCall = $supervisedMessage->toolCall ?? null;
            if ($toolCall === null || ($toolCall->name ?? '') === '') {
                continue;
            }
            if (AIConversationCompactionToolTelemetry::isCountedWrite($toolCall)) {
                $writeCount++;
            } elseif ($toolCall->isFailedOrRejected()) {
                $failedCallCount++;
            }
        }
        if ($writeCount === 0 && $failedCallCount === 0 && $widgetCount === 0) {
            return null;
        }
        $reasonParts = [];
        if ($writeCount > 0) {
            $reasonParts[] = "{$writeCount} write(s)";
        }
        if ($failedCallCount > 0) {
            $reasonParts[] = "{$failedCallCount} failed call(s)";
        }
        if ($widgetCount > 0) {
            $reasonParts[] = "{$widgetCount} widget(s)";
        }
        return 'step beat: ' . implode(', ', $reasonParts) . ' in this batch';
    }

    /**
     * SETTLED BEAT material: the interaction since `$sinceMessageIndex` carried an owner row, a write, a failed call, a
     * widget or a final text. The reason, or null without material.
     */
    public static function buildSettledBeatReason(AIConversationMessages $supervisedMessages, int $sinceMessageIndex): ?string
    {
        $ownerRowCount = 0;
        $writeCount = 0;
        $failedCallCount = 0;
        $widgetCount = 0;
        $finalTextCount = 0;
        foreach ($supervisedMessages->getElements() as $supervisedMessage) {
            if (($supervisedMessage->indexInConversation ?? -1) <= $sinceMessageIndex) {
                continue;
            }
            if ($supervisedMessage->isOwnerAuthoredInbound()) {
                $ownerRowCount++;
                continue;
            }
            if (($supervisedMessage->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_MESSAGE) {
                $widgetCount++;
            } elseif ($supervisedMessage->isAssistantText() && trim((string)($supervisedMessage->message ?? '')) !== '') {
                $finalTextCount++;
            }
            $toolCall = $supervisedMessage->toolCall ?? null;
            if ($toolCall !== null && ($toolCall->name ?? '') !== '') {
                if (AIConversationCompactionToolTelemetry::isCountedWrite($toolCall)) {
                    $writeCount++;
                } elseif ($toolCall->isFailedOrRejected()) {
                    $failedCallCount++;
                }
            }
        }
        $reasonParts = [];
        foreach (['owner row' => $ownerRowCount, 'write' => $writeCount, 'failed call' => $failedCallCount, 'widget' => $widgetCount, 'final text' => $finalTextCount] as $materialLabel => $materialCount) {
            if ($materialCount > 0) {
                $reasonParts[] = "{$materialCount} {$materialLabel}(s)";
            }
        }
        return $reasonParts !== [] ? 'settled beat: ' . implode(', ', $reasonParts) : null;
    }
}
