<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;

/** Conversations whose newest schedule run is the one the test hands in (null = no earlier run). */
class ConversationsServiceWithScheduleRunDouble extends AIConversationsService
{
    public function __construct(protected ?AIConversation $newestScheduleRunConversation)
    {
    }

    public function findNewestScheduleRunConversation(int $scheduleId): ?AIConversation
    {
        return $this->newestScheduleRunConversation;
    }
}
