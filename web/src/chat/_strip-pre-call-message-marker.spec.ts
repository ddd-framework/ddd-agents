import { describe, expect, it } from "vitest";
import { PRE_CALL_MESSAGE_MARKER, stripPreCallMessageMarker } from "./strip-pre-call-message-marker";

describe("stripPreCallMessageMarker", () => {
  it("removes the sentinel when it is the message's whole leading content", () => {
    expect(stripPreCallMessageMarker(`${PRE_CALL_MESSAGE_MARKER}I'm checking that now.`)).toBe(
      "I'm checking that now.",
    );
  });

  it("removes the sentinel and the whitespace it introduced, even with surrounding whitespace", () => {
    expect(stripPreCallMessageMarker(`  ${PRE_CALL_MESSAGE_MARKER}  I'm checking that now.`)).toBe(
      "I'm checking that now.",
    );
  });

  it("normalizes the mangled JSON form to its inner status line", () => {
    expect(stripPreCallMessageMarker('{ "preCallMessage": "Einen Moment" }Rest of the answer.')).toBe(
      "Einen MomentRest of the answer.",
    );
  });

  // A message with NO marker at all must keep its own leading indentation untouched — a four-space
  // indented code block at the very start of a message must stay plain CommonMark code, never slide to column 0
  // and become a widget fence.
  it("leaves a message's own leading indentation completely untouched when no marker is present", () => {
    const indented = '    ```widget\n{"type":"ToolTrace"}\n    ```';
    expect(stripPreCallMessageMarker(indented)).toBe(indented);
  });

  it("leaves ordinary leading whitespace untouched when no marker is present", () => {
    expect(stripPreCallMessageMarker("   Just some indented prose.")).toBe("   Just some indented prose.");
  });

  it("holds back a leading partial sentinel while still streaming", () => {
    expect(stripPreCallMessageMarker("{preCa")).toBe("");
  });

  it("holds back a leading partial mangled form while still streaming", () => {
    expect(stripPreCallMessageMarker('{ "preCallMessage": "Einen Mo')).toBe("");
  });

  it("returns an empty/undefined-ish string unchanged", () => {
    expect(stripPreCallMessageMarker("")).toBe("");
  });
});
