// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderLayoutTree } from "./layout-spec-render";
import { readMinColumnWidth } from "./Grid.composed";
import { getAgentsEnvironment, setAgentsEnvironment } from "../../../provider/environment";

/** `Grid` through the renderer: one cell per child, the column count and minimum, the empty report. */
describe("Grid", () => {
  test("renders one cell per child with the column count and minimum width", () => {
    const { container, reports } = renderLayoutTree({
      view: {
        type: "Grid",
        columns: 3,
        minColumnWidth: "regular",
        children: [
          { type: "Surface", title: "Visitors" },
          { type: "Surface", title: "Conversions" },
          { type: "Surface", title: "Conversion rate" },
        ],
      },
    });
    const grid = container.querySelector("div[class*='grid']") as HTMLElement;
    expect(grid.className).toMatch(/columns3/);
    expect(grid.className).toMatch(/minColumnRegular/);
    expect(grid.children).toHaveLength(3);
    expect(screen.getByText("Conversion rate")).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("defaults to two columns and regular width when every cell is a KPI tile", () => {
    const { container } = renderLayoutTree({
      view: {
        type: "Grid",
        children: [
          { type: "KpiTile", label: "Anrufe", value: 142 },
          { type: "KpiTile", label: "Website-Klicks", value: 318 },
        ],
      },
    });
    const grid = container.querySelector("div[class*='grid']") as HTMLElement;
    expect(grid.className).toMatch(/columns2/);
    expect(grid.className).toMatch(/minColumnRegular/);
  });

  test("without a written minimum, a grid of text tiles takes the regular minimum (one column on a phone)", () => {
    const { container } = renderLayoutTree({
      view: {
        type: "Grid",
        children: [
          { type: "Surface", title: "Prophylaxe", children: [{ type: "Text", text: "Professionelle Zahnreinigung" }] },
          { type: "Surface", title: "Implantologie", children: [{ type: "Text", text: "Implantate und Beratung" }] },
        ],
      },
    });
    const grid = container.querySelector("div[class*='grid']") as HTMLElement;
    expect(grid.className).toMatch(/minColumnRegular/);
  });

  test("a written minimum wins over the cell types", () => {
    const { container } = renderLayoutTree({
      view: { type: "Grid", minColumnWidth: "compact", children: [{ type: "Surface", title: "Prophylaxe" }] },
    });
    const grid = container.querySelector("div[class*='grid']") as HTMLElement;
    expect(grid.className).toMatch(/minColumnCompact/);
  });

  test("embedded widgets of the compact size class are compact cells; a regular one is not", () => {
    // Two stub widgets an app declares as embeddable, one per size class (an app's part of the node schema).
    const environmentBefore = getAgentsEnvironment();
    setAgentsEnvironment({
      ...environmentBefore,
      composedNodeSchemaApplicationPart: {
        embeddableWidgets: { ExampleTile: { sizeClass: "compact" }, ExampleList: { sizeClass: "regular" } },
      },
    });
    const widgetCell = (widget: string) => ({ type: "Widget", id: null, props: { widget }, nodesBySlotName: {} });
    const gridOf = (...widgets: string[]) => ({
      type: "Grid",
      id: null,
      props: {},
      nodesBySlotName: { children: widgets.map(widgetCell) },
    });
    try {
      expect(readMinColumnWidth(gridOf("ExampleTile", "ExampleTile"), undefined)).toBe("compact");
      expect(readMinColumnWidth(gridOf("ExampleTile", "ExampleList"), undefined)).toBe("regular");
      expect(readMinColumnWidth(gridOf("ExampleTile", "NotEmbeddable"), undefined)).toBe("regular");
    } finally {
      setAgentsEnvironment(environmentBefore);
    }
  });

  test("a grid without cells renders nothing and reports empty", () => {
    const { reports } = renderLayoutTree({ view: { type: "Grid", columns: 2 } });
    expect(reports.at(-1)).toMatchObject({ status: "empty" });
  });

  test.each([1, 2, 3, 4])("preserves column maximum %s and propagates the grid id", (columns) => {
    renderLayoutTree({
      view: {
        type: "Grid",
        id: "metrics",
        columns,
        children: [{ type: "KpiTile", label: "Status", value: "Verificat · publicat" }],
      },
    });
    const grid = screen.getByTestId("composed-view-widget-grid-metrics");
    expect(grid.className).toContain(`columns${columns}`);
    expect(grid.className).toContain("minColumnRegular");
  });

  test("explicit compact wins for KPI tiles with mixed optional parts", () => {
    renderLayoutTree({
      view: {
        type: "Grid",
        id: "metrics",
        minColumnWidth: "compact",
        children: [
          { type: "KpiTile", label: "Reviews", value: 22 },
          {
            type: "KpiTile",
            label: "Status",
            value: "Verified",
            unit: "%",
            detail: "Updated today",
            source: { name: "getGoogleProfile" },
          },
        ],
      },
    });
    expect(screen.getByTestId("composed-view-widget-grid-metrics").className).toContain("minColumnCompact");
  });

  test("pending and long optional text keep the KPI-only regular default", () => {
    renderLayoutTree({
      view: {
        type: "Grid",
        id: "metrics",
        children: [
          {
            type: "KpiTile",
            label: "Reviews",
            value: { $from: { toolCall: "call_7", path: "$.total", state: "pending" } },
          },
          {
            type: "KpiTile",
            label: "Status",
            value: "Verificat · publicat",
            detail: "Results collected during the last twenty-eight days",
          },
        ],
      },
    });
    expect(screen.getByTestId("composed-view-widget-grid-metrics").className).toContain("minColumnRegular");
  });
});
