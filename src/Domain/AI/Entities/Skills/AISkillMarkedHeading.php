<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE marked heading line of a skill body (`## [businessClass=walkIn|hybrid&modality=chat] Title`), parsed by
 * {@see AISkillSection::parseMarkedHeading()}: its heading level, its own selectors and its title. A marked heading
 * opens a conditional section ({@see AISkillOpenSection}).
 *
 * @phpstan-consistent-constructor
 */
class AISkillMarkedHeading extends ValueObject
{
    /** @var int The heading level (the number of `#`, 1–6) */
    public int $level = 0;

    /** @var array<string, string> The heading's own selectors: dimension => value or `a|b` */
    public array $selectors = [];

    /** @var string The heading title after the marker ('' = none) */
    public string $title = '';

    /**
     * @param int $level
     * @param array<string, string> $selectors
     * @param string $title
     * @return static
     */
    public static function forLevelSelectorsAndTitle(int $level, array $selectors, string $title): static
    {
        $aISkillMarkedHeading = new static();
        $aISkillMarkedHeading->level = $level;
        $aISkillMarkedHeading->selectors = $selectors;
        $aISkillMarkedHeading->title = $title;
        return $aISkillMarkedHeading;
    }
}
