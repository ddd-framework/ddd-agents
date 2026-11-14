// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import type { WidgetRenderReportEntry } from "../../../renderer/render-report/widget-render-report";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";

/**
 * The `Svg` node through the renderer: safe markup is drawn with its label and scales by its
 * viewBox; scripts, event attributes, `foreignObject`, `style` elements and every reference outside the graphic are
 * removed and reported while the rest is drawn; markup without an `<svg>` root renders one muted line and reports
 * `failed`; markup bound to a computed text follows the state.
 */
const renderTree = (widgetFields: Record<string, unknown>) => {
  const reports: WidgetRenderReportEntry[] = [];
  const tree = readComposedViewTree(widgetFields, getComposedNodeSchema());
  const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
  const { container, rerender } = render(
    <Provider store={store}>
      <ComposedViewRenderer
        tree={tree}
        interactive
        isEmailRender={false}
        agentSpaceKey={7}
        onReport={(entry) => reports.push(entry)}
      />
    </Provider>,
  );
  return { reports, container, tree, rerender };
};

describe("the Svg node", () => {
  test("safe markup is drawn with its label, sized by its viewBox", async () => {
    const { reports, container } = renderTree({
      view: {
        type: "Svg",
        markup: "<svg width='200' height='100'><circle cx='50' cy='50' r='40' fill='#4a90d9'/></svg>",
        label: "A blue circle",
        title: "Marketing Performance Radar",
      },
    });
    await waitFor(() => expect(container.querySelector("circle")).not.toBeNull());
    const svgElement = container.querySelector("svg");
    expect(svgElement?.getAttribute("viewBox")).toBe("0 0 200 100");
    expect(svgElement?.getAttribute("width")).toBeNull();
    expect(svgElement?.getAttribute("aria-label")).toBe("A blue circle");
    expect(screen.getByText("Marketing Performance Radar")).toBeTruthy();
    await waitFor(() => expect(reports.at(-1)).toEqual({ status: "mounted" }));
  });

  test("scripts, handlers, foreignObject, style and outside references are removed and reported", async () => {
    const { reports, container } = renderTree({
      view: {
        type: "Svg",
        markup:
          "<svg viewBox='0 0 100 100' onload='alert(1)'>" +
          "<script>alert(1)</script>" +
          "<style>@import url(https://evil.example/x.css);</style>" +
          "<foreignObject><div>html</div></foreignObject>" +
          "<a href='https://evil.example'><text>link</text></a>" +
          "<image href='https://evil.example/pixel.png'/>" +
          "<use href='https://evil.example/sprite.svg#icon'/>" +
          "<rect id='box' width='10' height='10' fill='url(https://evil.example/paint)'/>" +
          "<circle cx='50' cy='50' r='20' fill='url(#gradient)'/>" +
          "</svg>",
      },
    });
    await waitFor(() => expect(container.querySelector("circle")).not.toBeNull());
    const drawnMarkup = container.querySelector("svg")?.outerHTML ?? "";
    expect(drawnMarkup).not.toContain("script");
    expect(drawnMarkup).not.toContain("onload");
    expect(drawnMarkup).not.toContain("foreignObject");
    expect(drawnMarkup).not.toContain("evil.example");
    expect(drawnMarkup).not.toContain("<style");
    expect(container.querySelector("rect#box")?.getAttribute("fill")).toBeNull();
    expect(container.querySelector("circle")?.getAttribute("fill")).toBe("url(#gradient)");
    await waitFor(() => expect(reports.at(-1)?.status).toBe("failed"));
    expect(reports.at(-1)?.code).toBe("INVALID_PROP");
    expect(reports.at(-1)?.detail).toContain("the rest is drawn");
  });

  test("markup without an svg root renders one muted line and reports failed", async () => {
    const { reports } = renderTree({ view: { type: "Svg", markup: "<div>no graphic</div>" } });
    await waitFor(() => expect(screen.getByText("This graphic could not be shown.")).toBeTruthy());
    await waitFor(() => expect(reports.at(-1)?.code).toBe("RENDER_FAILED"));
  });

  test("markup bound to a computed text follows the state", async () => {
    const { container } = renderTree({
      state: { radius: 30 },
      computed: {
        circle: "\"<svg viewBox='0 0 100 100'><circle cx='50' cy='50' r='\" + string(radius) + \"'/></svg>\"",
      },
      view: { type: "Svg", markup: { $computed: "circle" } },
    });
    await waitFor(() => expect(container.querySelector("circle")?.getAttribute("r")).toBe("30"));
  });

  test("empty markup renders nothing and reports empty", async () => {
    const { reports, container } = renderTree({ view: { type: "Svg", markup: "" } });
    await waitFor(() => expect(reports.at(-1)?.status).toBe("empty"));
    expect(container.textContent).toBe("");
  });
});
