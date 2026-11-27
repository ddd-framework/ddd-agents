import { render } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";
/**
 * Renders a `ComposedView` tree through the real renderer and the folder registry, with
 * the generated node schema, and collects the block reports — shared by the media specs.
 */
export const renderMediaTree = (widgetFields, options = {}) => {
    const reports = [];
    const tree = readComposedViewTree(widgetFields, getComposedNodeSchema());
    const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
    const rendered = render(<Provider store={store}>
      <ComposedViewRenderer tree={tree} interactive={!options.isEmailRender} isEmailRender={options.isEmailRender ?? false} agentSpaceKey={7} onReport={(entry) => reports.push(entry)}/>
    </Provider>);
    return { ...rendered, reports, tree, lastReport: () => reports[reports.length - 1] };
};
