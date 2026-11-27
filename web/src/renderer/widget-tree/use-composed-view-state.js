import { useCallback, useMemo, useReducer, useRef } from "react";
/** The values for `initialState`: the box's while it still belongs to that tree, else the tree's own initial state. */
const valuesOfTree = (stateBox, initialState) => stateBox.initialState === initialState ? stateBox.values : initialState;
const stateReducer = (stateBox, action) => {
    const { initialState } = action;
    if (action.kind === "reset")
        return { initialState, values: initialState };
    const currentValues = valuesOfTree(stateBox, initialState);
    if (!Object.prototype.hasOwnProperty.call(initialState, action.name))
        return { initialState, values: currentValues };
    if (currentValues[action.name] === action.value)
        return { initialState, values: currentValues };
    return { initialState, values: { ...currentValues, [action.name]: action.value } };
};
const toStateValue = (value, budgets) => {
    if (typeof value === "string")
        return [...value].slice(0, budgets.maxStateStringLength).join("");
    if (typeof value === "number")
        return Number.isFinite(value) ? value : undefined;
    if (typeof value === "boolean" || value === null)
        return value;
    return undefined;
};
export const useComposedViewState = (tree, budgets) => {
    const initialState = tree.state;
    const [stateBox, dispatch] = useReducer(stateReducer, { initialState, values: initialState });
    const state = valuesOfTree(stateBox, initialState);
    const updateTimestamps = useRef([]);
    const setStateValue = useCallback((name, value) => {
        const stateValue = toStateValue(value, budgets);
        if (stateValue === undefined)
            return;
        const now = Date.now();
        updateTimestamps.current = updateTimestamps.current.filter((timestamp) => now - timestamp < 1000);
        if (updateTimestamps.current.length >= budgets.maxInputUpdatesPerSecond)
            return;
        updateTimestamps.current.push(now);
        dispatch({ kind: "set", name, value: stateValue, initialState });
    }, [budgets, initialState]);
    const resetState = useCallback(() => dispatch({ kind: "reset", initialState }), [initialState]);
    return useMemo(() => ({ state, setStateValue, resetState }), [state, setStateValue, resetState]);
};
