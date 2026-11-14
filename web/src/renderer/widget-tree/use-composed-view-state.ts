import { useCallback, useMemo, useReducer, useRef } from "react";
import type { ComposedViewBudgets } from "./composed-node-schema";
import type { ComposedViewTree } from "./composed-view-tree";

/**
 * The local state of one `ComposedView` instance: initialised from the tree's `state`, changed only by
 * the tree's own inputs (`onChange: {"set": …}`) and the `resetState` action — never written anywhere, so a replayed
 * message's inputs stay usable. Budgets: a string longer than `maxStateStringLength` is cut, a name the tree did
 * not declare is ignored, and at most `maxInputUpdatesPerSecond` updates per second are applied (a dragged slider
 * cannot flood the evaluator). A new tree (the payload changed under the same instance) starts from its own state.
 */

export type ComposedViewStateValue = string | number | boolean | null;

type StateValues = Record<string, ComposedViewStateValue>;

/** The values with the tree state they started from — a NEW tree (another payload) starts again from its own state. */
interface StateBox {
  initialState: StateValues;
  values: StateValues;
}

type StateAction =
  | { kind: "set"; name: string; value: ComposedViewStateValue; initialState: StateValues }
  | { kind: "reset"; initialState: StateValues };

/** The values for `initialState`: the box's while it still belongs to that tree, else the tree's own initial state. */
const valuesOfTree = (stateBox: StateBox, initialState: StateValues): StateValues =>
  stateBox.initialState === initialState ? stateBox.values : initialState;

const stateReducer = (stateBox: StateBox, action: StateAction): StateBox => {
  const { initialState } = action;
  if (action.kind === "reset") return { initialState, values: initialState };
  const currentValues = valuesOfTree(stateBox, initialState);
  if (!Object.prototype.hasOwnProperty.call(initialState, action.name)) return { initialState, values: currentValues };
  if (currentValues[action.name] === action.value) return { initialState, values: currentValues };
  return { initialState, values: { ...currentValues, [action.name]: action.value } };
};

export interface ComposedViewStateStore {
  state: Record<string, ComposedViewStateValue>;
  setStateValue: (name: string, value: unknown) => void;
  resetState: () => void;
}

const toStateValue = (value: unknown, budgets: ComposedViewBudgets): ComposedViewStateValue | undefined => {
  if (typeof value === "string") return [...value].slice(0, budgets.maxStateStringLength).join("");
  if (typeof value === "number") return Number.isFinite(value) ? value : undefined;
  if (typeof value === "boolean" || value === null) return value;
  return undefined;
};

export const useComposedViewState = (tree: ComposedViewTree, budgets: ComposedViewBudgets): ComposedViewStateStore => {
  const initialState = tree.state;
  const [stateBox, dispatch] = useReducer(stateReducer, { initialState, values: initialState });
  const state = valuesOfTree(stateBox, initialState);
  const updateTimestamps = useRef<number[]>([]);

  const setStateValue = useCallback(
    (name: string, value: unknown) => {
      const stateValue = toStateValue(value, budgets);
      if (stateValue === undefined) return;
      const now = Date.now();
      updateTimestamps.current = updateTimestamps.current.filter((timestamp) => now - timestamp < 1000);
      if (updateTimestamps.current.length >= budgets.maxInputUpdatesPerSecond) return;
      updateTimestamps.current.push(now);
      dispatch({ kind: "set", name, value: stateValue, initialState });
    },
    [budgets, initialState],
  );
  const resetState = useCallback(() => dispatch({ kind: "reset", initialState }), [initialState]);

  return useMemo(() => ({ state, setStateValue, resetState }), [state, setStateValue, resetState]);
};
