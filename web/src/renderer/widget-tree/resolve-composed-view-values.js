import { readComposedViewBinding, readComposedViewRowField } from "./composed-view-tree";
/** The identity of a `$source` wire value — its name and parameters as written; null for another value. */
export const composedViewSourceBindingKey = (wireValue) => {
    const binding = readComposedViewBinding(wireValue);
    if (binding === null || binding.kind !== "source" || !binding.isWellFormed)
        return null;
    return `${binding.target}|${JSON.stringify(binding.sourceParams)}`;
};
const readSourceValue = (wireValue, sourceValuesByBindingKey) => {
    const bindingKey = composedViewSourceBindingKey(wireValue);
    if (bindingKey === null)
        return { status: "failed", value: undefined };
    return sourceValuesByBindingKey?.[bindingKey] ?? { status: "pending", value: undefined };
};
const hasOwn = (object, key) => Object.prototype.hasOwnProperty.call(object, key);
/** The value and status a `$from` binding's stored snapshot carries. */
export const readFromBindingSnapshot = (fromBindingObject) => {
    const snapshotState = fromBindingObject.state;
    if (snapshotState === "resolved")
        return { status: "resolved", value: fromBindingObject.value };
    if (snapshotState === "failed")
        return { status: "failed", value: undefined };
    return { status: "pending", value: undefined };
};
/**
 * The tree's datasets as values: inline rows as written, `$from` from its snapshot, `$source` from
 * `sourceValuesByBindingKey` (filled by the data-source hydration; pending without).
 */
export const resolveComposedViewDatasets = (data, sourceValuesByBindingKey = {}) => {
    const resolved = { datasets: {}, statusByDatasetName: {} };
    for (const [datasetName, dataset] of Object.entries(data)) {
        const binding = readComposedViewBinding(dataset);
        if (binding === null) {
            resolved.datasets[datasetName] = dataset;
            resolved.statusByDatasetName[datasetName] = "resolved";
            continue;
        }
        const snapshot = binding.kind === "from"
            ? readFromBindingSnapshot((binding.wireValue.$from ?? {}))
            : readSourceValue(dataset, sourceValuesByBindingKey);
        resolved.datasets[datasetName] = snapshot.value;
        resolved.statusByDatasetName[datasetName] = snapshot.status;
    }
    return resolved;
};
/** One prop value with its bindings resolved (nested bindings in inline data included). */
export const resolveComposedViewValue = (wireValue, scope, onBindingStatus) => {
    const binding = readComposedViewBinding(wireValue);
    if (binding !== null) {
        switch (binding.kind) {
            case "state":
                return hasOwn(scope.state, binding.target) ? scope.state[binding.target] : undefined;
            case "computed":
                return hasOwn(scope.computed, binding.target) ? scope.computed[binding.target] : undefined;
            case "data": {
                const datasetStatus = scope.datasetStatuses?.[binding.target];
                if (datasetStatus !== undefined)
                    onBindingStatus?.(datasetStatus);
                return hasOwn(scope.datasets, binding.target) ? scope.datasets[binding.target] : undefined;
            }
            case "item": {
                const rowField = readComposedViewRowField(scope.item, binding.target);
                return rowField.found ? rowField.value : undefined;
            }
            case "from": {
                const snapshot = readFromBindingSnapshot((binding.wireValue.$from ?? {}));
                onBindingStatus?.(snapshot.status);
                return snapshot.value;
            }
            case "source": {
                const sourceValue = readSourceValue(wireValue, scope.sources);
                onBindingStatus?.(sourceValue.status);
                return sourceValue.value;
            }
            default:
                onBindingStatus?.("pending");
                return undefined;
        }
    }
    if (Array.isArray(wireValue))
        return wireValue.map((element) => resolveComposedViewValue(element, scope, onBindingStatus));
    if (typeof wireValue === "object" && wireValue !== null) {
        return Object.fromEntries(Object.entries(wireValue).map(([key, nested]) => [key, resolveComposedViewValue(nested, scope, onBindingStatus)]));
    }
    return wireValue;
};
