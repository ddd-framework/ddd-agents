<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Libs\Connections\Documents;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentAnchor;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentRead;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;

/**
 * Turns the JSON of a native document (Docs `documents.get`, Slides `presentations.get`) into the structured read the
 * agent sees: markdown with stable anchors, and the anchor map an edit resolves against. Pure: no I/O, unit tested.
 *
 * Anchors of a document: `p:<n>` the n-th paragraph of the body (1-based), `tbl:<t> r<row>c<column>` a table cell,
 * `nr:<name>` a named range. Anchors of a presentation: `s:<slide> obj:<objectId>` a shape or table with text.
 */
class GoogleDocumentStructuredReader
{
    public const array HEADING_PREFIXES = [
        'TITLE' => '# ', 'SUBTITLE' => '## ', 'HEADING_1' => '# ', 'HEADING_2' => '## ', 'HEADING_3' => '### ',
        'HEADING_4' => '#### ', 'HEADING_5' => '##### ', 'HEADING_6' => '###### ',
    ];

    /** @param array<string, mixed> $documentJson */
    public static function readDocument(array $documentJson, int $maximumCharacters): AgentConnectionDocumentRead
    {
        $documentRead = AgentConnectionDocumentRead::newInstance();
        $documentRead->itemId = (string)($documentJson['documentId'] ?? '');
        $documentRead->kind = AgentConnectionDriveItem::KIND_GOOGLE_DOC;
        $documentRead->title = (string)($documentJson['title'] ?? '');
        $documentRead->revisionId = (string)($documentJson['revisionId'] ?? '');
        $lines = [];
        $paragraphNumber = 0;
        $tableNumber = 0;
        foreach ((array)($documentJson['body']['content'] ?? []) as $structuralElement) {
            if (isset($structuralElement['paragraph'])) {
                $paragraphNumber++;
                $anchor = 'p:' . $paragraphNumber;
                static::addAnchor($documentRead, $anchor, (int)($structuralElement['startIndex'] ?? 0), (int)($structuralElement['endIndex'] ?? 0));
                $text = static::paragraphText((array)$structuralElement['paragraph']);
                if (trim($text) === '') {
                    continue;
                }
                $styleType = (string)($structuralElement['paragraph']['paragraphStyle']['namedStyleType'] ?? 'NORMAL_TEXT');
                $bullet = isset($structuralElement['paragraph']['bullet']) ? '- ' : '';
                $lines[] = '[' . $anchor . '] ' . (static::HEADING_PREFIXES[$styleType] ?? '') . $bullet . $text;
                continue;
            }
            if (isset($structuralElement['table'])) {
                $tableNumber++;
                $lines[] = '[tbl:' . $tableNumber . ']';
                $rowNumber = 0;
                foreach ((array)($structuralElement['table']['tableRows'] ?? []) as $tableRow) {
                    $rowNumber++;
                    $cells = [];
                    $columnNumber = 0;
                    foreach ((array)($tableRow['tableCells'] ?? []) as $tableCell) {
                        $columnNumber++;
                        $cellAnchor = 'tbl:' . $tableNumber . ' r' . $rowNumber . 'c' . $columnNumber;
                        static::addAnchor($documentRead, $cellAnchor, (int)($tableCell['startIndex'] ?? 0), (int)($tableCell['endIndex'] ?? 0));
                        $cellText = '';
                        foreach ((array)($tableCell['content'] ?? []) as $cellElement) {
                            if (isset($cellElement['paragraph'])) {
                                $cellText .= ($cellText !== '' ? ' ' : '') . static::paragraphText((array)$cellElement['paragraph']);
                            }
                        }
                        $cells[] = str_replace('|', '\|', trim($cellText));
                    }
                    $lines[] = '| ' . implode(' | ', $cells) . ' |';
                    if ($rowNumber === 1) {
                        $lines[] = '|' . str_repeat(' --- |', max(1, count($cells)));
                    }
                }
            }
        }
        foreach ((array)($documentJson['namedRanges'] ?? []) as $rangeName => $namedRanges) {
            foreach ((array)($namedRanges['namedRanges'] ?? []) as $namedRange) {
                foreach ((array)($namedRange['ranges'] ?? []) as $range) {
                    static::addAnchor($documentRead, 'nr:' . $rangeName, (int)($range['startIndex'] ?? 0), (int)($range['endIndex'] ?? 0));
                    break 2;
                }
            }
        }
        static::finish($documentRead, $lines, $maximumCharacters);
        return $documentRead;
    }

