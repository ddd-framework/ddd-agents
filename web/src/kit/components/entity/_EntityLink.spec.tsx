// @vitest-environment jsdom
import { getWidgetSelfHydrationKeyFieldName } from "../../../provider/environment";
import { describe, expect, test } from "vitest";
import { fireEvent, screen } from "@testing-library/react";
import type { ComposedViewRuntime } from "../../composed-view-component-types";
import { readOpenEntityActionTarget } from "../../../renderer/canvas/entity-detail-instance";
import { readEntityLinkTarget } from "./entity-link-target";
import {
  renderEntityTree,
  resolvedFromBinding,
  resolvedOpenEntityAction,
  ROW_BOUND_OPEN_ENTITY_ACTION,
} from "./render-entity-tree.spec-helper";

// Built per test: the block field of the agent space key is declared by the app's environment, installed after collection.
const buildActions = () => ({ openRoma: resolvedOpenEntityAction("call_7:competitor:0", "competitor", "4711") });

/** Two rows of a resolved `$from` list, each carrying its own `entityRef` + `entity` (`EntityReferenceRow::toWireArray()`). */
const buildPlaceRows = () => [
  {
    name: "Pizzeria Roma",
    entityRef: "call_7:competitor:0",
    entity: { entityType: "competitor", entityId: "4711", [getWidgetSelfHydrationKeyFieldName()]: 7 },
  },
  {
    name: "Trattoria Milano",
    entityRef: "call_7:competitor:1",
    entity: { entityType: "competitor", entityId: "4712", [getWidgetSelfHydrationKeyFieldName()]: 7 },
  },
];

