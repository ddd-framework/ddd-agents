import { findBalancedJsonObjectEnd } from "./balanced-json-object";
import { WIDGET_FENCE_LANG } from "./widget-fence-syntax";

/**
 * Repair the ```widget fence defects a model produces when it switches into a function call mid-text, or drops a
 * closing fence, or opens a second widget before closing the first (text + fence + tool call
 * in ONE response). A LINE-AWARE pass: CommonMark opens a fence on a line that (after up to 3 spaces of indent)
 * starts with ``` , and closes it only on a line whose sole content — same indent rule — is ``` . Content inside
 * ANY open fence (widget, json, or anything else) is data and is never scanned or touched, so a ``` mentioned
 * inside a JSON string value never gets mistaken for a delimiter.
 *
 * Two repairs, both scoped to the ```widget lang only:
 *  1. A glued opener (```widget stuck to the end of a prose line, so Markdown never recognized it as starting a
 *     line) is relocated onto its own line ONLY when a JSON object begins right after it — otherwise the text is
 *     left completely untouched (a prose sentence that merely mentions ```widget must not be mangled).
 *  2. An open widget fence with no proper closing-fence LINE before either end-of-text or the next fence-opener
 *     line gets a ``` closer spliced in right after its balanced JSON object (string-aware, so a ``` inside a JSON
 *     string is never treated as the closer). A widget fence whose body isn't a JSON object at all is left
 *     untouched — nothing could safely render there either way, and deleting text is not this function's job.
 *
 * The backend stores the model text as emitted, so this is the ONE place the shape is corrected — for stored
 * history and for every new message alike. Runs before `referenceNakedWidgetJson` so the re-fenced JSON is not
 * wrapped a second time. Pure; well-formed text is returned unchanged.
 */

/** A fence delimiter LINE per CommonMark, either character: up to 3 spaces indent, then a run of 3+ of the same
 * fence character (`` ` `` or `~`), then the rest of the line. Used by {@link findFencedBlockRanges} — the GENERAL
 * "is this real fence syntax" question, as opposed to the WIDGET-fence-only scanning above. */
const ANY_FENCE_DELIM_LINE = /^ {0,3}(`{3,}|~{3,})(.*)$/;

/** One real fence delimiter: its character, marker length and (trimmed) info string. */
interface FenceDelimiter {
  char: "`" | "~";
  length: number;
  info: string;
}

/**
 * Parse one line as a CommonMark fence delimiter, or null when it isn't one. A backtick run's info string may not
 * itself contain a backtick — CommonMark's own rule disambiguating a fence opener from an inline code span — so a
 * line such as "```js `x`" is not a fence delimiter at all (it never opens or closes anything).
 */
const parseFenceDelimiterLine = (line: string): FenceDelimiter | null => {
  const match = ANY_FENCE_DELIM_LINE.exec(line);
  if (!match) return null;
  const marker = match[1];
  const char = marker[0] as "`" | "~";
  const info = match[2].trim();
  if (char === "`" && info.includes("`")) return null;
  return { char, length: marker.length, info };
};

const isWidgetFenceInfo = (info: string): boolean => new RegExp(`^${WIDGET_FENCE_LANG}\\b`).test(info.trim());

/** A ```widget opener anywhere in the text, glued or not — built from the shared lang constant. */
const widgetOpenerPattern = (): RegExp => new RegExp("```[ \\t]*" + WIDGET_FENCE_LANG + "\\b");

/** One line of `text` starting at `lineStart`: its content (terminator excluded — a trailing `\r` of a CRLF
 * line ending is stripped too, so a delimiter regex's `.` never has to account for it) and the index right after
 * the line INCLUDING its terminator (or `text.length` at end-of-text). */
const readLine = (text: string, lineStart: number): { content: string; afterLine: number } => {
  const newlineIndex = text.indexOf("\n", lineStart);
  const lineEnd = newlineIndex === -1 ? text.length : newlineIndex;
  const afterLine = newlineIndex === -1 ? text.length : newlineIndex + 1;
  const rawContent = text.slice(lineStart, lineEnd);
  return { content: rawContent.endsWith("\r") ? rawContent.slice(0, -1) : rawContent, afterLine };
};

/**
 * Scan forward from `bodyStart` for a line that CLOSES the given opener: same fence character, a marker run at
 * least as long as the opener's, and nothing but whitespace after it (CommonMark's closing-fence rule). Unclosed →
 * end-of-text.
 */
const findClosingFenceEnd = (text: string, bodyStart: number, opener: FenceDelimiter): number => {
  let cursor = bodyStart;
  while (cursor < text.length) {
    const { content, afterLine } = readLine(text, cursor);
    const delimiter = parseFenceDelimiterLine(content);
    if (delimiter && delimiter.char === opener.char && delimiter.length >= opener.length && delimiter.info === "") {
      return afterLine;
    }
    cursor = afterLine;
  }
  return text.length;
};

/**
 * The `[start, end)` ranges of every REAL fenced code block in `text` — ``` or ~~~, CommonMark's own rules (up to
 * 3 spaces of indent, a closer using the same character with a run at least as long as the opener's, a backtick
 * opener's info string never containing a backtick). An unclosed opener runs to end-of-text. The GENERAL-PURPOSE
 * counterpart of this file's widget-fence-only scanning, built on the same {@link readLine} line walk — used by
 * `referenceNakedWidgetJson` to skip content genuinely inside a fence: a `~~~widget` fence (no tilde support
 * existed before), and content that follows a line which LOOKS like a backtick fence opener but isn't one per the
 * info-string rule above (that line never opens anything, so it can no longer swallow the real fence after it).
 */
