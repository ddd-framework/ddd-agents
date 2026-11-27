import { getComposedViewSubtreeNodes, readComposedViewBinding, } from "../renderer/widget-tree/composed-view-tree";
/** The moment of a resolved `$from` snapshot, or undefined (no `resolvedAt`, or not a readable ISO moment). */
const readSnapshotResolvedAtMs = (fromBindingObject) => {
    if (typeof fromBindingObject !== "object" || fromBindingObject === null || Array.isArray(fromBindingObject)) {
        return undefined;
    }
    const snapshot = fromBindingObject;
    if (snapshot.state !== "resolved" || typeof snapshot.resolvedAt !== "string")
        return undefined;
    const resolvedAtMs = Date.parse(snapshot.resolvedAt);
    return Number.isFinite(resolvedAtMs) ? resolvedAtMs : undefined;
};
const collectDataTimesFromValue = (value, collection) => {
    const binding = readComposedViewBinding(value);
    if (binding !== null) {
        if (binding.kind === "from") {
            collection.hasTurnTimeSnapshot = true;
            const resolvedAtMs = readSnapshotResolvedAtMs(binding.wireValue.$from);
            if (resolvedAtMs !== undefined) {
                collection.newestResolvedAtMs = Math.max(collection.newestResolvedAtMs ?? resolvedAtMs, resolvedAtMs);
            }
        }
        if (binding.kind === "source")
            collection.hasMailTimeRead = true;
        return;
    }
    if (Array.isArray(value))
        value.forEach((element) => collectDataTimesFromValue(element, collection));
    else if (typeof value === "object" && value !== null) {
        Object.values(value).forEach((nested) => collectDataTimesFromValue(nested, collection));
    }
};
/** The data paths with a time of a checked tree: its datasets and every node's props, embedded widgets included. */
export const readComposedViewDataTimes = (tree) => {
    const collection = {
        hasTurnTimeSnapshot: false,
        newestResolvedAtMs: undefined,
        hasMailTimeRead: false,
    };
    collectDataTimesFromValue(tree.data, collection);
    const nodes = tree.view ? getComposedViewSubtreeNodes(tree.view) : [];
    for (const node of nodes) {
        if (node.type === "Widget")
            collection.hasMailTimeRead = true;
        collectDataTimesFromValue(node.props, collection);
    }
    return {
        hasTurnTimeSnapshot: collection.hasTurnTimeSnapshot,
        turnTimeResolvedAt: collection.newestResolvedAtMs === undefined ? undefined : new Date(collection.newestResolvedAtMs).toISOString(),
        hasMailTimeRead: collection.hasMailTimeRead,
    };
};
