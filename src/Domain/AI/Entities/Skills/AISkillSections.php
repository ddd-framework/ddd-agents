<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariant;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A skill body cut into its sections (skill variants): core pieces and conditional
 * sections in file order. {@see fromMarkdown()} parses the marker headings, {@see renderFor()} re-assembles the text
 * the model sees for one set of variants, {@see renderedSectionIds()} names the conditional sections it contains —
 * the ids every trace cites (request snapshot, inspector, judge, run report).
 *
 * @property AISkillSection[] $elements
 * @method AISkillSection[] getElements()
 * @phpstan-consistent-constructor
 */
class AISkillSections extends ObjectSet
{
    /**
     * Parse a skill body. A marked heading opens a section; it ends at the next heading of the same or a higher
     * level (a lower number) — nested deeper headings belong to it. A marked heading NESTED in an open section opens
     * a child section whose selectors are the parent's AND its own; when the child closes, the parent resumes (its
     * later lines become a continuation piece under the parent's selectors). Headings inside fenced code blocks are
     * text. A marked heading whose marker does not parse stays text (the line is kept verbatim).
     */
    public static function fromMarkdown(string $markdown): static
    {
        $sections = new static();
        $position = 0;
        /** @var string[] $coreLines */
        $coreLines = [];
        /** @var AISkillOpenSection[] $openSections the stack, innermost last */
        $openSections = [];
        $openFence = null;
        // ASCII line breaks and blanks only: without the /u modifier `\R` and `\s` also match the byte 0x85 (NEL) —
        // the last byte of `★` (E2 98 85) — and would cut a multibyte character in half; the invalid UTF-8 then
        // broke the whole model request ("JSON parsing failed")
        foreach (preg_split('/\r\n|\n|\r/', $markdown) ?: [] as $line) {
            if (preg_match('/^[ \t]{0,3}(`{3,}|~{3,})/', $line, $fenceMatches) === 1) {
                if ($openFence === null) {
                    $openFence = $fenceMatches[1][0];
                } elseif ($fenceMatches[1][0] === $openFence) {
                    $openFence = null;
                }
            }
            $headingLevel = ($openFence === null && preg_match('/^[ \t]{0,3}(#{1,6})[ \t]/', $line, $headingMatches) === 1) ? strlen($headingMatches[1]) : 0;
            $markedHeading = ($headingLevel > 0) ? AISkillSection::parseMarkedHeading(ltrim($line)) : null;
            // a heading of the same or a higher level closes every open section at or below its depth
            while ($headingLevel > 0 && $openSections !== [] && $headingLevel <= $openSections[array_key_last($openSections)]->level) {
                self::closeInnermostSection($sections, $openSections, $position);
            }
            if ($markedHeading !== null) {
                if ($openSections === []) {
                    self::flushCoreLines($sections, $coreLines, $position);
                    $selectors = $markedHeading->selectors;
                } else {
                    // the child's text so far belongs to the parent: emit it as the parent's piece, then nest
                    self::emitOpenSectionLines($sections, $openSections, $position);
                    $selectors = array_merge($openSections[array_key_last($openSections)]->selectors, $markedHeading->selectors);
                }
                $openSections[] = AISkillOpenSection::forMarkedHeading($markedHeading, $selectors);
                continue;
            }
            if ($openSections !== []) {
                $openSections[array_key_last($openSections)]->appendLine($line);
            } else {
                $coreLines[] = $line;
            }
        }
        while ($openSections !== []) {
            self::closeInnermostSection($sections, $openSections, $position);
        }
        self::flushCoreLines($sections, $coreLines, $position);
        return $sections;
    }

    /** @param string[] $coreLines */
    protected static function flushCoreLines(self $sections, array &$coreLines, int &$position): void
    {
        $text = rtrim(implode("\n", $coreLines));
        $coreLines = [];
        if ($text !== '') {
            $coreSection = AISkillSection::core($text, $position++); // a variable: ObjectSet::add() takes references
            $sections->add($coreSection);
        }
    }

