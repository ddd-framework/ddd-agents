<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\CostSummaries;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The per-conversation cost breakdown of one conversation tree — the root first, then its delegation
 * children. Pure derivations fold over the loaded rows.
 *
 * @property AIConversationCostRow[] $elements
 * @method AIConversationCostRow[] getElements()
 */
class AIConversationCostRows extends ObjectSet
{
    /** The tree's total model spend, summed over its rows. */
    public function totalCostInUSD(): float
    {
        $total = 0.0;
        foreach ($this->getElements() as $aIConversationCostRow) {
            $total += $aIConversationCostRow->costInUSD;
        }
        return round($total, 4);
    }
}
