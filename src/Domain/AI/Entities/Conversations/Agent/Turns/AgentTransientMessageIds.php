<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

/**
 * The id counter of a turn's TRANSIENT messages — the rows of a conversation that is never
 * persisted (eval, `--ephemeral`) still need ids, unique across the whole delegation tree (framework identity is
 * global). One counter is shared by a turn and its inline children; a plain mutable runtime object.
 *
 * @phpstan-consistent-constructor
 */
class AgentTransientMessageIds
{
    protected function __construct(protected int $lastAllocatedId)
    {
    }

    /** A counter that continues above the highest id the conversation's loaded messages carry. */
    public static function startingAt(int $highestUsedId): static
    {
        return new static($highestUsedId);
    }

    /** The next transient id. */
    public function allocateId(): int
    {
        return ++$this->lastAllocatedId;
    }

    public function getLastAllocatedId(): int
    {
        return $this->lastAllocatedId;
    }
}
