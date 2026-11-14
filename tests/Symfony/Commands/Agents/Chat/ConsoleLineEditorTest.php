<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Chat;

use DDD\Symfony\Commands\Agents\Chat\ConsoleLineEditor;
use DDD\Symfony\Commands\Agents\Chat\ConsoleLineEditorKeyAction;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Unit tests for the raw-mode line editor's PURE key handling — the parts that don't need a TTY:
 * {@see ConsoleLineEditor::interpretKey} (byte sequence → {@see ConsoleLineEditorKeyAction}) and
 * {@see ConsoleLineEditor::applyAction} (action → the new {@see \DDD\Symfony\Commands\Agents\Chat\ConsoleLineEditorState}). The read loop + repaint need a real terminal and are verified interactively.
 */
class ConsoleLineEditorTest extends UnitTestCase
{
    protected ConsoleLineEditor $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->editor = new ConsoleLineEditor();
    }

    /** @return string[] the UTF-8 characters of the text */
    protected function characters(string $text): array
    {
        return $text === '' ? [] : preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    protected function action(string $actionType): ConsoleLineEditorKeyAction
    {
        return new ConsoleLineEditorKeyAction($actionType);
    }

    public function testInterpretArrowsAndControlKeys(): void
    {
        $cases = [
            // arrows (CSI + SS3 application-cursor forms)
            ["\x1b[D", 'left'], ["\x1bOD", 'left'], ["\x1b[C", 'right'], ["\x1bOC", 'right'],
            ["\x1b[A", 'up'], ["\x1b[B", 'down'],
            ["\x1b[H", 'home'], ["\x1b[F", 'end'], ["\x1b[3~", 'delete'],
            ["\x1b", 'clear'], // bare Escape clears the input
            ["\x1b[200~", 'pasteStart'], // bracketed-paste begin (multi-line paste handled as literal text)
            // word navigation — Option/Alt (ESC b / ESC f) AND modified arrows (Option/Ctrl)
            ["\x1bb", 'wordLeft'], ["\x1bf", 'wordRight'],
            ["\x1b[1;3D", 'wordLeft'], ["\x1b[1;3C", 'wordRight'],
            ["\x1b[1;5D", 'wordLeft'], ["\x1b[1;5C", 'wordRight'],
            // soft newline (Option/Alt+Enter, Shift+Enter CSI-u)
            ["\x1b\r", 'newline'], ["\x1b\n", 'newline'], ["\x1b[13;2u", 'newline'],
            // control chars
            ["\x03", 'cancel'], ["\x04", 'eof'], ["\r", 'submit'], ["\n", 'submit'],
            ["\x7f", 'backspace'], ["\x01", 'home'], ["\x05", 'end'],
            ["\x17", 'deleteWord'], ["\x1b\x7f", 'deleteWord'],
            ["\x15", 'killToStart'], ["\x0b", 'killToEnd'],
        ];
        foreach ($cases as [$key, $expected]) {
            $this->assertSame($expected, $this->editor->interpretKey($key)->type, 'key ' . bin2hex($key));
        }
    }

    public function testInterpretPrintableAndUtf8(): void
    {
        $insertAction = $this->editor->interpretKey('a');
        $this->assertSame(ConsoleLineEditorKeyAction::TYPE_INSERT, $insertAction->type);
        $this->assertSame('a', $insertAction->insertedCharacter);
        // an umlaut (multi-byte UTF-8) is a single inserted character
        $this->assertSame('ä', $this->editor->interpretKey('ä')->insertedCharacter);
        // a stray/unknown escape is a no-op, not inserted as garbage
        $noopAction = $this->editor->interpretKey("\x1b[Z");
        $this->assertSame('noop', $noopAction->type);
        $this->assertNull($noopAction->insertedCharacter);
        // a control key carries no character
        $this->assertNull($this->editor->interpretKey("\x1b[D")->insertedCharacter);
    }

    public function testInsertAtCursor(): void
    {
        $editorState = $this->editor->applyAction(ConsoleLineEditorKeyAction::forInsertedCharacter('X'), $this->characters('ac'), 1);
        $this->assertSame('aXc', $editorState->getText());
        $this->assertSame(['a', 'X', 'c'], $editorState->characters);
        $this->assertSame(2, $editorState->cursorIndex);
        $this->assertSame(ConsoleLineEditor::STATUS_EDITING, $editorState->status);
    }

    public function testCursorBoundsAndHomeEnd(): void
    {
        $characters = $this->characters('hello');
        $this->assertSame(0, $this->editor->applyAction($this->action('left'), $characters, 0)->cursorIndex);   // clamped
        $this->assertSame(5, $this->editor->applyAction($this->action('right'), $characters, 5)->cursorIndex);  // clamped
        $this->assertSame(0, $this->editor->applyAction($this->action('home'), $characters, 3)->cursorIndex);
        $this->assertSame(5, $this->editor->applyAction($this->action('end'), $characters, 1)->cursorIndex);
    }

    public function testWordNavigation(): void
    {
        $characters = $this->characters('foo bar baz');
        // from end → start of "baz"
        $this->assertSame(8, $this->editor->applyAction($this->action('wordLeft'), $characters, 11)->cursorIndex);
        // from start → end of "foo"
        $this->assertSame(3, $this->editor->applyAction($this->action('wordRight'), $characters, 0)->cursorIndex);
        // wordLeft skips trailing spaces first
        $this->assertSame(4, $this->editor->applyAction($this->action('wordLeft'), $characters, 7)->cursorIndex);
    }

    public function testBackspaceDeleteAndKills(): void
    {
        // backspace removes the char before the cursor
        $editorState = $this->editor->applyAction($this->action('backspace'), $this->characters('abc'), 2);
        $this->assertSame('ac', $editorState->getText());
        $this->assertSame(1, $editorState->cursorIndex);
        // delete removes the char at the cursor
        $this->assertSame('ab', $this->editor->applyAction($this->action('delete'), $this->characters('abc'), 2)->getText());
        // deleteWord removes the word before the cursor
        $editorState = $this->editor->applyAction($this->action('deleteWord'), $this->characters('foo bar'), 7);
        $this->assertSame('foo ', $editorState->getText());
        $this->assertSame(4, $editorState->cursorIndex);
        // killToStart / killToEnd
        $this->assertSame('cd', $this->editor->applyAction($this->action('killToStart'), $this->characters('abcd'), 2)->getText());
        $this->assertSame('ab', $this->editor->applyAction($this->action('killToEnd'), $this->characters('abcd'), 2)->getText());
    }

    public function testNewlineInsertsLineBreakAndClear(): void
    {
        $editorState = $this->editor->applyAction($this->action('newline'), $this->characters('ab'), 2);
        $this->assertSame("ab\n", $editorState->getText());
        $this->assertSame(3, $editorState->cursorIndex);
        $this->assertSame(ConsoleLineEditor::STATUS_EDITING, $editorState->status);
        // Esc clears the whole buffer
        $editorState = $this->editor->applyAction($this->action('clear'), $this->characters("ab\ncd"), 4);
        $this->assertSame('', $editorState->getText());
        $this->assertSame(0, $editorState->cursorIndex);
    }

    public function testCursorPositionAndVerticalMove(): void
    {
        $characters = $this->characters("foo\nbarbar"); // line0 "foo" (0..3), "\n" at 3, line1 "barbar" (4..10)
        $cursorPosition = $this->editor->findCursorPosition($characters, 2);       // 'o' on line 0
        $this->assertSame([0, 2], [$cursorPosition->lineIndex, $cursorPosition->columnIndex]);
        $cursorPosition = $this->editor->findCursorPosition($characters, 7);       // within "barbar" on line 1
        $this->assertSame([1, 3], [$cursorPosition->lineIndex, $cursorPosition->columnIndex]);
        $cursorPosition = $this->editor->findCursorPosition($characters, 99);      // past the end: the end of the last line
        $this->assertSame([1, 6], [$cursorPosition->lineIndex, $cursorPosition->columnIndex]);
        // up from (1, col5) → (0, min(5, len "foo"=3)) = index 3 (end of "foo")
        $editorState = $this->editor->applyAction($this->action('up'), $characters, 9);
        $this->assertSame(3, $editorState->cursorIndex);
        $this->assertSame(ConsoleLineEditor::STATUS_EDITING, $editorState->status);
        // down from line 0 col 2 → line 1 col 2 = index 4 + 2 = 6
        $this->assertSame(6, $this->editor->applyAction($this->action('down'), $characters, 2)->cursorIndex);
        // up on the FIRST line → history recall
        $this->assertSame(ConsoleLineEditor::STATUS_HISTORY_PREV, $this->editor->applyAction($this->action('up'), $characters, 2)->status);
        // down on the LAST line → history recall
        $this->assertSame(ConsoleLineEditor::STATUS_HISTORY_NEXT, $this->editor->applyAction($this->action('down'), $characters, 7)->status);
    }

    public function testVisualRowsWrapMath(): void
    {
        $this->assertSame(1, $this->editor->visualRows(0, 80));   // empty line still occupies one row
        $this->assertSame(1, $this->editor->visualRows(80, 80));  // exactly full → one row
        $this->assertSame(2, $this->editor->visualRows(81, 80));  // one over → wraps to two
        $this->assertSame(2, $this->editor->visualRows(160, 80));
        $this->assertSame(3, $this->editor->visualRows(161, 80));
    }

    public function testHomeEndAreLineScoped(): void
    {
        $characters = $this->characters("foo\nbar"); // cursor at index 6 (the 'r' position) on line 1
        $this->assertSame(4, $this->editor->applyAction($this->action('home'), $characters, 6)->cursorIndex); // start of "bar"
        $this->assertSame(7, $this->editor->applyAction($this->action('end'), $characters, 5)->cursorIndex);  // end of "bar"
    }

    public function testSubmitCancelEof(): void
    {
        $this->assertSame(ConsoleLineEditor::STATUS_SUBMIT, $this->editor->applyAction($this->action('submit'), $this->characters('hi'), 2)->status);
        $this->assertSame(ConsoleLineEditor::STATUS_CANCEL, $this->editor->applyAction($this->action('cancel'), $this->characters('hi'), 2)->status);
        // Ctrl+D cancels ONLY on an empty buffer; otherwise it's a no-op (stays editing)
        $this->assertSame(ConsoleLineEditor::STATUS_CANCEL, $this->editor->applyAction($this->action('eof'), [], 0)->status);
        $this->assertSame(ConsoleLineEditor::STATUS_EDITING, $this->editor->applyAction($this->action('eof'), $this->characters('x'), 1)->status);
    }
}
