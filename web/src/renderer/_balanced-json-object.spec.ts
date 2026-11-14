import { describe, expect, it } from "vitest";
import { findBalancedJsonObjectEnd } from "./balanced-json-object";

describe("findBalancedJsonObjectEnd", () => {
  it("finds the closing brace of a simple object", () => {
    const text = '{"type":"ExampleWidget"}';
    expect(findBalancedJsonObjectEnd(text, 0)).toBe(text.length - 1);
  });

  it("skips leading whitespace before the object starts", () => {
    const text = '\n\n  {"type":"ExampleWidget"}';
    expect(findBalancedJsonObjectEnd(text, 0)).toBe(text.length - 1);
  });

  it("ignores braces inside a JSON string value", () => {
    const text = '{ "type": "ExampleWidget", "title": "All {items}" }';
    expect(findBalancedJsonObjectEnd(text, 0)).toBe(text.length - 1);
  });

  it("handles an escaped quote and an escaped backslash inside a string", () => {
    const text = '{"title":"Escaped \\"quote\\" and \\\\ slash {x}"}';
    expect(findBalancedJsonObjectEnd(text, 0)).toBe(text.length - 1);
  });

  it("returns null when nothing starts with { at or after fromIndex", () => {
    expect(findBalancedJsonObjectEnd("no object here", 0)).toBeNull();
  });

  it("returns null when the object never balances within maxScanLength", () => {
    const text = "{" + '"a":1,'.repeat(100);
    expect(findBalancedJsonObjectEnd(text, 0, 10)).toBeNull();
  });

  it("respects a non-default maxScanLength when the object does balance within it", () => {
    const text = '{"a":1}';
    expect(findBalancedJsonObjectEnd(text, 0, 10)).toBe(text.length - 1);
  });
});
