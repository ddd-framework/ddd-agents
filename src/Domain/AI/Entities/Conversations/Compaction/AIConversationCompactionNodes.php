<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBConversationCompactionNodes;
use DDD\Domain\AI\Services\Conversations\ConversationCompactionNodesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Collection of {@see AIConversationCompactionNode} — the compaction tree of one conversation.
 *
 * @property AIConversationCompactionNode[] $elements
 * @method AIConversationCompactionNode getByUniqueKey(string $uniqueKey)
 * @method AIConversationCompactionNode[] getElements()
 * @method AIConversationCompactionNode first()
 * @method static ConversationCompactionNodesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBConversationCompactionNodes::class)]
class AIConversationCompactionNodes extends EntitySet
{
    use QueryOptionsTrait;

    // The service lives under Services/Conversations/ (with its siblings), not the flat Services/ path the
    // getService() convention resolves by default — so name it explicitly.
    public const string SERVICE_NAME = ConversationCompactionNodesService::class;

    // ---- Tree-traversal logic — the cover/exposed/roll-up planning, ON the tree it operates over.
    // Relocated from AIConversation (which now keeps thin guarded shims). All pure over the set's OWN nodes. ----

    /**
     * The nodes valid as of $asOf (created <= $asOf; null = now/all), sorted by covered-range start then level (higher
     * first) — a sorted copy of this set. The bi-temporal `created` filter makes the point-in-time read correct over the
     * immutable append-only tree.
     */
    public function getNodesAsOf(?DateTime $asOf): AIConversationCompactionNodes
    {
        $nodes = $this->getElements();
        if ($asOf !== null) {
            $asOfTimestamp = $asOf->getTimestamp();
            $nodes = array_values(array_filter(
                $nodes,
                static function (AIConversationCompactionNode $node) use ($asOfTimestamp): bool {
                    $nodeCreatedTime = $node->getChangeHistory()->createdTime ?? null;
                    return $nodeCreatedTime !== null && $nodeCreatedTime->getTimestamp() <= $asOfTimestamp;
                }
            ));
        }
        usort(
            $nodes,
            static function (AIConversationCompactionNode $a, AIConversationCompactionNode $b): int {
                $fromA = $a->coveredFromMessageIndex ?? PHP_INT_MAX;
                $fromB = $b->coveredFromMessageIndex ?? PHP_INT_MAX;
                if ($fromA !== $fromB) {
                    return $fromA <=> $fromB;
                }
                return ($b->level ?? -1) <=> ($a->level ?? -1);
            }
        );
        $nodesAsOf = AIConversationCompactionNodes::newInstance();
        foreach ($nodes as $node) {
            // add() takes the element BY-REF — a throwaway local keeps $node's type
            $nodeToAdd = $node;
            $nodesAsOf->add($nodeToAdd);
        }
        return $nodesAsOf;
    }

    /**
     * Memo for {@see self::buildCover()} — within ONE context build, `getCoveredUpToIndex` + `buildSummaryText` (and the
     * admin inspector's `buildMessageCompactionContext`) each rebuild the cover for the SAME `$asOf` over the SAME nodes.
     * Keyed on the asOf timestamp + the node count, so an append (a roll-up landing this round) re-derives. Not persisted.
     */
    protected ?AIConversationCompactionCover $coverMemo = null;
    protected ?string $coverMemoKey = null;

