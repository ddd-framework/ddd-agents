// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderLayoutTree } from "./layout-spec-render";

/** `Divider` through the renderer: a separator, horizontal by default, vertical on request. */
describe("Divider", () => {
  test("is a horizontal separator by default", () => {
    const { reports } = renderLayoutTree({ view: { type: "Divider" } });
    expect(screen.getByRole("separator").getAttribute("aria-orientation")).toBe("horizontal");
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("is vertical between the items of a row", () => {
    renderLayoutTree({
      view: {
        type: "Stack",
        direction: "row",
        children: [
          { type: "Quote", text: "A" },
          { type: "Divider", orientation: "vertical" },
        ],
      },
    });
    expect(screen.getByRole("separator").getAttribute("aria-orientation")).toBe("vertical");
  });
});
