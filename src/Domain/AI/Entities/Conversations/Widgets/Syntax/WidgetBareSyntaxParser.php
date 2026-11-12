<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;

/**
 * The bare `widget {…}` paragraph the model writes when it drops the backticks (the frontend's `BARE_WIDGET_PARAGRAPH`):
 * continued over non-blank lines until it ends in `}`.
 */
class WidgetBareSyntaxParser implements WidgetBlockSyntaxParser
{
    public function readBlockAtLine(WidgetBlockSourceText $widgetBlockSourceText, int $lineIndex): ?WidgetBlockSyntaxMatch
    {
        $line = $widgetBlockSourceText->getLine($lineIndex);
        if (!preg_match('/^[ \t]*widget[ \t]*(\{[^\r\n]*)/', $line, $bareWidget)) {
            return null;
        }
        $start = $widgetBlockSourceText->getLineStart($lineIndex);
        $widgetJson = $bareWidget[1];
        $end = $start + strlen($line);
        $lineCount = $widgetBlockSourceText->getLineCount();
        while ($lineIndex + 1 < $lineCount && trim($widgetBlockSourceText->getLine($lineIndex + 1)) !== '' && !str_ends_with(trim($widgetJson), '}')) {
            $innerLine = $widgetBlockSourceText->getLine(++$lineIndex);
            $widgetJson .= "\n" . $innerLine;
            $end = $widgetBlockSourceText->getLineStart($lineIndex) + strlen($innerLine);
        }
        return WidgetBlockSyntaxMatch::forBlock($lineIndex, $start, $end - $start, WidgetBlock::SYNTAX_BARE, $widgetJson);
    }

    public function getMessageTextMarkers(): array
    {
        return ['widget'];
    }
}
