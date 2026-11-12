<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Agent;

/**
 * Renders the Markdown the agent emits into terminal-ready lines. Pure string → string[] transform (no output
 * coupling, no domain state) so it is unit-tested directly and reused by both the live printer and any other
 * console surface.
 *
 * Emits RAW ANSI escape sequences (not Symfony `<tag>` markup) for two reasons: model text frequently contains
 * literal `<`/`>` that would collide with Symfony's formatter, and raw ANSI lets the SAME output be written to a
 * decorated terminal or stripped to plain text via the `$decorated` flag — the caller passes `OutputInterface::
 * isDecorated()`, so piped/`--debug` output stays plain and forensically faithful.
 *
 * Covered: ATX headers (`#`..`######`), bold (`**`/`__`), italic (`*`/`_`), inline code (`` ` ``), fenced code
 * blocks (```` ``` ````), unordered (`-`/`*`/`+`) and ordered (`1.`) lists, blockquotes (`>`), and GitHub pipe
 * tables (aligned to the widest cell per column). Anything unrecognised passes through verbatim.
 */
class ConsoleMarkdownRenderer
{
    protected const string RESET = "\033[0m";
    protected const string BOLD = "\033[1m";
    protected const string ITALIC = "\033[3m";
    protected const string UNDERLINE = "\033[4m";
    protected const string DIM = "\033[2m";
    protected const string CYAN = "\033[36m";
    protected const string YELLOW = "\033[33m";

    /**
     * Render Markdown to a list of terminal lines. When `$decorated` is false, all styling is dropped and the raw
     * text content is returned (tables still align — alignment is not decoration).
     *
     * @return string[]
     */
    public function render(string $markdown, bool $decorated = true): array
    {
        $lines = [];
        $sourceLines = explode("\n", $markdown);
        $count = count($sourceLines);
        $index = 0;

        while ($index < $count) {
            $line = $sourceLines[$index];

            // Fenced code block: emit verbatim (dimmed) until the closing fence.
            if (preg_match('/^\s*```/', $line)) {
                $index++;
                while ($index < $count && !preg_match('/^\s*```/', $sourceLines[$index])) {
                    $lines[] = $decorated ? self::DIM . $sourceLines[$index] . self::RESET : $sourceLines[$index];
                    $index++;
                }
                $index++; // skip the closing fence
                continue;
            }

            // Pipe table: a header line containing `|` immediately followed by a separator row (---|---).
            if (str_contains($line, '|')
                && $index + 1 < $count
                && preg_match('/^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/', $sourceLines[$index + 1])
            ) {
                $consoleRenderedTable = $this->renderTable($sourceLines, $index, $decorated);
                foreach ($consoleRenderedTable->renderedLines as $tableLine) {
                    $lines[] = $tableLine;
                }
                $index += $consoleRenderedTable->consumedSourceLineCount;
                continue;
            }

            $lines[] = $this->renderBlockLine($line, $decorated);
            $index++;
        }

        return $lines;
    }

