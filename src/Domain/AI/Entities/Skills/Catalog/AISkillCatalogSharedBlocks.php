<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The two once-per-turn shared system blocks (QueryOptions grammar + shared request parameters), emitted ONCE in the
 * agent's system context (not per tool). The typed object returned by
 * {@see \DDD\Domain\AI\Services\Skills\AISkillCatalogDocService::buildSharedBlocks()}.
 *
 * @phpstan-consistent-constructor
 */
class AISkillCatalogSharedBlocks extends ValueObject
{
    /** @var AISkillCatalogSharedBlock[] The shared blocks with their cost. */
    public array $blocks = [];

    /** @param AISkillCatalogSharedBlock[] $blocks */
    public static function make(array $blocks): static
    {
        $sharedBlocks = new static();
        $sharedBlocks->blocks = $blocks;
        return $sharedBlocks;
    }
}
