<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Hits of a semantic conversation search, best first.
 *
 * @property SemanticSearchHit[] $elements
 * @method SemanticSearchHit[] getElements()
 * @method SemanticSearchHit|null first()
 * @method SemanticSearchHit getByUniqueKey(string $uniqueKey)
 */
class SemanticSearchHits extends ObjectSet
{
}
