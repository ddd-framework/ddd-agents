<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE conversation's kept recall hits as {@see RecallRanking::groupByConversationCapped()} returns them: the
 * conversation, its best hits (highest score first, capped) and the best hit's score — the sort key of the groups.
 *
 * @phpstan-consistent-constructor
 */
class RecallConversationHitGroup extends ValueObject
{
    /** @var int The conversation the hits belong to */
    public int $conversationId = 0;

    /** @var RecallScoredHit[] The conversation's kept hits, highest score first */
    public array $hits = [];

    /** @var float The score of the best kept hit */
    public float $bestScore = 0.0;

    /**
     * @param RecallScoredHit[] $hitsBestFirst At least one hit of one conversation, highest score first
     * @return static
     */
    public static function forHitsBestFirst(array $hitsBestFirst): static
    {
        $recallConversationHitGroup = new static();
        $recallConversationHitGroup->hits = array_values($hitsBestFirst);
        $recallConversationHitGroup->conversationId = $recallConversationHitGroup->hits[0]->conversationId;
        $recallConversationHitGroup->bestScore = $recallConversationHitGroup->hits[0]->score;
        return $recallConversationHitGroup;
    }
}
