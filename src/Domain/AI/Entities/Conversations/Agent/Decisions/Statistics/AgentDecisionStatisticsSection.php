<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One section of the decision statistics: a heading, a table (when it has columns — an empty one reads "(no rows)"),
 * text lines and a note, in that order. A section without a title continues the one before it.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionStatisticsSection extends ValueObject
{
    /** @var string The heading; '' = no heading of its own. */
    public string $title = '';

    /** @var string[] The table's columns; empty = the section has no table. */
    public array $columns = [];

    /** @var AgentDecisionStatisticsRow[] The table's rows. */
    public array $rows = [];

    /** @var string[] Text lines after the table. */
    public array $textLines = [];

    /** @var string|null A closing note. */
    public ?string $note = null;

    /**
     * A section with a table of query rows.
     *
     * @param string[] $columns
     * @param array<int, array<string, mixed>> $valueRows
     */
    public static function withTable(string $title, array $columns, array $valueRows): static
    {
        $section = new static();
        $section->title = $title;
        $section->columns = $columns;
        foreach ($valueRows as $valueRow) {
            $section->rows[] = AgentDecisionStatisticsRow::fromValues($valueRow, $columns);
        }
        return $section;
    }

    /**
     * A section of text lines.
     *
     * @param string[] $textLines
     */
    public static function withTextLines(string $title, array $textLines): static
    {
        $section = new static();
        $section->title = $title;
        $section->textLines = $textLines;
        return $section;
    }

    /** The section as Markdown: heading, pipe table, lines, note. */
    public function toLlmMarkdown(): string
    {
        $lines = $this->title !== '' ? ['', '## ' . $this->title] : [];
        if ($this->columns !== []) {
            if ($this->rows === []) {
                $lines[] = '(no rows)';
            } else {
                $lines[] = '| ' . implode(' | ', $this->columns) . ' |';
                $lines[] = '|' . str_repeat(' --- |', count($this->columns));
                foreach ($this->rows as $row) {
                    $lines[] = '| ' . implode(' | ', $row->cells) . ' |';
                }
            }
        }
        foreach ($this->textLines as $textLine) {
            $lines[] = $textLine;
        }
        if ($this->note !== null) {
            $lines[] = 'Note: ' . $this->note;
        }
        return implode("\n", $lines);
    }
}
