<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A raw-mode, MULTI-LINE terminal line editor for an interactive console chat. It parses the keys itself instead of
 * relying on the OS readline/libedit, which does not engage inside a Symfony Console with ANSI streaming (arrow keys
 * echo as `^[[D`, Option+arrow as `^[b`, Ctrl+C does nothing).
 *
 * It puts the TTY in raw mode (`stty raw -echo`), reads byte-by-byte, decodes escape sequences into editing actions,
 * keeps a SINGLE UTF-8 character buffer that may contain `\n` (a multi-line entry), and repaints all rows on every
 * keystroke with the hardware cursor placed at the right (row, col). Supports:
 *  - ←/→ cursor, Option/Ctrl+←/→ word jumps (`ESC b`/`ESC f` AND `ESC[1;3D`/`ESC[1;5D`), Home/End,
 *  - ↑/↓ move the cursor between lines (to the same column above/below); at the top/bottom edge they recall history,
 *  - Option/Shift+Enter (and a trailing `\`) insert a newline; plain Enter submits the whole entry,
 *  - Backspace, Delete, Ctrl+W delete-word, Ctrl+U/K kill-to-start/end, Esc clears the input,
 *  - Ctrl+C / Ctrl+D-on-empty cancel (exit the console).
 *
 * The pure key→action mapping ({@see interpretKey}) and action application ({@see applyAction}) are isolated so they
 * are unit-tested without a TTY; the read loop + multi-row repaint need a real terminal (verified interactively).
 *
 * A multi-line entry pinned at the very bottom of the screen can glitch if the terminal scrolls mid-edit.
 *
 * A plain object of one chat session; no container service.
 */
#[Exclude]
class ConsoleLineEditor
{
    /** @var array<int, string> Submitted entries, oldest first — recalled with ↑/↓ at the top/bottom line. */
    protected array $history = [];

    /** Whether the shutdown function that restores the terminal after a fatal error during an edit is registered. */
    protected bool $isTerminalRestoreOnShutdownRegistered = false;

    /** VISUAL row (0-based, from the first input row, wrap-aware) the cursor was on at the last render. */
    protected int $renderedCursorRow = 0;

    /** Terminal width in columns — detected once per readLine() so wrapped lines are placed correctly. */
    protected int $terminalColumns = 80;

    public const string STATUS_EDITING = 'editing';
    public const string STATUS_SUBMIT = 'submit';
    public const string STATUS_CANCEL = 'cancel'; // Ctrl+C / Ctrl+D on empty buffer → exit the console
    public const string STATUS_HISTORY_PREV = 'historyPrev';
    public const string STATUS_HISTORY_NEXT = 'historyNext';

    /** True when we have a real interactive TTY and `stty` to control it; otherwise the caller should fall back. */
    public function isInteractive(): bool
    {
        if (!defined('STDIN') || !function_exists('stream_isatty') || !@stream_isatty(STDIN)) {
            return false;
        }
        $probe = @shell_exec('stty -g 2>/dev/null');
        return is_string($probe) && trim($probe) !== '';
    }

    /**
     * Read one (possibly multi-line) entry interactively in raw mode. Returns the submitted text (with embedded `\n`
     * for multi-line), or null when the user cancels (Ctrl+C, or Ctrl+D on an empty buffer) — the caller treats null
     * as "exit the console".
     *
     * @param string $prompt The prompt for the first line (may contain ANSI color; its visible width is measured).
     * @param string|null $continuationPrompt The prompt for the 2nd+ lines (defaults to a blank gutter the width of $prompt).
     */
    public function readLine(string $prompt, ?string $continuationPrompt = null): ?string
    {
        // No continuation gutter: logical newline lines start at column 0, the SAME as where the terminal wraps a long
        // line — so soft newlines and overflow-wrap look consistent (an indented gutter would only apply to logical
        // lines, not wraps, which is the mismatch the gutter caused).
        $continuationPrompt ??= '';
        $savedStty = trim((string)shell_exec('stty -g 2>/dev/null'));
        $this->terminalColumns = $this->detectTerminalWidth();
        shell_exec('stty raw -echo 2>/dev/null'); // raw, no echo: we own every keystroke + draw the lines ourselves
        if (!$this->isTerminalRestoreOnShutdownRegistered) {
            $this->isTerminalRestoreOnShutdownRegistered = true;
            register_shutdown_function([$this, 'restoreSaneTerminalSettings']);
        }

        $editorState = new ConsoleLineEditorState([], 0);
        $this->renderedCursorRow = 0;
        $historyIndex = count($this->history); // points "past the end" = the live buffer
        $liveDraft = '';

        // Bracketed paste: the terminal wraps pasted text in ESC[200~ … ESC[201~ so its embedded newlines are inserted
        // as text instead of each submitting the line (the multi-line-paste-explosion bug).
        echo "\x1b[?2004h";
        try {
            $this->render($prompt, $continuationPrompt, $editorState);
            while (true) {
                $key = $this->readKey();
                if ($key === '') { // EOF on the stream
                    return $editorState->characters === [] ? null : $editorState->getText();
                }
                $keyAction = $this->interpretKey($key);
                // A paste arrives as one burst between the bracket markers — insert it literally (newlines kept as \n).
                if ($keyAction->type === 'pasteStart') {
                    $pastedCharacters = $this->toChars($this->normalizeNewlines($this->readPaste()));
                    $characters = $editorState->characters;
                    array_splice($characters, $editorState->cursorIndex, 0, $pastedCharacters);
                    $editorState = new ConsoleLineEditorState($characters, $editorState->cursorIndex + count($pastedCharacters));
                    $this->render($prompt, $continuationPrompt, $editorState);
                    continue;
                }
                $editorState = $this->applyAction($keyAction, $editorState->characters, $editorState->cursorIndex);

                if ($editorState->status === self::STATUS_SUBMIT) {
                    $entry = $editorState->getText();
                    echo "\r\n";
                    $this->pushHistory($entry);
                    return $entry;
                }
                if ($editorState->status === self::STATUS_CANCEL) {
                    echo "\r\n";
                    return null;
                }
                if ($editorState->status === self::STATUS_HISTORY_PREV || $editorState->status === self::STATUS_HISTORY_NEXT) {
                    if ($historyIndex === count($this->history)) {
                        $liveDraft = $editorState->getText();
                    }
                    $historyIndex = $editorState->status === self::STATUS_HISTORY_PREV
                        ? max(0, $historyIndex - 1)
                        : min(count($this->history), $historyIndex + 1);
                    $recalledEntry = $historyIndex === count($this->history) ? $liveDraft : $this->history[$historyIndex];
                    $recalledCharacters = $this->toChars($recalledEntry);
                    $editorState = new ConsoleLineEditorState($recalledCharacters, count($recalledCharacters));
                }

                $this->render($prompt, $continuationPrompt, $editorState);
            }
        } finally {
            echo "\x1b[?2004l"; // disable bracketed paste
            $this->restoreTerminalSettings($savedStty);
        }
    }

    /**
     * Puts the terminal back into the settings saved before the raw mode; `stty sane` when none were saved.
     *
     * @param string $savedSttySettings the output of `stty -g` before the raw mode, or ''
     * @return void
     */
    protected function restoreTerminalSettings(string $savedSttySettings): void
    {
        if ($savedSttySettings === '') {
            $this->restoreSaneTerminalSettings();
            return;
        }
        shell_exec('stty ' . escapeshellarg($savedSttySettings) . ' 2>/dev/null');
    }

    /**
     * The safety net registered as a shutdown function: a fatal error during an edit leaves the terminal in raw mode
     * otherwise.
     *
     * @return void
     */
    public function restoreSaneTerminalSettings(): void
    {
        shell_exec('stty sane 2>/dev/null');
    }

    /** Read a bracketed-paste payload: everything up to the ESC[201~ end marker (which is consumed and dropped). */
    protected function readPaste(): string
    {
        $endMarker = "\x1b[201~";
        $pastedText = '';
        while (true) {
            $byte = fread(STDIN, 1);
            if ($byte === '' || $byte === false) {
                break;
            }
            $pastedText .= $byte;
            if (str_ends_with($pastedText, $endMarker)) {
                return substr($pastedText, 0, -strlen($endMarker));
            }
        }
        return $pastedText;
    }

    /** Normalize pasted line endings (CRLF / CR) to a single "\n" so multi-line paste lands as one multi-line entry. */
    protected function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /** Record a submitted entry for ↑/↓ recall (skips blanks + immediate duplicates). */
    protected function pushHistory(string $entry): void
    {
        if (trim($entry) === '' || ($this->history !== [] && $this->history[count($this->history) - 1] === $entry)) {
            return;
        }
        $this->history[] = $entry;
    }

    /** Read ONE logical key from STDIN: a single (possibly multi-byte UTF-8) character, or a full escape sequence. '' on EOF. */
    protected function readKey(): string
    {
        $byte = fread(STDIN, 1);
        if ($byte === '' || $byte === false) {
            return '';
        }
        $ord = ord($byte);
        if ($ord === 0x1b) {
            return $this->readEscapeSequence();
        }
        if ($ord >= 0xC0) { // UTF-8 lead byte → pull continuation bytes so cursor math stays per-character
            $continuationByteCount = $ord >= 0xF0 ? 3 : ($ord >= 0xE0 ? 2 : 1);
            for ($i = 0; $i < $continuationByteCount; $i++) {
                $continuationByte = fread(STDIN, 1);
                if ($continuationByte === '' || $continuationByte === false) {
                    break;
                }
                $byte .= $continuationByte;
            }
        }
        return $byte;
    }

    /**
     * Read the bytes after an ESC. A lone ESC (no byte follows within a short window) is the Escape KEY; otherwise it
     * is the start of a CSI (`ESC [ … final`), SS3 (`ESC O x`) or Meta (`ESC x`) sequence.
     */
    protected function readEscapeSequence(): string
    {
        if (!$this->byteAvailable(40000)) { // ~40ms: nothing follows → bare Escape key
            return "\x1b";
        }
        $second = fread(STDIN, 1);
        if ($second === '' || $second === false) {
            return "\x1b";
        }
        if ($second === '[') { // CSI: read until a final byte @A–Z a–z ~ (not ';')
            $escapeSequence = "\x1b[";
            while (true) {
                $byte = fread(STDIN, 1);
                if ($byte === '' || $byte === false) {
                    break;
                }
                $escapeSequence .= $byte;
                $byteValue = ord($byte);
                if ($byteValue >= 0x40 && $byteValue <= 0x7e && $byte !== ';') {
                    break;
                }
            }
            return $escapeSequence;
        }
        if ($second === 'O') { // SS3 (application cursor mode): ESC O <one byte>
            $byte = fread(STDIN, 1);
            return "\x1bO" . ($byte === false ? '' : $byte);
        }
        return "\x1b" . $second; // Meta/Alt key (e.g. Option+b/f, Option+Enter, Option+Backspace)
    }

    /** True if at least one byte is readable on STDIN within $microseconds (used to tell a bare ESC from a sequence). */
    protected function byteAvailable(int $microseconds): bool
    {
        $streamsToRead = [STDIN];
        $streamsToWrite = [];
        $streamsWithExceptions = [];
        return @stream_select($streamsToRead, $streamsToWrite, $streamsWithExceptions, 0, $microseconds) > 0;
    }

    /**
     * PURE: map a logical key (single char or escape sequence) to an editing action.
     *
     * @return ConsoleLineEditorKeyAction
     */
    public function interpretKey(string $key): ConsoleLineEditorKeyAction
    {
        $controlKeyActionType = $this->findControlKeyActionType($key);
        if ($controlKeyActionType !== null) {
            return new ConsoleLineEditorKeyAction($controlKeyActionType);
        }
        if ($key !== '' && $key[0] !== "\x1b" && ord($key[0]) >= 0x20) { // printable (incl. multi-byte UTF-8)
            return ConsoleLineEditorKeyAction::forInsertedCharacter($key);
        }
        return new ConsoleLineEditorKeyAction('noop');
    }

    /** PURE: the action type of a control key or escape sequence; null for a printable or unknown key. */
    protected function findControlKeyActionType(string $key): ?string
    {
        switch ($key) {
            case "\x1b[200~": return 'pasteStart';   // bracketed-paste begin (payload read in the loop)
            case "\x1b": return 'clear';             // bare Escape → clear the input
            case "\x03": return 'cancel';            // Ctrl+C
            case "\x04": return 'eof';               // Ctrl+D (cancel only on empty buffer)
            case "\r": case "\n": return 'submit';    // Enter
            // soft newline: Option/Alt+Enter (ESC CR / ESC LF) and Shift+Enter (CSI-u `ESC [13;2u`)
            case "\x1b\r": case "\x1b\n": case "\x1b[13;2u": return 'newline';
            case "\x7f": case "\x08": return 'backspace';
            case "\x01": return 'home';              // Ctrl+A
            case "\x05": return 'end';               // Ctrl+E
            case "\x02": return 'left';              // Ctrl+B
            case "\x06": return 'right';             // Ctrl+F
            case "\x17": return 'deleteWord';        // Ctrl+W
            case "\x15": return 'killToStart';       // Ctrl+U
            case "\x0b": return 'killToEnd';         // Ctrl+K
            case "\x0e": return 'down';              // Ctrl+N
            case "\x10": return 'up';                // Ctrl+P
            // arrows (CSI + SS3 application-cursor variants)
            case "\x1b[D": case "\x1bOD": return 'left';
            case "\x1b[C": case "\x1bOC": return 'right';
            case "\x1b[A": case "\x1bOA": return 'up';
            case "\x1b[B": case "\x1bOB": return 'down';
            case "\x1b[H": case "\x1bOH": case "\x1b[1~": return 'home';
            case "\x1b[F": case "\x1bOF": case "\x1b[4~": return 'end';
            case "\x1b[3~": return 'delete';
            // word navigation: Option/Alt (ESC b/f) AND Ctrl/Option/Shift modified arrows
            case "\x1bb": case "\x1b[1;3D": case "\x1b[1;5D": case "\x1b[1;2D": return 'wordLeft';
            case "\x1bf": case "\x1b[1;3C": case "\x1b[1;5C": case "\x1b[1;2C": return 'wordRight';
            case "\x1b\x7f": return 'deleteWord';    // Option+Backspace
        }
        return null;
    }

    /**
     * PURE: apply an action to the buffer and the cursor and return the new state.
     * ↑/↓ move within the buffer; at the first/last line they return a HISTORY status for the loop to handle.
     *
     * @param ConsoleLineEditorKeyAction $keyAction
     * @param string[] $characters
     * @param int $cursorIndex
     * @return ConsoleLineEditorState
     */
    public function applyAction(ConsoleLineEditorKeyAction $keyAction, array $characters, int $cursorIndex): ConsoleLineEditorState
    {
        $characterCount = count($characters);
        switch ($keyAction->type) {
            case ConsoleLineEditorKeyAction::TYPE_INSERT:
                array_splice($characters, $cursorIndex, 0, [$keyAction->insertedCharacter ?? '']);
                return new ConsoleLineEditorState($characters, $cursorIndex + 1);
            case 'newline':
                array_splice($characters, $cursorIndex, 0, ["\n"]);
                return new ConsoleLineEditorState($characters, $cursorIndex + 1);
            case 'submit':
                return new ConsoleLineEditorState($characters, $cursorIndex, self::STATUS_SUBMIT);
            case 'cancel':
                return new ConsoleLineEditorState($characters, $cursorIndex, self::STATUS_CANCEL);
            case 'eof':
                return new ConsoleLineEditorState($characters, $cursorIndex, $characterCount === 0 ? self::STATUS_CANCEL : self::STATUS_EDITING);
            case 'clear':
                return new ConsoleLineEditorState([], 0);
            case 'left':
                return new ConsoleLineEditorState($characters, max(0, $cursorIndex - 1));
            case 'right':
                return new ConsoleLineEditorState($characters, min($characterCount, $cursorIndex + 1));
            case 'home':
                return new ConsoleLineEditorState($characters, $this->lineStart($characters, $cursorIndex));
            case 'end':
                return new ConsoleLineEditorState($characters, $this->lineEnd($characters, $cursorIndex));
            case 'up':
                return $this->moveVertical($characters, $cursorIndex, -1);
            case 'down':
                return $this->moveVertical($characters, $cursorIndex, +1);
            case 'wordLeft':
                return new ConsoleLineEditorState($characters, $this->wordBoundaryLeft($characters, $cursorIndex));
            case 'wordRight':
                return new ConsoleLineEditorState($characters, $this->wordBoundaryRight($characters, $cursorIndex));
            case 'backspace':
                if ($cursorIndex > 0) {
                    array_splice($characters, $cursorIndex - 1, 1);
                    return new ConsoleLineEditorState($characters, $cursorIndex - 1);
                }
                return new ConsoleLineEditorState($characters, $cursorIndex);
            case 'delete':
                if ($cursorIndex < $characterCount) {
                    array_splice($characters, $cursorIndex, 1);
                }
                return new ConsoleLineEditorState($characters, $cursorIndex);
            case 'deleteWord':
                $wordStartIndex = $this->wordBoundaryLeft($characters, $cursorIndex);
                array_splice($characters, $wordStartIndex, $cursorIndex - $wordStartIndex);
                return new ConsoleLineEditorState($characters, $wordStartIndex);
            case 'killToStart':
                $lineStartIndex = $this->lineStart($characters, $cursorIndex);
                array_splice($characters, $lineStartIndex, $cursorIndex - $lineStartIndex);
                return new ConsoleLineEditorState($characters, $lineStartIndex);
            case 'killToEnd':
                $lineEndIndex = $this->lineEnd($characters, $cursorIndex);
                array_splice($characters, $cursorIndex, $lineEndIndex - $cursorIndex);
                return new ConsoleLineEditorState($characters, $cursorIndex);
            default:
                return new ConsoleLineEditorState($characters, $cursorIndex);
        }
    }

    /**
     * Move the cursor one logical line up (-1) or down (+1), keeping the column. At the first line an "up" and at the
     * last line a "down" return a HISTORY status so the caller recalls instead of moving.
     *
     * @param string[] $characters
     * @param int $cursorIndex
     * @param int $direction -1 up, +1 down
     * @return ConsoleLineEditorState
     */
    protected function moveVertical(array $characters, int $cursorIndex, int $direction): ConsoleLineEditorState
    {
        $lineStartIndex = $this->lineStart($characters, $cursorIndex);
        $columnIndex = $cursorIndex - $lineStartIndex;
        if ($direction < 0) {
            if ($lineStartIndex === 0) { // already on the first line → history
                return new ConsoleLineEditorState($characters, $cursorIndex, self::STATUS_HISTORY_PREV);
            }
            $previousLineEndIndex = $lineStartIndex - 1; // the "\n" terminating the previous line
            $previousLineStartIndex = $this->lineStart($characters, $previousLineEndIndex);
            return new ConsoleLineEditorState($characters, $previousLineStartIndex + min($columnIndex, $previousLineEndIndex - $previousLineStartIndex));
        }
        $lineEndIndex = $this->lineEnd($characters, $cursorIndex);
        if ($lineEndIndex >= count($characters)) { // already on the last line → history
            return new ConsoleLineEditorState($characters, $cursorIndex, self::STATUS_HISTORY_NEXT);
        }
        $nextLineStartIndex = $lineEndIndex + 1; // skip the "\n"
        $nextLineEndIndex = $this->lineEnd($characters, $nextLineStartIndex);
        return new ConsoleLineEditorState($characters, $nextLineStartIndex + min($columnIndex, $nextLineEndIndex - $nextLineStartIndex));
    }

    /**
     * @param string[] $characters
     * @param int $cursorIndex
     * @return int the index of the first character of the logical line of the cursor (just after the previous "\n", or 0)
     */
    protected function lineStart(array $characters, int $cursorIndex): int
    {
        $characterIndex = $cursorIndex;
        while ($characterIndex > 0 && $characters[$characterIndex - 1] !== "\n") {
            $characterIndex--;
        }
        return $characterIndex;
    }

    /**
     * @param string[] $characters
     * @param int $cursorIndex
     * @return int the index of the "\n" ending the logical line of the cursor, or the character count on the last line
     */
    protected function lineEnd(array $characters, int $cursorIndex): int
    {
        $characterCount = count($characters);
        $characterIndex = $cursorIndex;
        while ($characterIndex < $characterCount && $characters[$characterIndex] !== "\n") {
            $characterIndex++;
        }
        return $characterIndex;
    }

    /**
     * @param string[] $characters
     * @param int $cursorIndex
     * @return int the start of the word at or just before the cursor (trailing spaces skipped first); stops at a line start
     */
    protected function wordBoundaryLeft(array $characters, int $cursorIndex): int
    {
        $characterIndex = $cursorIndex;
        while ($characterIndex > 0 && ($characters[$characterIndex - 1] === ' ' || $characters[$characterIndex - 1] === "\n")) {
            $characterIndex--;
        }
        while ($characterIndex > 0 && $characters[$characterIndex - 1] !== ' ' && $characters[$characterIndex - 1] !== "\n") {
            $characterIndex--;
        }
        return $characterIndex;
    }

    /**
     * @param string[] $characters
     * @param int $cursorIndex
     * @return int the index after the word at or just after the cursor (leading spaces skipped first)
     */
    protected function wordBoundaryRight(array $characters, int $cursorIndex): int
    {
        $characterCount = count($characters);
        $characterIndex = $cursorIndex;
        while ($characterIndex < $characterCount && ($characters[$characterIndex] === ' ' || $characters[$characterIndex] === "\n")) {
            $characterIndex++;
        }
        while ($characterIndex < $characterCount && $characters[$characterIndex] !== ' ' && $characters[$characterIndex] !== "\n") {
            $characterIndex++;
        }
        return $characterIndex;
    }

    /**
     * PURE: where the cursor stands in the buffer — the line (the count of "\n" before it) and the column (the
     * characters since that line's start).
     *
     * @param string[] $characters
     * @param int $cursorIndex
     * @return ConsoleLineEditorCursorPosition
     */
    public function findCursorPosition(array $characters, int $cursorIndex): ConsoleLineEditorCursorPosition
    {
        $lineIndex = 0;
        $columnIndex = 0;
        $characterCount = count($characters);
        for ($characterIndex = 0; $characterIndex < $cursorIndex && $characterIndex < $characterCount; $characterIndex++) {
            if ($characters[$characterIndex] === "\n") {
                $lineIndex++;
                $columnIndex = 0;
            } else {
                $columnIndex++;
            }
        }
        return new ConsoleLineEditorCursorPosition($lineIndex, $columnIndex);
    }

    /**
     * @param string $text
     * @return string[] the UTF-8 characters of the text
     */
    protected function toChars(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $characters === false ? str_split($text) : $characters;
    }

    /** Visible width of a prompt (ANSI color codes stripped). */
    protected function visibleWidth(string $prompt): int
    {
        $promptWithoutColors = (string)preg_replace('/\x1b\[[0-9;]*m/', '', $prompt);
        return mb_strlen($promptWithoutColors);
    }

    /** Detect the terminal width (columns); falls back to 80. Read once per readLine so wrap math is stable. */
    protected function detectTerminalWidth(): int
    {
        $columnsOfEnvironment = (int)getenv('COLUMNS');
        if ($columnsOfEnvironment > 0) {
            return $columnsOfEnvironment;
        }
        $terminalSize = (string)@shell_exec('stty size 2>/dev/null'); // "<rows> <cols>"
        if (preg_match('/\d+\s+(\d+)/', $terminalSize, $terminalSizeMatches) && (int)$terminalSizeMatches[1] > 0) {
            return (int)$terminalSizeMatches[1];
        }
        $columnsOfTput = (int)@shell_exec('tput cols 2>/dev/null');
        return $columnsOfTput > 0 ? $columnsOfTput : 80;
    }

    /** Number of VISUAL (wrapped) rows a line of $visibleLength characters occupies at width $terminalColumns (empty line = 1). */
    public function visualRows(int $visibleLength, int $terminalColumns): int
    {
        return $visibleLength <= 0 ? 1 : intdiv($visibleLength - 1, $terminalColumns) + 1;
    }

    /**
     * Repaint the whole (multi-line, WRAP-AWARE) input: jump to the first visual row of the input area, clear downward,
     * redraw every logical line (the terminal wraps long ones), then place the hardware cursor at the buffer cursor's
     * VISUAL (row, col). All vertical moves count wrapped rows, so a long pasted line leaves no stray prompt.
     *
     * @param string $prompt
     * @param string $continuationPrompt
     * @param ConsoleLineEditorState $editorState
     */
    protected function render(string $prompt, string $continuationPrompt, ConsoleLineEditorState $editorState): void
    {
        $usableTerminalColumns = max(1, $this->terminalColumns);
        $lines = explode("\n", $editorState->getText());
        $cursorPosition = $this->findCursorPosition($editorState->characters, $editorState->cursorIndex);
        $cursorLineIndex = $cursorPosition->lineIndex;
        $cursorColumnIndex = $cursorPosition->columnIndex;

        // Visual row of the cursor + total visual rows — both wrap-aware.
        $cursorVisualRow = 0;
        $totalVisualRows = 0;
        foreach ($lines as $i => $line) {
            $prefixWidth = $this->visibleWidth($i === 0 ? $prompt : $continuationPrompt);
            $lineVisualRows = $this->visualRows($prefixWidth + mb_strlen($line), $usableTerminalColumns);
            if ($i < $cursorLineIndex) {
                $cursorVisualRow += $lineVisualRows;
            }
            $totalVisualRows += $lineVisualRows;
        }
        $cursorPrefixWidth = $this->visibleWidth($cursorLineIndex === 0 ? $prompt : $continuationPrompt);
        $cursorOffset = $cursorPrefixWidth + $cursorColumnIndex;
        $cursorVisualRow += intdiv($cursorOffset, $usableTerminalColumns);
        $cursorVisualColumn = $cursorOffset % $usableTerminalColumns;

        $terminalOutput = '';
        if ($this->renderedCursorRow > 0) {
            $terminalOutput .= "\x1b[" . $this->renderedCursorRow . 'A'; // up to the first visual row of the input
        }
        $terminalOutput .= "\r\x1b[J"; // column 0, clear to end of screen (erase the previous render incl. wrapped rows)
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $terminalOutput .= "\r\n";
            }
            $terminalOutput .= ($i === 0 ? $prompt : $continuationPrompt) . $line;
        }
        // After redraw the hardware cursor sits on the last visual row; move up to the cursor's visual row + set column.
        $lastVisualRow = $totalVisualRows - 1;
        if ($lastVisualRow > $cursorVisualRow) {
            $terminalOutput .= "\x1b[" . ($lastVisualRow - $cursorVisualRow) . 'A';
        }
        $terminalOutput .= "\x1b[" . ($cursorVisualColumn + 1) . 'G'; // absolute column (1-based)
        echo $terminalOutput;
        $this->renderedCursorRow = $cursorVisualRow;
    }
}
