// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderLayoutTree } from "./layout-spec-render";

/**
 * `Surface` through the renderer: the outer surface is raised, a nested one flat; the title,
 * subtitle, header controls, body and footer; a bound title; the empty report.
 */
describe("Surface", () => {
  test("raises the outer surface and keeps a nested one flat", () => {
    const { container, reports } = renderLayoutTree({
      view: {
        type: "Surface",
        title: "Analytics",
        subtitle: "Interactive demo · sample data",
        children: [
          { type: "Surface", title: "Visitors", density: "compact", children: [{ type: "Quote", text: "8,200" }] },
        ],
        slots: {
          header: [{ type: "Popover", label: "Method", text: "Weighted by channel." }],
          footer: [{ type: "Quote", text: "Updated today" }],
        },
      },
    });
    const surfaces = Array.from(container.querySelectorAll("[data-surface-level]"));
    expect(surfaces.map((surface) => surface.getAttribute("data-surface-level"))).toEqual(["elevated", "flat"]);
    expect(screen.getByText("Analytics")).toBeTruthy();
    expect(screen.getByText("Interactive demo · sample data")).toBeTruthy();
    expect(screen.getByRole("button", { name: /Method/ })).toBeTruthy();
    expect(screen.getByText("8,200")).toBeTruthy();
    expect(screen.getByText("Updated today")).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("the title follows a state binding", () => {
    renderLayoutTree({ state: { period: "Last 7 days" }, view: { type: "Surface", title: { $state: "period" } } });
    expect(screen.getByText("Last 7 days")).toBeTruthy();
  });

  test("the model's markup in the title shows as written, never as HTML", () => {
    renderLayoutTree({ view: { type: "Surface", title: "Fish & Chips <b>today</b>", subtitle: "<img src=x>" } });
    expect(screen.getByText("Fish & Chips <b>today</b>")).toBeTruthy();
    expect(screen.getByText("<img src=x>")).toBeTruthy();
  });

  test("a surface with no title and no content renders nothing and reports empty", () => {
    const { container, reports } = renderLayoutTree({ view: { type: "Surface" } });
    expect(container.querySelector("[data-surface-level]")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "empty" });
  });
});
