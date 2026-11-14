import { render } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import type { WidgetRenderReportEntry } from "../../../renderer/render-report/widget-render-report";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";

/**
 * The data specs' one way to render a tree: read with the generated schema, rendered through
 * the real `ComposedViewRenderer` in a store with the hydration slice, every block report collected. `findings` are
 * the reader's — a spec asserts them empty so a tree that only renders because the reader dropped part of it fails.
 */
export const renderDataTree = (widgetFields: Record<string, unknown>, options: { isEmailRender?: boolean } = {}) => {
  const { isEmailRender = false } = options;
  const reports: WidgetRenderReportEntry[] = [];
  const tree = readComposedViewTree(widgetFields, getComposedNodeSchema());
  const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
  const rendered = render(
    <Provider store={store}>
      <ComposedViewRenderer
        tree={tree}
        interactive={!isEmailRender}
        isEmailRender={isEmailRender}
        agentSpaceKey={7}
        onReport={(entry) => reports.push(entry)}
      />
    </Provider>,
  );
  return { ...rendered, reports, findings: tree.findings, lastReport: () => reports[reports.length - 1] };
};
