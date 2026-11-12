<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the get-or-create of a purposed conversation did
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::startOrResumePurposedConversation()}): the
 * conversation, and whether it is the existing singleton that was resumed — then a kickoff appendix the caller passed
 * was NOT delivered.
 *
 * @phpstan-consistent-constructor
 */
class AgentPurposedConversationStart extends ValueObject
{
    /** The purposed conversation — freshly started, or the existing singleton. */
    public AIConversation $conversation;

    /** True when the existing singleton was returned and nothing was started (the kickoff appendix was not delivered). */
    public bool $resumedExisting = false;

    /** A fresh conversation was started off the purpose's kickoff signal. */
    public static function started(AIConversation $conversation): static
    {
        $agentPurposedConversationStart = new static();
        $agentPurposedConversationStart->conversation = $conversation;
        return $agentPurposedConversationStart;
    }

    /** The existing singleton was returned; nothing was started. */
    public static function resumedExisting(AIConversation $conversation): static
    {
        $agentPurposedConversationStart = new static();
        $agentPurposedConversationStart->conversation = $conversation;
        $agentPurposedConversationStart->resumedExisting = true;
        return $agentPurposedConversationStart;
    }
}
