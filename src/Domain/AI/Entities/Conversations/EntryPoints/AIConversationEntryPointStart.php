<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\EntryPoints;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a start from a product entry point did
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::startOrReopenConversationAtEntryPoint()}): the
 * conversation, and whether it is the recent one that entry point had already opened — then nothing was appended and
 * no turn was started.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEntryPointStart extends ValueObject
{
    /** The conversation the entry point opened — freshly started, or the recent one reopened. */
    public AIConversation $conversation;

    /** True when the recent conversation of the entry point was returned and nothing was started. */
    public bool $reopenedExisting = false;

    /** A new conversation was started and stamped with the entry point's key. */
    public static function started(AIConversation $conversation): static
    {
        $aIConversationEntryPointStart = new static();
        $aIConversationEntryPointStart->conversation = $conversation;
        return $aIConversationEntryPointStart;
    }

    /** The recent conversation of the entry point was returned; nothing was appended or started. */
    public static function reopened(AIConversation $conversation): static
    {
        $aIConversationEntryPointStart = new static();
        $aIConversationEntryPointStart->conversation = $conversation;
        $aIConversationEntryPointStart->reopenedExisting = true;
        return $aIConversationEntryPointStart;
    }
}
