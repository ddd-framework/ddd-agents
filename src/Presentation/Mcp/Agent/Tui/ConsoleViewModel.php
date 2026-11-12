<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Agent\Tui;

/**
 * The TUI transcript as an ordered list of {@see ConsoleViewBlock}s, plus the pure layout math the shell needs:
 * the flat list of visible lines (respecting each block's collapse state) and the click→block hit-test. No
 * terminal, no php-tui types — so it is fully unit-tested; the shell only drives it (append blocks, render the
 * visible lines, forward a click row to {@see toggleAtRow()}).
 *
 * "Row" here is the ABSOLUTE visible-line index (0-based) into {@see visibleLines()}; the shell adds its scroll
 * offset before calling, so this class stays free of scroll/viewport state.
 */
class ConsoleViewModel
{
    /** @var array<int, ConsoleViewBlock> */
    protected array $blocks = [];

    public function append(ConsoleViewBlock $block): void
    {
        $this->blocks[] = $block;
    }

    /** @return array<int, ConsoleViewBlock> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * The flat transcript as it is currently shown: every block's visible lines in order, each tagged with its
     * block id (so a rendered row can be mapped back to its block for hit-testing).
     *
     * @return ConsoleViewLine[]
     */
    public function visibleLines(): array
    {
        $lines = [];
        foreach ($this->blocks as $block) {
            foreach ($block->visibleLines() as $text) {
                $lines[] = new ConsoleViewLine($block->id, $text);
            }
        }
        return $lines;
    }

    /** The block shown at the given absolute visible row, or null if the row is past the end. */
    public function blockAtRow(int $row): ?ConsoleViewBlock
    {
        $lines = $this->visibleLines();
        if ($row < 0 || $row >= count($lines)) {
            return null;
        }
        $blockId = $lines[$row]->blockId;
        foreach ($this->blocks as $block) {
            if ($block->id === $blockId) {
                return $block;
            }
        }
        return null;
    }

    /**
     * Click handler: toggle the collapsible block at the given absolute visible row. Returns true if a block was
     * actually toggled (i.e. the row hit a collapsible block) — the shell redraws when true.
     */
    public function toggleAtRow(int $row): bool
    {
        $block = $this->blockAtRow($row);
        if ($block === null || !$block->collapsible) {
            return false;
        }
        $block->toggle();
        return true;
    }

    /** Total number of currently-visible rows (for scroll clamping in the shell). */
    public function visibleRowCount(): int
    {
        return count($this->visibleLines());
    }

    /**
     * The slice of visible lines for a viewport of `$height` rows starting at `$scrollOffset` (clamped). Pure
     * scroll math so the shell stays trivial; returns the line texts only (hit-testing uses the absolute row).
     *
     * @return array<int, string>
     */
    public function viewportLines(int $scrollOffset, int $height): array
    {
        if ($height <= 0) {
            return [];
        }
        $texts = array_map(static fn (ConsoleViewLine $consoleViewLine): string => $consoleViewLine->text, $this->visibleLines());
        $maxOffset = max(0, count($texts) - $height);
        $offset = max(0, min($scrollOffset, $maxOffset));
        return array_slice($texts, $offset, $height);
    }

    /** The largest valid scroll offset for a viewport of `$height` rows (so the shell can clamp / jump to bottom). */
    public function maxScrollOffset(int $height): int
    {
        return max(0, $this->visibleRowCount() - max(1, $height));
    }
}
