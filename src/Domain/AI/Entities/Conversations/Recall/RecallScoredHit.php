<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE scored cross-conversation recall hit as {@see RecallRanking::groupByConversationCapped()} ranks it: the
 * conversation it belongs to, its score and the position of the matched message in that conversation. The search
 * that scored the hit builds it.
 *
 * @phpstan-consistent-constructor
 */
class RecallScoredHit extends ValueObject
{
    /** @var int The conversation the hit belongs to */
    public int $conversationId = 0;

    /** @var float The hit's score (higher is better) */
    public float $score = 0.0;

    /** @var int The matched message's position in its conversation */
    public int $indexInConversation = 0;

    /**
     * @param int $conversationId
     * @param float $score
     * @param int $indexInConversation
     * @return static
     */
    public static function forConversationScoreAndIndex(int $conversationId, float $score, int $indexInConversation): static
    {
        $recallScoredHit = new static();
        $recallScoredHit->conversationId = $conversationId;
        $recallScoredHit->score = $score;
        $recallScoredHit->indexInConversation = $indexInConversation;
        return $recallScoredHit;
    }
}
