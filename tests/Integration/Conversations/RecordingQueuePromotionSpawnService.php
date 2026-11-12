<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;

/** The spawn service that admits no queued child and records every tree it is asked to offer freed slots to. */
class RecordingQueuePromotionSpawnService extends AIConversationDelegationSpawnService
{
    /** @var int[] The root conversation id of every call, in call order */
    public array $rootConversationIdsOfferedFreedSlots = [];

    public function promoteQueuedDelegations(int $rootConversationId): array
    {
        $this->rootConversationIdsOfferedFreedSlots[] = $rootConversationId;
        return [];
    }
}
