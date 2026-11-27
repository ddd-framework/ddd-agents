<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The messages of one page of an earlier conversation, oldest first.
 *
 * @property AgentConversationRecallMessage[] $elements
 * @method AgentConversationRecallMessage[] getElements()
 * @method AgentConversationRecallMessage|null first()
 * @method AgentConversationRecallMessage getByUniqueKey(string $uniqueKey)
 */
class AgentConversationRecallMessages extends ObjectSet
{
}
