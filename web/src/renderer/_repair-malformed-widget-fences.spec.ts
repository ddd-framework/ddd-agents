import { describe, expect, it } from "vitest";
import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import remarkWidgetFencePlugin from "./remarkWidgetFencePlugin";
import { detectUnrenderedWidgets } from "./detect-unrendered-widgets";
import { repairMalformedWidgetFences } from "./repair-malformed-widget-fences";
import { normalizeServerMessageToMarkdown } from "../chat/normalize-server-message-to-markdown";

/** Top-level block summary after the fence plugin: `paragraph:<text>` / `leafDirective:<name>`. */
const renderedBlocks = (markdown: string): string[] => {
  const processor = unified().use(remarkParse).use(remarkDirective).use(remarkWidgetFencePlugin);
  const tree: any = processor.runSync(processor.parse(markdown));
  const textOf = (node: any): string =>
    typeof node.value === "string" ? node.value : (node.children ?? []).map(textOf).join("");
  const blocks: string[] = [];
  visit(tree, (node: any, _index, parent) => {
    if (parent !== tree) return;
    blocks.push(node.type === "leafDirective" ? `leafDirective:${node.name}` : `${node.type}:${textOf(node)}`);
  });
  return blocks;
};

// A stored shape: opener glued to the sentence, closing fence never emitted.
const STORED_67450 =
  'Următorul pas este să stabilim termenii pentru catering în Cluj-Napoca.```widget\n{"type":"ConversationSearchResults","limit":10}';

