import { getWidgetSelfHydrationKeyFieldName } from "../../../provider/environment";
import { describe, expect, test } from "vitest";
import {
  buildEntityMentionFields,
  entityMentionKey,
  MAX_ENTITY_MENTION_LABEL_LENGTH,
  normalizeEntityMentionFields,
  parseEntityMentionPayload,
  toEntityMentionWireFields,
} from "./entity-mention";

describe("entity-mention — the EntityMention fence fields", () => {
  test("reads a fence's fields and tolerates the spellings a copy uses", () => {
    expect(
      parseEntityMentionPayload(
        JSON.stringify({ entityType: "competitor", entityId: "8812", label: "Altes Gasthaus Leve", [getWidgetSelfHydrationKeyFieldName()]: 7 }),
      ),
    ).toEqual({ entityType: "competitor", entityId: "8812", label: "Altes Gasthaus Leve", agentSpaceKey: 7 });
    expect(normalizeEntityMentionFields({ kind: "review", id: 4711, name: "  Sehr  gut " })).toEqual({
      entityType: "review",
      entityId: "4711",
      label: "Sehr gut",
      agentSpaceKey: null,
    });
  });

  test("names no entity without a valid type or id, and never throws", () => {
    expect(parseEntityMentionPayload("{not json")).toBeNull();
    expect(parseEntityMentionPayload(undefined)).toBeNull();
    expect(normalizeEntityMentionFields([])).toBeNull();
    expect(normalizeEntityMentionFields({ entityType: "review" })).toBeNull();
    expect(normalizeEntityMentionFields({ entityType: "re view", entityId: "1" })).toBeNull();
    expect(normalizeEntityMentionFields({ entityType: "review", entityId: "../x" })).toBeNull();
    expect(normalizeEntityMentionFields({ entityType: "review", entityId: 1.5 })).toBeNull();
  });

  test("caps the label and drops a bad agent space key; the wire form leaves out what is null", () => {
    const mention = buildEntityMentionFields({
      entityType: "keyword",
      entityId: "55",
      label: "x".repeat(500),
      agentSpaceKey: -3,
    });
    expect(mention?.label).toHaveLength(MAX_ENTITY_MENTION_LABEL_LENGTH);
    expect(mention?.agentSpaceKey).toBeNull();
    expect(toEntityMentionWireFields({ entityType: "keyword", entityId: "55", label: null, agentSpaceKey: null })).toEqual(
      {
        entityType: "keyword",
        entityId: "55",
      },
    );
    expect(entityMentionKey({ entityType: "keyword", entityId: "55" })).toBe("keyword:55");
  });
});