    /**
     * The recency-graded resolution COVER: the minimal node set tiling [0 .. coveredUpTo], coarsest
     * (highest level) for old history, finest near the present. Greedy + gap-safe; its nodes run oldest→newest.
     */
    public function buildCover(?DateTime $asOf = null): AIConversationCompactionCover
    {
        $memoKey = ($asOf?->getTimestamp() ?? 'now') . '|' . count($this->getElements());
        if ($this->coverMemo !== null && $this->coverMemoKey === $memoKey) {
            return $this->coverMemo;
        }
        $nodes = $this->getNodesAsOf($asOf)->getElements();
        if ($nodes === []) {
            $this->coverMemo = AIConversationCompactionCover::ofCoverNodes([]);
            $this->coverMemoKey = $memoKey;
            return $this->coverMemo;
        }
        $cover = [];
        $position = 0;
        while (true) {
            $bestNode = null;
            foreach ($nodes as $node) {
                if ($node->coveredFromMessageIndex === null || $node->coveredToMessageIndex === null) {
                    continue;
                }
                if ($node->coveredFromMessageIndex > $position || $node->coveredToMessageIndex < $position) {
                    continue;
                }
                if ($bestNode === null
                    || ($node->level ?? -1) > ($bestNode->level ?? -1)
                    || (($node->level ?? -1) === ($bestNode->level ?? -1)
                        && $node->coveredToMessageIndex > $bestNode->coveredToMessageIndex)) {
                    $bestNode = $node;
                }
            }
            if ($bestNode === null) {
                break;
            }
            $cover[] = $bestNode;
            $position = $bestNode->coveredToMessageIndex + 1;
        }
        $this->coverMemo = AIConversationCompactionCover::ofCoverNodes($cover);
        $this->coverMemoKey = $memoKey;
        return $this->coverMemo;
    }

    /**
     * The prefix-tiling edge: the highest message index the cover fully covers (everything strictly greater is the raw
     * verbatim tail). Null = nothing compacted. Derived from {@see self::buildCover()} (NOT a MAX over all nodes).
     */
    public function getCoveredUpToIndex(?DateTime $asOf = null): ?int
    {
        return $this->buildCover($asOf)->getCoveredUpToIndex();
    }

    /**
     * Render the cover into the ONE synthetic "conversation so far" summary fed to the model: standing
     * constraints + preserved identifiers pinned once on top, then each cover node oldest→newest with its
     * `[messages a–b]` range label. Null when nothing is compacted.
     */
    public function buildSummaryText(?DateTime $asOf = null): ?string
    {
        return $this->buildCover($asOf)->buildSummaryText();
    }

    /**
     * Exposed (not-yet-rolled-up) level-$level nodes, sorted by coveredFrom ASC (a single pass): a node is
     * exposed ⟺ no strictly-higher-level node's range contains it. getNodesAsOf sorts (coveredFrom ASC, level DESC),
     * so the containment test collapses to "max coveredTo among higher-level nodes seen so far >= this node's". O(nodes).
     */
    public function getExposedNodesAtLevel(int $level): AIConversationCompactionNodes
    {
        $exposed = AIConversationCompactionNodes::newInstance();
        $maxCoveredToAbove = PHP_INT_MIN;
        foreach ($this->getNodesAsOf(null)->getElements() as $node) {
            if ($node->coveredFromMessageIndex === null || $node->coveredToMessageIndex === null) {
                continue;
            }
            $nodeLevel = $node->level ?? -1;
            if ($nodeLevel > $level) {
                if ($node->coveredToMessageIndex > $maxCoveredToAbove) {
                    $maxCoveredToAbove = $node->coveredToMessageIndex;
                }
            } elseif ($nodeLevel === $level && $maxCoveredToAbove < $node->coveredToMessageIndex) {
                $exposedNode = $node;
                $exposed->add($exposedNode);
            }
        }
        return $exposed;
    }

    /**
     * The next contiguous K-group of exposed level-$level nodes aged past the retention window, ready to roll up into a
     * level-($level+1) node, or null. Keeps the most-recent $retentionPerLevel exposed nodes fine; rolls
     * up only the OLDEST $fanIn, and ONLY if they form a hole-free contiguous run (else over-claims coverage).
     */
    public function getNextRollUpGroup(int $level, int $fanIn, int $retentionPerLevel): ?AIConversationCompactionRollUpGroup
    {
        return AIConversationCompactionExposedNodesByLevel::fromNodesByLevel([$level => $this->getExposedNodesAtLevel($level)->getElements()])
            ->buildNextRollUpGroupAtLevel($level, $fanIn, $retentionPerLevel);
    }

