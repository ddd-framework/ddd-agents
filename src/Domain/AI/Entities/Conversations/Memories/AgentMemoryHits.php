<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The memories a similarity search found, most similar first. Empty when no memory component is installed.
 *
 * @property AgentMemoryHit[] $elements
 * @method AgentMemoryHit|null first()
 * @method AgentMemoryHit[] getElements()
 */
class AgentMemoryHits extends ObjectSet
{
}
