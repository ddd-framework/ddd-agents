<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * ONE conversation's cost/size totals for the agent control panel's top-conversations breakdown:
 * the whole tree's spend (delegation children INCLUDED via rootConversationId, so a fork-join's cost lands on
 * the customer conversation that caused it), message volume and when it started — the "which conversations are
 * expensive/long" ranking, each row linkable into the admin inspector. Element of {@see AgentConversationStats}.
 */
class AgentConversationStat extends ValueObject
{
    /** @var int The ROOT conversation's id (the admin inspector target). */
    public int $conversationId;

    /** @var int The owning account. */
    public int $accountId;

    /** @var int|null The id of the space the conversation works for; null = a conversation at account level (no space). */
    public ?int $agentSpaceId = null;

    /** @var int Messages in the conversation tree (root + delegation children). */
    public int $messageCount;

    /** @var int Input + output tokens summed over the tree. */
    public int $totalTokens;

    /** @var float Cost in USD summed over the tree. */
    public float $totalCostInUSD;

    /** @var DateTime|null When the root conversation started. */
    public ?DateTime $created = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->conversationId ?? 0);
    }
}
