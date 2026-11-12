<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\Runs;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The open leaves of one purposed run's task plan, in plan order.
 *
 * @property AIConversationPurposedRunOpenItem[] $elements
 * @method AIConversationPurposedRunOpenItem[] getElements()
 */
class AIConversationPurposedRunOpenItems extends ObjectSet
{
}