    /** @param array<string, mixed> $presentationJson */
    public static function readPresentation(array $presentationJson, int $maximumCharacters): AgentConnectionDocumentRead
    {
        $documentRead = AgentConnectionDocumentRead::newInstance();
        $documentRead->itemId = (string)($presentationJson['presentationId'] ?? '');
        $documentRead->kind = AgentConnectionDriveItem::KIND_GOOGLE_SLIDES;
        $documentRead->title = (string)($presentationJson['title'] ?? '');
        $documentRead->revisionId = (string)($presentationJson['revisionId'] ?? '');
        $lines = [];
        $slideNumber = 0;
        foreach ((array)($presentationJson['slides'] ?? []) as $slide) {
            $slideNumber++;
            $lines[] = '## Slide ' . $slideNumber . ' [s:' . $slideNumber . ']';
            foreach ((array)($slide['pageElements'] ?? []) as $pageElement) {
                $objectId = (string)($pageElement['objectId'] ?? '');
                $text = static::shapeText((array)($pageElement['shape']['text']['textElements'] ?? []));
                if (isset($pageElement['table'])) {
                    $rows = [];
                    foreach ((array)($pageElement['table']['tableRows'] ?? []) as $tableRow) {
                        $cells = [];
                        foreach ((array)($tableRow['tableCells'] ?? []) as $tableCell) {
                            $cells[] = str_replace('|', '\|', trim(static::shapeText((array)($tableCell['text']['textElements'] ?? []))));
                        }
                        $rows[] = '| ' . implode(' | ', $cells) . ' |';
                    }
                    $text = implode("\n", $rows);
                }
                if (trim($text) === '') {
                    continue;
                }
                $anchor = 's:' . $slideNumber . ' obj:' . $objectId;
                $documentAnchor = AgentConnectionDocumentAnchor::newInstance();
                $documentAnchor->anchor = $anchor;
                $documentAnchor->objectId = $objectId;
                $documentRead->anchors->add($documentAnchor);
                $lines[] = '[' . $anchor . '] ' . trim($text);
            }
            $notes = '';
            foreach ((array)($slide['slideProperties']['notesPage']['pageElements'] ?? []) as $notesElement) {
                $notes .= static::shapeText((array)($notesElement['shape']['text']['textElements'] ?? []));
            }
            if (trim($notes) !== '') {
                $lines[] = '> Notes: ' . trim(str_replace("\n", ' ', $notes));
            }
        }
        static::finish($documentRead, $lines, $maximumCharacters);
        return $documentRead;
    }

    /** @param array<string, mixed> $paragraph */
    protected static function paragraphText(array $paragraph): string
    {
        $text = '';
        foreach ((array)($paragraph['elements'] ?? []) as $paragraphElement) {
            $text .= (string)($paragraphElement['textRun']['content'] ?? '');
        }
        return rtrim($text, "\n");
    }

    /** @param array<int, array<string, mixed>> $textElements */
    protected static function shapeText(array $textElements): string
    {
        $text = '';
        foreach ($textElements as $textElement) {
            $text .= (string)($textElement['textRun']['content'] ?? '');
        }
        return $text;
    }

    protected static function addAnchor(AgentConnectionDocumentRead $documentRead, string $anchor, int $startIndex, int $endIndex): void
    {
        $documentAnchor = AgentConnectionDocumentAnchor::newInstance();
        $documentAnchor->anchor = $anchor;
        $documentAnchor->startIndex = $startIndex;
        $documentAnchor->endIndex = $endIndex;
        $documentRead->anchors->add($documentAnchor);
    }

    /** @param string[] $lines */
    protected static function finish(AgentConnectionDocumentRead $documentRead, array $lines, int $maximumCharacters): void
    {
        $markdown = implode("\n", $lines);
        $documentRead->isTruncated = mb_strlen($markdown) > $maximumCharacters;
        $documentRead->markdown = $documentRead->isTruncated ? mb_substr($markdown, 0, $maximumCharacters) . "\n…" : $markdown;
    }
}
