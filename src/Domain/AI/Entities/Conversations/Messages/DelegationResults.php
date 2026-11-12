<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateToolArguments;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationResultSlice;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The per-child results of a `delegate` tool-call message: a map keyed by the CHILD conversation id →
 * its `{task, verdict}`. ONE `delegate` call spawns N children that all anchor back to the same delegate message,
 * and each child writes ONLY its own one-key slice when it finishes — concurrent settles in separate workers MERGE
 * here via `JSON_MERGE_PATCH` (the column is `#[DatabaseColumn(isMergableJSONColumn: true)]`), so no sibling clobbers
 * another (the DB unions the per-child keys; re-writing the same slice is idempotent → redelivery-safe).
 *
 * A ValueObject (not Entity) persisted as a JSON column: {@see mapToRepository()} returns the map of stored slices
 * ({@see DelegationResultSlice::toStoredSlice()}) so the column JSON is `{childId: {task, verdict}}` and the merge
 * unions the top-level child keys directly (no wrapper key).
 * {@see AIConversationMessage::getDelegationResult()} composes the labelled wire result from this map.
 *
 * Two shapes: a row written with a sub-agent record stores ONE sub-agent record under the key `subAgent`
 * ({@see $subAgent}); every other stored value is the legacy map above, read into one {@see DelegationResultSlice}
 * per child and written back with the stored values ({@see isLegacyMap()}). {@see buildSubAgentRecords()} reads either
 * shape as sub-agent records.
 */
class DelegationResults extends ValueObject
{
    /** The stored key of the sub-agent record. */
    public const string SUB_AGENT_KEY = 'subAgent';

    /**
     * @var DelegationResultSlice[] The children's results, keyed by the child conversation id (or by the spawn index
     *      for children that ran inside the parent's turn): each child's task, verdict and spend in USD — the only
     *      path child cost reaches the parent's turn total
     */
    public array $results = [];

    /**
     * The ONE sub-agent of a row written with a sub-agent record; left unset on a legacy row (an unset property is not
     * serialized, so a legacy row's output keeps its bytes).
     */
    public ?DelegationSubAgentRecord $subAgent;

    public function mapToRepository(): mixed
    {
        if (isset($this->subAgent)) {
            return [self::SUB_AGENT_KEY => $this->subAgent->mapToRepository()];
        }
        $storedSlices = [];
        foreach ($this->results as $childKey => $delegationResultSlice) {
            $storedSlices[$childKey] = $delegationResultSlice->toStoredSlice();
        }
        return $storedSlices;
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        if (is_array($repoObject) && is_array($repoObject[self::SUB_AGENT_KEY] ?? null)) {
            $this->subAgent = DelegationSubAgentRecord::fromStoredArray($repoObject[self::SUB_AGENT_KEY]);
            $this->results = [];
            return;
        }
        $this->results = [];
        foreach (is_array($repoObject) ? $repoObject : [] as $childKey => $storedSlice) {
            // a stored value that is no slice was never read by anyone; the column's merge keeps it stored
            if (is_array($storedSlice)) {
                $this->results[$childKey] = DelegationResultSlice::fromStoredSlice($storedSlice);
            }
        }
    }

    /** The spend of the sub-agent's ended runs in USD (each run's whole-subtree cost); 0 on a legacy row. */
    public function getSubAgentRunsCostInUsd(): float
    {
        if (!isset($this->subAgent)) {
            return 0.0;
        }
        $runsCostInUsd = 0.0;
        foreach ($this->subAgent->runs->getElements() as $delegationSubAgentRun) {
            $runsCostInUsd += $delegationSubAgentRun->costInUsd ?? 0.0;
        }
        return $runsCostInUsd;
    }

    /** The column holds the map of a call stored before sub-agent records existed (or nothing yet). */
    public function isLegacyMap(): bool
    {
        return !isset($this->subAgent);
    }

