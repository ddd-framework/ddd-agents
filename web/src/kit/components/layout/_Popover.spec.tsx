// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { act, fireEvent, screen } from "@testing-library/react";
import { renderLayoutTree } from "./layout-spec-render";

/**
 * `Popover` through the renderer: a click opens the note, a second click, Escape or a press outside
 * closes it; the e-mail and a surrounding Pressable show the label only; nothing to explain → empty.
 */
describe("Popover", () => {
  const view = { type: "Popover", label: "Method", text: "Visitors are weighted by channel; the benchmark is 90 %." };

  test("opens on click and closes on a second click", () => {
    const { reports } = renderLayoutTree({ view });
    const trigger = screen.getByRole("button", { name: /Method/ });
    expect(trigger.getAttribute("aria-expanded")).toBe("false");
    expect(screen.queryByRole("note")).toBeNull();
    act(() => {
      fireEvent.click(trigger);
    });
    expect(trigger.getAttribute("aria-expanded")).toBe("true");
    expect(screen.getByRole("note").textContent).toContain("benchmark is 90 %");
    expect(trigger.getAttribute("aria-controls")).toBe(screen.getByRole("note").id);
    act(() => {
      fireEvent.click(trigger);
    });
    expect(screen.queryByRole("note")).toBeNull();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("Escape and a press outside close it", () => {
    renderLayoutTree({ view });
    const trigger = screen.getByRole("button", { name: /Method/ });
    act(() => {
      fireEvent.click(trigger);
    });
    act(() => {
      fireEvent.keyDown(document, { key: "Escape" });
    });
    expect(screen.queryByRole("note")).toBeNull();
    expect(document.activeElement).toBe(trigger);
    act(() => {
      fireEvent.click(trigger);
    });
    act(() => {
      fireEvent.pointerDown(document.body);
    });
    expect(screen.queryByRole("note")).toBeNull();
  });

  test("the e-mail capture shows the label without a button", () => {
    renderLayoutTree({ view }, true);
    expect(screen.getByText("Method")).toBeTruthy();
    expect(screen.queryByRole("button")).toBeNull();
  });

  test("inside a Pressable it shows the label only, so no button sits in a button", () => {
    renderLayoutTree({
      state: { picked: "" },
      view: { type: "Pressable", onClick: { set: "picked" }, value: "x", children: [view] },
    });
    expect(screen.getAllByRole("button")).toHaveLength(1);
    expect(screen.getByText("Method")).toBeTruthy();
  });

  test("a popover with nothing to explain reports empty", () => {
    const { reports } = renderLayoutTree({ view: { type: "Popover", label: "Method" } });
    expect(screen.queryByRole("button")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "empty" });
  });
});
