import { useSyncExternalStore } from "react";
/**
 * A tiny shared channel between a canvas BODY and the inline widget that opened it: the body publishes
 * "this entity changed" / "this entity was removed", the inline list applies or refetches. Stands in for callbacks
 * captured into a rendered canvas node: a body mounted from the canvas model has no closure into the
 * widget instance, and the widget instance may have been windowed out meanwhile. Module state with
 * `useSyncExternalStore`.
 */
export const createEntityUpdateChannel = () => {
    let latest = null;
    const listeners = new Set();
    const subscribe = (listener) => {
        listeners.add(listener);
        return () => {
            listeners.delete(listener);
        };
    };
    const read = () => latest;
    return {
        publish: (update) => {
            latest = { revision: (latest?.revision ?? 0) + 1, update };
            listeners.forEach((listener) => listener());
        },
        /** The latest publication (null before the first). Re-renders the consumer on every publication. */
        useLatestUpdate: () => useSyncExternalStore(subscribe, read, read),
    };
};
