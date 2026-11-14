import { describe, expect, it, vi } from "vitest";
import { assistantMessageHasRenderableMarkdown } from "./assistant-message-has-renderable-markdown";

describe("assistantMessageHasRenderableMarkdown — keep text-less assistant rows out of the DOM", () => {
  it("reports no content for a tool-calling step (ASSISTANT/TEXT with an empty message)", () => {
    expect(assistantMessageHasRenderableMarkdown(null)).toBe(false);
    expect(assistantMessageHasRenderableMarkdown(undefined)).toBe(false);
    expect(assistantMessageHasRenderableMarkdown("")).toBe(false);
    expect(assistantMessageHasRenderableMarkdown("   \n ")).toBe(false);
  });

  it("does not warn about an 'unrecognized message shape' for an empty message", () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    assistantMessageHasRenderableMarkdown(null);
    assistantMessageHasRenderableMarkdown("");
    expect(warn).not.toHaveBeenCalled();
    warn.mockRestore();
  });

  it("reports no content for a parts payload whose text parts are all blank", () => {
    expect(assistantMessageHasRenderableMarkdown(JSON.stringify({ content: [{ type: "text", text: "  " }] }))).toBe(
      false,
    );
  });

  it("reports no content for a message that is only a bare JSON object of an unknown type", () => {
    expect(assistantMessageHasRenderableMarkdown('{"type":"no-op"}')).toBe(false);
    expect(assistantMessageHasRenderableMarkdown('  {"type": "noop", "reason": "x"}\n')).toBe(false);
  });

  it("reports content for a known widget type emitted as bare JSON, and for JSON inside prose", () => {
    expect(assistantMessageHasRenderableMarkdown('{ "type": "QuickReplies" }')).toBe(true);
    expect(assistantMessageHasRenderableMarkdown('Result:\n\n{"type":"no-op"}')).toBe(true);
    expect(assistantMessageHasRenderableMarkdown('{"kind":"no-op"}')).toBe(true);
  });

  it("reports content for plain text, for a text part, and for a widget/tool part", () => {
    expect(assistantMessageHasRenderableMarkdown("Hier sind deine Rankings.")).toBe(true);
    expect(assistantMessageHasRenderableMarkdown(JSON.stringify({ content: [{ type: "text", text: "Hallo" }] }))).toBe(
      true,
    );
    expect(
      assistantMessageHasRenderableMarkdown(JSON.stringify({ content: [{ type: "component", name: "Foo" }] })),
    ).toBe(true);
    expect(
      assistantMessageHasRenderableMarkdown(
        JSON.stringify({ content: [{ type: "tool_use", name: "get_rankings", input: {} }] }),
      ),
    ).toBe(true);
  });
});
