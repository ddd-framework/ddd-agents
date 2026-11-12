<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE line of the text a message's widget blocks are read from: the line with its line ending and its byte offset in
 * the text. Built by {@see WidgetBlockSourceText::fromMarkdown()}, one per line.
 *
 * @phpstan-consistent-constructor
 */
class WidgetBlockSourceLine extends ValueObject
{
    /** @var string The line with its line ending */
    public string $text = '';

    /** @var int The byte offset of the line in the text */
    public int $start = 0;

    /**
     * @param string $text
     * @param int $start
     * @return static
     */
    public static function forTextAtStart(string $text, int $start): static
    {
        $widgetBlockSourceLine = new static();
        $widgetBlockSourceLine->text = $text;
        $widgetBlockSourceLine->start = $start;
        return $widgetBlockSourceLine;
    }
}
