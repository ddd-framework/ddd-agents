<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * How many delegation children of one batch are in each run state (formerly the two arrays
 * `array{completed, failed, cancelled}` and `array{executable, terminal}`). Built from one GROUP BY over the children;
 * a state no child is in counts zero. TERMINAL = COMPLETED / FAILED / CANCELLED; every other state is EXECUTABLE
 * (IDLE included — a finished child never returns to IDLE).
 *
 * @phpstan-consistent-constructor
 */
class AgentDelegationChildCounts extends ValueObject
{
    /** @var array<string, int> run state → number of children */
    protected array $childCountByRunState = [];

    /** @param array<string, int> $childCountByRunState */
    public static function fromChildCountByRunState(array $childCountByRunState): static
    {
        $agentDelegationChildCounts = new static();
        $agentDelegationChildCounts->childCountByRunState = $childCountByRunState;
        return $agentDelegationChildCounts;
    }

    public function countInRunState(string $runState): int
    {
        return $this->childCountByRunState[$runState] ?? 0;
    }

    public function countCompleted(): int
    {
        return $this->countInRunState(AIConversationRunState::COMPLETED);
    }

    public function countFailed(): int
    {
        return $this->countInRunState(AIConversationRunState::FAILED);
    }

    public function countCancelled(): int
    {
        return $this->countInRunState(AIConversationRunState::CANCELLED);
    }

    /** Children in a terminal run state ({@see AIConversationRunState::TERMINAL}). */
    public function countTerminal(): int
    {
        $terminalCount = 0;
        foreach ($this->childCountByRunState as $runState => $childCount) {
            if (AIConversationRunState::of((string)$runState)->isTerminal()) {
                $terminalCount += $childCount;
            }
        }
        return $terminalCount;
    }

    /** Children still executable: every non-terminal run state. */
    public function countExecutable(): int
    {
        return array_sum($this->childCountByRunState) - $this->countTerminal();
    }
}
