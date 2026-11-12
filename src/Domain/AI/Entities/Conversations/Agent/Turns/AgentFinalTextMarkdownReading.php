<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use Parsedown;

/**
 * The Markdown structure of one final text that a typed final-text check reads: the fenced code
 * blocks the text carries beside its widget blocks, and the LaTeX formulas `\[ … \]` / `\( … \)` in its prose. Read by
 * a Markdown parse — erusev/parsedown, the parser the project already ships (league/commonmark is not installed) —
 * never by a pattern over the whole text: a fence is what the parser opens as a fenced code block and only counts with a
 * non-empty body; the closing backticks a widget block's JSON ran into leave with the block
 * ({@see WidgetBlocks::buildMarkdownWithoutBlocksAndTheirGluedClosers()}), so they open no fence over the prose after it;
 * a formula starts where the inline parse reads the escape sequence `\[` or `\(`, so a backslash inside a code span or a
 * code block is no formula. Such a delimiter counts when its closer follows and the content between holds math (a
 * backslash command, `^`, `_` or `=`), or when `\[` stands alone on its line (display math) — an escaped placeholder
 * `\[Name\]` and a footnote `\[1\]` never count. The widget blocks are taken out first, so a ```widget fence is no code
 * sample. Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class AgentFinalTextMarkdownReading extends Parsedown
{
    /** The info string of a widget fence (`WidgetFenceSyntaxParser`) — a fence the widget parsers left in the text is no code sample. */
    public const string WIDGET_FENCE_LANGUAGE = 'widget';

    /** What makes the content between `\[` and `\]` (or `\(` and `\)`) a formula: a backslash command, `^`, `_` or `=`. */
    public const string MATH_CONTENT_PATTERN = '/\\\\[A-Za-z]+|[\^_=]/';

    /** The key under which a fenced code block carries its info string's first word until the block completes. */
    protected const string CODE_FENCE_LANGUAGE_BLOCK_KEY = 'codeFenceLanguage';

    /** @var string[] The info string's first word of every fenced code block with a non-empty body, text order; '' for a fence without one. */
    public array $codeFenceLanguages = [];

    /** @var int How many LaTeX formulas (an opening `\[` / `\(` that counts, see the class docblock) the inline text holds. */
    public int $latexDelimiterCount = 0;

    /** The structure of `$markdown`, its widget blocks left out. */
    public static function fromFinalText(string $markdown): static
    {
        $agentFinalTextMarkdownReading = new static();
        $agentFinalTextMarkdownReading->setSafeMode(true);
        $agentFinalTextMarkdownReading->text(WidgetBlocks::fromMarkdown($markdown)->buildMarkdownWithoutBlocksAndTheirGluedClosers());
        return $agentFinalTextMarkdownReading;
    }

    /** Whether the text carries a code sample (a fence that is not a widget fence) or a LaTeX formula in its prose. */
    public function carriesCodeFenceOrLatexFormula(): bool
    {
        if ($this->latexDelimiterCount > 0) {
            return true;
        }
        foreach ($this->codeFenceLanguages as $codeFenceLanguage) {
            if ($codeFenceLanguage !== self::WIDGET_FENCE_LANGUAGE) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $Line
     * @return array<string, mixed>|null
     */
    protected function blockFencedCode($Line)
    {
        $fencedCodeBlock = parent::blockFencedCode($Line);
        if ($fencedCodeBlock !== null) {
            $codeFenceInfoString = trim(substr((string)$Line['text'], (int)$fencedCodeBlock['openerLength']), "\t ");
            $fencedCodeBlock[self::CODE_FENCE_LANGUAGE_BLOCK_KEY] = strtolower(explode(' ', str_replace("\t", ' ', $codeFenceInfoString))[0]);
        }
        return $fencedCodeBlock;
    }

    /**
     * A fenced code block counts once it ends (closed or at the end of the text) and only with a non-empty body.
     *
     * @param array<string, mixed> $Block
     * @return array<string, mixed>
     */
    protected function blockFencedCodeComplete($Block)
    {
        if (trim((string)($Block['element']['element']['text'] ?? '')) !== '') {
            $this->codeFenceLanguages[] = (string)($Block[self::CODE_FENCE_LANGUAGE_BLOCK_KEY] ?? '');
        }
        return parent::blockFencedCodeComplete($Block);
    }

    /**
     * @param array<string, mixed> $Excerpt
     * @return array<string, mixed>|null
     */
    protected function inlineEscapeSequence($Excerpt)
    {
        if (self::opensLatexFormula((string)$Excerpt['text'], (string)($Excerpt['context'] ?? ''))) {
            $this->latexDelimiterCount++;
        }
        return parent::inlineEscapeSequence($Excerpt);
    }

    /**
     * Whether the escape sequence at the start of `$textFromBackslash` (the rest of the inline text `$inlineText` from
     * the backslash on) opens a LaTeX formula: `\[` / `\(` whose closer `\]` / `\)` follows with math between, or a `\[`
     * that stands alone on its line.
     */
    protected static function opensLatexFormula(string $textFromBackslash, string $inlineText): bool
    {
        $delimiterCloser = match ($textFromBackslash[1] ?? null) {
            '[' => '\\]',
            '(' => '\\)',
            default => null,
        };
        if ($delimiterCloser === null) {
            return false;
        }
        $closerPosition = strpos($textFromBackslash, $delimiterCloser, 2);
        if ($closerPosition !== false && preg_match(self::MATH_CONTENT_PATTERN, substr($textFromBackslash, 2, $closerPosition - 2)) === 1) {
            return true;
        }
        if ($delimiterCloser !== '\\]' || trim(explode("\n", substr($textFromBackslash, 2), 2)[0]) !== '') {
            return false;
        }
        $textBeforeDelimiter = substr($inlineText, 0, strlen($inlineText) - strlen($textFromBackslash));
        $lineStartPosition = strrpos($textBeforeDelimiter, "\n");
        return trim(substr($textBeforeDelimiter, $lineStartPosition === false ? 0 : $lineStartPosition + 1)) === '';
    }
}
