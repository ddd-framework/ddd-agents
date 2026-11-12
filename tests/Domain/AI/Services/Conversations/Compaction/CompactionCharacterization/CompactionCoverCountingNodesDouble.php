<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionCover;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A node set that counts its {@see buildCover()} calls (the "double was hit" evidence of the supervisor cover
 * telemetry, which reads the already-loaded tree through exactly this method) and otherwise answers as the real set.
 */
class CompactionCoverCountingNodesDouble extends AIConversationCompactionNodes
{
    /** @var array<int, int|string> the asOf timestamp ('now' for null) of every buildCover() call, in order */
    public array $buildCoverCalls = [];

    public function buildCover(?DateTime $asOf = null): AIConversationCompactionCover
    {
        $this->buildCoverCalls[] = $asOf?->getTimestamp() ?? 'now';
        return parent::buildCover($asOf);
    }
}
