import { describe, expect, it } from "vitest";
import { unified } from "unified";
import remarkParse from "remark-parse";
import remarkDirective from "remark-directive";
import { visit } from "unist-util-visit";
import remarkWidgetFencePlugin from "./remarkWidgetFencePlugin";
import { detectUnrenderedWidgets } from "./detect-unrendered-widgets";
import { referenceNakedWidgetJson } from "./reference-naked-widget-json";

const firstWidgetNodeName = (markdown: string): string | undefined => {
  const processor = unified().use(remarkParse).use(remarkDirective).use(remarkWidgetFencePlugin);
  const tree = processor.runSync(processor.parse(markdown));
  let found: string | undefined;
  visit(tree, "leafDirective", (node: any) => {
    found ??= node.name;
  });
  return found;
};

describe("referenceNakedWidgetJson — render naked widget JSON a weak model emitted without a fence", () => {
  it("wraps a known widget type in a ```widget fence so the plugin renders it", () => {
    const referenced = referenceNakedWidgetJson('Here are your connections:\n\n{ "type": "QuickReplies" }');
    expect(referenced).toContain('```widget\n{ "type": "QuickReplies" }\n```');
    // …and that fenced form renders as the real widget node.
    expect(firstWidgetNodeName(referenced)).toBe("QuickReplies");
    // …and the detector no longer reports it as an unrendered (missingFence) widget.
    expect(detectUnrenderedWidgets(referenced)).toEqual([]);
  });

  it("preserves a payload-carrying widget's JSON verbatim inside the fence", () => {
    const naked = '{ "type": "WebSearchResults", "answer": "hi", "sources": [{ "url": "https://x" }] }';
    const referenced = referenceNakedWidgetJson(`Intro.\n\n${naked}`);
    expect(referenced).toContain("```widget\n" + naked + "\n```");
  });

  it("leaves an UNKNOWN type untouched (the corrective-signal path still handles it)", () => {
    const input = 'Intro.\n\n{ "type": "connections" }';
    expect(referenceNakedWidgetJson(input)).toBe(input);
  });

  it("never double-fences JSON already inside a ```widget fence", () => {
    const input = '```widget\n{ "type": "QuickReplies" }\n```';
    expect(referenceNakedWidgetJson(input)).toBe(input);
  });

  it("leaves arbitrary non-widget JSON alone", () => {
    const input = 'Config:\n\n{ "foo": 1, "bar": 2 }';
    expect(referenceNakedWidgetJson(input)).toBe(input);
  });

  it("re-fences MULTIPLE naked widgets in one message", () => {
    const referenced = referenceNakedWidgetJson(
      '{ "type": "QuickReplies" }\n\ntext\n\n{ "type": "WebSearchResults", "mode": "short" }',
    );
    expect(referenced).toContain('```widget\n{ "type": "QuickReplies" }\n```');
    expect(referenced).toContain('```widget\n{ "type": "WebSearchResults", "mode": "short" }\n```');
  });

  // A valid ~~~widget fence is a REAL fence — its JSON must never be re-wrapped.
  it("never re-wraps JSON already inside a valid ~~~widget fence", () => {
    const input = '~~~widget\n{"type":"ToolTrace"}\n~~~';
    expect(referenceNakedWidgetJson(input)).toBe(input);
  });

  // A backtick-fence info string containing a backtick (e.g. "```js `x`") is not a valid CommonMark fence
  // opener at all, so it must never pair with the REAL ```widget fence that follows it.
  it('never re-wraps a ```widget fence that follows a line like "```js `x`" (invalid info-string, not a fence)', () => {
    const input = '```js `x`\n```widget\n{"type":"ToolTrace"}\n```';
    expect(referenceNakedWidgetJson(input)).toBe(input);
  });

  // CRLF line endings must not break the real-fence scan used to skip already-fenced content.
  it("recognizes a ```widget fence with CRLF line endings as already fenced", () => {
    const input = 'Text\r\n```widget\r\n{"type":"ToolTrace"}\r\n```\r\nAfter';
    expect(referenceNakedWidgetJson(input)).toBe(input);
  });
});