    /**
     * The sub-agent records of the `delegate` row this column belongs to: the one record of a new row;
     * for an old row one READ-ONLY legacy record per `arguments.tasks[]` item — COMPLETED with the answer the map holds
     * for its task (matched by the stored task text, in spawn order), NO_RESULT_RECORDED without one (a child the
     * platform ended is never read as running) — plus one record per answer that matches no task (an awaited
     * conversation). A FAILED old call started nothing and has no record.
     */
    public function buildSubAgentRecords(AIConversationMessage $delegateCallMessage): DelegationSubAgentRecords
    {
        $delegationSubAgentRecords = new DelegationSubAgentRecords();
        if (isset($this->subAgent)) {
            $delegationSubAgentRecords->add($this->subAgent);
            return $delegationSubAgentRecords;
        }
        $delegateToolCall = isset($delegateCallMessage->toolCall) ? $delegateCallMessage->toolCall : null;
        if ($delegateToolCall === null || $delegateToolCall->status === AIConversationMessage::STATUS_FAILED) {
            return $delegationSubAgentRecords;
        }
        $resultSlicesInSpawnOrder = $this->results;
        // the map is keyed by child conversation id (async) or spawn index (sync, stored as a list): numeric order = spawn order
        $resultSlicesAreKeyedByChildId = !array_is_list($resultSlicesInSpawnOrder);
        uksort($resultSlicesInSpawnOrder, static fn (int|string $a, int|string $b): int => (int)$a <=> (int)$b);
        $unmatchedResultSlices = $resultSlicesInSpawnOrder;
        foreach (DelegateToolArguments::fromLegacyArguments($delegateToolCall->arguments) as $legacyTask) {
            $matchedChildKey = null;
            foreach ($unmatchedResultSlices as $childKey => $delegationResultSlice) {
                if (trim($delegationResultSlice->task) === $legacyTask->taskText) {
                    $matchedChildKey = $childKey;
                    break;
                }
            }
            if ($matchedChildKey === null) {
                $legacyRecord = DelegationSubAgentRecord::legacy($legacyTask, null, null, null);
            } else {
                $legacyRecord = self::buildLegacyRecordFromResultSlice($legacyTask, $matchedChildKey, $unmatchedResultSlices[$matchedChildKey], $resultSlicesAreKeyedByChildId);
                unset($unmatchedResultSlices[$matchedChildKey]);
            }
            $delegationSubAgentRecords->add($legacyRecord);
        }
        foreach ($unmatchedResultSlices as $childKey => $delegationResultSlice) {
            $resultSliceTaskText = trim($delegationResultSlice->task);
            $resultSliceTask = AgentDelegationAgentTask::forTask($resultSliceTaskText !== '' ? $resultSliceTaskText : '(no task text stored)', []);
            $legacyRecord = self::buildLegacyRecordFromResultSlice($resultSliceTask, $childKey, $delegationResultSlice, $resultSlicesAreKeyedByChildId);
            $delegationSubAgentRecords->add($legacyRecord);
        }
        return $delegationSubAgentRecords;
    }

    /**
     * @param AgentDelegationAgentTask $legacyTask
     * @param int|string $childKey The key the slice is stored under: the child conversation id, or the spawn index
     * @param DelegationResultSlice $delegationResultSlice
     * @param bool $resultSlicesAreKeyedByChildId
     * @return DelegationSubAgentRecord
     */
    protected static function buildLegacyRecordFromResultSlice(
        AgentDelegationAgentTask $legacyTask,
        int|string $childKey,
        DelegationResultSlice $delegationResultSlice,
        bool $resultSlicesAreKeyedByChildId
    ): DelegationSubAgentRecord {
        return DelegationSubAgentRecord::legacy(
            $legacyTask,
            $resultSlicesAreKeyedByChildId && is_numeric($childKey) ? (int)$childKey : null,
            $delegationResultSlice->verdict,
            $delegationResultSlice->costInUSD
        );
    }
}
