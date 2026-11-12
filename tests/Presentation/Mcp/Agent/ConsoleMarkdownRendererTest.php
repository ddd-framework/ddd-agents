<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Agent;

use DDD\Presentation\Mcp\Agent\ConsoleMarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown→terminal renderer (console-tool polish, point 3a/3b). Pure string→lines transform, so it is
 * tested directly. Two axes: decorated (raw ANSI emitted) and plain (`$decorated=false` for piped/--debug output,
 * styling dropped but structure/alignment kept).
 */
class ConsoleMarkdownRendererTest extends TestCase
{
    protected ConsoleMarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ConsoleMarkdownRenderer();
    }

    public function testPlainModeStripsAllStylingButKeepsText(): void
    {
        $lines = $this->renderer->render("**bold** and *italic* and `code`", decorated: false);
        $this->assertSame(['bold and italic and code'], $lines);
        $this->assertStringNotContainsString("\033", implode('', $lines), 'no ANSI in plain mode');
    }

    public function testBoldAndInlineCodeEmitAnsiWhenDecorated(): void
    {
        $lines = $this->renderer->render('**x** `y`', decorated: true);
        $this->assertStringContainsString("\033[1m", $lines[0], 'bold sequence present');
        $this->assertStringContainsString("\033[36m", $lines[0], 'inline-code (cyan) sequence present');
    }

    public function testHeaderAndBulletsAndOrderedList(): void
    {
        $lines = $this->renderer->render("# Title\n- one\n- two\n1. first", decorated: false);
        $this->assertSame('Title', $lines[0]);
        $this->assertSame('* one', $lines[1]);
        $this->assertSame('* two', $lines[2]);
        $this->assertSame('1. first', $lines[3]);
    }

    public function testFencedCodeBlockEmittedVerbatim(): void
    {
        $lines = $this->renderer->render("```\n**not bold** inside\n```", decorated: false);
        $this->assertSame(['**not bold** inside'], $lines, 'fence content is not inline-parsed, fences dropped');
    }

    public function testPipeTableAlignsColumns(): void
    {
        $markdown = "| Keyword | Pos |\n|---|---|\n| zahnarzt | 7 |\n| implantologie | 1 |";
        $lines = $this->renderer->render($markdown, decorated: false);
        // Header + divider + 2 rows.
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('Keyword', $lines[0]);
        $this->assertStringContainsString('implantologie', $lines[3]);
        // Every body cell column is padded to the widest value ("implantologie" = 13) → header cell padded too.
        $this->assertStringContainsString('zahnarzt     ', $lines[2], 'first column padded to widest cell');
    }

    public function testLiteralAngleBracketsSurviveUnescaped(): void
    {
        // Raw ANSI (not Symfony tags) → model text with < > must pass through untouched.
        $lines = $this->renderer->render('use <Entity> here', decorated: true);
        $this->assertStringContainsString('<Entity>', $lines[0]);
    }
}
