<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE `.md` skill or purpose config split into its two parts by
 * {@see \DDD\Domain\AI\Services\Skills\AISkillsService::splitFrontmatter()}: the fields of the leading YAML
 * frontmatter block and the text after it.
 *
 * @phpstan-consistent-constructor
 */
class AISkillFrontmatterAndBody extends ValueObject
{
    /** @var array<string, mixed> The parsed frontmatter fields; empty when the file has no (or no map) frontmatter */
    public array $frontmatter = [];

    /** @var string The text after the frontmatter block; the whole input when there is none */
    public string $body = '';

    /**
     * @param array<string, mixed> $frontmatter
     * @param string $body
     * @return static
     */
    public static function forFrontmatterAndBody(array $frontmatter, string $body): static
    {
        $aISkillFrontmatterAndBody = new static();
        $aISkillFrontmatterAndBody->frontmatter = $frontmatter;
        $aISkillFrontmatterAndBody->body = $body;
        return $aISkillFrontmatterAndBody;
    }
}
