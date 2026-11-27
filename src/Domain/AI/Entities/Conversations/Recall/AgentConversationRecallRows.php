<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The conversations a recall search found, best first.
 *
 * @property AgentConversationRecallRow[] $elements
 * @method AgentConversationRecallRow[] getElements()
 * @method AgentConversationRecallRow|null first()
 * @method AgentConversationRecallRow getByUniqueKey(string $uniqueKey)
 */
class AgentConversationRecallRows extends ObjectSet
{
}
