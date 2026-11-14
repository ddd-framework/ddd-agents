<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;

/** The activity lines of a conversation nobody reads: a step records nothing. */
class ActivityLinesServiceWithoutReadersDouble extends AgentActivityLinesService
{
    public function recordStepForReaders(AIConversation $conversation): void
    {
    }
}
