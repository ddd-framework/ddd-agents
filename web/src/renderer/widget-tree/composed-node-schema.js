import { getAgentsEnvironment } from "../../provider/environment";
import generatedComposedNodeSchema from "./composed-node-schema.json";
/**
 * The package's committed schema, typed: its budgets and rules and the types the package itself declares. A reader of
 * types an app may add (node types, widgets, data sources, entity types) reads `getComposedNodeSchema()`.
 */
export const composedNodeSchema = generatedComposedNodeSchema;
/** Byte order of the type names, as the backend sorts them. */
const compareTypeNames = (first, second) => (first < second ? -1 : first > second ? 1 : 0);
const mergeSortedByTypeName = (packageEntries, applicationEntries) => Object.fromEntries(Object.entries({ ...packageEntries, ...applicationEntries }).sort(([firstType], [secondType]) => compareTypeNames(firstType, secondType)));
const withoutTypeNames = (entriesByTypeName, typeNames) => entriesByTypeName === undefined || !typeNames?.length
    ? entriesByTypeName
    : Object.fromEntries(Object.entries(entriesByTypeName).filter(([typeName]) => !typeNames.includes(typeName)));
const mergeTypeNames = (packageTypeNames, applicationTypeNames) => [...new Set([...(packageTypeNames ?? []), ...(applicationTypeNames ?? [])])].sort(compareTypeNames);
/** A schema with an app's part added: every section holds both sides' types, sorted by type name. */
export const addComposedNodeSchemaApplicationPart = (schema, applicationPart) => ({
    ...schema,
    nodeTypes: mergeSortedByTypeName(schema.nodeTypes, applicationPart.nodeTypes),
    embeddableWidgets: mergeSortedByTypeName(withoutTypeNames(schema.embeddableWidgets, applicationPart.replacedPackageWidgets), applicationPart.embeddableWidgets),
    widgetTypes: mergeTypeNames(schema.widgetTypes, applicationPart.widgetTypes),
    dataSources: mergeSortedByTypeName(schema.dataSources, applicationPart.dataSources),
    entityTypes: mergeTypeNames(schema.entityTypes, applicationPart.entityTypes),
});
let schemaWithApplicationPart;
/**
 * The schema the readers apply: the package's schema with the part the app declares
 * (`AgentsEnvironment.composedNodeSchemaApplicationPart`); the package's schema alone when the app declares none.
 * Read at call time — an app installs its environment after this module is loaded. The same object while the part is.
 */
export const getComposedNodeSchema = () => {
    const applicationPart = getAgentsEnvironment().composedNodeSchemaApplicationPart;
    if (!applicationPart)
        return composedNodeSchema;
    if (schemaWithApplicationPart?.applicationPart !== applicationPart) {
        schemaWithApplicationPart = {
            applicationPart,
            schema: addComposedNodeSchemaApplicationPart(composedNodeSchema, applicationPart),
        };
    }
    return schemaWithApplicationPart.schema;
};
/**
 * The slot of one node — `ComposedViewNodeSlot::buildForNodeProps()`: its size class read from the node's
 * `sizeClassFromProp` (a Grid with `columns: 3` gives compact cells); the declaration itself otherwise.
 */
export const resolveComposedSlotForNodeProps = (slot, nodeProps) => {
    if (!slot.sizeClassFromProp || !slot.sizeClassesByPropValue)
        return slot;
    const propValue = Object.prototype.hasOwnProperty.call(nodeProps, slot.sizeClassFromProp)
        ? nodeProps[slot.sizeClassFromProp]
        : undefined;
    if (typeof propValue !== "number" && typeof propValue !== "string")
        return slot;
    const propValueKey = String(propValue);
    const sizeClassOfPropValue = Object.prototype.hasOwnProperty.call(slot.sizeClassesByPropValue, propValueKey)
        ? slot.sizeClassesByPropValue[propValueKey]
        : undefined;
    return sizeClassOfPropValue === undefined || sizeClassOfPropValue === slot.sizeClass
        ? slot
        : { ...slot, sizeClass: sizeClassOfPropValue };
};
/** Whether a size class fits a slot's size class (compact ≤ regular ≤ full). */
export const sizeClassFitsSlot = (sizeClass, slotSizeClass) => {
    const order = ["compact", "regular", "full"];
    return order.indexOf(sizeClass) !== -1 && order.indexOf(sizeClass) <= order.indexOf(slotSizeClass);
};
