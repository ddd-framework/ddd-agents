/**
 * The instance id of the `entityDetail` canvas: one instance per entity — `entityDetail:<entityType>:<id>`.
 * The entity type is the backend resolver's `ENTITY_TYPE` (a lowercase-first name like `review`, `keyword`); the id is
 * the one the backend resolved the platform-minted reference to when it stored the row (`openEntity` action) — a string, as `EntityReferenceRow::$entityId` is (a row id, or an external id such as a place's). A
 * module that imports only the environment accessor, safe for every layer.
 */
import { getWidgetSelfHydrationKeyFieldName } from "../../provider/environment";

export interface EntityDetailTarget {
  entityType: string;
  entityId: string;
}

const ENTITY_TYPE_PATTERN = /^[a-zA-Z][a-zA-Z0-9]*$/;
/** An entity id: letters, digits, `_`, `-`, `.` — a row id or an external id, never a path or markup. */
const ENTITY_ID_PATTERN = /^[A-Za-z0-9_.-]{1,256}$/;
const ENTITY_DETAIL_INSTANCE_ID = /^entityDetail:([a-zA-Z][a-zA-Z0-9]*):([A-Za-z0-9_.-]{1,256})$/;

export const canvasInstanceIdForEntityDetail = (target: EntityDetailTarget): string =>
  `entityDetail:${target.entityType}:${target.entityId}`;

/** The entity an `entityDetail` instance id names, or undefined for another instance id. */
export const readEntityDetailInstanceId = (instanceId: string): EntityDetailTarget | undefined => {
  const match = ENTITY_DETAIL_INSTANCE_ID.exec(instanceId);
  return match ? { entityType: match[1], entityId: match[2] } : undefined;
};

/** The `refState` the backend writes on an `openEntity` action whose `ref` is bound to the current row. */
export const OPEN_ENTITY_REF_STATE_FROM_ROW = "row";

const isPlainRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null && !Array.isArray(value);

/**
 * Whether an `openEntity` action opens the entity of the row it fires in — its `ref` is a `{"$item": "<key>"}`
 * binding (`{"$item":"entityRef"}` inside a `Repeat`, a `Map` point, a list row), or the backend marked it
 * `refState: "row"` (`ComposedViewBindingResolution::STATE_FROM_ROW`). Such an action carries no entity of its own.
 */
export const isRowBoundOpenEntityAction = (action: Record<string, unknown>): boolean => {
  if (action.refState === OPEN_ENTITY_REF_STATE_FROM_ROW) return true;
  const reference = action.ref;
  if (!isPlainRecord(reference)) return false;
  const referenceKeys = Object.keys(reference);
  return (
    referenceKeys.length === 1 &&
    referenceKeys[0] === "$item" &&
    typeof reference.$item === "string" &&
    reference.$item !== ""
  );
};

/**
 * The entity of an `{entityType, entityId, <agent space key field>}` object (`EntityReferenceRow::toWireArray()`), or
 * undefined. The key sits under the block field the app declares (`AgentsEnvironment.widgetSelfHydrationKeyFieldName`).
 */
const readEntityDetailTargetOfEntity = (
  entity: unknown,
): (EntityDetailTarget & { agentSpaceKey?: number }) | undefined => {
  if (!isPlainRecord(entity)) return undefined;
  const { entityType, entityId } = entity;
  const agentSpaceKey = entity[getWidgetSelfHydrationKeyFieldName()];
  if (typeof entityType !== "string" || !ENTITY_TYPE_PATTERN.test(entityType)) return undefined;
  const entityIdText = typeof entityId === "number" && Number.isInteger(entityId) ? String(entityId) : entityId;
  if (typeof entityIdText !== "string" || !ENTITY_ID_PATTERN.test(entityIdText)) return undefined;
  return {
    entityType,
    entityId: entityIdText,
    agentSpaceKey: typeof agentSpaceKey === "number" ? agentSpaceKey : undefined,
  };
};

/**
 * The entity an `openEntity` action opens, or undefined (the action then does nothing):
 * - an action with a platform-minted reference — `{"type":"openEntity", "ref":"<toolCallId>:<entityType>:<index>",
 *   "entity":{"entityType","entityId",<agent space key field>}}` — opens the `entity` the backend resolved it to; undefined while
 *   the reference is unresolved;
 * - a row-bound action ({@link isRowBoundOpenEntityAction}) opens the `entity` of `currentRow`, the row of the item
 *   scope the event fires in (each row of a resolved `$from` list carries its own `entityRef` + `entity`) — NEVER an
 *   `entity` written on the action; undefined without a row or for a row without an entity.
 */
export const readOpenEntityActionTarget = (
  action: Record<string, unknown>,
  currentRow?: unknown,
): (EntityDetailTarget & { agentSpaceKey?: number }) | undefined => {
  if (isRowBoundOpenEntityAction(action)) {
    return isPlainRecord(currentRow) ? readEntityDetailTargetOfEntity(currentRow.entity) : undefined;
  }
  return readEntityDetailTargetOfEntity(action.entity);
};