describe("repairMalformedWidgetFences — a fence the model broke by switching into a tool call mid-text", () => {
  it("moves a glued opener onto its own line and closes the fence behind the JSON", () => {
    expect(repairMalformedWidgetFences(STORED_67450)).toBe(
      'Următorul pas este să stabilim termenii pentru catering în Cluj-Napoca.\n```widget\n{"type":"ConversationSearchResults","limit":10}\n```',
    );
  });

  it("renders the repaired message as prose + the widget, with no literal backticks left in the prose", () => {
    const blocks = renderedBlocks(normalizeServerMessageToMarkdown(STORED_67450));
    expect(blocks).toEqual([
      "paragraph:Următorul pas este să stabilim termenii pentru catering în Cluj-Napoca.",
      "leafDirective:ConversationSearchResults",
    ]);
    expect(detectUnrenderedWidgets(normalizeServerMessageToMarkdown(STORED_67450))).toEqual([]);
  });

  it("closes an unclosed fence before the prose that follows the JSON", () => {
    expect(
      repairMalformedWidgetFences('Here:\n```widget\n{ "type": "MultiChoice", "title": "All {reviews}" }\nContinue?'),
    ).toBe('Here:\n```widget\n{ "type": "MultiChoice", "title": "All {reviews}" }\n```\nContinue?');
  });

  it.each(["Example ```widget block", "A ``` code fence", 'Escaped "quote", \\ slash and {braces} ```widget'])(
    "preserves fence text inside a valid JSON string: %s",
    (title) => {
      const json = JSON.stringify({ type: "MultiChoice", title, reviews: [] });
      const markdown = "```widget\n" + json + "\n```\nContinue?";
      expect(repairMalformedWidgetFences(markdown)).toBe(markdown);
      expect(renderedBlocks(normalizeServerMessageToMarkdown(markdown))).toEqual([
        "leafDirective:MultiChoice",
        "paragraph:Continue?",
      ]);
      const malformed = "Intro.```widget\n" + json;
      expect(repairMalformedWidgetFences(malformed)).toBe("Intro.\n```widget\n" + json + "\n```");
    },
  );

  it("closes a missing fence before the next widget opener", () => {
    const markdown = '```widget\n{"type":"MultiChoice"}\n```widget\n{"type":"QuickReplies"}\n```';
    expect(renderedBlocks(normalizeServerMessageToMarkdown(markdown))).toEqual([
      "leafDirective:MultiChoice",
      "leafDirective:QuickReplies",
    ]);
  });

  it("leaves a dangling opener without a JSON object untouched (never deletes text)", () => {
    const markdown = "Your starting point:\n```widget\n";
    expect(repairMalformedWidgetFences(markdown)).toBe(markdown);
  });

  it("leaves well-formed text unchanged", () => {
    const wellFormed =
      'Intro.\n\n```widget\n{ "type": "QuickReplies" }\n```\n\nMore:\n```widget\n{ "type": "SuggestedActions" }\n```\nDone.';
    expect(repairMalformedWidgetFences(wellFormed)).toBe(wellFormed);
    expect(repairMalformedWidgetFences("plain prose, no fence")).toBe("plain prose, no fence");
    expect(repairMalformedWidgetFences('```json\n{"a":1}\n```')).toBe('```json\n{"a":1}\n```');
  });

  // A prose line that merely MENTIONS ```widget, with no JSON following — leave it fully untouched, never
  // drop the tail after the fence-looking text.
  it("leaves a prose mention of ```widget with no following JSON completely untouched", () => {
    const markdown = "The ```widget fence is used like so.";
    expect(repairMalformedWidgetFences(markdown)).toBe(markdown);
  });

  // F3a: a JSON string value containing literal ```widget text must not get a newline injected into it, and must
  // not spawn a spurious extra closer. Covered generically by the it.each block above (the "Escaped …" case), and
  // explicitly here for the exact reported shape.
  it("does not mutate a fenced widget body whose own field value contains literal ```widget text", () => {
    const json = '{"type":"X","t":"```widget"}';
    const markdown = "```widget\n" + json + "\n```";
    expect(repairMalformedWidgetFences(markdown)).toBe(markdown);
  });

  // F3b: the SAME literal text inside a ```json (non-widget) fence's body must never be touched either — its
  // content is never scanned for a widget opener at all.
  it("does not mutate a ```json fence body containing literal ```widget text in a string", () => {
    const markdown = '```json\n{"a":"```widget"}\n```';
    expect(repairMalformedWidgetFences(markdown)).toBe(markdown);
  });

  // F3d: an indented ```widget fence inside a list item is already well-formed CommonMark (up to 3 spaces of
  // indent) — it must not be pulled to column 0, which would split the list.
  it("preserves an indented widget fence inside a list item", () => {
    const markdown = '1. First step\n\n   ```widget\n   {"type":"X"}\n   ```\n\n2. Second step';
    expect(repairMalformedWidgetFences(markdown)).toBe(markdown);
  });

  // Two widget openers with no closer between them — remark would otherwise swallow the second opener and
  // everything after it into ONE code node whose JSON never parses, so neither widget renders.
  it("splits two widget openers with no closer between them into two separate fences", () => {
    const markdown = '```widget\n{"type":"MultiChoice"}\n```widget\n{"type":"QuickReplies"}\n```';
    const repaired = repairMalformedWidgetFences(markdown);
    expect(renderedBlocks(repaired)).toEqual(["leafDirective:MultiChoice", "leafDirective:QuickReplies"]);
  });

  // The "``` widget" form (a space before the lang) must repair identically to "```widget".
  it("repairs a glued opener written with a space before the lang", () => {
    const json = JSON.stringify({ type: "MultiChoice" });
    const malformed = "Intro.``` widget\n" + json;
    expect(repairMalformedWidgetFences(malformed)).toBe("Intro.\n``` widget\n" + json + "\n```");
  });

  // A line whose backtick-fence info string itself contains a backtick
  // ("```js `x`") is NOT a fence delimiter at all per CommonMark (the rule disambiguating a fence opener from an
  // inline code span) — it must never be mistaken for an "other language" fence opener that then swallows the
  // REAL ```widget fence below it (and everything after it, including unrelated prose) as that bogus fence's
  // unclosed body.
  it('closes the real ```widget fence that follows a line like "```js `x`", instead of treating that line as an unclosed "other fence" swallowing everything after it', () => {
    const markdown = '```js `x`\n```widget\n{"type":"ToolTrace"}\n\nMore prose that should remain separate.';
    expect(repairMalformedWidgetFences(markdown)).toBe(
      '```js `x`\n```widget\n{"type":"ToolTrace"}\n```\n\nMore prose that should remain separate.',
    );
    const blocks = renderedBlocks(normalizeServerMessageToMarkdown(markdown));
    expect(blocks).toEqual([
      "paragraph:```js x",
      "leafDirective:ToolTrace",
      "paragraph:More prose that should remain separate.",
    ]);
  });

  // MINOR 1 (review): the CRLF-tolerant line scan (readLine strips a trailing \r) also repairs a malformed widget
  // fence that arrived with \r\n line endings — a glued opener with no closing fence at all.
  it("repairs a glued opener with no closer when the message uses CRLF line endings", () => {
    const markdown = 'Prose glued.```widget\r\n{"type":"ToolTrace"}';
    expect(repairMalformedWidgetFences(markdown)).toBe('Prose glued.\n```widget\r\n{"type":"ToolTrace"}\n```');
  });
});
