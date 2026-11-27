import { getWidgetSelfHydrationKeyFieldName } from "../../../provider/environment";
import { configureStore } from "@reduxjs/toolkit";
import { render } from "@testing-library/react";
import { Provider } from "react-redux";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { readComposedViewTree, } from "../../../renderer/widget-tree/composed-view-tree";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";
/**
 * The entity components' spec helper: reads the block fields with the generated schema and renders
 * the tree through the real `ComposedViewRenderer`, collecting the block reports and the `openEntity` actions it runs.
 */
export const renderEntityTree = (widgetFields, options = {}) => {
    const { isEmailRender = false } = options;
    const reports = [];
    const openedActions = [];
    const openedRows = [];
    const tree = readComposedViewTree(widgetFields);
    const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
    render(<Provider store={store}>
      <ComposedViewRenderer tree={tree} interactive={!isEmailRender} isEmailRender={isEmailRender} agentSpaceKey={7} messageId={11} onReport={(entry) => reports.push(entry)} onOpenEntity={(action, currentRow) => {
            openedActions.push(action);
            openedRows.push(currentRow);
        }}/>
    </Provider>);
    return { reports, openedActions, openedRows, findingCodes: tree.findings.map((finding) => finding.code) };
};
/** A `$from` dataset as the backend stores it once resolved — each row with its own `entityRef` + `entity`. */
export const resolvedFromBinding = (rows) => ({
    $from: { toolCall: "call_7", path: "$.places[*]", state: "resolved", value: rows, rowCount: rows.length },
});
/** An `openEntity` action bound to the current row, as the backend stores it (`refState: "row"`, no entity of its own). */
export const ROW_BOUND_OPEN_ENTITY_ACTION = { type: "openEntity", ref: { $item: "entityRef" }, refState: "row" };
/** An `openEntity` action as the backend stores it after resolving the reference. */
export const resolvedOpenEntityAction = (ref, entityType, entityId) => ({
    type: "openEntity",
    ref,
    entity: { entityType, entityId, [getWidgetSelfHydrationKeyFieldName()]: 7 },
});