    /**
     * Emit the innermost open section's collected lines as a piece (heading + text on the first piece, text only on a
     * continuation after a nested child) and reset its line buffer; the section stays open.
     *
     * @param self $sections
     * @param AISkillOpenSection[] $openSections the stack, innermost last
     * @param int $position
     * @return void
     */
    protected static function emitOpenSectionLines(self $sections, array &$openSections, int &$position): void
    {
        if ($openSections === []) {
            return;
        }
        $innermostOpenSection = $openSections[array_key_last($openSections)];
        if ($innermostOpenSection->hasPieceToEmit()) {
            $conditionalSection = $innermostOpenSection->buildPiece($position++);
            $sections->add($conditionalSection);
        }
        $innermostOpenSection->startContinuation();
    }

    /**
     * @param self $sections
     * @param AISkillOpenSection[] $openSections the stack, innermost last
     * @param int $position
     * @return void
     */
    protected static function closeInnermostSection(self $sections, array &$openSections, int &$position): void
    {
        if ($openSections === []) {
            return;
        }
        self::emitOpenSectionLines($sections, $openSections, $position);
        array_pop($openSections);
    }

    /** The text for one set of variants: core + the matching sections, in file order, blank-line separated. */
    /** @var array<string, string> Rendered texts, keyed by `phone|screen` + text hash (the inputs are deploy-invariant). */
    protected static array $renderedSurfaceTextsByKey = [];

    /**
     * Render a text whose only variant sections are `[surface=screen]` / `[surface=phone]` (a tool
     * description, the base prompt) for the phone line or the screen; a text without such a marker comes back as is.
     */
    public static function renderSurfaceSectionsOf(string $text, bool $ownerIsOnThePhoneLine): string
    {
        if (!str_contains($text, '[' . OwnerDevice::SURFACE_DIMENSION . '=')) {
            return $text;
        }
        $cacheKey = ($ownerIsOnThePhoneLine ? 'phone|' : 'screen|') . md5($text);
        if (!array_key_exists($cacheKey, self::$renderedSurfaceTextsByKey)) {
            $sections = self::fromMarkdown($text);
            if ($ownerIsOnThePhoneLine) {
                $phoneVariants = new AIConversationVariants();
                $phoneVariants->put(AIConversationVariant::decided(OwnerDevice::SURFACE_DIMENSION, OwnerDevice::SURFACE_PHONE, AIConversationVariant::SOURCE_RECORD));
                self::$renderedSurfaceTextsByKey[$cacheKey] = $sections->renderFor($phoneVariants);
            } else {
                self::$renderedSurfaceTextsByKey[$cacheKey] = $sections->renderAll();
            }
        }
        return self::$renderedSurfaceTextsByKey[$cacheKey];
    }

    public function renderFor(?AIConversationVariants $variants): string
    {
        $pieces = [];
        foreach ($this->getElements() as $section) {
            if ($section->appliesTo($variants)) {
                $pieces[] = $section->render();
            }
        }
        return trim(implode("\n\n", array_filter($pieces, static fn (string $piece): bool => trim($piece) !== '')));
    }

    /**
     * The whole body with every section, each heading naming its condition (`## Mobile workshop (only when
     * workshopKind=mobile)`) — what a variant-less render shows: a reader without a situation still sees WHEN a
     * part applies.
     */
    public function renderAll(): string
    {
        $pieces = [];
        foreach ($this->getElements() as $section) {
            // a `[surface=screen|phone]` section follows the screen default — rendered plain or not at all
            if ($section->isDefaultedSurfaceSection()) {
                if ($section->appliesTo(null)) {
                    $pieces[] = $section->render();
                }
                continue;
            }
            $pieces[] = $section->render(true);
        }
        return trim(implode("\n\n", array_filter($pieces, static fn (string $piece): bool => trim($piece) !== '')));
    }

