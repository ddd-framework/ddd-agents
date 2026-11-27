<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One page of the drive's change feed (Google `changes.list`): changed items, removed ids, the token to continue
 * (`nextPageToken`) or, on the last page, the token of the next pull (`newStartPageToken`).
 */
class AgentConnectionDriveChanges extends ValueObject
{
    public AgentConnectionDriveItems $changedItems;
    /** @var string[] items removed or no longer visible to the token */
    public array $removedItemIds = [];
    public ?string $nextPageToken = null;
    public ?string $newStartPageToken = null;

    public function __construct()
    {
        $this->changedItems = new AgentConnectionDriveItems();
        parent::__construct();
    }
}
