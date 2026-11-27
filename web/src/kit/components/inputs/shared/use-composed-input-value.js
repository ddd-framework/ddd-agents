import { useState } from "react";
import { isComposedInputUsable } from "./composed-input-values";
/**
 * The value of one kit input. A tree normally BINDS the control to its state (`value: {"$state":
 * "period"}` + `onChange: {"set": "period"}`): the state is the truth and every node bound to it follows. A control
 * written with no value but a `{"set": <name>}` event shows its own set target (the lenient state
 * rule — the readers declare an undeclared target with null). A control with a literal value, or with neither, still
 * works on its own — it keeps the owner's last choice, so a click never looks dead. The event is the node's RAW wire
 * value (`node.props[eventPropName]`), as the runtime expects it.
 */
export const useComposedInputValue = (options) => {
    const { node, runtime, resolvedValue, readValue, fallbackValue, valuePropName = "value", eventPropName = "onChange", toEventValue, } = options;
    const [ownValue, setOwnValue] = useState(undefined);
    const wireValue = node.props[valuePropName];
    const eventWireValue = node.props[eventPropName];
    const ownSetTarget = wireValue === undefined &&
        typeof eventWireValue === "object" &&
        eventWireValue !== null &&
        !Array.isArray(eventWireValue) &&
        typeof eventWireValue.set === "string" &&
        Object.prototype.hasOwnProperty.call(runtime.state, eventWireValue.set)
        ? eventWireValue.set
        : undefined;
    const isBoundToTheTree = ownSetTarget !== undefined || (typeof wireValue === "object" && wireValue !== null && !Array.isArray(wireValue));
    const treeValue = readValue(ownSetTarget !== undefined ? runtime.state[ownSetTarget] : resolvedValue);
    const value = (isBoundToTheTree ? (treeValue ?? ownValue) : (ownValue ?? treeValue)) ?? fallbackValue;
    // A frozen control (a replay, the e-mail capture) is `inert` in the browser; this guard holds where inert does not.
    const changeValue = (nextValue) => {
        if (!isComposedInputUsable(runtime))
            return;
        setOwnValue(nextValue);
        runtime.runEvent(node.props[eventPropName], toEventValue ? toEventValue(nextValue) : nextValue);
    };
    return { value, changeValue };
};
