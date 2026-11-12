<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The agent control panel's top-conversations breakdown: one {@see AgentConversationStat} per ROOT
 * conversation over the selected range, costliest first (limit applied in the query). Built by
 * `DBAIConversation::findTopConversationStats()`.
 *
 * @property AgentConversationStat[] $elements
 * @method AgentConversationStat[] getElements()
 * @method AgentConversationStat|null first()
 * @method AgentConversationStat getByUniqueKey(string $uniqueKey)
 */
class AgentConversationStats extends ObjectSet
{
}
