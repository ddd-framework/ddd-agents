<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;

/**
 * The `:::Name … :::` container — recognised, never parsed: no reader supports it, so it is one block with the outcome
 * `UNSUPPORTED_SYNTAX` that runs to its `:::` closer line (or the end of the text). Its own block still counts for the
 * ordinals of the message's blocks.
 */
class WidgetContainerSyntaxParser implements WidgetBlockSyntaxParser
{
    public function readBlockAtLine(WidgetBlockSourceText $widgetBlockSourceText, int $lineIndex): ?WidgetBlockSyntaxMatch
    {
        $line = $widgetBlockSourceText->getLine($lineIndex);
        if (!preg_match('/^[ \t]*(:{2,3})([A-Za-z][\w]*)([^\r\n]*)/', $line, $directive) || $directive[1] !== ':::') {
            return null;
        }
        $start = $widgetBlockSourceText->getLineStart($lineIndex);
        $end = $start + strlen($line);
        $lineCount = $widgetBlockSourceText->getLineCount();
        while ($lineIndex + 1 < $lineCount) {
            $innerLine = $widgetBlockSourceText->getLine(++$lineIndex);
            $end = $widgetBlockSourceText->getLineStart($lineIndex) + strlen($innerLine);
            if (preg_match('/^[ \t]*:::[ \t]*(?:\r?\n|\r)?$/', $innerLine)) {
                break;
            }
        }
        return WidgetBlockSyntaxMatch::forBlock($lineIndex, $start, $end - $start, WidgetBlock::SYNTAX_CONTAINER, '', WidgetBlock::OUTCOME_UNSUPPORTED_SYNTAX);
    }

    public function getMessageTextMarkers(): array
    {
        return ['::'];
    }
}
