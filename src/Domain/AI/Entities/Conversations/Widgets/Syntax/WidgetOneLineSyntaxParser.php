<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;

/**
 * The one-line ```` ```widget {…}``` ```` (spaces allowed) — an inline code span the frontend's bare-paragraph rule
 * renders as a widget when it is a paragraph of its own; a line inside a prose paragraph is read anyway, as the bare
 * rule does, and a one- or two-backtick span is not read (both listed in the parity fixture). A code span: equal
 * backtick runs, no run of that length inside. Syntax `BARE`.
 */
class WidgetOneLineSyntaxParser implements WidgetBlockSyntaxParser
{
    public function readBlockAtLine(WidgetBlockSourceText $widgetBlockSourceText, int $lineIndex): ?WidgetBlockSyntaxMatch
    {
        $line = $widgetBlockSourceText->getLine($lineIndex);
        if (!preg_match('/^[ \t]*(`{3,})[ \t]*widget[ \t]*(\{.*\})[ \t]*\1[ \t]*$/', rtrim($line, "\r\n"), $oneLineWidget)
            || str_contains($oneLineWidget[2], $oneLineWidget[1])
        ) {
            return null;
        }
        return WidgetBlockSyntaxMatch::forBlock($lineIndex, $widgetBlockSourceText->getLineStart($lineIndex), strlen($line), WidgetBlock::SYNTAX_BARE, $oneLineWidget[2]);
    }

    public function getMessageTextMarkers(): array
    {
        return ['widget'];
    }
}
