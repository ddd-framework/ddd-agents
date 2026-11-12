<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The decisions of a supervisor tandem after a given tandem row, in tandem order ({@see SupervisorCheckpointDecision}).
 *
 * @property SupervisorCheckpointDecision[] $elements
 * @method SupervisorCheckpointDecision[] getElements()
 * @method SupervisorCheckpointDecision|null first()
 * @method SupervisorCheckpointDecision getByUniqueKey(string $uniqueKey)
 */
class SupervisorCheckpointDecisions extends ObjectSet
{
    /** Whether one of the decisions intervenes (carries a signal text) — work the supervised conversation still owes. */
    public function hasIntervention(): bool
    {
        foreach ($this->getElements() as $supervisorCheckpointDecision) {
            if ($supervisorCheckpointDecision->signalText !== null) {
                return true;
            }
        }
        return false;
    }
}
