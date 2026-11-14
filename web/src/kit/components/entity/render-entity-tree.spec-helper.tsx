import { getWidgetSelfHydrationKeyFieldName } from "../../../provider/environment";
import { configureStore } from "@reduxjs/toolkit";
import { render } from "@testing-library/react";
import { Provider } from "react-redux";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import {
  type ComposedViewAction,
  readComposedViewTree,
} from "../../../renderer/widget-tree/composed-view-tree";
import type { WidgetRenderReportEntry } from "../../../renderer/render-report/widget-render-report";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";

export interface RenderedEntityTree {
  /** Every block report, in order — the last one is the block's status now. */
  reports: WidgetRenderReportEntry[];
  /** Every `openEntity` action the tree ran. */
  openedActions: ComposedViewAction[];
  /** The row each opened action fired in (undefined for an action with its own entity), parallel to `openedActions`. */
  openedRows: unknown[];
  findingCodes: string[];
}

/**
 * The entity components' spec helper: reads the block fields with the generated schema and renders
 * the tree through the real `ComposedViewRenderer`, collecting the block reports and the `openEntity` actions it runs.
 */
export const renderEntityTree = (
  widgetFields: Record<string, unknown>,
  options: { isEmailRender?: boolean } = {},
): RenderedEntityTree => {
  const { isEmailRender = false } = options;
  const reports: WidgetRenderReportEntry[] = [];
  const openedActions: ComposedViewAction[] = [];
  const openedRows: unknown[] = [];
  const tree = readComposedViewTree(widgetFields);
  const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
  render(
    <Provider store={store}>
      <ComposedViewRenderer
        tree={tree}
        interactive={!isEmailRender}
        isEmailRender={isEmailRender}
        agentSpaceKey={7}
        messageId={11}
        onReport={(entry) => reports.push(entry)}
        onOpenEntity={(action, currentRow) => {
          openedActions.push(action);
          openedRows.push(currentRow);
        }}
      />
    </Provider>,
  );
  return { reports, openedActions, openedRows, findingCodes: tree.findings.map((finding) => finding.code) };
};

/** A `$from` dataset as the backend stores it once resolved — each row with its own `entityRef` + `entity`. */
export const resolvedFromBinding = (rows: Array<Record<string, unknown>>) => ({
  $from: { toolCall: "call_7", path: "$.places[*]", state: "resolved", value: rows, rowCount: rows.length },
});

/** An `openEntity` action bound to the current row, as the backend stores it (`refState: "row"`, no entity of its own). */
export const ROW_BOUND_OPEN_ENTITY_ACTION = { type: "openEntity", ref: { $item: "entityRef" }, refState: "row" };

/** An `openEntity` action as the backend stores it after resolving the reference. */
export const resolvedOpenEntityAction = (ref: string, entityType: string, entityId: string) => ({
  type: "openEntity",
  ref,
  entity: { entityType, entityId, [getWidgetSelfHydrationKeyFieldName()]: 7 },
});
