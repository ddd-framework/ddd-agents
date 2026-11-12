<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The anomalies of one call or conversation trace ({@see AIConversationTraceAnomaly}).
 *
 * @property AIConversationTraceAnomaly[] $elements
 * @method AIConversationTraceAnomaly[] getElements()
 * @method AIConversationTraceAnomaly|null first()
 */
class AIConversationTraceAnomalies extends ObjectSet
{
}
