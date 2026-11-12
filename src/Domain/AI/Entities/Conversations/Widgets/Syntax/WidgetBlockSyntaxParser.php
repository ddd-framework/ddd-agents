<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

/**
 * One written form of a widget block ("one parser per syntax"): the ```widget fence, the bare
 * `widget {…}` line, the stored `::Name{payload='…'}` leaf, the `:::` container — and any later form such as a tag
 * syntax. {@see \DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks::fromMarkdown()} asks the parsers in their
 * order at every line; the first that reads the line wins. Every parser yields the same envelope
 * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock}), so no reader depends on the syntax.
 *
 * A new syntax = one class implementing this interface + its place in `WidgetBlocks::getSyntaxParsers()` + its markers.
 */
interface WidgetBlockSyntaxParser
{
    /** What this parser reads starting at line `$lineIndex`, or null when the line does not open its form. */
    public function readBlockAtLine(WidgetBlockSourceText $widgetBlockSourceText, int $lineIndex): ?WidgetBlockSyntaxMatch;

    /**
     * Substrings every block of this form contains — the database preselection of the widget view
     * (`AIConversationMessagesService::findWidgetAndReactionRows()`) loads a row when its text carries one of them.
     *
     * @return string[]
     */
    public function getMessageTextMarkers(): array;
}
