<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

/**
 * Pure ranking algorithms for conversation recall — no DB, no model, no persistence (a static-method
 * utility, not a persisted entity). The search
 * service hands these ranked id-lists (one from the vector ANN search, one from the FULLTEXT search) + per-conversation
 * hit scores, and gets back a fused / grouped ordering. Reusable by BOTH within-conversation recall and
 * cross-conversation recall.
 */
class RecallRanking
{
    /** Reciprocal Rank Fusion constant — the Elasticsearch production default; needs no tuning (research #2). */
    public const int RRF_K = 60;

    /**
     * Reciprocal Rank Fusion of N ranked candidate lists (research #2): `score(id) = Σ over each list of 1/(k + rank)`,
     * rank being the 0-based position in that list. Fuses the vector + FULLTEXT candidate sets without needing their
     * (incomparable) raw scores — ranks only. Returns the ids ordered by fused score DESC; an id present in more lists
     * and/or higher up wins. Stable for equal scores (insertion order, PHP 8 `arsort`).
     *
     * @param list<list<int|string>> $rankedLists each a ranked list of hit ids (rank = array position)
     * @return list<int|string>
     */
    public static function reciprocalRankFusion(array $rankedLists, int $k = self::RRF_K): array
    {
        $fusedScoreById = [];
        foreach ($rankedLists as $rankedList) {
            $rank = 0;
            foreach ($rankedList as $id) {
                $fusedScoreById[$id] = ($fusedScoreById[$id] ?? 0.0) + 1.0 / ($k + $rank + 1);
                $rank++;
            }
        }
        arsort($fusedScoreById);
        return array_keys($fusedScoreById);
    }

    /**
     * Combine a semantic similarity score with a recency rank into one cross-conversation score:
     * `overall = α·semantic + β·recencyWeight`, where recencyWeight is `1/(recencyRank+1)` (rank 0 = most recent).
     * α/β are tuning knobs (defaults favour semantic). The recency term is bounded by `(0, β]`, so it only breaks ties
     * or overrides a semantic gap SMALLER than β — a semantic gap ≥ β always wins regardless of recency. A negative
     * rank is clamped to 0 (a malformed recency index must never crash the recall path).
     */
    public static function combineSemanticAndRecency(
        float $semanticScore,
        int $recencyRank,
        float $alpha = 1.0,
        float $beta = 0.3
    ): float {
        $recencyWeight = 1.0 / (max(0, $recencyRank) + 1);
        return $alpha * $semanticScore + $beta * $recencyWeight;
    }

    /**
     * Group cross-conversation hits BY conversation: keep the best `$maxPerConversation` hits per
     * conversation (highest score first), then return the conversations ordered by their BEST hit's score DESC, capped
     * at `$maxConversations`. Stops one chat from flooding the result; the caller carries title/date per conversation
     * for provenance.
     *
     * @param RecallScoredHit[] $hits
     * @param int $maxConversations
     * @param int $maxPerConversation
     * @return RecallConversationHitGroup[]
     */
    public static function groupByConversationCapped(array $hits, int $maxConversations = 5, int $maxPerConversation = 3): array
    {
        $hitsByConversation = [];
        foreach ($hits as $hit) {
            $hitsByConversation[$hit->conversationId][] = $hit;
        }

        $groups = [];
        foreach ($hitsByConversation as $conversationHits) {
            usort($conversationHits, static fn (RecallScoredHit $a, RecallScoredHit $b): int => $b->score <=> $a->score);
            $kept = array_slice($conversationHits, 0, max(0, $maxPerConversation));
            if ($kept === []) {
                continue; // $maxPerConversation < 1 → nothing kept; never build a null-keyed group
            }
            $groups[] = RecallConversationHitGroup::forHitsBestFirst($kept);
        }

        usort($groups, static fn (RecallConversationHitGroup $a, RecallConversationHitGroup $b): int => $b->bestScore <=> $a->bestScore);
        return array_slice($groups, 0, $maxConversations);
    }
}
