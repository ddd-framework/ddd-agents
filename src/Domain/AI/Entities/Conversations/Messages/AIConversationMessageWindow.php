<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A char-bounded slice of a conversation in two parts, never the whole conversation: the new messages to work on
 * ({@see $delta}, oldest first) and the read-only messages before them ({@see $earlierContext}, chronological) that give
 * the delta its meaning. Assembled from already-loaded messages; no repo, no persistence. The memory curator reads it
 * (an application's curation window extends it).
 */
class AIConversationMessageWindow extends ValueObject
{
    /** @var AIConversationMessages|null The new messages to work on (char-bounded, oldest first); empty when there is nothing new. */
    public ?AIConversationMessages $delta = null;

    /** @var AIConversationMessages|null The read-only earlier messages (char-bounded, chronological); null when none precede the delta. */
    public ?AIConversationMessages $earlierContext = null;

    /**
     * True when the delta holds at least one message. Pure.
     *
     * @return bool
     */
    public function hasDelta(): bool
    {
        return $this->delta !== null && !$this->delta->isEmpty();
    }
}
