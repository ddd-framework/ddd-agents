// @vitest-environment jsdom
import { getWidgetSelfHydrationKeyFieldName } from "../provider/environment";
import React from "react";
import { describe, expect, test, vi } from "vitest";
import { act, fireEvent, render, renderHook, screen, waitFor } from "@testing-library/react";
import { composedNodeSchema, getComposedNodeSchema } from "../renderer/widget-tree/composed-node-schema";
import { useComposedViewState } from "../renderer/widget-tree/use-composed-view-state";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../renderer/widget-hydration";
import { registerComposedViewDataSourceFetcher } from "../renderer/widget-tree/composed-view-data-sources";
import type { ComposedNodeSchema } from "../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../renderer/widget-tree/composed-view-tree";
import parityFixture from "../renderer/widget-tree/composed-view-parity.json";
import type { WidgetRenderReportEntry } from "../renderer/render-report/widget-render-report";
import { ComposedViewRenderer } from "./ComposedViewRenderer";
import {
  buildComposedViewComponentRegistry,
  ComposedViewComponentsContext,
  composedViewComponents,
} from "./composed-view-components";
import type { ComposedViewComponentProps } from "./composed-view-component-types";

/**
 * The renderer with two stub components (the kit's real components are tested with their own specs): values
 * flow from state and computed expressions into the nodes, an input event changes state and every bound node follows,
 * a node that throws is contained in its slot and reported `failed`, a tree with dropped parts reports `failed` with the
 * finding code, a `$source` dataset is read through the widget hydration (pending, then its rows), and the folder registry
 * registers the core `Widget` node.
 */

vi.mock("./components/embedding/EmbeddedConversationWidget", () => ({
  EmbeddedConversationWidget: ({ widgetName, fields }: { widgetName: string; fields: Record<string, unknown> }) => (
    <div data-testid="embedded-widget">{`${widgetName} ${JSON.stringify(fields)}`}</div>
  ),
}));

const stubSchema = parityFixture.stubSchema as unknown as ComposedNodeSchema;

const StubStack = ({ slots }: ComposedViewComponentProps) => (
  <div data-testid="stack">
    {slots.header}
    {slots.children}
  </div>
);
const StubText = ({ props, node, runtime, sizeClass }: ComposedViewComponentProps) => (
  <button type="button" data-size-class={sizeClass} onClick={() => runtime.runEvent(node.props.onClick, "30d")}>
    {props.text === undefined ? "∅" : String(props.text)}
  </button>
);
const StubMap = (): React.ReactElement => {
  throw new Error("stub map crash");
};
const StubGrid = ({ slots }: ComposedViewComponentProps) => <div data-testid="grid">{slots.children}</div>;
const StubRepeat = ({ props, isPending, renderSlotForItem }: ComposedViewComponentProps) => (
  <div data-testid="repeat" data-pending={String(isPending)}>
    {(Array.isArray(props.source) ? props.source : []).flatMap((item, itemIndex) =>
      renderSlotForItem("children", item, itemIndex),
    )}
  </div>
);

const stubComponents = buildComposedViewComponentRegistry([
  { nodeType: "StubStack", component: StubStack },
  { nodeType: "StubText", component: StubText },
  { nodeType: "StubMap", component: StubMap },
  { nodeType: "StubRepeat", component: StubRepeat },
]);

const renderTree = (widgetFields: Record<string, unknown>, schema: ComposedNodeSchema = stubSchema) => {
  const reports: WidgetRenderReportEntry[] = [];
  const tree = readComposedViewTree(widgetFields, schema);
  const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
  render(
    <Provider store={store}>
      <ComposedViewComponentsContext.Provider value={stubComponents}>
        <ComposedViewRenderer
          tree={tree}
          interactive
          isEmailRender={false}
          agentSpaceKey={7}
          onReport={(entry) => reports.push(entry)}
        />
      </ComposedViewComponentsContext.Provider>
    </Provider>,
  );
  return reports;
};

