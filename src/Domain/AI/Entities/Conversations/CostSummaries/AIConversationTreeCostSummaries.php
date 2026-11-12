<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\CostSummaries;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Tree cost summaries for a set of root conversations. Keyed lookup folds over the loaded elements.
 *
 * @property AIConversationTreeCostSummary[] $elements
 * @method AIConversationTreeCostSummary[] getElements()
 */
class AIConversationTreeCostSummaries extends ObjectSet
{
    /** The tree total for one root conversation, or null when it is not in this projection. */
    public function getForRootConversationId(int $rootConversationId): ?AIConversationTreeCostSummary
    {
        foreach ($this->getElements() as $aIConversationTreeCostSummary) {
            if ($aIConversationTreeCostSummary->rootConversationId === $rootConversationId) {
                return $aIConversationTreeCostSummary;
            }
        }
        return null;
    }
}
