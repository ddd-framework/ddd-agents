<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE conditional section that is still open while {@see AISkillSections::fromMarkdown()} reads a skill body: the
 * level and title of the marked heading that opened it, its effective selectors (a nested section carries its
 * parent's AND its own), the lines collected since its last emitted piece, and whether its first piece (the one with
 * the heading) was already emitted. The parser keeps a stack of these, innermost last.
 *
 * @phpstan-consistent-constructor
 */
class AISkillOpenSection extends ValueObject
{
    /** @var int The heading level that opened the section */
    public int $level = 0;

    /** @var array<string, string> The effective selectors: dimension => value or `a|b` */
    public array $selectors = [];

    /** @var string The heading title after the marker ('' = none) */
    public string $title = '';

    /** @var string[] The body lines collected since the last emitted piece */
    public array $lines = [];

    /** @var bool Whether the first piece (heading + text) was already emitted; later pieces are continuations */
    public bool $isFirstPieceEmitted = false;

    /**
     * @param AISkillMarkedHeading $aISkillMarkedHeading The heading that opens the section
     * @param array<string, string> $selectors The effective selectors (the parent's merged with the heading's own)
     * @return static
     */
    public static function forMarkedHeading(AISkillMarkedHeading $aISkillMarkedHeading, array $selectors): static
    {
        $aISkillOpenSection = new static();
        $aISkillOpenSection->level = $aISkillMarkedHeading->level;
        $aISkillOpenSection->selectors = $selectors;
        $aISkillOpenSection->title = $aISkillMarkedHeading->title;
        return $aISkillOpenSection;
    }

    /**
     * @param string $line One body line of the section
     * @return void
     */
    public function appendLine(string $line): void
    {
        $this->lines[] = $line;
    }

    /** @return string The collected lines as one text, trailing blanks removed */
    public function getCollectedText(): string
    {
        return rtrim(implode("\n", $this->lines));
    }

    /** @return bool Whether a piece is due: collected text, or a first piece that has a title */
    public function hasPieceToEmit(): bool
    {
        return $this->getCollectedText() !== '' || (!$this->isFirstPieceEmitted && $this->title !== '');
    }

    /**
     * The collected text as a conditional section: heading + text on the first piece, text only on a continuation.
     *
     * @param int $position The position of the piece in the skill body
     * @return AISkillSection
     */
    public function buildPiece(int $position): AISkillSection
    {
        return AISkillSection::conditional(
            $this->selectors,
            $this->level,
            $this->isFirstPieceEmitted ? '' : $this->title,
            $this->getCollectedText(),
            $position
        );
    }

    /**
     * Empties the collected lines and marks the first piece as emitted; the section stays open.
     *
     * @return void
     */
    public function startContinuation(): void
    {
        $this->lines = [];
        $this->isFirstPieceEmitted = true;
    }
}
