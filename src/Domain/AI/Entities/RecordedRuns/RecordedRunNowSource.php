<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

use DDD\Infrastructure\Base\DateTime\DateTime;
use LogicException;

/**
 * The pinned clock of a recorded or replayed run: every read of
 * "now" on a switched site answers the same pinned moment, so a recording and its replay write and send the same
 * bytes. The recording stores the moment it pinned; the replay pins the same one. Pure — reads no clock; the wall
 * clock is read by {@see \DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources} when no source is installed.
 *
 * @phpstan-consistent-constructor
 */
class RecordedRunNowSource
{
    /** The moment every read answers. */
    public ?DateTime $pinnedMoment = null;

    /** How many reads this source answered — a replay that reads the clock more or less often shows here. */
    public int $readCount = 0;

    public static function pinnedAt(DateTime $pinnedMoment): static
    {
        $recordedRunNowSource = new static();
        $recordedRunNowSource->pinnedMoment = clone $pinnedMoment;
        return $recordedRunNowSource;
    }

    /**
     * One read of "now": a copy of the pinned moment (a caller may modify its copy freely).
     *
     * @throws LogicException when nothing is pinned — an installed source without a moment is a harness error, never a
     *                        silent epoch 0 written into a row
     */
    public function takeReading(): DateTime
    {
        if ($this->pinnedMoment === null) {
            throw new LogicException('RecordedRunNowSource: no moment is pinned');
        }
        $this->readCount++;
        return clone $this->pinnedMoment;
    }
}
