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
 * The `Math` node through the renderer: KaTeX typesets a block formula under its heading with the
 * LaTeX badge and the caption line; an inline formula stays inline; invalid LaTeX shows its source and reports
 * `failed`; KaTeX's safe mode drops `\href`; a formula bound to a computed value follows the state.
 */
const renderTree = (widgetFields: Record<string, unknown>) => {
  const reports: WidgetRenderReportEntry[] = [];
  const tree = readComposedViewTree(widgetFields, getComposedNodeSchema());
  const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
  const { container } = render(
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
  return { reports, container, tree };
};

describe("the Math node", () => {
  test("a block formula is typeset under its heading, badge and caption", async () => {
    const { reports, container, tree } = renderTree({
      view: {
        type: "Math",
        latex: "CR = \\frac{N_{Conversions}}{N_{Visitors}} \\times 100\\%",
        title: "Conversion Rate",
        caption: "Example: 750 conversions from 25,000 visitors give 3 %.",
      },
    });
    expect(tree.findings).toEqual([]);
    await waitFor(() => expect(container.querySelector(".katex-display")).not.toBeNull());
    expect(screen.getByText("Conversion Rate")).toBeTruthy();
    expect(screen.getByText("LaTeX")).toBeTruthy();
    expect(screen.getByText("Example: 750 conversions from 25,000 visitors give 3 %.")).toBeTruthy();
    expect(container.querySelector("math")).not.toBeNull();
    await waitFor(() => expect(reports.at(-1)).toEqual({ status: "mounted" }));
  });

  test("an inline formula is set inside its line, without a heading", async () => {
    const { container } = renderTree({ view: { type: "Math", latex: "a^2 + b^2 = c^2", display: "inline" } });
    await waitFor(() => expect(container.querySelector(".katex")).not.toBeNull());
    expect(container.querySelector(".katex-display")).toBeNull();
    expect(screen.queryByText("LaTeX")).toBeNull();
  });

  test("invalid LaTeX shows its source and reports failed with the parse message", async () => {
    const { reports } = renderTree({ view: { type: "Math", latex: "\\frac{1}{" } });
    await waitFor(() => expect(screen.getByText("This formula could not be rendered.")).toBeTruthy());
    expect(screen.getByText("\\frac{1}{")).toBeTruthy();
    await waitFor(() => expect(reports.at(-1)?.status).toBe("failed"));
    expect(reports.at(-1)?.code).toBe("RENDER_FAILED");
    expect(reports.at(-1)?.detail).toContain("not valid LaTeX");
  });

  test("KaTeX's safe mode renders no link for \\href", async () => {
    const { container } = renderTree({ view: { type: "Math", latex: "\\href{javascript:alert(1)}{x}" } });
    await waitFor(() => expect(container.querySelector(".katex, .katex-error")).not.toBeNull());
    expect(container.querySelector("a")).toBeNull();
    expect(container.querySelector("[href]")).toBeNull();
  });

  test("a formula bound to a computed value is typeset from the state", async () => {
    const { container } = renderTree({
      state: { power: 3 },
      computed: { formula: "'x^' + string(power)" },
      view: { type: "Math", latex: { $computed: "formula" } },
    });
    await waitFor(() => expect(container.querySelector(".katex-display")).not.toBeNull());
    expect(container.querySelector("annotation")?.textContent).toBe("x^3");
  });

  test("an empty formula renders nothing and reports empty", async () => {
    const { reports, container } = renderTree({ view: { type: "Math", latex: " ", title: "Nothing" } });
    await waitFor(() => expect(reports.at(-1)?.status).toBe("empty"));
    expect(container.textContent).toBe("");
  });

  test("a heading with markup shows as written", async () => {
    renderTree({ view: { type: "Math", latex: "a < b", title: "<b>Bold</b> & <i>x</i>" } });
    await waitFor(() => expect(screen.getByText("<b>Bold</b> & <i>x</i>")).toBeTruthy());
  });
});
