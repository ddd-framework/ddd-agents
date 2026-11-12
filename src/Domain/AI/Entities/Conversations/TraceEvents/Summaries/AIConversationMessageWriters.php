<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The writers of a page of conversation rows, one per row ({@see AIConversationMessageWriter}).
 *
 * @property AIConversationMessageWriter[] $elements
 * @method AIConversationMessageWriter[] getElements()
 * @method AIConversationMessageWriter|null first()
 */
class AIConversationMessageWriters extends ObjectSet
{
}
