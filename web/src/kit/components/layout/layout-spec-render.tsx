import { render } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import type { WidgetRenderReportEntry } from "../../../renderer/render-report/widget-render-report";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";

/**
 * Test helper of the layout specs: reads a `ComposedView` payload with
 * the generated schema and renders it through the real `ComposedViewRenderer` with the folder-registered components,
 * collecting every block report. Not a spec itself (no leading underscore).
 */
export const renderLayoutTree = (widgetFields: Record<string, unknown>, isEmailRender = false) => {
  const reports: WidgetRenderReportEntry[] = [];
  const tree = readComposedViewTree(widgetFields, getComposedNodeSchema());
  const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
  const renderResult = render(
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
  return { ...renderResult, reports, tree };
};
