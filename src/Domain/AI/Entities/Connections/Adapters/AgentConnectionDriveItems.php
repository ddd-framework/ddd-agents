<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A page of {@see AgentConnectionDriveItem}; `nextPageToken` continues it.
 *
 * @property AgentConnectionDriveItem[] $elements
 * @method AgentConnectionDriveItem[] getElements()
 * @method AgentConnectionDriveItem|null first()
 */
class AgentConnectionDriveItems extends ObjectSet
{
    public ?string $nextPageToken = null;
}
