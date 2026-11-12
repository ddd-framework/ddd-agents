<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The runs of one sub-agent record, in run-number order (stored as the record's `runs` map keyed by run number).
 *
 * @property DelegationSubAgentRun[] $elements
 * @method DelegationSubAgentRun[] getElements()
 * @method DelegationSubAgentRun|null first()
 * @phpstan-consistent-constructor
 */
class DelegationSubAgentRuns extends ObjectSet
{
    public function getRun(int $runNumber): ?DelegationSubAgentRun
    {
        foreach ($this->getElements() as $delegationSubAgentRun) {
            if ($delegationSubAgentRun->runNumber === $runNumber) {
                return $delegationSubAgentRun;
            }
        }
        return null;
    }

    public function getNewestRun(): ?DelegationSubAgentRun
    {
        $newestRun = null;
        foreach ($this->getElements() as $delegationSubAgentRun) {
            if ($newestRun === null || $delegationSubAgentRun->runNumber > $newestRun->runNumber) {
                $newestRun = $delegationSubAgentRun;
            }
        }
        return $newestRun;
    }

    public function countRuns(): int
    {
        return count($this->getElements());
    }

    /**
     * Whether a run carries a finish time and no `reportedToParentAt` stamp: its end is recorded and the one
     * notification of that end has not reached the parent.
     */
    public function hasFinishedRunNotReportedToParent(): bool
    {
        foreach ($this->getElements() as $delegationSubAgentRun) {
            if ($delegationSubAgentRun->finishedAt !== null && $delegationSubAgentRun->reportedToParentAt === null) {
                return true;
            }
        }
        return false;
    }

    /** The ended runs whose one notification has not been written into the parent yet. */
    public function getEndedRunsNotReportedToParent(): static
    {
        $endedRunsNotReportedToParent = new static();
        foreach ($this->getElements() as $delegationSubAgentRun) {
            if ($delegationSubAgentRun->hasEnded() && $delegationSubAgentRun->reportedToParentAt === null) {
                $endedRunsNotReportedToParent->add($delegationSubAgentRun);
            }
        }
        return $endedRunsNotReportedToParent;
    }

    /**
     * Every run, oldest first.
     *
     * @return DelegationSubAgentRun[]
     */
    public function getRunsInOrder(): array
    {
        $runsByNumber = [];
        foreach ($this->getElements() as $delegationSubAgentRun) {
            $runsByNumber[$delegationSubAgentRun->runNumber] = $delegationSubAgentRun;
        }
        ksort($runsByNumber);
        return array_values($runsByNumber);
    }

    /**
     * The run a deadline row created at `$deadlineCreatedAt` belongs to (every run of an async sub-agent
     * gets its own deadline row, written within a second of the run's `queuedAt` — run 1 just before it, a continuation
     * just after it): the run whose `queuedAt` lies closest to that moment. Null when no run carries a `queuedAt`.
     */
    public function getRunQueuedClosestTo(DateTime $deadlineCreatedAt): ?DelegationSubAgentRun
    {
        $closestRun = null;
        $closestDistanceInSeconds = null;
        foreach ($this->getRunsInOrder() as $delegationSubAgentRun) {
            if ($delegationSubAgentRun->queuedAt === null) {
                continue;
            }
            $distanceInSeconds = abs($delegationSubAgentRun->queuedAt->getTimestamp() - $deadlineCreatedAt->getTimestamp());
            if ($closestDistanceInSeconds === null || $distanceInSeconds < $closestDistanceInSeconds) {
                $closestRun = $delegationSubAgentRun;
                $closestDistanceInSeconds = $distanceInSeconds;
            }
        }
        return $closestRun;
    }

    /**
     * The runs before `$runNumber`, oldest first.
     *
     * @return DelegationSubAgentRun[]
     */
    public function getRunsBefore(int $runNumber): array
    {
        return array_values(array_filter(
            $this->getRunsInOrder(),
            static fn (DelegationSubAgentRun $delegationSubAgentRun): bool => $delegationSubAgentRun->runNumber < $runNumber
        ));
    }

    /**
     * The stored `runs` map keyed by run number.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mapToRepository(): array
    {
        $storedRuns = [];
        foreach ($this->getElements() as $delegationSubAgentRun) {
            $storedRuns[$delegationSubAgentRun->runNumber] = $delegationSubAgentRun->mapToRepository();
        }
        ksort($storedRuns);
        return $storedRuns;
    }

    /** @param array<int|string, mixed> $storedRuns */
    public static function fromStoredArray(array $storedRuns): static
    {
        $delegationSubAgentRuns = new static();
        foreach ($storedRuns as $storedRun) {
            if (is_array($storedRun)) {
                $delegationSubAgentRun = DelegationSubAgentRun::fromStoredArray($storedRun);
                $delegationSubAgentRuns->add($delegationSubAgentRun);
            }
        }
        return $delegationSubAgentRuns;
    }
}
