<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Activity;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The activity lines of one running turn in row order. Keyed by tool-call message id and state, so a
 * finished element replaces its queued twin when both are merged into one set.
 *
 * @property AgentActivityLine[] $elements
 * @method AgentActivityLine[] getElements()
 * @method AgentActivityLine|null first()
 * @method AgentActivityLine getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentActivityLines extends ObjectSet
{
    /** The elements the narrator still owes a line: not skipped, no line yet. */
    public function withoutLine(): static
    {
        $missing = new static();
        foreach ($this->getElements() as $activityLine) {
            if ($activityLine->wantsLine() && $activityLine->line === null) {
                $missing->add($activityLine);
            }
        }
        return $missing;
    }

    /** The narrated sentences in order — what the customer has already read, for the next narrator call. */
    public function linesTold(): array
    {
        $told = [];
        foreach ($this->getElements() as $activityLine) {
            if ($activityLine->line !== null && $activityLine->line !== '') {
                $told[] = $activityLine->line;
            }
        }
        return $told;
    }
}
