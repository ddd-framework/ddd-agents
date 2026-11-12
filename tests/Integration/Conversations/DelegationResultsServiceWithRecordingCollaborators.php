<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationResultsService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;

/**
 * The delegation results service with its own statements untouched and what leaves the database replaced by
 * recorders: the realtime signal, the notification of the parent and the resume of a child that keeps running.
 */
class DelegationResultsServiceWithRecordingCollaborators extends AIConversationDelegationResultsService
{
    public RecordingRealtimeSignalService $recordingRealtimeSignalService;

    public RecordingRunEndNotificationsService $recordingRunEndNotificationsService;

    /** @var int[] The id of every conversation whose resume was asked for, in call order */
    public array $resumedConversationIds = [];

    public function __construct()
    {
        $this->recordingRealtimeSignalService = new RecordingRealtimeSignalService();
        $this->recordingRunEndNotificationsService = new RecordingRunEndNotificationsService();
    }

    protected function realtimeSignalService(): AIConversationRealtimeSignalService
    {
        return $this->recordingRealtimeSignalService;
    }

    protected function delegationNotificationsService(): AIConversationDelegationNotificationsService
    {
        return $this->recordingRunEndNotificationsService;
    }

    protected function resumeConversationAsync(AIConversation $conversation): void
    {
        $this->resumedConversationIds[] = (int)$conversation->id;
    }
}
