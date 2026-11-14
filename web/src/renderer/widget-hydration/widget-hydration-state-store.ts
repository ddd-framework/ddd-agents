import { useSyncExternalStore } from "react";
import type { AgentsStateAction, AgentsStateStore } from "../../provider/environment";
import { WidgetHydrationReducer } from "./widget-hydration.reducer";
import type { WidgetHydrationSliceState } from "./widget-hydration.types";

/** The state of the package's default store: the widget hydration state under the key package code reads. */
export interface AgentsWidgetHydrationState {
  AIExtensionsWidgetHydration: WidgetHydrationSliceState;
}

/**
 * The package's own state store (widget hydration is one store per app): the widget hydration reducer
 * behind `useSyncExternalStore`, no Redux. Package code reads and dispatches through it whenever the app supplies no
 * `AgentsEnvironment.stateStore` (the host application supplies its Redux store, which mounts the same reducer). Actions the
 * reducer does not know (the app's "a chat surface is open" action) change nothing.
 */
export class AgentsWidgetHydrationStateContainer implements AgentsStateStore {
  protected state: AgentsWidgetHydrationState = {
    AIExtensionsWidgetHydration: WidgetHydrationReducer(undefined, { type: "agents/widgetHydrationStoreCreated" }),
  };
  protected readonly listeners = new Set<() => void>();

  getState = (): AgentsWidgetHydrationState => this.state;

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  };

  dispatch = (action: AgentsStateAction): AgentsStateAction => {
    const nextHydrationState = WidgetHydrationReducer(this.state.AIExtensionsWidgetHydration, action);
    if (nextHydrationState === this.state.AIExtensionsWidgetHydration) return action;
    this.state = { AIExtensionsWidgetHydration: nextHydrationState };
    for (const notifyListener of [...this.listeners]) notifyListener();
    return action;
  };

  /** `AgentsStateStore.useSelector`: one value of the state, re-rendering when it changes. */
  useSelector = <Selected,>(selector: (state: AgentsWidgetHydrationState) => Selected): Selected => {
    const readSelectedValue = () => selector(this.state);
    return useSyncExternalStore(this.subscribe, readSelectedValue, readSelectedValue);
  };

  /** `AgentsStateStore.useDispatch`. */
  useDispatch = (): ((action: AgentsStateAction) => AgentsStateAction) => this.dispatch;
}

/** A new widget hydration store (a test, a second app on one page). */
export const createAgentsWidgetHydrationStateStore = (): AgentsStateStore =>
  new AgentsWidgetHydrationStateContainer();

let defaultStateStore: AgentsStateStore | null = null;

/** The one default store of the page, created on first use. */
export const getDefaultAgentsStateStore = (): AgentsStateStore => {
  defaultStateStore ??= createAgentsWidgetHydrationStateStore();
  return defaultStateStore;
};
