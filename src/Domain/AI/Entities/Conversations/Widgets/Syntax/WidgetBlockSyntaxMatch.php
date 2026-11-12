<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one {@see WidgetBlockSyntaxParser} read at a line: the lines it consumed and, when they hold a
 * widget block, the block's span, syntax, JSON as written, closure and — for a syntax that decides it itself — its
 * outcome. A consumed ordinary code fence carries no block (its lines are text). Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class WidgetBlockSyntaxMatch extends ValueObject
{
    /** @var int The last line the parser consumed; the scan continues after it. */
    public int $lastLineIndex = 0;

    /** @var bool Whether the consumed lines hold a widget block (false for an ordinary code fence). */
    public bool $carriesBlock = true;

    /** @var int Byte offset of the block in the text. */
    public int $start = 0;

    /** @var int Bytes the block spans. */
    public int $length = 0;

    /** @var string One of the `WidgetBlock::SYNTAX_*` constants. */
    public string $syntax = '';

    /** @var string The block's JSON as written (a leaf's attributes re-encoded); empty when there is none. */
    public string $widgetJson = '';

    /** @var string|null An outcome the syntax decides itself (`UNSUPPORTED_SYNTAX`, `MALFORMED` for an unreadable leaf); null = the JSON decides. */
    public ?string $syntaxOutcome = null;

    /** @var bool Whether the block renders closed. */
    public bool $isClosed = true;

    /** A match holding one widget block. */
    public static function forBlock(int $lastLineIndex, int $start, int $length, string $syntax, string $widgetJson, ?string $syntaxOutcome = null, bool $isClosed = true): static
    {
        $widgetBlockSyntaxMatch = new static();
        $widgetBlockSyntaxMatch->lastLineIndex = $lastLineIndex;
        $widgetBlockSyntaxMatch->start = $start;
        $widgetBlockSyntaxMatch->length = $length;
        $widgetBlockSyntaxMatch->syntax = $syntax;
        $widgetBlockSyntaxMatch->widgetJson = $widgetJson;
        $widgetBlockSyntaxMatch->syntaxOutcome = $syntaxOutcome;
        $widgetBlockSyntaxMatch->isClosed = $isClosed;
        return $widgetBlockSyntaxMatch;
    }

    /** A match that consumed lines without a widget block (an ordinary code fence). */
    public static function forTextLines(int $lastLineIndex): static
    {
        $widgetBlockSyntaxMatch = new static();
        $widgetBlockSyntaxMatch->lastLineIndex = $lastLineIndex;
        $widgetBlockSyntaxMatch->carriesBlock = false;
        return $widgetBlockSyntaxMatch;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->start . '-' . $this->lastLineIndex);
    }
}
