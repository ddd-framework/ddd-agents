<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Agent\Tui;

/**
 * One vertically-stacked block of the TUI transcript (a user turn, an agent answer, a tool call, a metrics line).
 * Pure data + collapse state — no rendering substrate, no terminal. The TUI shell renders a list of these and the
 * pure {@see ConsoleViewModel} maps a click row back to the block it hit.
 *
 * A block has a `headerLine` (always shown) and optional `bodyLines` (the detail). When `collapsible` is true the
 * body is hidden until `expanded` — this is the click-to-expand tool result. When false (a plain text/agent block)
 * the body is part of the content and always shown.
 */
class ConsoleViewBlock
{
    /** @param string[] $bodyLines */
    public function __construct(
        public readonly string $id,
        public readonly string $headerLine,
        public array $bodyLines = [],
        public readonly bool $collapsible = false,
        public bool $expanded = false,
    ) {
    }

    /** The lines this block currently contributes: header always; body when not collapsible, or when expanded. */
    public function visibleLines(): array
    {
        if (!$this->collapsible || $this->expanded) {
            return array_merge([$this->headerLine], $this->bodyLines);
        }
        return [$this->headerLine];
    }

    /** A collapsible block toggles; a non-collapsible block ignores the toggle. Returns the new expanded state. */
    public function toggle(): bool
    {
        if ($this->collapsible) {
            $this->expanded = !$this->expanded;
        }
        return $this->expanded;
    }
}
