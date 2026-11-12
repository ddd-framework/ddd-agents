<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\AI\Entities\Conversations\Recall\RecallConversationHitGroup;
use DDD\Domain\AI\Entities\Conversations\Recall\RecallRanking;
use DDD\Domain\AI\Entities\Conversations\Recall\RecallScoredHit;
use PHPUnit\Framework\TestCase;

/**
 * Pure recall-ranking algorithms: Reciprocal Rank Fusion of the hybrid candidate lists, the
 * semantic+recency combine (cross-conversation E4), and the group-by-conversation cap. No DB/model.
 */
class RecallRankingTest extends TestCase
{
    public function testReciprocalRankFusionRanksSharedIdsHighest(): void
    {
        // list A: [1,2,3] (vector), list B: [3,1,4] (fulltext).
        // id 1: 1/(60+1) + 1/(60+2) = .032519; id 3: 1/(60+3) + 1/(60+1) = .032264 → 1 just above 3.
        // id 2: 1/62 only; id 4: 1/63 only → 2 above 4.
        $fused = RecallRanking::reciprocalRankFusion([[1, 2, 3], [3, 1, 4]]);
        $this->assertSame([1, 3, 2, 4], $fused, 'ids in BOTH lists fuse highest; single-list ids fall by rank');
    }

    public function testReciprocalRankFusionEdgeCases(): void
    {
        $this->assertSame([], RecallRanking::reciprocalRankFusion([]));
        $this->assertSame(['a', 'b'], RecallRanking::reciprocalRankFusion([['a', 'b']]), 'a single list keeps its order');
    }

    public function testCombineSemanticAndRecency(): void
    {
        // semantic 0.8, most-recent (rank 0): 1.0*0.8 + 0.3*(1/1) = 1.1
        $this->assertEqualsWithDelta(1.1, RecallRanking::combineSemanticAndRecency(0.8, 0), 1e-9);
        // rank 9: 0.8 + 0.3*(1/10) = 0.83
        $this->assertEqualsWithDelta(0.83, RecallRanking::combineSemanticAndRecency(0.8, 9), 1e-9);
        // A semantic gap ≥ β (0.3) always wins regardless of recency: 0.95@rank9 (1.01) > 0.4@rank0 (0.7).
        $this->assertGreaterThan(
            RecallRanking::combineSemanticAndRecency(0.4, 0),
            RecallRanking::combineSemanticAndRecency(0.95, 9)
        );
        // But a semantic gap SMALLER than β: recency overrides — 0.02@rank0 (0.32) > 0.01@rank999 (~0.0103).
        $this->assertGreaterThan(
            RecallRanking::combineSemanticAndRecency(0.01, 999),
            RecallRanking::combineSemanticAndRecency(0.02, 0)
        );
        // A negative rank is clamped to 0 (no DivisionByZeroError), equal to rank 0.
        $this->assertSame(
            RecallRanking::combineSemanticAndRecency(0.5, 0),
            RecallRanking::combineSemanticAndRecency(0.5, -1)
        );
    }

    public function testGroupByConversationCappedDegenerateInputs(): void
    {
        $this->assertSame([], RecallRanking::groupByConversationCapped([]), 'no hits → no groups');
        $hits = [RecallScoredHit::forConversationScoreAndIndex(1, 0.9, 2)];
        $this->assertSame([], RecallRanking::groupByConversationCapped($hits, maxPerConversation: 0), 'keep-0-per-conv → no null-keyed group');
        $this->assertSame([], RecallRanking::groupByConversationCapped($hits, maxConversations: 0), 'cap-0-conversations → empty');
    }

    public function testGroupByConversationCapped(): void
    {
        $hits = [
            RecallScoredHit::forConversationScoreAndIndex(10, 0.5, 3),
            RecallScoredHit::forConversationScoreAndIndex(10, 0.9, 7),
            RecallScoredHit::forConversationScoreAndIndex(10, 0.1, 1), // lowest of 4 → dropped (maxPerConversation=3)
            RecallScoredHit::forConversationScoreAndIndex(10, 0.2, 2),
            RecallScoredHit::forConversationScoreAndIndex(20, 0.7, 4),
            RecallScoredHit::forConversationScoreAndIndex(30, 0.95, 5),
        ];
        $groups = RecallRanking::groupByConversationCapped($hits, maxConversations: 2, maxPerConversation: 3);

        // Conversations ordered by best hit (30=0.95, 10=0.9, 20=0.7) → capped to 2 → [30, 10].
        $this->assertCount(2, $groups);
        $this->assertContainsOnlyInstancesOf(RecallConversationHitGroup::class, $groups);
        $this->assertSame(30, $groups[0]->conversationId);
        $this->assertSame(10, $groups[1]->conversationId);
        // Conv 10 keeps its best 3 (0.9, 0.5, 0.2), drops the 0.1; bestScore = 0.9.
        $this->assertCount(3, $groups[1]->hits);
        $this->assertEqualsWithDelta(0.9, $groups[1]->hits[0]->score, 1e-9);
        $this->assertEqualsWithDelta(0.9, $groups[1]->bestScore, 1e-9);
        $this->assertSame([7, 3, 2], array_map(static fn (RecallScoredHit $hit): int => $hit->indexInConversation, $groups[1]->hits));
    }
}
