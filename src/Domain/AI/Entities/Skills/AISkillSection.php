<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One piece of a skill's instruction body: either CORE text (no
 * selectors — always rendered) or a CONDITIONAL section opened by a marked heading `## [dimension=value] Title` and
 * closed by the next heading of the same or a higher level. A section renders only when the conversation's resolved
 * variants match every selector (`a|b` = either value; `x=1&y=2` = both dimensions). The marker is stripped from the
 * rendered heading; a heading without a title renders body only.
 *
 * @phpstan-consistent-constructor
 */
class AISkillSection extends ValueObject
{
    /** @var array<string, string> dimension => value or `a|b`; empty = core */
    public array $selectors = [];

    /** @var int The heading level that opened the section (0 for core) */
    public int $headingLevel = 0;

    /** @var string The heading title after the marker ('' = none) */
    public string $title = '';

    /** @var string The section body — the lines after the marked heading up to the section end */
    public string $markdown = '';

    /** @var int The position in the skill body (render order) */
    public int $position = 0;

    public static function core(string $markdown, int $position): static
    {
        $section = new static();
        $section->markdown = $markdown;
        $section->position = $position;
        return $section;
    }

    /** @param array<string, string> $selectors */
    public static function conditional(array $selectors, int $headingLevel, string $title, string $markdown, int $position): static
    {
        $section = new static();
        $section->selectors = $selectors;
        $section->headingLevel = $headingLevel;
        $section->title = $title;
        $section->markdown = $markdown;
        $section->position = $position;
        return $section;
    }

    public function isCore(): bool
    {
        return $this->selectors === [];
    }

    /** `businessClass=walkIn` / `businessClass=mobile&addressPublished=undecided` — the id the traces cite. */
    public function getId(): string
    {
        $parts = [];
        foreach ($this->selectors as $dimension => $value) {
            $parts[] = $dimension . '=' . $value;
        }
        return implode('&', $parts);
    }

    /**
     * Whether the section renders for the variants: core always; a conditional one when every selector matches. A
     * section whose ONLY selector is `surface=screen` / `surface=phone` follows the screen default: it is
     * decided by the surface alone, also without variants or without a decided surface.
     */
    public function appliesTo(?AIConversationVariants $variants): bool
    {
        if ($this->isCore()) {
            return true;
        }
        $defaultedSurfaceMatch = $this->matchesDefaultedSurface($variants);
        if ($defaultedSurfaceMatch !== null) {
            return $defaultedSurfaceMatch;
        }
        return $variants !== null && $variants->matchesSelectors($this->selectors);
    }

    /** Whether the section is decided by the screen default alone (`[surface=screen]` / `[surface=phone]`). */
    public function isDefaultedSurfaceSection(): bool
    {
        return count($this->selectors) === 1
            && OwnerDevice::matchesDefaultedSurfaceSelector((string)($this->selectors[OwnerDevice::SURFACE_DIMENSION] ?? ''), null) !== null;
    }

    /** The screen-default verdict for `$variants`, or null when the section is not a defaulted surface section. */
    protected function matchesDefaultedSurface(?AIConversationVariants $variants): ?bool
    {
        if (!$this->isDefaultedSurfaceSection()) {
            return null;
        }
        return OwnerDevice::matchesDefaultedSurfaceSelector(
            (string)$this->selectors[OwnerDevice::SURFACE_DIMENSION],
            $variants?->get(OwnerDevice::SURFACE_DIMENSION)?->value
        );
    }

    /**
     * The rendered text: a conditional section re-emits its heading without the marker (title only); with
     * `$nameCondition` the heading carries the condition in words (`Title (only when businessClass=walkIn)`) — the
     * variant-less render, where every section shows and the reader needs the "when".
     */
    public function render(bool $nameCondition = false): string
    {
        if ($this->isCore()) {
            return $this->markdown;
        }
        $title = $this->title;
        if ($nameCondition) {
            $condition = 'only when ' . str_replace('&', ' and ', $this->getId());
            $title = $title !== '' ? $title . ' (' . $condition . ')' : ucfirst($condition);
        }
        $heading = $title !== '' ? str_repeat('#', $this->headingLevel) . ' ' . $title . "\n" : '';
        return rtrim($heading . $this->markdown);
    }

    /**
     * Parse a heading line's marker: `## [businessClass=walkIn|hybrid&modality=chat] Title` → its level, selectors
     * and title; null for an unmarked line.
     *
     * @param string $line
     * @return AISkillMarkedHeading|null
     */
    public static function parseMarkedHeading(string $line): ?AISkillMarkedHeading
    {
        if (preg_match('/^(#{1,6})[ \t]+\[([^\]]+)\][ \t]*(.*)$/', rtrim($line), $matches) !== 1) {
            return null;
        }
        $selectors = [];
        foreach (explode('&', $matches[2]) as $pair) {
            $pair = trim($pair);
            if (preg_match('/^([A-Za-z][A-Za-z0-9]*)[ \t]*=[ \t]*([A-Za-z0-9_]+(?:[ \t]*\|[ \t]*[A-Za-z0-9_]+)*)$/', $pair, $pairMatches) !== 1) {
                return null;
            }
            $selectors[$pairMatches[1]] = implode('|', array_map('trim', explode('|', $pairMatches[2])));
        }
        return AISkillMarkedHeading::forLevelSelectorsAndTitle(strlen($matches[1]), $selectors, trim($matches[3]));
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->position);
    }
}
