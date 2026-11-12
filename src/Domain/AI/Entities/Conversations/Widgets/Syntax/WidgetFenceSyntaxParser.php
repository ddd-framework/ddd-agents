<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Syntax;

/**
 * The fenced form: a block opened by ``` or ~~~ of any length ≥ 3 with at most three spaces of indent
 * and an info string `widget` followed by nothing or whitespace — never a backtick in a backtick fence's info string
 * (CommonMark; ```` ```widget {…}``` ```` on one line is {@see WidgetOneLineSyntaxParser}'s). An ordinary code fence is
 * consumed as text, so nothing inside it is read as a widget. A ``` widget fence follows the frontend's repair
 * (`repairMalformedWidgetFences`): without a closer line before the end of the text or the next ``` line with an info
 * string, it ends right after its balanced JSON object. An unclosed fence runs to the end of the text and is marked so.
 */
class WidgetFenceSyntaxParser implements WidgetBlockSyntaxParser
{
    public function readBlockAtLine(WidgetBlockSourceText $widgetBlockSourceText, int $lineIndex): ?WidgetBlockSyntaxMatch
    {
        $line = $widgetBlockSourceText->getLine($lineIndex);
        if (!preg_match('/^ {0,3}(`{3,}|~{3,})([^\r\n]*)/', $line, $fence) || ($fence[1][0] === '`' && str_contains($fence[2], '`'))) {
            return null;
        }
        $isWidget = preg_match('/^widget(?:\s|$)/', trim($fence[2])) === 1;
        // the frontend repairs a ``` widget fence only — exactly three backticks (repairMalformedWidgetFences)
        $fenceBody = static::readFenceBody($widgetBlockSourceText, $lineIndex + 1, $fence[1], $isWidget && $fence[1] === '```');
        if (!$isWidget) {
            return WidgetBlockSyntaxMatch::forTextLines($fenceBody->lastLineIndex);
        }
        return $fenceBody->buildBlockSyntaxMatch($widgetBlockSourceText->getLineStart($lineIndex));
    }

    public function getMessageTextMarkers(): array
    {
        return ['widget'];
    }

    /**
     * A fence's body from line `$bodyLineIndex` on: up to its closer line (`isClosed`), else to the end of the text. A
     * repairable widget fence follows the frontend's repair (repairMalformedWidgetFences) where no closer line comes
     * before the end of the text or before the next ``` line with an info string: when a JSON object begins the body
     * and balances, the block ends right after its `}` and is closed (the frontend splices a closer there) and the
     * rest is text; otherwise the fence reads as CommonMark does — unclosed when no closer line comes.
     *
     * @param WidgetBlockSourceText $widgetBlockSourceText
     * @param int $bodyLineIndex
     * @param string $openingFence
     * @param bool $isRepairable
     * @return WidgetFenceBody
     */
    public static function readFenceBody(WidgetBlockSourceText $widgetBlockSourceText, int $bodyLineIndex, string $openingFence, bool $isRepairable): WidgetFenceBody
    {
        $markdown = $widgetBlockSourceText->markdown;
        $fenceMarker = $openingFence[0];
        $fenceLength = strlen($openingFence);
        $lineCount = $widgetBlockSourceText->getLineCount();
        $bodyStart = $bodyLineIndex < $lineCount ? $widgetBlockSourceText->getLineStart($bodyLineIndex) : strlen($markdown);
        $widgetJson = '';
        $end = $bodyStart;
        $closed = false;
        $boundaryLineIndex = null;
        $lineIndex = $bodyLineIndex - 1;
        while (++$lineIndex < $lineCount) {
            $innerLine = $widgetBlockSourceText->getLine($lineIndex);
            $end = $widgetBlockSourceText->getLineStart($lineIndex) + strlen($innerLine);
            if (preg_match('/^ {0,3}' . preg_quote($fenceMarker, '/') . '{' . $fenceLength . ',}[ \t]*(?:\r?\n|\r)?$/', $innerLine)) {
                $closed = true;
                break;
            }
            if ($boundaryLineIndex === null && preg_match('/^ {0,3}```([^\r\n]*)/', $innerLine, $delimiter) && trim($delimiter[1]) !== '') {
                $boundaryLineIndex = $lineIndex;
            }
            $widgetJson .= $innerLine;
        }
        if ($isRepairable && (!$closed || $boundaryLineIndex !== null)) {
            $jsonObjectEnd = $widgetBlockSourceText->findBalancedJsonObjectEnd($bodyStart);
            if ($jsonObjectEnd !== null && ($boundaryLineIndex === null || $jsonObjectEnd < $widgetBlockSourceText->getLineStart($boundaryLineIndex))) {
                $lastLineIndex = $bodyLineIndex;
                while ($lastLineIndex + 1 < $lineCount && $widgetBlockSourceText->getLineStart($lastLineIndex + 1) <= $jsonObjectEnd) {
                    $lastLineIndex++;
                }
                // the frontend splices a closer here: the block renders closed
                return WidgetFenceBody::forBodyEnd($jsonObjectEnd + 1, substr($markdown, $bodyStart, $jsonObjectEnd + 1 - $bodyStart), true, $lastLineIndex);
            }
        }
        return WidgetFenceBody::forBodyEnd($end, $widgetJson, $closed, min($lineIndex, $lineCount - 1));
    }
}
