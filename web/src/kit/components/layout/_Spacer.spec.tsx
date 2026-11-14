// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { renderLayoutTree } from "./layout-spec-render";

/** `Spacer` through the renderer: hidden from screen readers, a size step, `grow`. */
describe("Spacer", () => {
  test("is hidden room of the given size that may grow", () => {
    const { container, reports } = renderLayoutTree({
      view: {
        type: "Stack",
        direction: "row",
        children: [{ type: "Spacer", size: "large", grow: true }, { type: "Divider" }],
      },
    });
    const spacer = container.querySelector("div[aria-hidden='true']") as HTMLElement;
    expect(spacer.className).toMatch(/large/);
    expect(spacer.className).toMatch(/grow/);
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("defaults to the medium step", () => {
    const { container } = renderLayoutTree({ view: { type: "Spacer" } });
    expect((container.querySelector("div[aria-hidden='true']") as HTMLElement).className).toMatch(/medium/);
  });
});
