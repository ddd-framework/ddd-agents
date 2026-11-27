/**
 * The widget hydration state: one memory per hydration key. A plain reducer under the key
 * `AIExtensionsWidgetHydration` of a state store: the app's own (`AgentsEnvironment.stateStore`, the host application mounts it in
 * its Redux store) or, without one, the package's default store (`widget-hydration-state-store.ts`).
 */
const SLICE_NAME = "widgetHydrationSlice";
const emptyMemory = () => {
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
const initialState = {
    widgets: {},
};
const readMemory = (state, key) => state.widgets[key] ?? emptyMemory();
const withMemory = (state, key, memory) => ({
    ...state,
    widgets: { ...state.widgets, [key]: memory },
});
/** Does a result still belong to the request the memory tracks? (0 = the memory was reset since.) */
const isCurrentRequest = (memory, requestId) => memory.requestId === 0 || memory.requestId === requestId;
const actionType = (name) => `${SLICE_NAME}/${name}`;
/** The action creators (the action types are the ones the former Redux Toolkit slice used). */
export const WidgetHydrationSlice = {
    memoryEnsured: (payload) => ({ type: actionType("memoryEnsured"), payload }),
    instanceAttached: (payload) => ({
        type: actionType("instanceAttached"),
        payload,
    }),
    hydrationStarted: (payload) => ({
        type: actionType("hydrationStarted"),
        payload,
    }),
    hydrationSucceeded: (payload) => ({
        type: actionType("hydrationSucceeded"),
        payload,
    }),
    hydrationFailed: (payload) => ({
        type: actionType("hydrationFailed"),
        payload,
    }),
    widgetReset: (payload) => ({ type: actionType("widgetReset"), payload }),
    allWidgetsReset: () => ({ type: actionType("allWidgetsReset") }),
};
export const WidgetHydrationReducer = (state = initialState, action) => {
    switch (action.type) {
        case actionType("memoryEnsured"): {
            const { key } = action.payload;
            return state.widgets[key] ? state : withMemory(state, key, emptyMemory());
        }
        case actionType("instanceAttached"): {
            const { key, instanceId } = action.payload;
            return withMemory(state, key, { ...readMemory(state, key), loadingInstanceId: instanceId });
        }
        case actionType("hydrationStarted"): {
            const { key, instanceId, requestId } = action.payload;
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
            const { key, requestId, data } = action.payload;
            const memory = readMemory(state, key);
            if (!isCurrentRequest(memory, requestId))
                return state.widgets[key] ? state : withMemory(state, key, memory);
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
            const { key, requestId, error } = action.payload;
            const memory = readMemory(state, key);
            if (!isCurrentRequest(memory, requestId))
                return state.widgets[key] ? state : withMemory(state, key, memory);
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
            const { key } = action.payload;
            if (!(key in state.widgets))
                return state;
            const { [key]: removedMemory, ...remainingWidgets } = state.widgets;
            return { ...state, widgets: remainingWidgets };
        }
        case actionType("allWidgetsReset"):
            return { ...state, widgets: {} };
        default:
            return state;
    }
};