    /**
     * Exposed nodes at EVERY level in ONE pass — the per-round frontier, computed once instead of
     * re-running getExposedNodesAtLevel (each re-sorting all M nodes) per level per roll-up iteration. Same
     * single-pass running-max as {@see self::getExposedNodesAtLevel()}, but tracking the max coveredTo PER level so
     * every level's exposed set falls out of one walk over getNodesAsOf(null). `buildExposedNodesByLevel()[$L]` ===
     * `getExposedNodesAtLevel($L)` for every L (locked by RollUpFrontierIncrementalEqualsRecomputeTest).
     * The round maintains this structure incrementally across roll-ups
     * ({@see AIConversationCompactionExposedNodesByLevel::replaceRolledUpGroupWithParent()}), so the whole roll-up
     * phase is O(M) once, not O((levels+roll-ups) · M log M).
     */
    public function buildExposedNodesByLevel(): AIConversationCompactionExposedNodesByLevel
    {
        $exposedByLevel = [];
        $maxCoveredToAtLevel = []; // level => max coveredTo seen so far
        foreach ($this->getNodesAsOf(null)->getElements() as $node) {
            if ($node->coveredFromMessageIndex === null || $node->coveredToMessageIndex === null) {
                continue;
            }
            $nodeLevel = $node->level ?? -1;
            $maxCoveredToAbove = PHP_INT_MIN;
            foreach ($maxCoveredToAtLevel as $seenLevel => $seenMaxCoveredTo) {
                if ($seenLevel > $nodeLevel && $seenMaxCoveredTo > $maxCoveredToAbove) {
                    $maxCoveredToAbove = $seenMaxCoveredTo;
                }
            }
            if ($maxCoveredToAbove < $node->coveredToMessageIndex) {
                $exposedByLevel[$nodeLevel][] = $node;
            }
            if (!isset($maxCoveredToAtLevel[$nodeLevel]) || $node->coveredToMessageIndex > $maxCoveredToAtLevel[$nodeLevel]) {
                $maxCoveredToAtLevel[$nodeLevel] = $node->coveredToMessageIndex;
            }
        }
        return AIConversationCompactionExposedNodesByLevel::fromNodesByLevel($exposedByLevel);
    }

    // ---- Tree soundness — "is the BUILT tree stimmig?", the questions the consistency report asks of
    // the FULL node set. Debug/validation reads; never on the turn path. ----

    /** @return AIConversationCompactionNode[][] level (`?? -1`) → nodes, in set order */
    protected function groupNodesByLevel(): array
    {
        $nodesByLevel = [];
        foreach ($this->getElements() as $treeNode) {
            $nodesByLevel[$treeNode->level ?? -1][] = $treeNode;
        }
        return $nodesByLevel;
    }

    /** How many nodes sit at $level (the leaves: level 0). */
    public function countNodesAtLevel(int $level): int
    {
        return count($this->groupNodesByLevel()[$level] ?? []);
    }

    /**
     * Every node's range is valid (non-null, from ≤ to, level ≥ 0) and its level agrees with `fromRawMessages` (a
     * level-0 node is built from raw messages, a higher one is not).
     */
    public function hasValidRangesAndLevels(): bool
    {
        foreach ($this->getElements() as $treeNode) {
            $level = $treeNode->level;
            if ($level === null || $treeNode->coveredFromMessageIndex === null || $treeNode->coveredToMessageIndex === null
                || $level < 0 || $treeNode->coveredFromMessageIndex > $treeNode->coveredToMessageIndex
                || ($treeNode->fromRawMessages ?? null) !== ($level === 0)) {
                return false;
            }
        }
        return true;
    }

