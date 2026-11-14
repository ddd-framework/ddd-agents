// @vitest-environment jsdom
import { afterEach, describe, expect, test, vi } from "vitest";
import { act, fireEvent, screen } from "@testing-library/react";
import { composedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { renderLayoutTree } from "./layout-spec-render";

/**
 * `Carousel` through the renderer: static slides, slides repeated per item with `$item`, the
 * `maxRepeatRows` cap, the arrows (only while there is something to scroll to), the e-mail capture, the empty report.
 */
const slidesOf = () =>
  screen.getAllByRole("group").filter((group) => group.getAttribute("aria-roledescription") === "slide");

afterEach(() => vi.restoreAllMocks());

describe("Carousel", () => {
  test("each child node is one slide", () => {
    const { reports } = renderLayoutTree({
      view: {
        type: "Carousel",
        label: "Channels",
        children: [
          { type: "Surface", title: "Organic" },
          { type: "Surface", title: "Paid" },
          { type: "Surface", title: "Direct" },
        ],
      },
    });
    expect(slidesOf()).toHaveLength(3);
    expect(slidesOf()[1].getAttribute("aria-label")).toBe("2 of 3");
    expect(screen.getByRole("group", { name: "Channels" })).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("with items the children repeat per item with $item bound", () => {
    renderLayoutTree({
      data: {
        channels: [
          { name: "Organic", visitors: "4,190" },
          { name: "Paid", visitors: "2,620" },
        ],
      },
      view: {
        type: "Carousel",
        items: { $data: "channels" },
        children: [
          { type: "Surface", title: { $item: "name" }, children: [{ type: "Quote", text: { $item: "visitors" } }] },
        ],
      },
    });
    expect(slidesOf()).toHaveLength(2);
    expect(screen.getByText("Paid")).toBeTruthy();
    expect(screen.getByText("2,620")).toBeTruthy();
  });

  test("caps the repeated slides at maxRepeatRows and reports the cut", () => {
    const itemCount = composedNodeSchema.budgets.maxRepeatRows + 3;
    const { reports } = renderLayoutTree({
      data: { rows: Array.from({ length: itemCount }, (unused, rowIndex) => ({ n: `row ${rowIndex}` })) },
      view: { type: "Carousel", items: { $data: "rows" }, children: [{ type: "Quote", text: { $item: "n" } }] },
    });
    expect(slidesOf()).toHaveLength(composedNodeSchema.budgets.maxRepeatRows);
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "BUDGET_EXCEEDED" });
  });

  test("shows the next arrow only while the track can scroll forward, and pages by one slide", () => {
    vi.spyOn(HTMLElement.prototype, "scrollWidth", "get").mockReturnValue(900);
    vi.spyOn(HTMLElement.prototype, "clientWidth", "get").mockReturnValue(400);
    const scrollBy = vi.fn();
    Object.defineProperty(HTMLElement.prototype, "scrollBy", { value: scrollBy, configurable: true, writable: true });
    renderLayoutTree({
      view: {
        type: "Carousel",
        children: [
          { type: "Quote", text: "A" },
          { type: "Quote", text: "B" },
          { type: "Quote", text: "C" },
        ],
      },
    });
    expect(screen.queryByRole("button", { name: "Show the previous slides" })).toBeNull();
    act(() => {
      fireEvent.click(screen.getByRole("button", { name: "Show the next slides" }));
    });
    expect(scrollBy).toHaveBeenCalledWith(expect.objectContaining({ left: expect.any(Number) }));
    delete (HTMLElement.prototype as { scrollBy?: unknown }).scrollBy;
  });

  test("the e-mail capture shows the slides without arrows", () => {
    vi.spyOn(HTMLElement.prototype, "scrollWidth", "get").mockReturnValue(900);
    vi.spyOn(HTMLElement.prototype, "clientWidth", "get").mockReturnValue(400);
    renderLayoutTree(
      {
        view: {
          type: "Carousel",
          children: [
            { type: "Quote", text: "A" },
            { type: "Quote", text: "B" },
          ],
        },
      },
      true,
    );
    expect(slidesOf()).toHaveLength(2);
    expect(screen.queryByRole("button")).toBeNull();
  });

  test("items that resolve to nothing show no slide and report empty", () => {
    const { reports } = renderLayoutTree({
      data: { rows: [] },
      view: { type: "Carousel", items: { $data: "rows" }, children: [{ type: "Quote", text: "never" }] },
    });
    expect(screen.queryByText("never")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "empty" });
  });
});