    /** Render a single non-table, non-fence line: headers, list markers, blockquotes, then inline spans. */
    protected function renderBlockLine(string $line, bool $decorated): string
    {
        // ATX header — strip the leading #'s, render bold+underline.
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $text = $this->renderInline($m[2], $decorated);
            return $decorated ? self::BOLD . self::UNDERLINE . $text . self::RESET : $text;
        }
        // Blockquote.
        if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
            $text = $this->renderInline($m[1], $decorated);
            return ($decorated ? self::DIM . '│ ' . self::RESET : '| ') . $text;
        }
        // Unordered list item → bullet.
        if (preg_match('/^(\s*)[-*+]\s+(.*)$/', $line, $m)) {
            $bullet = $decorated ? self::YELLOW . '•' . self::RESET : '*';
            return $m[1] . $bullet . ' ' . $this->renderInline($m[2], $decorated);
        }
        // Ordered list item → keep the number.
        if (preg_match('/^(\s*)(\d+)\.\s+(.*)$/', $line, $m)) {
            $marker = $decorated ? self::YELLOW . $m[2] . '.' . self::RESET : $m[2] . '.';
            return $m[1] . $marker . ' ' . $this->renderInline($m[3], $decorated);
        }
        return $this->renderInline($line, $decorated);
    }

    /** Apply inline spans: inline code first (so its content is not re-parsed), then bold, then italic. */
    protected function renderInline(string $text, bool $decorated): string
    {
        // Inline code — replace with a placeholder so bold/italic inside backticks is left literal.
        $codeSpans = [];
        $text = (string)preg_replace_callback('/`([^`]+)`/', function (array $m) use (&$codeSpans, $decorated): string {
            $token = "\x00CODE" . count($codeSpans) . "\x00";
            $codeSpans[$token] = $decorated ? self::CYAN . $m[1] . self::RESET : $m[1];
            return $token;
        }, $text);

        if ($decorated) {
            // Bold (**x** / __x__) before italic so the inner markers of bold aren't eaten by the italic rule.
            $text = (string)preg_replace('/\*\*(.+?)\*\*/', self::BOLD . '$1' . self::RESET, $text);
            $text = (string)preg_replace('/__(.+?)__/', self::BOLD . '$1' . self::RESET, $text);
            $text = (string)preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?!\*)/', self::ITALIC . '$1' . self::RESET, $text);
            $text = (string)preg_replace('/(?<![_\w])_(?!\s)(.+?)(?<!\s)_(?!_)/', self::ITALIC . '$1' . self::RESET, $text);
        } else {
            $text = (string)preg_replace(['/\*\*(.+?)\*\*/', '/__(.+?)__/'], '$1', $text);
            $text = (string)preg_replace(['/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?!\*)/', '/(?<![_\w])_(?!\s)(.+?)(?<!\s)_(?!_)/'], '$1', $text);
        }

        return strtr($text, $codeSpans);
    }

    /**
     * Render a GitHub pipe table starting at `$start` (header row; `$start+1` is the separator). Columns are padded
     * to the widest cell.
     *
     * @param string[] $sourceLines
     * @param int $start
     * @param bool $decorated
     * @return ConsoleRenderedTable
     */
    protected function renderTable(array $sourceLines, int $start, bool $decorated): ConsoleRenderedTable
    {
        $rows = [];
        $index = $start;
        $count = count($sourceLines);
        // Header row.
        $header = $this->splitTableRow($sourceLines[$index]);
        $index += 2; // skip header + separator
        while ($index < $count && str_contains($sourceLines[$index], '|')) {
            $rows[] = $this->splitTableRow($sourceLines[$index]);
            $index++;
        }

        // Column widths from header + body (measured on the styling-free text).
        $columnCount = count($header);
        $widths = [];
        foreach ($header as $col => $cell) {
            $widths[$col] = mb_strlen($cell);
        }
        foreach ($rows as $row) {
            foreach ($row as $col => $cell) {
                $widths[$col] = max($widths[$col] ?? 0, mb_strlen($cell));
            }
        }

        $renderCells = function (array $cells, bool $boldHeader) use ($widths, $columnCount, $decorated): string {
            $out = [];
            for ($col = 0; $col < $columnCount; $col++) {
                $raw = $cells[$col] ?? '';
                $padded = $raw . str_repeat(' ', max(0, ($widths[$col] ?? 0) - mb_strlen($raw)));
                $styled = $this->renderInline($padded, $decorated);
                if ($boldHeader && $decorated) {
                    $styled = self::BOLD . $styled . self::RESET;
                }
                $out[] = $styled;
            }
            $sep = $decorated ? self::DIM . ' │ ' . self::RESET : ' | ';
            return implode($sep, $out);
        };

        $lines = [$renderCells($header, true)];
        // Divider under the header.
        $dividerParts = [];
        for ($col = 0; $col < $columnCount; $col++) {
            $dividerParts[] = str_repeat('─', $widths[$col] ?? 0);
        }
        $divider = implode('─┼─', $dividerParts);
        $lines[] = $decorated ? self::DIM . $divider . self::RESET : $divider;
        foreach ($rows as $row) {
            $lines[] = $renderCells($row, false);
        }

        return new ConsoleRenderedTable($lines, $index - $start);
    }

    /**
     * Split a `| a | b | c |` row into trimmed cells (leading/trailing pipes optional).
     *
     * @return string[]
     */
    protected function splitTableRow(string $line): array
    {
        $line = trim($line);
        $line = (string)preg_replace('/^\||\|$/', '', $line);
        return array_map('trim', explode('|', $line));
    }
}
