import { render } from "@testing-library/react";
import { Provider } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import { WidgetHydrationReducer } from "../../../../renderer/widget-hydration";
import { composedNodeSchema, } from "../../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../../renderer/widget-tree/composed-view-tree";
import { ComposedViewRenderer } from "../../../ComposedViewRenderer";
import { buildComposedViewComponentRegistry, ComposedViewComponentsContext, composedViewComponents, } from "../../../composed-view-components";
/**
 * The spec tree of the input components: the real generated schema and the real
 * folder-registered input components, plus two TEST-ONLY node types — `InputSpecStack` (a column for several inputs,
 * the layout `Stack` does not belong to the inputs) and `InputSpecProbe` (prints one bound value, so a spec sees the tree
 * state an input wrote). Used by the `_<Type>.spec.tsx` files of this folder only.
 */
const InputSpecStack = (props) => {
    const { slots } = props;
    return <div data-testid="input-spec-stack">{slots.children}</div>;
};
const InputSpecProbe = (props) => {
    const { props: nodeProps } = props;
    const shownValue = nodeProps.text === undefined ? "∅" : JSON.stringify(nodeProps.text);
    return <output data-testid="input-spec-probe">{shownValue}</output>;
};
const inputSpecSchema = {
    ...composedNodeSchema,
    nodeTypes: {
        ...composedNodeSchema.nodeTypes,
        InputSpecStack: {
            categories: ["layout"],
            providesItemScope: false,
            summary: "test-only column",
            props: [],
            slots: [
                {
                    name: "children",
                    acceptsCategories: ["inline", "block", "data", "media", "input", "layout"],
                    acceptsNodeTypes: [],
                    minChildren: 0,
                    maxChildren: null,
                    sizeClass: "regular",
                    description: "",
                },
            ],
        },
        InputSpecProbe: {
            categories: ["inline"],
            providesItemScope: false,
            summary: "test-only value probe",
            props: [
                {
                    name: "text",
                    types: ["string", "number", "boolean"],
                    isRequired: false,
                    isBindable: true,
                    allowedValues: null,
                    description: "",
                },
            ],
            slots: [],
        },
    },
};
const inputSpecComponents = buildComposedViewComponentRegistry([
    ...Object.values(composedViewComponents).filter((definition) => definition.nodeType !== "InputSpecStack" && definition.nodeType !== "InputSpecProbe"),
    { nodeType: "InputSpecStack", component: InputSpecStack },
    { nodeType: "InputSpecProbe", component: InputSpecProbe },
]);
/** Reads `widgetFields` with the spec schema and renders them through the real renderer. */
export const renderInputSpecTree = (widgetFields, options = {}) => {
    const { interactive = true, isEmailRender = false } = options;
    const reports = [];
    const tree = readComposedViewTree(widgetFields, inputSpecSchema);
    const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
    render(<Provider store={store}>
      <ComposedViewComponentsContext.Provider value={inputSpecComponents}>
        <ComposedViewRenderer tree={tree} interactive={interactive} isEmailRender={isEmailRender} agentSpaceKey={7} onReport={(entry) => reports.push(entry)}/>
      </ComposedViewComponentsContext.Provider>
    </Provider>);
    return { reports, findingCodes: tree.findings.map((finding) => finding.code) };
};
/** A probe node that prints the state value `stateName`. */
export const stateProbe = (stateName) => ({ type: "InputSpecProbe", text: { $state: stateName } });
