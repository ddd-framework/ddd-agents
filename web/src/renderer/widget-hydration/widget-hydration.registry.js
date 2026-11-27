const registry = new Map();
const defaultIsEqual = (prev, next) => {
    if (prev === next) {
        return true;
    }
    try {
        return JSON.stringify(prev) === JSON.stringify(next);
    }
    catch {
        return false;
    }
};
export const registerWidgetHydrator = (type, options) => {
    if (registry.has(type)) {
        console.warn(`[widget-hydration] Hydrator for "${type}" already registered. Overwriting.`);
    }
    registry.set(type, {
        hydrator: options.hydrator,
        deriveKey: options.deriveKey ??
            ((t) => {
                return t;
            }),
        isPayloadEqual: options.isPayloadEqual ?? defaultIsEqual,
        refetchOnNewInstance: options.refetchOnNewInstance ?? false,
        staleMs: options.staleMs ?? Number.POSITIVE_INFINITY,
    });
};
export const getWidgetHydrator = (type) => {
    return registry.get(type);
};
export const hasWidgetHydrator = (type) => {
    return registry.has(type);
};
/** Every registered hydrator type — the widget preview answers them from its fixture (`widget-preview-hydration.ts`). */
export const listWidgetHydratorTypes = () => [...registry.keys()];
export const unregisterWidgetHydrator = (type) => {
    registry.delete(type);
};
