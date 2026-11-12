<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The sub-agent records of one conversation (or of one `delegate` row), in conversation order.
 *
 * @property DelegationSubAgentRecord[] $elements
 * @method DelegationSubAgentRecord[] getElements()
 * @method DelegationSubAgentRecord|null first()
 * @phpstan-consistent-constructor
 */
class DelegationSubAgentRecords extends ObjectSet
{
    /** The record the model addresses as `#$number`; null for an unknown number (legacy records have none). */
    public function getByNumber(int $number): ?DelegationSubAgentRecord
    {
        foreach ($this->getElements() as $delegationSubAgentRecord) {
            if ($delegationSubAgentRecord->number === $number) {
                return $delegationSubAgentRecord;
            }
        }
        return null;
    }

    /**
     * The next step the newest request declared for the moment all its sub-agents ended (its tool result's
     * `delegationNextStepWhenAllSubAgentsEnded`); null when no request declared one. Pure.
     */
    public function findNewestDeclaredNextStep(): ?string
    {
        $declaredNextStep = null;
        foreach ($this->getElements() as $delegationSubAgentRecord) {
            $nextStep = trim((string)($delegationSubAgentRecord->nextStepWhenAllSubAgentsOfRequestEnded ?? ''));
            if ($nextStep !== '') {
                $declaredNextStep = $nextStep;
            }
        }
        return $declaredNextStep;
    }

    /** The records whose current run is QUEUED or RUNNING. */
    public function getUnfinished(): static
    {
        $unfinishedRecords = new static();
        foreach ($this->getElements() as $delegationSubAgentRecord) {
            if ($delegationSubAgentRecord->hasUnfinishedRun()) {
                $unfinishedRecords->add($delegationSubAgentRecord);
            }
        }
        return $unfinishedRecords;
    }

    /** The first record that makes a new tool run of `$toolRunTask` redundant ({@see DelegationSubAgentRecord::makesToolRunRedundant()}); null = none. */
    public function findRecordMakingToolRunRedundant(AgentDelegationToolRunTask $toolRunTask): ?DelegationSubAgentRecord
    {
        foreach ($this->getElements() as $delegationSubAgentRecord) {
            if ($delegationSubAgentRecord->makesToolRunRedundant($toolRunTask)) {
                return $delegationSubAgentRecord;
            }
        }
        return null;
    }

    /** The records one tool call's result asked for. */
    public function getOfRequesterToolCallId(string $requestedByToolCallId): static
    {
        $recordsOfRequest = new static();
        foreach ($this->getElements() as $delegationSubAgentRecord) {
            if ($delegationSubAgentRecord->requestedByToolCallId === $requestedByToolCallId) {
                $recordsOfRequest->add($delegationSubAgentRecord);
            }
        }
        return $recordsOfRequest;
    }

    /** The records with a sub-agent number (every record written since sub-agents are numbered). */
    public function getNumbered(): static
    {
        $numberedRecords = new static();
        foreach ($this->getElements() as $delegationSubAgentRecord) {
            if (!$delegationSubAgentRecord->isLegacy()) {
                $numberedRecords->add($delegationSubAgentRecord);
            }
        }
        return $numberedRecords;
    }

    /** The read-only records of old multi-task rows. */
    public function getLegacy(): static
    {
        $legacyRecords = new static();
        foreach ($this->getElements() as $delegationSubAgentRecord) {
            if ($delegationSubAgentRecord->isLegacy()) {
                $legacyRecords->add($delegationSubAgentRecord);
            }
        }
        return $legacyRecords;
    }

    /** Every record except the one numbered `$excludedNumber` (records read from another copy of the rows); null excludes none. */
    public function getAllExceptNumber(?int $excludedNumber): static
    {
        $otherRecords = new static();
        foreach ($this->getElements() as $delegationSubAgentRecord) {
            if ($excludedNumber === null || $delegationSubAgentRecord->number !== $excludedNumber) {
                $otherRecords->add($delegationSubAgentRecord);
            }
        }
        return $otherRecords;
    }

    /** Every record except `$excludedRecord` (compared by identity). */
    public function getAllExcept(DelegationSubAgentRecord $excludedRecord): static
    {
        $otherRecords = new static();
        foreach ($this->getElements() as $delegationSubAgentRecord) {
            if ($delegationSubAgentRecord !== $excludedRecord) {
                $otherRecords->add($delegationSubAgentRecord);
            }
        }
        return $otherRecords;
    }
}
