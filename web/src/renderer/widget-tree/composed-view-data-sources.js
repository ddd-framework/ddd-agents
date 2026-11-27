import { registerWidgetHydrator } from "../widget-hydration";
import { getComposedNodeSchema } from "./composed-node-schema";
import { getComposedViewSubtreeNodes, readComposedViewBinding, } from "./composed-view-tree";
import { composedViewSourceBindingKey } from "./resolve-composed-view-values";
/** The hydration type a source registers under. */
export const composedViewDataSourceHydrationType = (dataSourceName) => `ComposedViewDataSource:${dataSourceName}`;
/** The rows at a schema `rowsPath` (`$`, `$.field`, `$.a.b`) of a response; undefined when the path does not hold a list. */
export const readRowsAtPath = (response, rowsPath) => {
    let current = response;
    for (const segment of rowsPath
        .replace(/^\$\.?/, "")
        .split(".")
        .filter(Boolean)) {
        if (typeof current !== "object" || current === null || !Object.prototype.hasOwnProperty.call(current, segment)) {
            return undefined;
        }
        current = current[segment];
    }
    return Array.isArray(current) ? current : undefined;
};
const registeredFetcherNames = new Set();
/** Registers a fetcher as a widget hydrator; the rows land in the hydration memory like any widget's data. */
export const registerComposedViewDataSourceFetcher = (fetcher, schema = getComposedNodeSchema()) => {
    const dataSourceSchema = schema.dataSources?.[fetcher.name];
    registeredFetcherNames.add(fetcher.name);
    registerWidgetHydrator(composedViewDataSourceHydrationType(fetcher.name), {
        hydrator: async (payload, context) => {
            const response = await fetcher.fetchResponse(payload.params, payload.agentSpaceKey, context.signal);
            const rows = readRowsAtPath(response, dataSourceSchema?.rowsPath ?? "$");
            if (rows === undefined)
                throw new Error(`the data source "${fetcher.name}" returned no rows at ${dataSourceSchema?.rowsPath ?? "$"}`);
            return rows.slice(0, schema.budgets.maxCollectionItems);
        },
        deriveKey: (type, payload) => `${type}:${payload.agentSpaceKey ?? "active"}:${JSON.stringify(payload.params)}`,
        staleMs: (dataSourceSchema?.freshnessSeconds ?? 300) * 1000,
    });
};
/** Whether a fetcher is registered for a source name. */
export const hasComposedViewDataSourceFetcher = (dataSourceName) => registeredFetcherNames.has(dataSourceName);
const fetcherModules = import.meta.glob(["../../widgets/**/*.composed-data-source.ts", "../../kit/**/*.composed-data-source.ts"], { eager: true });
for (const fetcherModule of Object.values(fetcherModules)) {
    if (fetcherModule.composedViewDataSourceFetcher)
        registerComposedViewDataSourceFetcher(fetcherModule.composedViewDataSourceFetcher);
}
const collectFromValue = (value, sourceBindings) => {
    const binding = readComposedViewBinding(value);
    if (binding !== null) {
        const bindingKey = composedViewSourceBindingKey(value);
        if (bindingKey !== null && !sourceBindings.has(bindingKey)) {
            sourceBindings.set(bindingKey, {
                bindingKey,
                dataSourceName: binding.target,
                writtenParams: binding.sourceParams,
            });
        }
        return;
    }
    if (Array.isArray(value))
        value.forEach((element) => collectFromValue(element, sourceBindings));
    else if (typeof value === "object" && value !== null)
        Object.values(value).forEach((nested) => collectFromValue(nested, sourceBindings));
};
/** Every distinct `$source` binding of a tree — its datasets and every node's props — in tree order. */
export const collectComposedViewSourceBindings = (tree) => {
    const sourceBindings = new Map();
    collectFromValue(tree.data, sourceBindings);
    const nodes = tree.view ? getComposedViewSubtreeNodes(tree.view) : [];
    for (const node of nodes)
        collectFromValue(node.props, sourceBindings);
    return [...sourceBindings.values()];
};
/** The parameters with their `$state` bindings resolved against the instance state. */
export const resolveComposedViewSourceParams = (writtenParams, state) => Object.fromEntries(Object.entries(writtenParams).map(([paramName, paramValue]) => {
    const paramBinding = readComposedViewBinding(paramValue);
    if (paramBinding?.kind === "state") {
        return [
            paramName,
            Object.prototype.hasOwnProperty.call(state, paramBinding.target) ? state[paramBinding.target] : undefined,
        ];
    }
    return [paramName, paramValue];
}));
