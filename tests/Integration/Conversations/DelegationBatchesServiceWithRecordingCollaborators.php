<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;

/**
 * The delegation batches service with its own statements untouched and its two collaborators replaced by recorders:
 * the notification of a run end and the admission of queued children belong to other services.
 */
class DelegationBatchesServiceWithRecordingCollaborators extends AIConversationDelegationBatchesService
{
    public RecordingRunEndNotificationsService $recordingRunEndNotificationsService;

    public RecordingQueuePromotionSpawnService $recordingQueuePromotionSpawnService;

    public function __construct()
    {
        $this->recordingRunEndNotificationsService = new RecordingRunEndNotificationsService();
        $this->recordingQueuePromotionSpawnService = new RecordingQueuePromotionSpawnService();
    }

    protected function delegationNotificationsService(): AIConversationDelegationNotificationsService
    {
        return $this->recordingRunEndNotificationsService;
    }

    protected function delegationSpawnService(): AIConversationDelegationSpawnService
    {
        return $this->recordingQueuePromotionSpawnService;
    }
}
