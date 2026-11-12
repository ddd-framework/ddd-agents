<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the compaction cover says about the work done BEFORE the raw rows a reader has loaded: the COMPLETED calls per tool summed over the cover nodes that end before the first loaded row, and whether
 * those counts are incomplete. Built by {@see AIConversationCompactionCover::buildToolTelemetryBefore()}; read by the
 * supervisor briefing's cumulative tally and its closed-gate check.
 *
 * Only the two fields the briefing reads are carried: the covered
 * range and the nodes' write excerpts had no reader and are not collected.
 */
class AIConversationCoverToolTelemetry extends ValueObject
{
    /** @var AIConversationCompactionToolCompletionCount[] COMPLETED calls per tool name, in the order the cover nodes first name each tool. */
    public array $toolCompletionCounts = [];

    /**
     * @var bool Whether the counts miss calls: a cover node straddles the first loaded row, a node predates the
     *           telemetry slot, or a node's own telemetry says its counts are incomplete.
     */
    public bool $countsIncomplete = false;

    /** @return array<string, int> Completed counts keyed by tool name (a read view over the typed list). */
    public function getCompletedCountsByToolName(): array
    {
        $countsByToolName = [];
        foreach ($this->toolCompletionCounts as $toolCompletionCount) {
            $countsByToolName[$toolCompletionCount->toolName] = ($countsByToolName[$toolCompletionCount->toolName] ?? 0)
                + $toolCompletionCount->completedCount;
        }
        return $countsByToolName;
    }

    /** Add $count completions of $toolName (summing into an existing entry, which keeps its position). */
    public function addCompletedCount(string $toolName, int $count): void
    {
        foreach ($this->toolCompletionCounts as $toolCompletionCount) {
            if ($toolCompletionCount->toolName === $toolName) {
                $toolCompletionCount->completedCount += $count;
                return;
            }
        }
        $toolCompletionCount = new AIConversationCompactionToolCompletionCount();
        $toolCompletionCount->toolName = $toolName;
        $toolCompletionCount->completedCount = $count;
        $this->toolCompletionCounts[] = $toolCompletionCount;
    }
}
