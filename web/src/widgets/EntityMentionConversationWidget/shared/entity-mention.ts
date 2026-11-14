/**
 * The `EntityMention` fence: ONE entity the owner picked from a detail panel ("Ask about
 * this"), carried in the owner's own next message as a ```widget fence, so the backend reads WHICH entity the
 * question is about (`EntityMentionConversationWidget.php`) instead of guessing it from a name. One widget per entity;
 * a message carries at most {@link MAX_ENTITY_MENTIONS_PER_MESSAGE}. Field names equal the backend class's properties.
 * Pure; every reader is alias-tolerant and never throws.
 */
import { getWidgetSelfHydrationKeyFieldName } from "../../../provider/environment";

export const ENTITY_MENTION_WIDGET_NAME = "EntityMention";

/** Most entities one message mentions — one more "Ask about this" drops the oldest mention. */
export const MAX_ENTITY_MENTIONS_PER_MESSAGE = 5;

/** Longest display name carried into the message (the name is shown, never trusted — the id identifies). */
export const MAX_ENTITY_MENTION_LABEL_LENGTH = 120;

/** The backend resolver's `ENTITY_TYPE` form (`competitor`, `review`, `keyword`) — the `entityDetail` instance id rule. */
const ENTITY_TYPE_PATTERN = /^[a-zA-Z][a-zA-Z0-9]*$/;
/** A row id or an external id — letters, digits, `_`, `-`, `.` (the `entityDetail` instance id rule). */
const ENTITY_ID_PATTERN = /^[A-Za-z0-9_.-]{1,256}$/;

export interface EntityMentionFields {
  entityType: string;
  entityId: string;
  /** The entity's name as the panel showed it; null when the opener knew none. */
  label: string | null;
  /** The key of the agent space the entity belongs to; null = the chat's agent space. */
  agentSpaceKey: number | null;
}

/** What an opener (the detail panel's button) knows about the entity. */
export interface EntityMentionTarget {
  entityType: string;
  entityId: string | number;
  label?: string | null;
  agentSpaceKey?: number | null;
}

const readFirstDefined = (record: Record<string, unknown>, keys: string[]): unknown => {
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(record, key) && record[key] !== undefined && record[key] !== null) {
      return record[key];
    }
  }
  return undefined;
};

const readEntityId = (value: unknown): string | null => {
  const entityIdText = typeof value === "number" && Number.isInteger(value) ? String(value) : value;
  return typeof entityIdText === "string" && ENTITY_ID_PATTERN.test(entityIdText) ? entityIdText : null;
};

const readLabel = (value: unknown): string | null => {
  if (typeof value !== "string") return null;
  const label = value.replace(/\s+/g, " ").trim();
  return label === "" ? null : label.slice(0, MAX_ENTITY_MENTION_LABEL_LENGTH);
};

const readAgentSpaceKey = (value: unknown): number | null => {
  const agentSpaceKey = typeof value === "string" && /^\d{1,18}$/.test(value) ? Number(value) : value;
  return typeof agentSpaceKey === "number" && Number.isInteger(agentSpaceKey) && agentSpaceKey > 0
    ? agentSpaceKey
    : null;
};

/**
 * The mention a fence's fields (or a composer widget's fields) carry, or null when they name no entity. Tolerates the
 * spellings a copy might use (`type`/`kind` for the type, `id` for the id, `name`/`title` for the label).
 */
export const normalizeEntityMentionFields = (fields: unknown): EntityMentionFields | null => {
  if (typeof fields !== "object" || fields === null || Array.isArray(fields)) return null;
  const record = fields as Record<string, unknown>;
  const entityType = readFirstDefined(record, ["entityType", "kind"]);
  const entityId = readEntityId(readFirstDefined(record, ["entityId", "id"]));
  if (typeof entityType !== "string" || !ENTITY_TYPE_PATTERN.test(entityType) || entityId === null) return null;
  return {
    entityType,
    entityId,
    label: readLabel(readFirstDefined(record, ["label", "name", "title"])),
    agentSpaceKey: readAgentSpaceKey(record[getWidgetSelfHydrationKeyFieldName()]),
  };
};

/** The mention a widget `payload` (the fence's JSON string) carries, or null. */
export const parseEntityMentionPayload = (payload: string | undefined): EntityMentionFields | null => {
  if (typeof payload !== "string" || payload === "") return null;
  try {
    return normalizeEntityMentionFields(JSON.parse(payload));
  } catch {
    return null;
  }
};

/** The composer widget's fields for an opener's target, or null for a target that names no entity. */
export const buildEntityMentionFields = (target: EntityMentionTarget): EntityMentionFields | null =>
  normalizeEntityMentionFields({
    entityType: target.entityType,
    entityId: target.entityId,
    label: target.label ?? null,
    [getWidgetSelfHydrationKeyFieldName()]: target.agentSpaceKey ?? null,
  });

/** The fields as the fence carries them: a null label or agent space key is not written. */
export const toEntityMentionWireFields = (mention: EntityMentionFields): Record<string, unknown> => {
  const wireFields: Record<string, unknown> = { entityType: mention.entityType, entityId: mention.entityId };
  if (mention.label !== null) wireFields.label = mention.label;
  if (mention.agentSpaceKey !== null) wireFields[getWidgetSelfHydrationKeyFieldName()] = mention.agentSpaceKey;
  return wireFields;
};

/** Identity of a mention: one entity is mentioned once per message. */
export const entityMentionKey = (mention: Pick<EntityMentionFields, "entityType" | "entityId">): string =>
  `${mention.entityType}:${mention.entityId}`;
