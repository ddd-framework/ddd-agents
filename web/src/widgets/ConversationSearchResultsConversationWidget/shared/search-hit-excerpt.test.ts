import { describe, expect, it } from "vitest";
import { normalizeSearchHitExcerpt } from "./search-hit-excerpt";

describe("normalizeSearchHitExcerpt", () => {
  it("extracts text from OpenAI-style message payload JSON", () => {
    const excerpt = JSON.stringify({
      content: [{ type: "text", text: 'Welcome back. We were working on "Lose Weight Fast".' }],
    });

    expect(normalizeSearchHitExcerpt(excerpt)).toBe('Welcome back. We were working on "Lose Weight Fast".');
  });

  it("extracts text from truncated OpenAI-style message payload excerpts", () => {
    const excerpt =
      '{ "content": [ { "type": "text", "text": "Welcome back. Last time we were working on \\"Lose Weight Fast\\"" },...';

    expect(normalizeSearchHitExcerpt(excerpt)).toBe('Welcome back. Last time we were working on "Lose Weight Fast"');
  });
});
