import { getWidgetHydrator, listWidgetHydratorTypes, registerWidgetHydrator, unregisterWidgetHydrator, } from "../renderer/widget-hydration/widget-hydration.registry";
import { isPendingResponse, isStatusResponse } from "./widget-preview-requests";
/** The fixture's entry for one hydration: the exact derived key first, then the hydrator type (as the capture reads). */
const findFixtureHydration = (hydration, type, key) => {
    if (Object.prototype.hasOwnProperty.call(hydration, key))
        return { found: true, value: hydration[key], hydrationKey: key };
    if (Object.prototype.hasOwnProperty.call(hydration, type))
        return { found: true, value: hydration[type], hydrationKey: type };
    return { found: false, hydrationKey: key };
};
/** One fixture value as a hydrator result: a copy of the body, a failing `__status`, or a `__pending` that never ends. */
const answerFromFixture = (value, hydrationKey, onFailedRequest) => {
    if (isPendingResponse(value))
        return new Promise(() => { });
    if (isStatusResponse(value)) {
        onFailedRequest(`HYDRATION ${hydrationKey}`);
        return Promise.reject(new Error(`preview hydration ${value.__status}`));
    }
    return Promise.resolve(structuredClone(value));
};
/**
 * The preview's hydration transport for the `interactive` and `replay` modes: every registered hydrator is replaced by
 * one that answers from the fixture's `hydration` (by derived key, else by type) and otherwise runs the real hydrator,
 * whose requests the request transport answers from `responses`. The widget therefore runs its live code path —
 * `useWidgetHydration` with the store's hydration slice, no frozen capture context — so a self-hydrating canvas widget
 * auto-opens its canvas like in the chat (`CanvasLauncherWidget`). The `email` mode keeps the capture's frozen hydration.
 * Returns the disposer that puts the real hydrators back.
 */
export const installWidgetPreviewHydration = (hydration, onFailedRequest) => {
    const replacedHydrators = new Map();
    for (const type of listWidgetHydratorTypes()) {
        const registered = getWidgetHydrator(type);
        if (!registered)
            continue;
        replacedHydrators.set(type, registered);
        unregisterWidgetHydrator(type);
        registerWidgetHydrator(type, {
            ...registered,
            hydrator: (payload, context) => {
                const fixtureHydration = findFixtureHydration(hydration, type, registered.deriveKey(type, payload));
                return fixtureHydration.found
                    ? answerFromFixture(fixtureHydration.value, fixtureHydration.hydrationKey, onFailedRequest)
                    : registered.hydrator(payload, context);
            },
        });
    }
    return () => {
        for (const [type, registered] of replacedHydrators) {
            unregisterWidgetHydrator(type);
            registerWidgetHydrator(type, registered);
        }
    };
};
