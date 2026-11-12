<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfigMemo;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;

/** The realtime signal service that publishes nothing and records the status of every change signal it is asked for. */
class RecordingRealtimeSignalService extends AIConversationRealtimeSignalService
{
    /** @var string[] The run status of every change signal, in call order */
    public array $publishedRunStatuses = [];

    public function publishChangeSignal(AIConversation $conversation, string $runStatus, ?AIConversationTaskPushNotificationConfigMemo $pushConfigMemo = null): void
    {
        $this->publishedRunStatuses[] = $runStatus;
    }
}
