<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The recency-graded resolution COVER of one conversation (formerly
 * `AIConversationCompactionNode[]`): the minimal node set tiling [0 .. coveredUpTo], coarsest for old history,
 * finest near the present, oldest → newest. Built once by {@see AIConversationCompactionNodes::buildCover()}; the prefix
 * edge and the rendered summary are questions of the cover itself.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCompactionCover extends ValueObject
{
    /** @var AIConversationCompactionNode[] */
    protected array $coverNodes = [];

    /** @param AIConversationCompactionNode[] $coverNodes oldest → newest */
    public static function ofCoverNodes(array $coverNodes): static
    {
        $cover = new static();
        $cover->coverNodes = array_values($coverNodes);
        return $cover;
    }

    /** @return AIConversationCompactionNode[] the cover nodes, oldest → newest */
    public function getCoverNodes(): array
    {
        return $this->coverNodes;
    }

    public function isEmpty(): bool
    {
        return $this->coverNodes === [];
    }

    public function countCoverNodes(): int
    {
        return count($this->coverNodes);
    }

    /**
     * The prefix-tiling edge: the highest message index the cover fully covers (everything strictly greater is the raw
     * verbatim tail). Null = nothing compacted. The newest cover node's end — NOT a MAX over all nodes.
     */
    public function getCoveredUpToIndex(): ?int
    {
        if ($this->coverNodes === []) {
            return null;
        }
        return $this->coverNodes[count($this->coverNodes) - 1]->coveredToMessageIndex;
    }

    /**
     * Whether the cover nodes tile [0 .. their last end] contiguously: the first starts at 0, each next one right after
     * the previous one's end — no gap, no overlap. An empty cover tiles (nothing to tile). The consistency report's
     * `coverTilesContiguously` check.
     */
    public function tilesContiguouslyFromZero(): bool
    {
        $expectedNextFrom = 0;
        foreach ($this->coverNodes as $coverNode) {
            if (($coverNode->coveredFromMessageIndex ?? -1) !== $expectedNextFrom) {
                return false;
            }
            $expectedNextFrom = ($coverNode->coveredToMessageIndex ?? -1) + 1;
        }
        return true;
    }

    /**
     * The tool telemetry of the summarised history BEFORE the loaded raw rows: the COMPLETED counts the
     * cover nodes preserve ({@see AIConversationCompactionToolTelemetry}), summed over every cover node that starts
     * before $firstLoadedMessageIndex and at/before $upToMessageIndex — a node whose raw rows are loaded is counted from
     * the rows, never twice. A node that reaches into the loaded rows (partial overlap) or carries no telemetry slot
     * marks the counts incomplete and adds nothing; a node's own `countsIncomplete` carries over. A plain sum, never
     * {@see AIConversationCompactionToolTelemetry::merge()}. $firstLoadedMessageIndex is PHP_INT_MAX when no raw rows
     * are loaded. Pure over the cover's own nodes.
     */
    public function buildToolTelemetryBefore(int $firstLoadedMessageIndex, int $upToMessageIndex): AIConversationCoverToolTelemetry
    {
        $coverToolTelemetry = new AIConversationCoverToolTelemetry();
        foreach ($this->coverNodes as $coverNode) {
            $coveredFromIndex = $coverNode->coveredFromMessageIndex ?? 0;
            $coveredToIndex = $coverNode->coveredToMessageIndex ?? -1;
            if ($coveredFromIndex >= $firstLoadedMessageIndex || $coveredFromIndex > $upToMessageIndex) {
                continue; // its raw rows are loaded (or it lies past the checkpoint) — counted from the rows, never twice
            }
            if ($coveredToIndex >= $firstLoadedMessageIndex) {
                // the loaded rows cover its tail; the node's counts span the whole range and cannot be split
                $coverToolTelemetry->countsIncomplete = true;
                continue;
            }
            $nodeToolTelemetry = isset($coverNode->preservedFacts) ? $coverNode->preservedFacts->toolTelemetry : null;
            if ($nodeToolTelemetry === null) {
                $coverToolTelemetry->countsIncomplete = true; // a pre-slot node: its calls are unknown, not zero
                continue;
            }
            foreach ($nodeToolTelemetry->getCompletedCountsByToolName() as $toolName => $completedCount) {
                $coverToolTelemetry->addCompletedCount((string)$toolName, $completedCount);
            }
            $coverToolTelemetry->countsIncomplete = $coverToolTelemetry->countsIncomplete || $nodeToolTelemetry->countsIncomplete;
        }
        return $coverToolTelemetry;
    }

    /**
     * Render the cover into the ONE synthetic "conversation so far" summary fed to the model: standing
     * constraints + preserved identifiers pinned once on top, then each cover node oldest→newest with its
     * `[messages a–b]` range label. Null when nothing is compacted.
     */
    public function buildSummaryText(): ?string
    {
        if ($this->coverNodes === []) {
            return null;
        }
        $standingConstraints = [];
        $preservedIdentifiers = [];
        $blocks = [];
        foreach ($this->coverNodes as $node) {
            foreach ($node->preservedFacts->standingConstraints ?? [] as $standingConstraint) {
                $standingConstraints[$standingConstraint] = true;
            }
            foreach ($node->preservedFacts->identifiers ?? [] as $identifier) {
                $preservedIdentifiers[$identifier] = true;
            }
            $blocks[] = sprintf(
                "[messages %d–%d]\n%s",
                $node->coveredFromMessageIndex ?? 0,
                $node->coveredToMessageIndex ?? 0,
                $node->summaryText ?? ''
            );
        }
        $rendered = '';
        if ($standingConstraints !== []) {
            $rendered .= "STANDING CONSTRAINTS (still in force):\n" . implode("\n", array_keys($standingConstraints)) . "\n\n";
        }
        if ($preservedIdentifiers !== []) {
            $rendered .= "PRESERVED FACTS & IDENTIFIERS (stated earlier, carried verbatim):\n"
                . implode("\n", array_keys($preservedIdentifiers)) . "\n\n";
        }
        return $rendered . implode("\n\n", $blocks);
    }
}