describe("ComposedViewRenderer — the kit tree, rendered", () => {
  test("binds state, data and computed values and re-computes after an input event", () => {
    const reports = renderTree({
      state: { period: "7d" },
      data: { rows: [{ v: 2 }, { v: 3 }] },
      computed: { total: "sum(rows, r => r.v)", label: 'period == "7d" ? "week" : "month"' },
      view: {
        type: "StubStack",
        slots: { header: [{ type: "StubText", text: { $computed: "label" }, onClick: { set: "period" } }] },
        children: [
          { type: "StubText", text: { $computed: "total" } },
          { type: "StubText", text: { $state: "period" } },
        ],
      },
    });
    expect(screen.getByText("week")).toBeTruthy();
    expect(screen.getByText("5")).toBeTruthy();
    act(() => {
      fireEvent.click(screen.getByText("week"));
    });
    expect(screen.getByText("month")).toBeTruthy();
    expect(screen.getByText("30d")).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("contains a throwing node in its slot and reports it failed; the siblings render", () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    const reports = renderTree({
      view: { type: "StubStack", children: [{ type: "StubMap" }, { type: "StubText", text: "still here" }] },
    });
    expect(screen.getByText("still here")).toBeTruthy();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "RENDER_FAILED" });
  });

  test("reports a tree with dropped parts as failed with the first finding code", () => {
    const reports = renderTree({
      view: { type: "StubStack", children: [{ type: "NoSuchNode" }, { type: "StubText", text: "ok" }] },
    });
    expect(screen.getByText("ok")).toBeTruthy();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_NODE" });
  });

  test("reports a computed value that does not compile and renders it empty", () => {
    const reports = renderTree({
      computed: { broken: "1 +" },
      view: { type: "StubText", text: { $computed: "broken" } },
    });
    expect(screen.getByText("∅")).toBeTruthy();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_EXPRESSION" });
  });

  test("a $from binding without its stored snapshot is pending", () => {
    const reports = renderTree({
      data: { live: { $from: { toolCall: "call_1", path: "$.rows" } } },
      view: { type: "StubText", text: "x" },
    });
    expect(reports.at(-1)).toEqual({ status: "pending" });
  });

  test("a prop bound with $data to a pending dataset marks its node pending, like a direct $from", () => {
    renderTree({
      data: { live: { $from: { toolCall: "call_1", path: "$.rows" } } },
      view: { type: "StubRepeat", source: { $data: "live" }, children: [{ type: "StubText", text: "row" }] },
    });
    expect(screen.getByTestId("repeat").dataset.pending).toBe("true");
  });

  test("a prop bound with $data to a resolved dataset is not pending", () => {
    renderTree({
      data: { live: { $from: { toolCall: "call_1", path: "$.rows", state: "resolved", value: [{ n: 1 }] } } },
      view: { type: "StubRepeat", source: { $data: "live" }, children: [{ type: "StubText", text: "row" }] },
    });
    expect(screen.getByTestId("repeat").dataset.pending).toBe("false");
    expect(screen.getAllByText("row")).toHaveLength(1);
  });

  test("a $source dataset is pending until the hydration delivers its rows", async () => {
    const fetchResponse = vi.fn(async () => ({ rankings: [{ position: 4 }, { position: 6 }] }));
    registerComposedViewDataSourceFetcher({ name: "rankings", fetchResponse }, stubSchema);
    const reports = renderTree({
      data: { rows: { $source: { name: "rankings", params: { days: 30 } } } },
      computed: { best: "min(rows, r => r.position)" },
      view: { type: "StubText", text: { $computed: "best" } },
    });
    expect(reports[0]).toEqual({ status: "pending" });
    await waitFor(() => expect(screen.getByText("4")).toBeTruthy());
    expect(fetchResponse).toHaveBeenCalledWith({ days: 30 }, 7, expect.anything());
    await waitFor(() => expect(reports.at(-1)).toEqual({ status: "mounted" }));
  });

  test("a $source without a registered fetcher fails as an unresolved binding", async () => {
    const schemaWithUnfetchedSource = {
      ...stubSchema,
      dataSources: { ...stubSchema.dataSources, unfetched: { ...stubSchema.dataSources?.rankings } },
    } as ComposedNodeSchema;
    const reports = renderTree(
      { data: { rows: { $source: { name: "unfetched" } } }, view: { type: "StubText", text: "x" } },
      schemaWithUnfetchedSource,
    );
    await waitFor(() => expect(reports.at(-1)).toMatchObject({ status: "failed", code: "UNRESOLVED_BINDING" }));
  });

  test("a repeating node renders at most maxRepeatRows items and reports the cut", () => {
    const rowCount = composedNodeSchema.budgets.maxRepeatRows + 25;
    const reports = renderTree({
      data: { rows: Array.from({ length: rowCount }, (unused, rowIndex) => ({ n: rowIndex })) },
      view: { type: "StubRepeat", source: { $data: "rows" }, children: [{ type: "StubText", text: "row" }] },
    });
    expect(screen.getAllByText("row")).toHaveLength(composedNodeSchema.budgets.maxRepeatRows);
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "BUDGET_EXCEEDED" });
  });

  test("past maxRenderedNodes nothing more renders and the block reports it", () => {
    const templateNodeCount = Math.ceil(
      composedNodeSchema.budgets.maxRenderedNodes / composedNodeSchema.budgets.maxRepeatRows,
    );
    const reports = renderTree({
      data: { rows: Array.from({ length: composedNodeSchema.budgets.maxRepeatRows }, () => 1) },
      view: {
        type: "StubRepeat",
        source: { $data: "rows" },
        children: Array.from({ length: templateNodeCount + 1 }, () => ({ type: "StubText", text: "cell" })),
      },
    });
    expect(screen.getAllByText("cell").length).toBeLessThan(composedNodeSchema.budgets.maxRenderedNodes);
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "BUDGET_EXCEEDED" });
  });

  test("a new tree under the same instance starts from its own state", () => {
    const firstTree = readComposedViewTree(
      { state: { period: "7d" }, view: { type: "StubText", text: "x" } },
      stubSchema,
    );
    const secondTree = readComposedViewTree(
      { state: { period: "1y" }, view: { type: "StubText", text: "x" } },
      stubSchema,
    );
    const { result, rerender } = renderHook(({ tree }) => useComposedViewState(tree, composedNodeSchema.budgets), {
      initialProps: { tree: firstTree },
    });
    act(() => result.current.setStateValue("period", "30d"));
    expect(result.current.state.period).toBe("30d");
    rerender({ tree: secondTree });
    expect(result.current.state.period).toBe("1y");
  });

  test("a Grid cell gets the size class of its column count: 1 full, 2 regular, 3 compact", () => {
    const tree = readComposedViewTree(
      {
        view: {
          type: "Stack",
          children: [
            { type: "Grid", columns: 1, children: [{ type: "Text", text: "one" }] },
            { type: "Grid", columns: 2, children: [{ type: "Text", text: "two" }] },
            { type: "Grid", columns: 3, children: [{ type: "Text", text: "three" }] },
          ],
        },
      },
      getComposedNodeSchema(),
    );
    const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
    render(
      <Provider store={store}>
        <ComposedViewComponentsContext.Provider
          value={buildComposedViewComponentRegistry([
            { nodeType: "Stack", component: StubStack },
            { nodeType: "Grid", component: StubGrid },
            { nodeType: "Text", component: StubText },
          ])}
        >
          <ComposedViewRenderer tree={tree} interactive isEmailRender={false} onReport={() => {}} />
        </ComposedViewComponentsContext.Provider>
      </Provider>,
    );
    expect(screen.getByText("one").dataset.sizeClass).toBe("full");
    expect(screen.getByText("two").dataset.sizeClass).toBe("regular");
    expect(screen.getByText("three").dataset.sizeClass).toBe("compact");
  });

  test("a node's failure reaches the block report with the node's path (an embedded widget's cell)", () => {
    const FailingCell = ({ runtime }: ComposedViewComponentProps) => {
      React.useEffect(() => runtime.reportNodeStatus("failed", "HYDRATION_FAILED", "StubCompact: read failed"));
      return null;
    };
    const reports: WidgetRenderReportEntry[] = [];
    const tree = readComposedViewTree(
      {
        view: {
          type: "StubStack",
          children: [
            { type: "StubText", text: "ok" },
            { type: "StubText", text: "x" },
          ],
        },
      },
      stubSchema,
    );
    const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
    render(
      <Provider store={store}>
        <ComposedViewComponentsContext.Provider
          value={buildComposedViewComponentRegistry([
            { nodeType: "StubStack", component: StubStack },
            { nodeType: "StubText", component: FailingCell },
          ])}
        >
          <ComposedViewRenderer
            tree={tree}
            interactive
            isEmailRender={false}
            onReport={(entry) => reports.push(entry)}
          />
        </ComposedViewComponentsContext.Provider>
      </Provider>,
    );
    expect(reports.at(-1)).toEqual({
      status: "failed",
      code: "HYDRATION_FAILED",
      detail: "view.children[0]: StubCompact: read failed",
    });
  });

  test("a lifted widget is shown after the view and the block mounts with the WIDGET_LIFTED note", () => {
    const reports = renderTree({
      title: "Dashboard",
      view: {
        type: "StubStack",
        children: [
          { type: "StubText", text: "Overview" },
          { type: "Widget", widget: "StubStandalone", fields: { [getWidgetSelfHydrationKeyFieldName()]: 7 } },
        ],
      },
    });
    expect(screen.getByText("Overview")).toBeTruthy();
    expect(screen.getByTestId("embedded-widget").textContent).toBe(`StubStandalone ${JSON.stringify({ [getWidgetSelfHydrationKeyFieldName()]: 7 })}`);
    expect(reports.at(-1)).toMatchObject({ status: "mounted", code: "WIDGET_LIFTED" });
  });

  test("a view the lift took entirely shows only its widgets — never a title over an empty layout", () => {
    const reports = renderTree({
      title: "Widgets in widgets",
      view: { type: "StubStack", children: [{ type: "Widget", widget: "StubStandalone" }] },
    });
    expect(screen.queryByText("Widgets in widgets")).toBeNull();
    expect(screen.getByTestId("embedded-widget").textContent).toBe("StubStandalone {}");
    expect(reports.at(-1)).toMatchObject({ status: "mounted", code: "WIDGET_LIFTED" });
  });

  test("a view with nothing to show renders one explicit line instead of an empty block", () => {
    renderTree(
      { title: "Empty", view: { type: "Grid", columns: 2, children: [{ type: "NoSuchNode" }] } },
      getComposedNodeSchema(),
    );
    expect(screen.getByText("Empty")).toBeTruthy();
    expect(screen.getByText("This view has nothing to show right now.")).toBeTruthy();
  });

  test("the folder registry registers the core Widget node", () => {
    expect(Object.keys(composedViewComponents)).toContain("Widget");
  });

  test("every node type of the package's schema has a registered component", () => {
    const nodeTypesWithoutComponent = Object.keys(composedNodeSchema.nodeTypes).filter(
      (nodeType) => !Object.prototype.hasOwnProperty.call(composedViewComponents, nodeType),
    );
    expect(nodeTypesWithoutComponent).toEqual([]);
  });

  test("the one registered component without a node type of the package's schema is VideoCard, whose type an app declares", () => {
    const componentsWithoutPackageNodeType = Object.keys(composedViewComponents).filter(
      (nodeType) => !Object.prototype.hasOwnProperty.call(composedNodeSchema.nodeTypes, nodeType),
    );
    expect(componentsWithoutPackageNodeType).toEqual(["VideoCard"]);
  });
});
