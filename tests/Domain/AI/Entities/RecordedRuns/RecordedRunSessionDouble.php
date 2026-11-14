<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSessionInterface;

/**
 * A recording session that records the calls a harness makes on it, in order, and records nothing else.
 */
class RecordedRunSessionDouble implements AgentRecordedRunSessionInterface
{
    /** @var string[] The names of the calls made, in order */
    public array $calledMethodNames = [];

    /** @var array<string, int|string|null> */
    public array $scopeIdsByName = [];

    public bool $replay = false;

    public function isReplay(): bool
    {
        $this->calledMethodNames[] = 'isReplay';
        return $this->replay;
    }

    public function readHighWaterMarks(): void
    {
        $this->calledMethodNames[] = 'readHighWaterMarks';
    }

    public function setScopeIds(array $scopeIdsByName): void
    {
        $this->calledMethodNames[] = 'setScopeIds';
        $this->scopeIdsByName = array_merge($this->scopeIdsByName, $scopeIdsByName);
    }

    public function beginTurn(int $turnOrdinal): void
    {
        $this->calledMethodNames[] = 'beginTurn';
    }

    public function setRootConversation(AIConversation $rootConversation): void
    {
        $this->calledMethodNames[] = 'setRootConversation';
    }

    public function describeSharedScopeTables(): ?string
    {
        $this->calledMethodNames[] = 'describeSharedScopeTables';
        return null;
    }

    public function start(): void
    {
        $this->calledMethodNames[] = 'start';
    }

    public function runQueuedMessages(): int
    {
        $this->calledMethodNames[] = 'runQueuedMessages';
        return 0;
    }

    public function stop(bool $runRemainingMessages = true): void
    {
        $this->calledMethodNames[] = 'stop';
    }

    public function concludeRun(AIConversation $rootConversation, array $scopeIdsByName): void
    {
        $this->calledMethodNames[] = 'concludeRun';
    }

    public function buildRecording(AIConversation $rootConversation, array $scopeIdsByName, array $runFields): array
    {
        $this->calledMethodNames[] = 'buildRecording';
        return ['scopeIds' => $scopeIdsByName] + $runFields;
    }

    public function compareWithRecording(AIConversation $rootConversation, array $scopeIdsByName): AgentRecordedRunComparison
    {
        $this->calledMethodNames[] = 'compareWithRecording';
        return AgentRecordedRunComparison::newInstance();
    }

    public function describeRun(array $snapshot): string
    {
        $this->calledMethodNames[] = 'describeRun';
        return count($snapshot) . ' sections';
    }
}
