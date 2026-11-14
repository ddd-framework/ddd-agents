// @vitest-environment jsdom
import { getWidgetSelfHydrationKeyFieldName } from "../../provider/environment";
import { describe, expect, test, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import type { CanvasState } from "../../renderer/canvas/canvas-state";
import {
  canvasInstanceIdForEntityDetail,
  readEntityDetailInstanceId,
  readOpenEntityActionTarget,
} from "../../renderer/canvas/entity-detail-instance";
import {
  buildEntityDetailBodyRegistry,
  EntityDetailBodiesContext,
  EntityDetailBodyProps,
} from "./entity-detail-bodies";
import { EntityDetailCanvasBody } from "./EntityDetailCanvasBody";
import { useReportEntityDetailName } from "./entity-detail-name";

// The ask button's chip label is what the shell hands it; the stub prints it.
vi.mock(
  "../../widgets/EntityMentionConversationWidget/EntityMentionAskButton/EntityMentionAskButton",
  () => ({
    EntityMentionAskButton: ({ entityId, label }: { entityId: string; label?: string }) => (
      <output data-testid="ask-button">{`${entityId}:${label ?? "(no name)"}`}</output>
    ),
  }),
);

/**
 * The `entityDetail` canvas: the instance id round-trips the entity, an `openEntity` action
 * opens only once the backend resolved its reference, and the shell mounts the body registered for the entity type
 * (a stub here; the real bodies are registered per entity type) or a notice for a type without one.
 */

const StubReviewBody = ({ entityType, entityId }: EntityDetailBodyProps) => <div>{`${entityType} #${entityId}`}</div>;

/** A body that knows its entity's name once hydrated — here at once. */
const StubNamedPlaceBody = ({ entityId }: EntityDetailBodyProps) => {
  useReportEntityDetailName(entityId === "1" ? "Altes Gasthaus Leve" : undefined);
  return <div>{`place #${entityId}`}</div>;
};

const canvasFor = (instanceId: string): CanvasState => ({
  canvasId: "entityDetail",
  instanceId,
  anchor: { kind: "message", messageId: 9 },
  origin: "cardClick",
  mountGeneration: 1,
});

const renderBody = (instanceId: string) =>
  render(
    <EntityDetailBodiesContext.Provider
      value={buildEntityDetailBodyRegistry([
        { entityType: "review", component: StubReviewBody },
        { entityType: "place", component: StubNamedPlaceBody },
      ])}
    >
      <EntityDetailCanvasBody canvas={canvasFor(instanceId)} anchorMessageMarkdown={null} agentSpaceKey={7} />
    </EntityDetailBodiesContext.Provider>,
  );

describe("entityDetail canvas", () => {
  test("the instance id round-trips the entity and refuses another shape", () => {
    const instanceId = canvasInstanceIdForEntityDetail({ entityType: "review", entityId: "42" });
    expect(instanceId).toBe("entityDetail:review:42");
    expect(readEntityDetailInstanceId(instanceId)).toEqual({ entityType: "review", entityId: "42" });
    expect(readEntityDetailInstanceId("entityDetail:place:ChIJ-abc_1.2")).toEqual({
      entityType: "place",
      entityId: "ChIJ-abc_1.2",
    });
    expect(readEntityDetailInstanceId("entityDetail:place:a/b")).toBeUndefined();
    expect(readEntityDetailInstanceId("exampleEditor:entity:42")).toBeUndefined();
    expect(readEntityDetailInstanceId("entityDetail:re view:42")).toBeUndefined();
  });

  test("an openEntity action opens only with the entity the backend resolved", () => {
    expect(readOpenEntityActionTarget({ type: "openEntity", ref: "call_1:review:0" })).toBeUndefined();
    expect(
      readOpenEntityActionTarget({
        type: "openEntity",
        ref: "call_1:review:0",
        entity: { entityType: "review", entityId: 42, [getWidgetSelfHydrationKeyFieldName()]: 7 },
      }),
    ).toEqual({ entityType: "review", entityId: "42", agentSpaceKey: 7 });
    expect(
      readOpenEntityActionTarget({ type: "openEntity", entity: { entityType: "review", entityId: "<b>" } }),
    ).toBeUndefined();
  });

  test("a row-bound openEntity action opens the current row's entity, never one written on the action", () => {
    const rowBoundAction = {
      type: "openEntity",
      ref: { $item: "entityRef" },
      refState: "row",
      entity: { entityType: "review", entityId: "999" },
    };
    const row = {
      name: "Pizzeria Roma",
      entityRef: "call_7:place:1",
      entity: { entityType: "place", entityId: "ChIJ-roma", [getWidgetSelfHydrationKeyFieldName()]: 7 },
    };
    expect(readOpenEntityActionTarget(rowBoundAction, row)).toEqual({
      entityType: "place",
      entityId: "ChIJ-roma",
      agentSpaceKey: 7,
    });
    // `refState: "row"` alone (the backend's mark) and the `$item` ref alone both bind the action to the row.
    expect(readOpenEntityActionTarget({ type: "openEntity", ref: "x", refState: "row" }, row)).toMatchObject({
      entityId: "ChIJ-roma",
    });
    expect(readOpenEntityActionTarget({ type: "openEntity", ref: { $item: "entityRef" } }, row)).toMatchObject({
      entityId: "ChIJ-roma",
    });
    expect(readOpenEntityActionTarget(rowBoundAction)).toBeUndefined();
    expect(readOpenEntityActionTarget(rowBoundAction, { name: "No entity" })).toBeUndefined();
    expect(readOpenEntityActionTarget(rowBoundAction, "a scalar row")).toBeUndefined();
    // An action-level entity is unaffected by a row.
    expect(
      readOpenEntityActionTarget(
        { type: "openEntity", ref: "call_1:review:0", entity: { entityType: "review", entityId: "42" } },
        row,
      ),
    ).toEqual({ entityType: "review", entityId: "42", agentSpaceKey: undefined });
  });

  test("mounts the body registered for the entity type", () => {
    renderBody("entityDetail:review:42");
    expect(screen.getByText("review #42")).toBeTruthy();
  });

  test("the ask button names the entity once its body reported the name", () => {
    renderBody("entityDetail:place:1");
    expect(screen.getByTestId("ask-button").textContent).toBe("1:Altes Gasthaus Leve");
  });

  test("a body that reports no name leaves the chip unnamed", () => {
    renderBody("entityDetail:review:42");
    expect(screen.getByTestId("ask-button").textContent).toBe("42:(no name)");
  });

  test("an entity type without a body shows a notice, never an empty pane", () => {
    renderBody("entityDetail:keyword:3");
    expect(screen.getByText("This detail cannot be shown here yet.")).toBeTruthy();
  });

  test("a second body for one entity type is refused", () => {
    expect(() =>
      buildEntityDetailBodyRegistry([
        { entityType: "review", component: StubReviewBody },
        { entityType: "review", component: StubReviewBody },
      ]),
    ).toThrow(/two entity detail bodies/i);
  });
});
