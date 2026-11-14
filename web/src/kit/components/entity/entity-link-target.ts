import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import { readOpenEntityActionTarget } from "../../../renderer/canvas/entity-detail-instance";
import type { ComposedViewRuntime } from "../../composed-view-component-types";

/**
 * Whether an `EntityLink`'s click opens a detail panel. Pure; never throws.
 *
 * - `missing` — no action name (`action` absent, empty or not text);
 * - `unresolved` — the tree's action of that name is gone (the persist pass dropped it), is not `openEntity`, or
 *   carries no resolved `entity` — the reference did not resolve, a click would open nothing;
 * - `resolved` — an `openEntity` action with its resolved entity — for a row-bound action (`ref: {"$item":"entityRef"}`)
 *   the `entity` of the row the link renders in (`runtime.currentRow`);
 * - `unchecked` — the runtime cannot look actions up (it has no `findAction`), so the name is trusted and the click
 *   runs the action; the widget opens nothing for an unresolved one.
 */
export type EntityLinkTargetState = "missing" | "unresolved" | "resolved" | "unchecked";

export interface EntityLinkTarget {
  state: EntityLinkTargetState;
  actionName: string;
}

/**
 * The runtime's optional action lookup: the tree action by name, or undefined. Not on `ComposedViewRuntime` yet — the
 * renderer adds it; until then every link is `unchecked`.
 */
type RuntimeWithActionLookup = ComposedViewRuntime & {
  findAction?: (actionName: string) => Record<string, unknown> | undefined;
};

export const readEntityLinkTarget = (actionProp: ExpressionValue, runtime: ComposedViewRuntime): EntityLinkTarget => {
  const actionName = typeof actionProp === "string" ? actionProp.trim() : "";
  if (actionName === "") return { state: "missing", actionName: "" };
  const { findAction } = runtime as RuntimeWithActionLookup;
  if (typeof findAction !== "function") return { state: "unchecked", actionName };
  const action = findAction(actionName);
  if (
    action === undefined ||
    action.type !== "openEntity" ||
    readOpenEntityActionTarget(action, runtime.currentRow) === undefined
  ) {
    return { state: "unresolved", actionName };
  }
  return { state: "resolved", actionName };
};
