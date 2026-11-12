<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The tables of optional components whose zero vectors the embedding repair re-embeds besides the core's own tables.
 * Empty when no such component is installed.
 *
 * @property AgentEmbeddingRepairTarget[] $elements
 * @method AgentEmbeddingRepairTarget|null first()
 * @method AgentEmbeddingRepairTarget[] getElements()
 */
class AgentEmbeddingRepairTargets extends ObjectSet
{
}