    /**
     * The ids of the conditional sections rendered for the variants, in order.
     *
     * @return string[]
     */
    public function renderedSectionIds(?AIConversationVariants $variants): array
    {
        $ids = [];
        foreach ($this->getElements() as $section) {
            if (!$section->isCore() && $section->appliesTo($variants)) {
                $ids[] = $section->getId();
            }
        }
        return $ids;
    }

    /**
     * The body without any conditional section — the core pieces only, joined as {@see renderFor()} joins them
     * (an append-only epoch's system content carries no situation-dependent text).
     */
    public function renderWithoutConditionalSections(): string
    {
        $pieces = [];
        foreach ($this->getElements() as $section) {
            if ($section->isCore()) {
                $pieces[] = $section->render();
            }
        }
        return trim(implode("\n\n", array_filter($pieces, static fn (string $piece): bool => trim($piece) !== '')));
    }

    /**
     * The conditional sections only, for `$variants` (the appended `# Situation` entry): the matching
     * ones with their headings; without variants every section, each heading naming its condition (as {@see renderAll()}).
     */
    public function renderConditionalSectionsFor(?AIConversationVariants $variants): string
    {
        $pieces = [];
        foreach ($this->getElements() as $section) {
            if ($section->isCore()) {
                continue;
            }
            if ($variants !== null || $section->isDefaultedSurfaceSection()) {
                if ($section->appliesTo($variants)) {
                    $pieces[] = $section->render();
                }
                continue;
            }
            $pieces[] = $section->render(true);
        }
        return trim(implode("\n\n", array_filter($pieces, static fn (string $piece): bool => trim($piece) !== '')));
    }

    /**
     * The conditional sections {@see renderConditionalSectionsFor()} renders, as `id => label` — the label names the
     * section's title and its condition (`Mobile workshop (when workshopKind=mobile)`); a continuation piece without a
     * title is labelled by its condition alone. The ids are those of {@see renderedSectionIds()} /
     * {@see variantLessRenderedSectionIds()}.
     *
     * @return array<string, string>
     */
    public function buildConditionalSectionLabelsFor(?AIConversationVariants $variants): array
    {
        $labelsById = [];
        foreach ($this->getElements() as $section) {
            if ($section->isCore() || ($variants !== null || $section->isDefaultedSurfaceSection()) && !$section->appliesTo($variants)) {
                continue;
            }
            $sectionId = $section->getId();
            if (!isset($labelsById[$sectionId]) || $section->title !== '') {
                $labelsById[$sectionId] = ($section->title !== '' ? $section->title . ' ' : '') . '(when ' . str_replace('&', ' and ', $sectionId) . ')';
            }
        }
        return $labelsById;
    }

    /** @return string[] Every conditional section id, in order. */
    public function allSectionIds(): array
    {
        $ids = [];
        foreach ($this->getElements() as $section) {
            if (!$section->isCore()) {
                $ids[] = $section->getId();
            }
        }
        return $ids;
    }

    /** @return string[] The ids of the conditional sections {@see renderAll()} renders, in order. */
    public function variantLessRenderedSectionIds(): array
    {
        $ids = [];
        foreach ($this->getElements() as $section) {
            if (!$section->isCore() && (!$section->isDefaultedSurfaceSection() || $section->appliesTo(null))) {
                $ids[] = $section->getId();
            }
        }
        return $ids;
    }

    /** @return string[] The dimensions the sections key on. */
    public function getDimensionNames(): array
    {
        $names = [];
        foreach ($this->getElements() as $section) {
            foreach (array_keys($section->selectors) as $dimension) {
                $names[(string)$dimension] = true;
            }
        }
        return array_keys($names);
    }

    public function hasConditionalSections(): bool
    {
        foreach ($this->getElements() as $section) {
            if (!$section->isCore()) {
                return true;
            }
        }
        return false;
    }

    public function getCoreText(): string
    {
        $pieces = [];
        foreach ($this->getElements() as $section) {
            if ($section->isCore()) {
                $pieces[] = $section->markdown;
            }
        }
        return trim(implode("\n\n", $pieces));
    }
}