describe("EntityLink — the entity name that opens its detail panel", () => {
  test("a click runs the named openEntity action and the node reports mounted", () => {
    const { reports, openedActions } = renderEntityTree({
      actions: buildActions(),
      view: { type: "EntityLink", text: "Pizzeria Roma", action: "openRoma" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Pizzeria Roma" }));
    expect(openedActions).toEqual([buildActions().openRoma]);
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("the chip variant is a button with the name and the detail glyph", () => {
    const { openedActions } = renderEntityTree({
      actions: buildActions(),
      view: { type: "EntityLink", text: "Pizzeria Roma", action: "openRoma", variant: "chip" },
    });
    fireEvent.click(screen.getByTestId("composed-entity-link"));
    expect(openedActions).toHaveLength(1);
  });

  test("without an action the name shows as plain text and the node reports failed UNRESOLVED_REF", () => {
    const { reports } = renderEntityTree({ view: { type: "EntityLink", text: "Pizzeria Roma" } });
    expect(screen.queryByRole("button")).toBeNull();
    expect(screen.getByText("Pizzeria Roma")).toBeTruthy();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "UNRESOLVED_REF" });
  });

  test("in the e-mail capture the name shows without a control", () => {
    const { reports } = renderEntityTree(
      { actions: buildActions(), view: { type: "EntityLink", text: "Pizzeria Roma", action: "openRoma" } },
      { isEmailRender: true },
    );
    expect(screen.queryByRole("button")).toBeNull();
    expect(screen.getByText("Pizzeria Roma")).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("model text with markup is shown as text, never parsed", () => {
    renderEntityTree({ actions: buildActions(), view: { type: "EntityLink", text: "<b>Roma</b> & Co", action: "openRoma" } });
    expect(screen.getByRole("button").textContent).toBe("<b>Roma</b> & Co");
  });

  test("an empty bound name reports empty; a link without text is dropped by the reader", () => {
    const empty = renderEntityTree({
      state: { name: "" },
      actions: buildActions(),
      view: { type: "EntityLink", text: { $state: "name" }, action: "openRoma" },
    });
    expect(empty.reports.at(-1)).toMatchObject({ status: "empty", code: "NO_DATA" });

    const dropped = renderEntityTree({ view: { type: "EntityLink", action: "openRoma" } });
    expect(dropped.findingCodes).toEqual(["INVALID_NODE"]);
    expect(dropped.reports.at(-1)).toMatchObject({ status: "failed" });
  });

  test("inside a Repeat every row opens its own entity through a bound action name", () => {
    const { openedActions } = renderEntityTree({
      data: {
        competitors: [
          { name: "Pizzeria Roma", action: "openRoma" },
          { name: "Trattoria Milano", action: "openMilano" },
        ],
      },
      actions: { ...buildActions(), openMilano: resolvedOpenEntityAction("call_7:competitor:1", "competitor", "4712") },
      view: {
        type: "Repeat",
        items: { $data: "competitors" },
        children: [{ type: "EntityLink", text: { $item: "name" }, action: { $item: "action" } }],
      },
    });
    fireEvent.click(screen.getByRole("button", { name: "Trattoria Milano" }));
    expect(openedActions).toEqual([expect.objectContaining({ ref: "call_7:competitor:1" })]);
  });

  test('inside a Repeat over a resolved $from list, a {"$item":"entityRef"} action opens each row\'s own entity', () => {
    const { openedActions, openedRows, reports } = renderEntityTree({
      data: { places: resolvedFromBinding(buildPlaceRows()) },
      actions: { openPlace: ROW_BOUND_OPEN_ENTITY_ACTION },
      view: {
        type: "Repeat",
        items: { $data: "places" },
        children: [{ type: "EntityLink", text: { $item: "name" }, action: "openPlace" }],
      },
    });
    fireEvent.click(screen.getByRole("button", { name: "Trattoria Milano" }));
    expect(openedActions).toEqual([ROW_BOUND_OPEN_ENTITY_ACTION]);
    expect(readOpenEntityActionTarget(openedActions[0], openedRows[0])).toEqual({
      entityType: "competitor",
      entityId: "4712",
      agentSpaceKey: 7,
    });
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a row without an entity shows its name without a link and reports failed UNRESOLVED_REF", () => {
    const { openedActions, reports } = renderEntityTree({
      data: { places: resolvedFromBinding([{ name: "Unlisted Café" }]) },
      actions: { openPlace: ROW_BOUND_OPEN_ENTITY_ACTION },
      view: {
        type: "Repeat",
        items: { $data: "places" },
        children: [{ type: "EntityLink", text: { $item: "name" }, action: "openPlace" }],
      },
    });
    expect(screen.queryByRole("button")).toBeNull();
    expect(screen.getByText("Unlisted Café")).toBeTruthy();
    expect(openedActions).toEqual([]);
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "UNRESOLVED_REF" });
  });
});

describe("readEntityLinkTarget — whether the click opens a panel", () => {
  const runtimeWith = (actions: Record<string, Record<string, unknown>>) =>
    ({ findAction: (actionName: string) => actions[actionName] }) as unknown as ComposedViewRuntime;

  test("no action name is missing; a runtime without lookup trusts the name", () => {
    expect(readEntityLinkTarget(undefined, runtimeWith({})).state).toBe("missing");
    expect(readEntityLinkTarget(" ", runtimeWith({})).state).toBe("missing");
    expect(readEntityLinkTarget("openRoma", {} as ComposedViewRuntime).state).toBe("unchecked");
  });

  test("with the lookup: a resolved openEntity action is resolved; gone, not openEntity or unresolved is unresolved", () => {
    const runtime = runtimeWith({
      openRoma: buildActions().openRoma,
      copyIt: { type: "copy", value: "x" },
      openPending: { type: "openEntity", ref: "call_7:competitor:2" },
    });
    expect(readEntityLinkTarget("openRoma", runtime).state).toBe("resolved");
    expect(readEntityLinkTarget("copyIt", runtime).state).toBe("unresolved");
    expect(readEntityLinkTarget("openPending", runtime).state).toBe("unresolved");
    expect(readEntityLinkTarget("gone", runtime).state).toBe("unresolved");
  });
});
