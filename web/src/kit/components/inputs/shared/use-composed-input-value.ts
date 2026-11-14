import { useState } from "react";
import type { ComposedViewNode } from "../../../../renderer/widget-tree/composed-view-tree";
import type { ExpressionValue } from "../../../../renderer/widget-tree/expression/evaluate-expression";
import type { ComposedViewRuntime } from "../../../composed-view-component-types";
import { isComposedInputUsable } from "./composed-input-values";

export interface ComposedInputValueOptions<ValueType> {
  node: ComposedViewNode;
  runtime: ComposedViewRuntime;
  /** The prop that carries the value (`value`, `checked`), resolved. */
  resolvedValue: ExpressionValue | undefined;
  /** Narrows the resolved value to the control's type; `undefined` for a value of another shape. */
  readValue: (resolvedValue: ExpressionValue | undefined) => ValueType | undefined;
  /** What the control shows when nothing is set. */
  fallbackValue: ValueType;
  valuePropName?: string;
  eventPropName?: string;
  /** What the event writes for a shown value (a number field shows text but writes a number); the value itself by default. */
  toEventValue?: (nextValue: ValueType) => unknown;
}

export interface ComposedInputValue<ValueType> {
  /** The value the control shows. */
  value: ValueType;
  /** The owner changed the control: keep it and run the node's event (`{"set": …}` writes the tree state). */
  changeValue: (nextValue: ValueType) => void;
}

/**
 * The value of one kit input. A tree normally BINDS the control to its state (`value: {"$state":
 * "period"}` + `onChange: {"set": "period"}`): the state is the truth and every node bound to it follows. A control
 * written with no value but a `{"set": <name>}` event shows its own set target (the lenient state
 * rule — the readers declare an undeclared target with null). A control with a literal value, or with neither, still
 * works on its own — it keeps the owner's last choice, so a click never looks dead. The event is the node's RAW wire
 * value (`node.props[eventPropName]`), as the runtime expects it.
 */
export const useComposedInputValue = <ValueType>(
  options: ComposedInputValueOptions<ValueType>,
): ComposedInputValue<ValueType> => {
  const {
    node,
    runtime,
    resolvedValue,
    readValue,
    fallbackValue,
    valuePropName = "value",
    eventPropName = "onChange",
    toEventValue,
  } = options;
  const [ownValue, setOwnValue] = useState<ValueType | undefined>(undefined);
  const wireValue = node.props[valuePropName];
  const eventWireValue = node.props[eventPropName];
  const ownSetTarget =
    wireValue === undefined &&
    typeof eventWireValue === "object" &&
    eventWireValue !== null &&
    !Array.isArray(eventWireValue) &&
    typeof eventWireValue.set === "string" &&
    Object.prototype.hasOwnProperty.call(runtime.state, eventWireValue.set)
      ? eventWireValue.set
      : undefined;
  const isBoundToTheTree =
    ownSetTarget !== undefined || (typeof wireValue === "object" && wireValue !== null && !Array.isArray(wireValue));
  const treeValue = readValue(ownSetTarget !== undefined ? runtime.state[ownSetTarget] : resolvedValue);
  const value = (isBoundToTheTree ? (treeValue ?? ownValue) : (ownValue ?? treeValue)) ?? fallbackValue;
  // A frozen control (a replay, the e-mail capture) is `inert` in the browser; this guard holds where inert does not.
  const changeValue = (nextValue: ValueType) => {
    if (!isComposedInputUsable(runtime)) return;
    setOwnValue(nextValue);
    runtime.runEvent(node.props[eventPropName], toEventValue ? toEventValue(nextValue) : nextValue);
  };
  return { value, changeValue };
};
