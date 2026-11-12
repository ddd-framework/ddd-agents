<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a drive of a conversation runs on (formerly resolved inline in
 * `AIConversationAgentService::driveConversation()`): the conversation it continues (null: it starts a new one), the
 * space the conversation works for (none: account level) and the account the drive runs as. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::findConversationToDrive()}.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationDriveTarget extends ValueObject
{
    /** The conversation the drive continues; null when it starts a new one. */
    public ?AIConversation $existingConversation = null;

    /** The space of the conversation: the existing conversation's own, else the requested one; null = account level. */
    public ?AgentSpace $agentSpace = null;

    /** The account the drive runs as (an admin drive impersonates it). */
    public Account $account;

    public static function of(?AIConversation $existingConversation, ?AgentSpace $agentSpace, Account $account): static
    {
        $driveTarget = new static();
        $driveTarget->existingConversation = $existingConversation;
        $driveTarget->agentSpace = $agentSpace;
        $driveTarget->account = $account;
        return $driveTarget;
    }
}
