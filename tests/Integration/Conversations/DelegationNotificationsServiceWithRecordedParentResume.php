<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationRunEndNotification;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use RuntimeException;

/**
 * The run-end notifier with its writes untouched and what leaves the database replaced by recorders: the resume of
 * the parent and the realtime signal. On request the write fails after the notification row was written, as a lost
 * connection or a refused statement would end it.
 */
class DelegationNotificationsServiceWithRecordedParentResume extends AIConversationDelegationNotificationsService
{
    public RecordingRealtimeSignalService $recordingRealtimeSignalService;

    /** @var int[] The id of every parent whose resume was dispatched, in call order */
    public array $resumedParentConversationIds = [];

    /** Whether the write throws after the compare-and-set and the notification row were written */
    public bool $failsAfterTheNotificationRowWasWritten = false;

    public function __construct()
    {
        $this->recordingRealtimeSignalService = new RecordingRealtimeSignalService();
    }

    protected function writeNotificationRowIfUnreported(AIConversation $parentConversation, int $delegateCallMessageId, int $runNumber): ?DelegationRunEndNotification
    {
        $notification = parent::writeNotificationRowIfUnreported($parentConversation, $delegateCallMessageId, $runNumber);
        if ($this->failsAfterTheNotificationRowWasWritten) {
            throw new RuntimeException('The write failed after the notification row was written.');
        }
        return $notification;
    }

    protected function dispatchParentResume(int $parentConversationId): void
    {
        $this->resumedParentConversationIds[] = $parentConversationId;
    }

    protected function realtimeSignalService(): AIConversationRealtimeSignalService
    {
        return $this->recordingRealtimeSignalService;
    }
}
