<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

/**
 * The fenced form with its opener glued to the end of a prose line (the frontend's repair, `repairMalformedWidgetFences`):
 * a ```widget stuck after prose opens a fence when a JSON object begins on the next line — the block starts at the
 * backticks, the prose before them stays text. A line that itself starts with ``` is a fence line, never a glued opener.
 * Asked last: every other form has had its chance at the line.
 */
class WidgetGluedFenceOpenerSyntaxParser implements WidgetBlockSyntaxParser
{
    public function readBlockAtLine(WidgetBlockSourceText $widgetBlockSourceText, int $lineIndex): ?WidgetBlockSyntaxMatch
    {
        $line = $widgetBlockSourceText->getLine($lineIndex);
        // $gluedOpener[0] = the opener with its byte offset in the line (PREG_OFFSET_CAPTURE)
        if (!preg_match('/```[ \t]*widget(?=\s|$)/', $line, $gluedOpener, PREG_OFFSET_CAPTURE)
            || trim(substr($line, 0, $gluedOpener[0][1])) === ''
            || preg_match('/^ {0,3}```/', $line) === 1
            || $lineIndex + 1 >= $widgetBlockSourceText->getLineCount()
            || $widgetBlockSourceText->findBalancedJsonObjectEnd($widgetBlockSourceText->getLineStart($lineIndex + 1)) === null
        ) {
            return null;
        }
        $fenceBody = WidgetFenceSyntaxParser::readFenceBody($widgetBlockSourceText, $lineIndex + 1, '```', true);
        return $fenceBody->buildBlockSyntaxMatch($widgetBlockSourceText->getLineStart($lineIndex) + $gluedOpener[0][1]);
    }

    public function getMessageTextMarkers(): array
    {
        return ['widget'];
    }
}
