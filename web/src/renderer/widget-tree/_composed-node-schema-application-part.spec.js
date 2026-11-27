import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { addComposedNodeSchemaApplicationPart, composedNodeSchema, getComposedNodeSchema } from "./composed-node-schema";
const exampleApplicationPart = {
    nodeTypes: { ExampleCard: { ...composedNodeSchema.nodeTypes.Text } },
    embeddableWidgets: { AExampleTile: { sizeClass: "compact" } },
    widgetTypes: ["AExampleTile", "ExampleStandalone"],
    entityTypes: ["exampleRecord"],
};
describe("composed-node-schema — the app's part", () => {
    let environmentBefore = getAgentsEnvironment();
    beforeEach(() => {
        environmentBefore = getAgentsEnvironment();
    });
    afterEach(() => setAgentsEnvironment(environmentBefore));
    it("adds every section of the app's part beside the package's types, sorted by type name as the backend sorts", () => {
        const schema = addComposedNodeSchemaApplicationPart(composedNodeSchema, exampleApplicationPart);
        expect(schema.budgets).toBe(composedNodeSchema.budgets);
        expect(Object.keys(schema.nodeTypes)).toEqual([...Object.keys(composedNodeSchema.nodeTypes), "ExampleCard"].sort((first, second) => first < second ? -1 : first > second ? 1 : 0));
        expect(Object.keys(schema.embeddableWidgets)[0]).toBe("AExampleTile");
        expect(schema.embeddableWidgets.CustomVisualization).toEqual(composedNodeSchema.embeddableWidgets.CustomVisualization);
        expect(schema.widgetTypes).toContain("ComposedView");
        expect(schema.widgetTypes).toContain("ExampleStandalone");
        expect(schema.widgetTypes?.[0]).toBe("AExampleTile");
        expect(schema.entityTypes).toEqual([...composedNodeSchema.entityTypes, "exampleRecord"].sort());
    });
    it("drops the package's embeddable entry of a widget the app replaces and keeps the app's own entry", () => {
        const replacedWidgetName = "CustomVisualization";
        expect(composedNodeSchema.embeddableWidgets[replacedWidgetName]).toBeDefined();
        const withoutOwnEntry = addComposedNodeSchemaApplicationPart(composedNodeSchema, {
            replacedPackageWidgets: [replacedWidgetName],
            widgetTypes: [replacedWidgetName],
        });
        expect(withoutOwnEntry.embeddableWidgets[replacedWidgetName]).toBeUndefined();
        expect(withoutOwnEntry.widgetTypes).toContain(replacedWidgetName);
        const withOwnEntry = addComposedNodeSchemaApplicationPart(composedNodeSchema, {
            replacedPackageWidgets: [replacedWidgetName],
            embeddableWidgets: { [replacedWidgetName]: { sizeClass: "compact" } },
        });
        expect(withOwnEntry.embeddableWidgets[replacedWidgetName]).toEqual({ sizeClass: "compact" });
    });
    it("reads the part the app declares at call time, the same object while the part is the same", () => {
        setAgentsEnvironment({});
        expect(getComposedNodeSchema()).toBe(composedNodeSchema);
        setAgentsEnvironment({ composedNodeSchemaApplicationPart: exampleApplicationPart });
        const schemaWithPart = getComposedNodeSchema();
        expect(schemaWithPart.nodeTypes.ExampleCard).toBeDefined();
        expect(getComposedNodeSchema()).toBe(schemaWithPart);
    });
});
