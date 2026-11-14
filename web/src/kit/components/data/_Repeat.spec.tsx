// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { composedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { renderDataTree } from "./render-data-tree.spec-helper";

describe("Repeat", () => {
  test("repeats its children once per item with $item bound, limit cuts", () => {
    const { findings, lastReport } = renderDataTree({
      data: {
        channels: [
          { name: "Organic", visitors: 4190 },
          { name: "Paid", visitors: 2620 },
          { name: "Direct", visitors: 1390 },
        ],
      },
      view: {
        type: "Repeat",
        items: { $data: "channels" },
        limit: 2,
        children: [
          { type: "KpiTile", label: { $item: "name" }, value: { $item: "visitors" }, source: { name: "Spec source" } },
        ],
      },
    });
    expect(findings).toEqual([]);
    expect(screen.getByText("Organic")).toBeTruthy();
    expect(screen.getByText("2,620")).toBeTruthy();
    expect(screen.queryByText("Direct")).toBeNull();
    expect(lastReport()).toEqual({ status: "mounted" });
  });

  test("$item reads a dotted field of a nested object; a field the resolved $from rows lack is a note", () => {
    const resolvedRows = [
      { externalId: "r1", rating: 5, author: { name: "Anna" } },
      { externalId: "r2", rating: 4, author: { name: "Ben" } },
    ];
    const { findings } = renderDataTree({
      view: {
        type: "Repeat",
        items: {
          $from: { toolCall: "call_4", path: "$.reviews[*]", state: "resolved", value: resolvedRows, rowCount: 2 },
        },
        children: [
          { type: "Badge", text: { $item: "author.name" } },
          { type: "Badge", text: { $item: "created" } },
        ],
      },
    });
    expect(findings.map((finding) => finding.code)).toEqual(["UNKNOWN_ITEM_KEY"]);
    expect(findings[0].message).toContain("externalId, rating, author.name");
    expect(screen.getByText("Anna")).toBeTruthy();
    expect(screen.getByText("Ben")).toBeTruthy();
  });

  test("an empty list reports empty", () => {
    const { lastReport } = renderDataTree({
      data: { channels: [] },
      view: { type: "Repeat", items: { $data: "channels" }, children: [{ type: "Icon", name: "star" }] },
    });
    expect(lastReport()).toMatchObject({ status: "empty", code: "NO_DATA" });
  });

  test("in a Table it repeats only TableRows and reports the rest", () => {
    const { container, lastReport, findings } = renderDataTree({
      data: { rows: [{ x: "a" }] },
      view: {
        type: "Table",
        source: { name: "Spec source" },
        children: [
          {
            type: "Repeat",
            items: { $data: "rows" },
            children: [
              { type: "TableRow", children: [{ type: "TableCell", value: { $item: "x" } }] },
              { type: "Icon", name: "star", label: "Stray" },
            ],
          },
        ],
      },
    });
    expect(findings).toEqual([]);
    expect(container.querySelectorAll("tbody tr")).toHaveLength(1);
    expect(screen.queryByRole("img", { name: "Stray" })).toBeNull();
    expect(lastReport()).toMatchObject({ status: "failed", code: "INVALID_NODE" });
  });

  test("the renderer caps the items at maxRepeatRows and reports it", () => {
    const maxRepeatRows = composedNodeSchema.budgets.maxRepeatRows;
    const { container, lastReport } = renderDataTree({
      view: {
        type: "List",
        children: [
          {
            type: "Repeat",
            items: Array.from({ length: maxRepeatRows + 1 }, (unused, itemIndex) => ({ n: String(itemIndex) })),
            children: [{ type: "ListItem", title: { $item: "n" } }],
          },
        ],
      },
    });
    expect(container.querySelectorAll("li")).toHaveLength(maxRepeatRows);
    expect(lastReport()).toMatchObject({ status: "failed", code: "BUDGET_EXCEEDED" });
  });
});
