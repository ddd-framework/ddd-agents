<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/** The result of the agent's search over the owner's earlier conversations */
class AgentConversationRecallResult extends ValueObject
{
    /** @var string The query searched for; empty = the most recent conversations */
    public string $query = '';

    /** @var AgentConversationRecallRows The conversations found, best first */
    public AgentConversationRecallRows $rows;

    /** @var int Conversations found but left out because their readers are narrower than this conversation's */
    public int $leftOutForAudienceCount = 0;

    /** @var bool Whether others read the current conversation (a shared space) */
    public bool $currentConversationIsShared = false;

    public function __construct()
    {
        parent::__construct();
        $this->rows = new AgentConversationRecallRows();
    }
}
