import { readOpenEntityActionTarget } from "../../../renderer/canvas/entity-detail-instance";
export const readEntityLinkTarget = (actionProp, runtime) => {
    const actionName = typeof actionProp === "string" ? actionProp.trim() : "";
    if (actionName === "")
        return { state: "missing", actionName: "" };
    const { findAction } = runtime;
    if (typeof findAction !== "function")
        return { state: "unchecked", actionName };
    const action = findAction(actionName);
    if (action === undefined ||
        action.type !== "openEntity" ||
        readOpenEntityActionTarget(action, runtime.currentRow) === undefined) {
        return { state: "unresolved", actionName };
    }
    return { state: "resolved", actionName };
};
