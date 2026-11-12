<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one supervisor checkpoint decided: the beat it ran as and the signal text of its intervention (null = none).
 *
 * @phpstan-consistent-constructor
 */
class SupervisorCheckpointOutcome extends ValueObject
{
    /** The beat of the checkpoint ({@see SupervisorCheckpointBriefingHeader::BEAT_STEP} / `BEAT_SETTLED`). */
    public string $beat = SupervisorCheckpointBriefingHeader::BEAT_SETTLED;

    /** The text the supervised agent reads; null = no intervention. */
    public ?string $signalText = null;

    public static function create(string $beat, ?string $signalText): static
    {
        $supervisorCheckpointOutcome = new static();
        $supervisorCheckpointOutcome->beat = $beat;
        $supervisorCheckpointOutcome->signalText = $signalText;
        return $supervisorCheckpointOutcome;
    }
}
