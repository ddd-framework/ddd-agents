import type { AgentsStateAction } from "../../provider/environment";
import { WidgetHydrationSliceState, WidgetMemory } from "./widget-hydration.types";

/**
 * The widget hydration state: one memory per hydration key. A plain reducer under the key
 * `AIExtensionsWidgetHydration` of a state store: the app's own (`AgentsEnvironment.stateStore`, the host application mounts it in
 * its Redux store) or, without one, the package's default store (`widget-hydration-state-store.ts`).
 */

const SLICE_NAME = "widgetHydrationSlice";

const emptyMemory = (): WidgetMemory => {
  return {
    data: null,
    status: "idle",
    error: null,
    loadingInstanceId: null,
    inFlight: false,
    requestId: 0,
    lastLoadedAt: null,
  };
};

const initialState: WidgetHydrationSliceState = {
  widgets: {},
};

const readMemory = (state: WidgetHydrationSliceState, key: string): WidgetMemory => state.widgets[key] ?? emptyMemory();

const withMemory = (state: WidgetHydrationSliceState, key: string, memory: WidgetMemory): WidgetHydrationSliceState => ({
  ...state,
  widgets: { ...state.widgets, [key]: memory },
});

/** Does a result still belong to the request the memory tracks? (0 = the memory was reset since.) */
const isCurrentRequest = (memory: WidgetMemory, requestId: number): boolean =>
  memory.requestId === 0 || memory.requestId === requestId;

interface MemoryEnsuredPayload {
  key: string;
}
interface InstanceAttachedPayload {
  key: string;
  instanceId: string;
}
interface HydrationStartedPayload {
  key: string;
  instanceId: string;
  requestId: number;
}
interface HydrationSucceededPayload {
  key: string;
  requestId: number;
  data: unknown;
}
interface HydrationFailedPayload {
  key: string;
  requestId: number;
  error: string;
}
interface WidgetResetPayload {
  key: string;
}

const actionType = (name: string): string => `${SLICE_NAME}/${name}`;

/** The action creators (the action types are the ones the former Redux Toolkit slice used). */
export const WidgetHydrationSlice = {
  memoryEnsured: (payload: MemoryEnsuredPayload): AgentsStateAction => ({ type: actionType("memoryEnsured"), payload }),
  instanceAttached: (payload: InstanceAttachedPayload): AgentsStateAction => ({
    type: actionType("instanceAttached"),
    payload,
  }),
  hydrationStarted: (payload: HydrationStartedPayload): AgentsStateAction => ({
    type: actionType("hydrationStarted"),
    payload,
  }),
  hydrationSucceeded: (payload: HydrationSucceededPayload): AgentsStateAction => ({
    type: actionType("hydrationSucceeded"),
    payload,
  }),
  hydrationFailed: (payload: HydrationFailedPayload): AgentsStateAction => ({
    type: actionType("hydrationFailed"),
    payload,
  }),
  widgetReset: (payload: WidgetResetPayload): AgentsStateAction => ({ type: actionType("widgetReset"), payload }),
  allWidgetsReset: (): AgentsStateAction => ({ type: actionType("allWidgetsReset") }),
};

export const WidgetHydrationReducer = (
  state: WidgetHydrationSliceState = initialState,
  action: AgentsStateAction,
): WidgetHydrationSliceState => {
  switch (action.type) {
    case actionType("memoryEnsured"): {
      const { key } = action.payload as MemoryEnsuredPayload;
      return state.widgets[key] ? state : withMemory(state, key, emptyMemory());
    }
    case actionType("instanceAttached"): {
      const { key, instanceId } = action.payload as InstanceAttachedPayload;
      return withMemory(state, key, { ...readMemory(state, key), loadingInstanceId: instanceId });
    }
    case actionType("hydrationStarted"): {
      const { key, instanceId, requestId } = action.payload as HydrationStartedPayload;
      return withMemory(state, key, {
        ...readMemory(state, key),
        status: "loading",
        error: null,
        inFlight: true,
        loadingInstanceId: instanceId,
        requestId,
      });
    }
    case actionType("hydrationSucceeded"): {
      const { key, requestId, data } = action.payload as HydrationSucceededPayload;
      const memory = readMemory(state, key);
      if (!isCurrentRequest(memory, requestId)) return state.widgets[key] ? state : withMemory(state, key, memory);
      return withMemory(state, key, {
        ...memory,
        status: "success",
        error: null,
        inFlight: false,
        data,
        loadingInstanceId: null,
        requestId,
        lastLoadedAt: Date.now(),
      });
    }
    case actionType("hydrationFailed"): {
      const { key, requestId, error } = action.payload as HydrationFailedPayload;
      const memory = readMemory(state, key);
      if (!isCurrentRequest(memory, requestId)) return state.widgets[key] ? state : withMemory(state, key, memory);
      return withMemory(state, key, {
        ...memory,
        status: "error",
        error,
        inFlight: false,
        loadingInstanceId: null,
        requestId,
      });
    }
    case actionType("widgetReset"): {
      const { key } = action.payload as WidgetResetPayload;
      if (!(key in state.widgets)) return state;
      const { [key]: removedMemory, ...remainingWidgets } = state.widgets;
      return { ...state, widgets: remainingWidgets };
    }
    case actionType("allWidgetsReset"):
      return { ...state, widgets: {} };
    default:
      return state;
  }
};
