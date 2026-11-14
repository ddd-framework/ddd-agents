<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/**
 * The messages service of the tool-call child: every lease is claimed, and the outcome the child writes is kept in
 * memory instead of being stored.
 */
class ToolCallOutcomeRecordingMessagesServiceDouble extends AIConversationMessagesService
{
    /** @var AIConversationMessage[] The tool call rows whose outcome the child wrote, in order */
    public array $writtenOutcomeMessages = [];

    public function claimToolCallExecutionLease(int $messageId, string $lease): bool
    {
        return true;
    }

    public function writeToolCallOutcomeIfLeaseHeld(AIConversationMessage $message, string $lease): bool
    {
        $this->writtenOutcomeMessages[] = $message;
        return true;
    }
}
