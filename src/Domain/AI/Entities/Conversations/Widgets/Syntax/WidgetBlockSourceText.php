<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The text a message's widget blocks are read from, split into lines (each with its line ending and its byte offset) —
 * what every {@see WidgetBlockSyntaxParser} reads. Built once per {@see \DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks::fromMarkdown()};
 * reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class WidgetBlockSourceText extends ValueObject
{
    /** @var string The whole text — the spans of the blocks index into it. */
    public string $markdown = '';

    /** @var WidgetBlockSourceLine[] Each line with its line ending and its byte offset. */
    protected array $lines = [];

    public static function fromMarkdown(string $markdown): static
    {
        $widgetBlockSourceText = new static();
        $widgetBlockSourceText->markdown = $markdown;
        preg_match_all('/[^\r\n]*(?:\r\n|\n|\r|$)/', $markdown, $sourceLines, PREG_OFFSET_CAPTURE);
        foreach ($sourceLines[0] as [$lineText, $lineStart]) {
            $widgetBlockSourceText->lines[] = WidgetBlockSourceLine::forTextAtStart($lineText, $lineStart);
        }
        return $widgetBlockSourceText;
    }

    public function getLineCount(): int
    {
        return count($this->lines);
    }

    /** The line at `$lineIndex` with its line ending. */
    public function getLine(int $lineIndex): string
    {
        return $this->lines[$lineIndex]->text;
    }

    /** The byte offset of the line at `$lineIndex` in the text. */
    public function getLineStart(int $lineIndex): int
    {
        return $this->lines[$lineIndex]->start;
    }

    /**
     * The byte offset of the `}` closing the JSON object that begins (after whitespace) at `$fromOffset`, or null — the
     * frontend's string-aware brace walk (findBalancedJsonObjectEnd), the same 5000-character cap.
     */
    public function findBalancedJsonObjectEnd(int $fromOffset): ?int
    {
        $markdown = $this->markdown;
        $objectStart = $fromOffset + strspn($markdown, " \t\n\r\f\v", $fromOffset);
        if (($markdown[$objectStart] ?? '') !== '{') {
            return null;
        }
        $depth = 0;
        $inString = false;
        $escaped = false;
        $characterCount = 0;
        for ($offset = $objectStart, $length = strlen($markdown); $offset < $length; $offset++) {
            $byte = $markdown[$offset];
            if ((ord($byte) & 0xC0) !== 0x80 && ++$characterCount > 5000) {
                return null;
            }
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($byte === '\\') {
                $escaped = true;
                continue;
            }
            if ($byte === '"') {
                $inString = !$inString;
            }
            if ($inString) {
                continue;
            }
            if ($byte === '{') {
                $depth++;
            } elseif ($byte === '}' && --$depth === 0) {
                return $offset;
            }
        }
        return null;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(md5($this->markdown));
    }
}
