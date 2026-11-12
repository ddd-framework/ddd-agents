<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The roll-up phase computes the exposed frontier for all levels ONCE
 * ({@see AIConversationCompactionNodes::buildExposedNodesByLevel}) and maintains it incrementally instead of
 * re-deriving (re-sorting all nodes) per level per iteration. This proves both halves: (1) the one-pass per-level
 * frontier equals calling getExposedNodesAtLevel per level; (2) the incremental update the round applies after a
 * roll-up (drop the K children at level L, append the parent at L+1) equals recomputing the frontier from scratch
 * over the post-insert node set — so the cheap incremental maintenance never diverges from the authoritative recompute.
 */
class RollUpFrontierIncrementalEqualsRecomputeTest extends UnitTestCase
{
    protected function node(int $level, int $from, int $to): AIConversationCompactionNode
    {
        $node = self::instantiateWithoutConstructor(AIConversationCompactionNode::class);
        $node->level = $level;
        $node->coveredFromMessageIndex = $from;
        $node->coveredToMessageIndex = $to;
        $node->summaryText = "L$level [$from-$to]";
        return $node;
    }

    /** @param array<int, AIConversationCompactionNode> $nodes */
    protected function set(array $nodes): AIConversationCompactionNodes
    {
        $set = self::instantiateWithoutConstructor(AIConversationCompactionNodes::class);
        self::setPropertyValue($set, 'elements', $nodes);
        self::setPropertyValue($set, 'elementCount', count($nodes));
        return $set;
    }

    /** @param array<int, array<int, AIConversationCompactionNode>> $byLevel @return array<int, array<int, array{int,int,int}>> */
    protected function shapeByLevel(array $byLevel): array
    {
        $shaped = [];
        foreach ($byLevel as $level => $nodes) {
            $shaped[$level] = array_map(
                static fn (AIConversationCompactionNode $n): array => [$n->level, $n->coveredFromMessageIndex, $n->coveredToMessageIndex],
                $nodes
            );
        }
        ksort($shaped);
        return $shaped;
    }

    public function testBuildExposedByLevelEqualsPerLevelExposed(): void
    {
        // 10 L0 leaves + 1 L1 over the first 5 → exposed: L0 has the last 5, L1 has the one rolled node.
        $nodes = [];
        for ($i = 0; $i < 10; $i++) {
            $nodes[] = $this->node(0, $i * 10, $i * 10 + 9);
        }
        $nodes[] = $this->node(1, 0, 49); // covers L0 leaves 0..4
        $set = $this->set($nodes);

        $byLevel = $set->buildExposedNodesByLevel();
        for ($level = 0; $level <= 3; $level++) {
            $perLevel = array_map(
                static fn (AIConversationCompactionNode $n): array => [$n->level, $n->coveredFromMessageIndex, $n->coveredToMessageIndex],
                $set->getExposedNodesAtLevel($level)->getElements()
            );
            self::assertSame($perLevel, $this->shapeByLevel($byLevel->toNodesByLevel())[$level] ?? [], "buildExposedNodesByLevel[$level] must equal getExposedNodesAtLevel($level)");
        }
    }

    public function testIncrementalUpdateEqualsRecomputeAfterRollUp(): void
    {
        // A frontier of 6 exposed L0 leaves [0-9]..[50-59]; fanIn 5, retention 0 → roll the oldest 5 into an L1 parent.
        $nodes = [];
        for ($i = 0; $i < 6; $i++) {
            $nodes[] = $this->node(0, $i * 10, $i * 10 + 9);
        }
        $set = $this->set($nodes);

        $exposedByLevel = $set->buildExposedNodesByLevel();
        $rollUpGroup = $exposedByLevel->buildNextRollUpGroupAtLevel(0, fanIn: 5, retentionPerLevel: 0);
        self::assertNotNull($rollUpGroup);
        $group = $rollUpGroup->getChildNodes();

        // Simulate the round's roll-up: build the parent over the group, apply the incremental update.
        $parent = $this->node(1, $group[0]->coveredFromMessageIndex, $group[4]->coveredToMessageIndex); // L1 [0-49]
        $exposedByLevel->replaceRolledUpGroupWithParent($rollUpGroup, $parent);

        // Recompute from scratch over the post-insert node set (the parent now persisted/added to the tree).
        $nodes[] = $parent;
        $recomputed = $this->set($nodes)->buildExposedNodesByLevel();

        self::assertSame(
            $this->shapeByLevel($recomputed->toNodesByLevel()),
            $this->shapeByLevel($exposedByLevel->toNodesByLevel()),
            'the incremental frontier update must match a full recompute over the post-roll-up tree'
        );
    }
}