    /** Each LEVEL is a disjoint tiling — sorted by coveredFrom, no two nodes of one level overlap. */
    public function hasDisjointLevels(): bool
    {
        foreach ($this->groupNodesByLevel() as $levelNodes) {
            usort($levelNodes, static fn (AIConversationCompactionNode $a, AIConversationCompactionNode $b): int => ($a->coveredFromMessageIndex ?? 0) <=> ($b->coveredFromMessageIndex ?? 0));
            for ($i = 1; $i < count($levelNodes); $i++) {
                if (($levelNodes[$i]->coveredFromMessageIndex ?? 0) <= ($levelNodes[$i - 1]->coveredToMessageIndex ?? -1)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** The level-0 leaves tile [0 .. lastLeafTo] CONTIGUOUSLY (no gap, no overlap) — the compacted range. No leaves = true. */
    public function hasLeavesTilingFromZero(): bool
    {
        $leafNodes = $this->groupNodesByLevel()[0] ?? [];
        usort($leafNodes, static fn (AIConversationCompactionNode $a, AIConversationCompactionNode $b): int => ($a->coveredFromMessageIndex ?? 0) <=> ($b->coveredFromMessageIndex ?? 0));
        $expectedLeafFrom = 0;
        foreach ($leafNodes as $leafNode) {
            if (($leafNode->coveredFromMessageIndex ?? -1) !== $expectedLeafFrom) {
                return false;
            }
            $expectedLeafFrom = ($leafNode->coveredToMessageIndex ?? -1) + 1;
        }
        return true;
    }

    /**
     * LAMINAR: any two nodes are nested or disjoint, NEVER partially overlapping (the tree invariant that makes the
     * cover/frontier reads provably correct). O(M²) over all pairs — fine for a debug validator over the full set,
     * never for the turn path.
     */
    public function isLaminar(): bool
    {
        $allTreeNodes = $this->getElements();
        $treeNodeCount = count($allTreeNodes);
        for ($i = 0; $i < $treeNodeCount; $i++) {
            for ($j = $i + 1; $j < $treeNodeCount; $j++) {
                $aFrom = $allTreeNodes[$i]->coveredFromMessageIndex ?? 0;
                $aTo = $allTreeNodes[$i]->coveredToMessageIndex ?? -1;
                $bFrom = $allTreeNodes[$j]->coveredFromMessageIndex ?? 0;
                $bTo = $allTreeNodes[$j]->coveredToMessageIndex ?? -1;
                $disjoint = $aTo < $bFrom || $bTo < $aFrom;
                $aInsideB = $bFrom <= $aFrom && $aTo <= $bTo;
                $bInsideA = $aFrom <= $bFrom && $bTo <= $aTo;
                if (!$disjoint && !$aInsideB && !$bInsideA) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Every ROLL-UP node exactly spans a contiguous run of the level below — parent.from = first child.from,
     * parent.to = last child.to, and those children tile [parent.from .. parent.to] with no hole (no over-claim).
     */
    public function hasRollUpsSpanningContiguousChildren(): bool
    {
        $nodesByLevel = $this->groupNodesByLevel();
        foreach ($this->getElements() as $parentNode) {
            $parentLevel = $parentNode->level ?? -1;
            if ($parentLevel <= 0) {
                continue;
            }
            $childNodes = array_values(array_filter(
                $nodesByLevel[$parentLevel - 1] ?? [],
                static fn (AIConversationCompactionNode $c): bool =>
                    ($c->coveredFromMessageIndex ?? 0) >= ($parentNode->coveredFromMessageIndex ?? 0)
                    && ($c->coveredToMessageIndex ?? -1) <= ($parentNode->coveredToMessageIndex ?? -1)
            ));
            usort($childNodes, static fn (AIConversationCompactionNode $a, AIConversationCompactionNode $b): int => ($a->coveredFromMessageIndex ?? 0) <=> ($b->coveredFromMessageIndex ?? 0));
            $lastChild = $childNodes !== [] ? $childNodes[count($childNodes) - 1] : null;
            if ($lastChild === null
                || ($childNodes[0]->coveredFromMessageIndex ?? -1) !== ($parentNode->coveredFromMessageIndex ?? -2)
                || ($lastChild->coveredToMessageIndex ?? -1) !== ($parentNode->coveredToMessageIndex ?? -2)) {
                return false;
            }
            $expectedChildFrom = $parentNode->coveredFromMessageIndex;
            foreach ($childNodes as $childNode) {
                if (($childNode->coveredFromMessageIndex ?? -1) !== $expectedChildFrom) {
                    return false;
                }
                $expectedChildFrom = ($childNode->coveredToMessageIndex ?? -1) + 1;
            }
        }
        return true;
    }
}
