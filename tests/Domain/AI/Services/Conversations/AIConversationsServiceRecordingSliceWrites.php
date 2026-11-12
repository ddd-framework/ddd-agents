<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;

/** Records the slices the service would write instead of writing them. */
class AIConversationsServiceRecordingSliceWrites extends AIConversationsService
{
    /** @var array<int, array<string, mixed>> */
    public array $writtenSlices = [];

    protected function writeAgentWorkingStateSliceUnderRunToken(AIConversation $conversation, array $slice, ?string $runToken): void
    {
        $this->writtenSlices[] = $slice;
    }
}
