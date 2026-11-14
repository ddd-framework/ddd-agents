<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The replayed turns of one model ({@see AgentActivityLineModelReplay::$turnReplays}), one per turn, in conversation
 * order.
 *
 * @property AgentActivityLineTurnReplay[] $elements
 * @method AgentActivityLineTurnReplay[] getElements()
 * @method AgentActivityLineTurnReplay|null first()
 * @method AgentActivityLineTurnReplay|null getByUniqueKey(string $uniqueKey)
 */
class AgentActivityLineTurnReplays extends ObjectSet
{
    /**
     * @param int $turnIndex
     * @return AgentActivityLineTurnReplay|null the replay of that turn
     */
    public function getByTurnIndex(int $turnIndex): ?AgentActivityLineTurnReplay
    {
        return $this->getByUniqueKey(AgentActivityLineTurnReplay::uniqueKeyForTurnIndex($turnIndex));
    }
}
