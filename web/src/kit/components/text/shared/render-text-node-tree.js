import { configureStore } from "@reduxjs/toolkit";
import { render } from "@testing-library/react";
import { Provider } from "react-redux";
import { WidgetHydrationReducer } from "../../../../renderer/widget-hydration";
import { readComposedViewTree } from "../../../../renderer/widget-tree/composed-view-tree";
import { ComposedViewRenderer } from "../../../ComposedViewRenderer";
/**
 * The text components' spec helper: reads the block fields with the generated schema and renders the
 * tree through the real `ComposedViewRenderer` and the folder's component registry, collecting the block reports.
 */
export const renderTextNodeTree = (widgetFields, options = {}) => {
    const { isEmailRender = false } = options;
    const reports = [];
    const tree = readComposedViewTree(widgetFields);
    const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
    const { container } = render(<Provider store={store}>
      <ComposedViewRenderer tree={tree} interactive={!isEmailRender} isEmailRender={isEmailRender} agentSpaceKey={7} onReport={(entry) => reports.push(entry)}/>
    </Provider>);
    return { reports, container, findingCodes: tree.findings.map((finding) => finding.code) };
};
/**
 * A prop bound to a tool result whose call has not stored it yet (`$from` without its snapshot) — the node itself waits
 * (`isPending`), the loading state. A `$data` binding to a dataset whose `$from` is still pending waits the same way.
 */
export const PENDING_FROM_BINDING = { $from: { toolCall: "call_1", path: "$.value" } };
