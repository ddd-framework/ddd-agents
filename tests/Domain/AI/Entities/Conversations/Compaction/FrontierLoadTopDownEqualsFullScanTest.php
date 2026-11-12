<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The compaction round loads ONLY the exposed frontier (~log_K N nodes) via
 * {@see \DDD\Domain\AI\Services\Conversations\ConversationCompactionNodesService::findExposedNodesForConversation},
 * a top-down per-level indexed query (`level = L AND coveredFrom > higherEdge`), instead of all M ≈ Θ(N) nodes. The DB
 * query can't run in a UnitTestCase, so this reimplements its top-down logic over an in-memory node array and proves it
 * returns the SAME frontier as a full-set scan ({@see AIConversationCompactionNodes::buildExposedNodesByLevel}). That
 * is the byte-identical guarantee for loading the frontier instead of everything.
 */
class FrontierLoadTopDownEqualsFullScanTest extends UnitTestCase
{
    protected function node(int $level, int $from, int $to): AIConversationCompactionNode
    {
        $node = self::instantiateWithoutConstructor(AIConversationCompactionNode::class);
        $node->level = $level;
        $node->coveredFromMessageIndex = $from;
        $node->coveredToMessageIndex = $to;
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

    /** @param array<int, AIConversationCompactionNode> $nodes @return array<int, array{int,int,int}> sorted */
    protected function shapeSorted(array $nodes): array
    {
        $shaped = array_map(
            static fn (AIConversationCompactionNode $n): array => [$n->level, $n->coveredFromMessageIndex, $n->coveredToMessageIndex],
            $nodes
        );
        usort($shaped, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return $shaped;
    }

    /**
     * In-memory mirror of findExposedNodesForConversation's top-down per-level query.
     *
     * @param array<int, AIConversationCompactionNode> $allNodes
     * @return array<int, AIConversationCompactionNode>
     */
    protected function topDownFrontier(array $allNodes): array
    {
        $frontier = [];
        $higherEdge = -1;
        for ($level = 16 - 1; $level >= 0; $level--) {
            $exposedAtLevel = array_values(array_filter(
                $allNodes,
                static fn (AIConversationCompactionNode $n): bool =>
                    ($n->level ?? -1) === $level
                    && $n->coveredFromMessageIndex !== null
                    && $n->coveredFromMessageIndex > $higherEdge
            ));
            usort($exposedAtLevel, static fn (AIConversationCompactionNode $a, AIConversationCompactionNode $b): int =>
                ($a->coveredFromMessageIndex ?? 0) <=> ($b->coveredFromMessageIndex ?? 0));
            foreach ($exposedAtLevel as $node) {
                $frontier[] = $node;
                if (($node->coveredToMessageIndex ?? -1) > $higherEdge) {
                    $higherEdge = $node->coveredToMessageIndex;
                }
            }
        }
        return $frontier;
    }

    /**
     * @dataProvider trees
     * @param array<int, AIConversationCompactionNode> $nodes
     */
    public function testTopDownFrontierEqualsFullScan(array $nodes): void
    {
        $fullScanFrontier = [];
        foreach ($this->set($nodes)->buildExposedNodesByLevel()->toNodesByLevel() as $levelNodes) {
            foreach ($levelNodes as $node) {
                $fullScanFrontier[] = $node;
            }
        }

        self::assertSame(
            $this->shapeSorted($fullScanFrontier),
            $this->shapeSorted($this->topDownFrontier($nodes)),
            'the top-down per-level frontier load must equal the full-set buildExposedNodesByLevel'
        );
    }

    /** @return array<string, array{array<int, AIConversationCompactionNode>}> */
    public static function trees(): array
    {
        $self = new self('trees');
        // Full trees INCLUDING the covered (already-rolled-up) nodes the frontier load must NOT return.
        return [
            'one L1 over the oldest 5 L0, 5 L0 still exposed' => [array_merge(
                array_map(static fn (int $i): AIConversationCompactionNode => $self->node(0, $i * 10, $i * 10 + 9), range(0, 9)),
                [$self->node(1, 0, 49)]
            )],
            'two-level prefix: L2[0-124] over L1[0-24],L1[25-49] over their L0; recent L0 exposed' => [[
                $self->node(0, 0, 4), $self->node(0, 5, 9), $self->node(0, 10, 14), $self->node(0, 15, 19), $self->node(0, 20, 24),
                $self->node(0, 25, 29), $self->node(0, 125, 129), $self->node(0, 130, 134),
                $self->node(1, 0, 24), $self->node(1, 25, 49),
                $self->node(2, 0, 124),
            ]],
            'deep L0..L3 all-prefix + recent L0 tail' => [[
                $self->node(0, 0, 4), $self->node(1, 0, 24), $self->node(2, 0, 124), $self->node(3, 0, 499),
                $self->node(0, 500, 509), $self->node(0, 510, 519),
            ]],
            'nothing rolled — all L0 exposed' => [[
                $self->node(0, 0, 9), $self->node(0, 10, 19), $self->node(0, 20, 29),
            ]],
            'empty' => [[]],
        ];
    }
}
