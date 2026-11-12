<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;

/** The run-end notifier that writes nothing into a parent and records every run end it is asked to tell. */
class RecordingRunEndNotificationsService extends AIConversationDelegationNotificationsService
{
    /** @var int[][] One `[delegate row id, run number]` pair per call, in call order */
    public array $notifiedRunEnds = [];

    public function notifyParentOfSubAgentRunEnd(int $delegateCallMessageId, int $runNumber): bool
    {
        $this->notifiedRunEnds[] = [$delegateCallMessageId, $runNumber];
        return true;
    }
}
