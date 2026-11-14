// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderLayoutTree } from "./layout-spec-render";

/** `Stack` through the renderer: children in order, row/column, gap step, the empty report. */
describe("Stack", () => {
  test("renders its children in a wrapping row with the gap step and reports mounted", () => {
    const { container, reports, tree } = renderLayoutTree({
      view: {
        type: "Stack",
        direction: "row",
        gap: "large",
        justify: "between",
        children: [
          { type: "Quote", text: "First" },
          { type: "Quote", text: "Second" },
        ],
      },
    });
    expect(tree.findings).toEqual([]);
    const stack = container.querySelector("div[class*='stack']") as HTMLElement;
    expect(stack.className).toMatch(/row/);
    expect(stack.className).toMatch(/wrap/);
    expect(stack.className).toMatch(/gapLarge/);
    expect(stack.className).toMatch(/justifyBetween/);
    expect(screen.getByText("First").compareDocumentPosition(screen.getByText("Second"))).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    );
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a column is the default and a row may refuse to wrap", () => {
    const { container } = renderLayoutTree({
      view: {
        type: "Stack",
        children: [{ type: "Stack", direction: "row", wrap: false, children: [{ type: "Divider" }] }],
      },
    });
    const [outer, inner] = Array.from(container.querySelectorAll("div[class*='stack']")) as HTMLElement[];
    expect(outer.className).toMatch(/column/);
    expect(outer.className).toMatch(/gapMedium/);
    expect(inner.className).not.toMatch(/wrap/);
  });

  test("a stack without children renders nothing and reports empty", () => {
    const { container, reports } = renderLayoutTree({ view: { type: "Stack" } });
    expect(container.querySelector("div[class*='stack']")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "empty" });
  });
});
