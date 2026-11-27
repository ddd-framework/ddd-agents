import { useSyncExternalStore } from "react";
import { WidgetHydrationReducer } from "./widget-hydration.reducer";
/**
 * The package's own state store (widget hydration is one store per app): the widget hydration reducer
 * behind `useSyncExternalStore`, no Redux. Package code reads and dispatches through it whenever the app supplies no
 * `AgentsEnvironment.stateStore` (the host application supplies its Redux store, which mounts the same reducer). Actions the
 * reducer does not know (the app's "a chat surface is open" action) change nothing.
 */
export class AgentsWidgetHydrationStateContainer {
    state = {
        AIExtensionsWidgetHydration: WidgetHydrationReducer(undefined, { type: "agents/widgetHydrationStoreCreated" }),
    };
    listeners = new Set();
    getState = () => this.state;
    subscribe = (listener) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };
    dispatch = (action) => {
        const nextHydrationState = WidgetHydrationReducer(this.state.AIExtensionsWidgetHydration, action);
        if (nextHydrationState === this.state.AIExtensionsWidgetHydration)
            return action;
        this.state = { AIExtensionsWidgetHydration: nextHydrationState };
        for (const notifyListener of [...this.listeners])
            notifyListener();
        return action;
    };
    /** `AgentsStateStore.useSelector`: one value of the state, re-rendering when it changes. */
    useSelector = (selector) => {
        const readSelectedValue = () => selector(this.state);
        return useSyncExternalStore(this.subscribe, readSelectedValue, readSelectedValue);
    };
    /** `AgentsStateStore.useDispatch`. */
    useDispatch = () => this.dispatch;
}
/** A new widget hydration store (a test, a second app on one page). */
export const createAgentsWidgetHydrationStateStore = () => new AgentsWidgetHydrationStateContainer();
let defaultStateStore = null;
/** The one default store of the page, created on first use. */
export const getDefaultAgentsStateStore = () => {
    defaultStateStore ??= createAgentsWidgetHydrationStateStore();
    return defaultStateStore;
};