export const findFencedBlockRanges = (text: string): Array<[number, number]> => {
  const ranges: Array<[number, number]> = [];
  let cursor = 0;
  while (cursor < text.length) {
    const { content, afterLine } = readLine(text, cursor);
    const opener = parseFenceDelimiterLine(content);
    if (opener) {
      const end = findClosingFenceEnd(text, afterLine, opener);
      ranges.push([cursor, end]);
      cursor = end;
      continue;
    }
    cursor = afterLine;
  }
  return ranges;
};

/**
 * Scan forward from `bodyStart` (right after a fence opener line) for the next LINE-anchored BACKTICK fence
 * delimiter (via {@link parseFenceDelimiterLine} — the same CommonMark rules `findFencedBlockRanges` uses, so a
 * line like "```js `x`" — an invalid info string, not a fence delimiter at all — is correctly skipped as plain
 * content rather than mistaken for one): a proper closing line (empty info) closes the fence normally; any other
 * delimiter line (another opener, any lang) is the boundary where this fence gave up without closing. Neither
 * found before end-of-text → unclosed at EOF.
 */
const findFenceEnd = (text: string, bodyStart: number): { closesAt: number } | { unclosedBoundary: number } => {
  let cursor = bodyStart;
  while (cursor < text.length) {
    const { content, afterLine } = readLine(text, cursor);
    const delimiter = parseFenceDelimiterLine(content);
    if (delimiter && delimiter.char === "`") {
      return delimiter.info === "" ? { closesAt: afterLine } : { unclosedBoundary: cursor };
    }
    cursor = afterLine;
  }
  return { unclosedBoundary: text.length };
};

/**
 * Resolve an OPEN widget fence's body starting at `bodyStart`: verbatim through a real closing-fence line when one
 * exists before end-of-text or the next fence-opener line; otherwise a ``` closer spliced in right after the
 * balanced JSON object; or, when the body isn't a JSON object at all, everything to end-of-text left untouched.
 */
const closeWidgetFenceBody = (text: string, bodyStart: number): { appended: string; resumeAt: number } => {
  const end = findFenceEnd(text, bodyStart);
  if ("closesAt" in end) {
    return { appended: text.slice(bodyStart, end.closesAt), resumeAt: end.closesAt };
  }
  const jsonObjectEnd = findBalancedJsonObjectEnd(text, bodyStart);
  if (jsonObjectEnd === null) {
    return { appended: text.slice(bodyStart), resumeAt: text.length };
  }
  return { appended: text.slice(bodyStart, jsonObjectEnd + 1) + "\n```", resumeAt: jsonObjectEnd + 1 };
};

export const repairMalformedWidgetFences = (text: string): string => {
  if (!widgetOpenerPattern().test(text)) return text;

  let result = "";
  let cursor = 0;
  while (cursor < text.length) {
    const { content, afterLine } = readLine(text, cursor);

    // Backtick fences only here (tilde ```widget``` has no repair path, same as before): a line whose backtick
    // run's info string itself contains a backtick (e.g. "```js `x`") is not a fence delimiter at all per
    // CommonMark, so it falls through to the glued-opener check / plain-text copy below, never swallowing a real
    // ```widget fence that follows it as a bogus "other fence".
    const delimiter = parseFenceDelimiterLine(content);
    if (delimiter && delimiter.char === "`") {
      const info = delimiter.info;
      if (info !== "" && isWidgetFenceInfo(info)) {
        result += content + text.slice(cursor + content.length, afterLine); // the opener line, verbatim
        const { appended, resumeAt } = closeWidgetFenceBody(text, afterLine);
        result += appended;
        cursor = resumeAt;
        continue;
      }
      if (info !== "") {
        // Some other fenced language: copy its whole body through untouched — never scanned for a widget opener,
        // so a widget-fence mention inside a JSON string value in THIS fence's content is never touched (a widget
        // fence itself can't legally start inside an already-open fence either way).
        const otherFenceEnd = findFenceEnd(text, afterLine);
        const bodyEnd = "closesAt" in otherFenceEnd ? otherFenceEnd.closesAt : text.length;
        result += text.slice(cursor, bodyEnd);
        cursor = bodyEnd;
        continue;
      }
    }

    // Not a fence-delimiter line: a glued opener (the fence's backticks stuck to the end of prose). Relocate onto
    // its own line ONLY when a JSON object begins right after it; a prose line that merely mentions ```widget with
    // nothing to render is left completely untouched.
    const gluedMatch = widgetOpenerPattern().exec(content);
    if (gluedMatch && gluedMatch.index > 0 && content.slice(0, gluedMatch.index).trim() !== "") {
      const bodyStart = afterLine;
      if (findBalancedJsonObjectEnd(text, bodyStart) !== null) {
        const openerLine = content.slice(gluedMatch.index) + text.slice(cursor + content.length, afterLine);
        result += content.slice(0, gluedMatch.index) + "\n" + openerLine;
        const { appended, resumeAt } = closeWidgetFenceBody(text, bodyStart);
        result += appended;
        cursor = resumeAt;
        continue;
      }
    }

    result += text.slice(cursor, afterLine);
    cursor = afterLine;
  }
  return result;
};
