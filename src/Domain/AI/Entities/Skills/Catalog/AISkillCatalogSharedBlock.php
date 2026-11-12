<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills\Catalog;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One once-per-turn shared system block (the QueryOptions grammar or the shared request-parameters doc): its stable
 * key, title, token cost, and rendered markdown.
 *
 * @phpstan-consistent-constructor
 */
class AISkillCatalogSharedBlock extends ValueObject
{
    /** @var string Stable key (`queryOptionsSyntax` | `sharedRequestParameters`). */
    public string $key = '';

    /** @var string Human title. */
    public string $title = '';

    /** @var int The block's token cost (GPT-3 BPE tokens). */
    public int $tokens = 0;

    /** @var string The rendered markdown the agent sees once. */
    public string $markdown = '';

    public static function make(string $key, string $title, int $tokens, string $markdown): static
    {
        $block = new static();
        $block->key = $key;
        $block->title = $title;
        $block->tokens = $tokens;
        $block->markdown = $markdown;
        return $block;
    }
}
