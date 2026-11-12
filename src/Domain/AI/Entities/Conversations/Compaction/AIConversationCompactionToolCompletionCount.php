<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One entry of {@see AIConversationCompactionToolTelemetry::$toolCompletionCounts}: how many calls of ONE tool
 * COMPLETED inside the covered range. A typed list entry instead of a keyed map so the JSON column stays
 * documentable (the autodocumenter needs `T[]`, never `array<string, int>`).
 */
class AIConversationCompactionToolCompletionCount extends ValueObject
{
    /** @var string The tool name as the model called it (e.g. `updateProfile`). */
    public string $toolName = '';

    /** @var int COMPLETED calls of this tool in the covered range (refused duplicates excluded). */
    public int $completedCount = 0;
}
