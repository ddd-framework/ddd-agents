// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderLayoutTree } from "./layout-spec-render";

/** `Flow` through the renderer: wrapping items, the small default gap, a dropped layout child. */
describe("Flow", () => {
  test("renders its items with the small gap by default", () => {
    const { container, reports } = renderLayoutTree({
      view: {
        type: "Flow",
        children: [
          { type: "Popover", label: "Method", text: "Weighted by channel." },
          { type: "Popover", label: "Sources", text: "Google Analytics." },
        ],
      },
    });
    const flow = container.querySelector("div[class*='flow']") as HTMLElement;
    expect(flow.className).toMatch(/gapSmall/);
    expect(screen.getByText("Method")).toBeTruthy();
    expect(screen.getByText("Sources")).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a layout node in a flow is dropped and reported; the rest renders", () => {
    const { reports } = renderLayoutTree({
      view: { type: "Flow", children: [{ type: "Grid", children: [{ type: "Divider" }] }, { type: "Divider" }] },
    });
    expect(screen.getByRole("separator")).toBeTruthy();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_NODE" });
  });

  test("a flow without items reports empty", () => {
    const { reports } = renderLayoutTree({ view: { type: "Flow" } });
    expect(reports.at(-1)).toMatchObject({ status: "empty" });
  });
});
