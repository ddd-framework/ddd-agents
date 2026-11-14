// @vitest-environment jsdom
import { beforeEach, describe, expect, test, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import type { WidgetRenderReportEntry } from "../../../renderer/render-report/widget-render-report";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";

/**
 * The `Diagram` node through the renderer, with Mermaid stubbed (jsdom has no SVG layout): Mermaid
 * runs strict with SVG labels, its drawing is shown after sanitising (a script it would let through is removed and
 * reported), a parse error renders one muted line and reports `failed` with Mermaid's message, an empty source
 * reports `empty`.
 */
const mermaidStub = vi.hoisted(() => ({
  initialize: vi.fn(),
  parse: vi.fn(),
  render: vi.fn(),
}));
vi.mock("mermaid", () => ({ default: mermaidStub }));

const DRAWN_FLOWCHART =
  "<svg id='drawn' viewBox='0 0 200 300' style='max-width: 200px;' role='graphics-document document'" +
  " aria-roledescription='flowchart-v2'><style>#drawn .node rect{fill:#eee}</style>" +
  "<g class='node'><rect width='80' height='30'/><text>User request</text></g></svg>";

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
  return { reports, container };
};

describe("the Diagram node", () => {
  beforeEach(() => {
    mermaidStub.initialize.mockReset();
    mermaidStub.parse.mockReset().mockResolvedValue(true);
    mermaidStub.render.mockReset().mockResolvedValue({ svg: DRAWN_FLOWCHART });
  });

  test("Mermaid draws the source strictly with SVG labels, and the drawing is shown", async () => {
    const source = "flowchart TD\n  A[User request] --> B[Widget search]";
    const { reports, container } = renderTree({ view: { type: "Diagram", source, title: "Widget life cycle" } });
    await waitFor(() => expect(container.querySelector("svg text")?.textContent).toBe("User request"));
    expect(mermaidStub.initialize).toHaveBeenCalledWith(
      expect.objectContaining({ securityLevel: "strict", htmlLabels: false, startOnLoad: false }),
    );
    expect(mermaidStub.render).toHaveBeenCalledWith(expect.stringMatching(/^composed-diagram-/), source);
    expect(container.querySelector("svg style")).not.toBeNull();
    expect(container.querySelector("svg")?.getAttribute("aria-label")).toBe("Widget life cycle");
    expect(screen.getByText("Widget life cycle")).toBeTruthy();
    await waitFor(() => expect(reports.at(-1)).toEqual({ status: "mounted" }));
  });

  test("a script in the drawn SVG is removed and reported", async () => {
    mermaidStub.render.mockResolvedValue({
      svg: "<svg viewBox='0 0 10 10'><script>alert(1)</script><rect width='5' height='5'/></svg>",
    });
    const { reports, container } = renderTree({ view: { type: "Diagram", source: "flowchart TD\n  A --> B" } });
    await waitFor(() => expect(container.querySelector("svg rect")).not.toBeNull());
    expect(container.querySelector("script")).toBeNull();
    await waitFor(() => expect(reports.at(-1)?.code).toBe("INVALID_PROP"));
  });

  test("a parse error renders one muted line and reports Mermaid's message", async () => {
    mermaidStub.parse.mockRejectedValue(new Error("Parse error on line 2: Expecting 'SEMI'"));
    const { reports, container } = renderTree({ view: { type: "Diagram", source: "flowchart TD\n  A -->" } });
    await waitFor(() => expect(screen.getByText("This diagram could not be drawn.")).toBeTruthy());
    expect(mermaidStub.render).not.toHaveBeenCalled();
    expect(container.querySelector("svg")).toBeNull();
    await waitFor(() => expect(reports.at(-1)?.status).toBe("failed"));
    expect(reports.at(-1)?.code).toBe("RENDER_FAILED");
    expect(reports.at(-1)?.detail).toContain("Parse error on line 2");
  });

  test("an empty source reports empty", async () => {
    const { reports, container } = renderTree({ view: { type: "Diagram", source: "   " } });
    expect(container.textContent).toBe("");
    await waitFor(() => expect(reports.at(-1)?.status).toBe("empty"));
  });
});
