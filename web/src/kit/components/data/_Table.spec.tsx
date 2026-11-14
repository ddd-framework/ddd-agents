// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen, within } from "@testing-library/react";
import { composedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { renderDataTree } from "./render-data-tree.spec-helper";

const coalitions = [
  { coalition: "CSU + Freie Wähler", seats: 94, majority: true },
  { coalition: "CSU + Grüne", seats: 104, majority: true },
  { coalition: "CSU + SPD", seats: 86, majority: false },
];

describe("Table", () => {
  test("the short form writes the header and one row per data row, numbers right, booleans as check or cross", () => {
    const { findings, lastReport, container } = renderDataTree({
      data: { coalitions },
      view: {
        type: "Table",
        source: { name: "Spec source" },
        columns: [
          { key: "coalition", label: "Coalition" },
          { key: "seats", label: "Seats" },
          { key: "majority", label: "Majority (91)" },
        ],
        rows: { $data: "coalitions" },
        caption: "GMS poll of 19 October 2026, 180 modelled seats",
      },
    });
    expect(findings).toEqual([]);
    expect(screen.getAllByRole("columnheader").map((header) => header.textContent)).toEqual([
      "Coalition",
      "Seats",
      "Majority (91)",
    ]);
    const bodyRows = container.querySelectorAll("tbody tr");
    expect(bodyRows).toHaveLength(3);
    expect(
      within(bodyRows[2] as HTMLElement)
        .getByText("86")
        .closest("td")?.className,
    ).toMatch(/end/);
    expect(within(bodyRows[0] as HTMLElement).getByRole("img", { name: "Yes" })).toBeTruthy();
    expect(within(bodyRows[2] as HTMLElement).getByRole("img", { name: "No" })).toBeTruthy();
    expect(screen.getByText("GMS poll of 19 October 2026, 180 modelled seats")).toBeTruthy();
    expect(lastReport()).toEqual({ status: "mounted" });
  });

  test("the node form: a header row, a Repeat of rows whose cells hold an Icon, a bar and a signed toned value, a total row", () => {
    const { findings, lastReport, container } = renderDataTree({
      data: {
        parties: [
          { party: "CSU", share: 36, change: -3 },
          { party: "AfD", share: 21, change: 2 },
        ],
      },
      view: {
        type: "Table",
        source: { name: "Spec source" },
        children: [
          {
            type: "TableRow",
            header: true,
            children: [
              { type: "TableCell", value: "Party" },
              { type: "TableCell", value: "Share" },
              { type: "TableCell", value: "Change" },
            ],
          },
          {
            type: "Repeat",
            items: { $data: "parties" },
            children: [
              {
                type: "TableRow",
                children: [
                  { type: "TableCell", value: { $item: "party" } },
                  { type: "TableCell", value: { $item: "share" }, format: "percent", bar: 0.36 },
                  {
                    type: "TableCell",
                    value: { $item: "change" },
                    signed: true,
                    tone: "danger",
                    children: [{ type: "Icon", name: "arrowDown", tone: "danger", label: "Fell" }],
                  },
                ],
              },
            ],
          },
          {
            type: "TableRow",
            emphasis: true,
            children: [
              { type: "TableCell", value: "Total" },
              { type: "TableCell", value: 57, format: "percent" },
              { type: "TableCell" },
            ],
          },
        ],
      },
    });
    expect(findings).toEqual([]);
    expect(container.querySelectorAll("th")).toHaveLength(3);
    const rows = container.querySelectorAll("tbody tr");
    expect(rows).toHaveLength(4);
    expect(within(rows[1] as HTMLElement).getByText("36 %")).toBeTruthy();
    expect(within(rows[1] as HTMLElement).getByText("-3")).toBeTruthy();
    expect(within(rows[2] as HTMLElement).getByText("+2")).toBeTruthy();
    expect(within(rows[1] as HTMLElement).getAllByRole("img", { name: "Fell" })).toHaveLength(1);
    expect((rows[1].querySelectorAll("td")[1] as HTMLElement).style.getPropertyValue("--agents-table-cell-bar")).toBe("36%");
    expect(within(rows[3] as HTMLElement).getByText("Total")).toBeTruthy();
    expect(lastReport()).toEqual({ status: "mounted" });
  });

  test("a heat cell tints by intensity, a heat cell without a value shows a dash", () => {
    const { container, findings } = renderDataTree({
      state: { missingMonth: null },
      view: {
        type: "Table",
        source: { name: "Spec source" },
        children: [
          {
            type: "TableRow",
            children: [
              { type: "TableCell", value: "May" },
              { type: "TableCell", value: 82, format: "percent", heat: 0.82 },
              { type: "TableCell", value: { $state: "missingMonth" }, heat: { $state: "missingMonth" } },
            ],
          },
        ],
      },
    });
    expect(findings).toEqual([]);
    const cells = container.querySelectorAll("td");
    expect((cells[1] as HTMLElement).style.getPropertyValue("--agents-table-cell-heat")).toBe("67%");
    expect(cells[2].textContent).toBe("—");
  });

  test("empty rows show one muted line and report empty", () => {
    const { lastReport } = renderDataTree({
      data: { rows: [] },
      view: {
        type: "Table",
        columns: [{ key: "x", label: "X" }],
        rows: { $data: "rows" },
        emptyText: "No coalition reaches a majority.",
      },
    });
    expect(screen.getByText("No coalition reaches a majority.")).toBeTruthy();
    expect(lastReport()).toMatchObject({ status: "empty", code: "NO_DATA" });
  });

  test("a pending $from binding shows skeleton rows and reports pending", () => {
    const { container, lastReport } = renderDataTree({
      view: {
        type: "Table",
        columns: [{ key: "x", label: "X" }],
        rows: { $from: { toolCall: "call_7", path: "$.rows[*]", state: "pending" } },
      },
    });
    expect(container.querySelectorAll("[aria-busy=true]")).toHaveLength(3);
    expect(lastReport()).toEqual({ status: "pending" });
  });

  test("the short form shows at most maxRepeatRows rows and reports the rest", () => {
    const maxRepeatRows = composedNodeSchema.budgets.maxRepeatRows;
    const { container, lastReport } = renderDataTree({
      view: {
        type: "Table",
        columns: [{ key: "n", label: "N" }],
        rows: Array.from({ length: maxRepeatRows + 5 }, (unused, rowIndex) => ({ n: rowIndex })),
      },
    });
    expect(container.querySelectorAll("tbody tr")).toHaveLength(maxRepeatRows);
    expect(lastReport()).toMatchObject({ status: "failed", code: "BUDGET_EXCEEDED" });
  });

  test("a source list shows one chip per page, links in the chat and plain text in the e-mail capture", () => {
    const widgetFields = {
      view: {
        type: "Table",
        columns: [
          { key: "item", label: "Model" },
          { key: "figure", label: "Range" },
        ],
        rows: [
          { item: "ID.3", figure: 426 },
          { item: "ID.4", figure: 550 },
        ],
        source: [
          { name: "volkswagen.de ID.3", url: "https://www.volkswagen.de/id3" },
          { name: "volkswagen.de ID.4", url: "https://www.volkswagen.de/id4" },
          { url: "https://www.adac.de/test" },
          { name: "" },
        ],
      },
    };
    const { findings, container, unmount } = renderDataTree(widgetFields);
    expect(findings).toEqual([]);
    expect(Array.from(container.querySelectorAll("a")).map((link) => link.getAttribute("href"))).toEqual([
      "https://www.volkswagen.de/id3",
      "https://www.volkswagen.de/id4",
      "https://www.adac.de/test",
    ]);
    expect(screen.getByText("www.adac.de")).toBeTruthy();
    unmount();

    const emailRender = renderDataTree(widgetFields, { isEmailRender: true });
    expect(emailRender.container.querySelectorAll("a")).toHaveLength(0);
    expect(screen.getByText("volkswagen.de ID.4")).toBeTruthy();
  });

  test("a TableRow at the root renders a valid one-row table", () => {
    const { container, findings } = renderDataTree({
      view: { type: "TableRow", children: [{ type: "TableCell", value: "alone" }] },
    });
    expect(findings).toEqual([]);
    expect(container.querySelector("table tbody tr td")?.textContent).toBe("alone");
  });

  test("model text shows as written — markup in a cell or a header is text, never HTML", () => {
    const { container } = renderDataTree({
      view: {
        type: "Table",
        columns: [{ key: "name", label: "<em>Name</em>" }],
        rows: [{ name: "<b>Bäckerei</b> & Café <img src=x>" }],
      },
    });
    expect(container.querySelector("b, em, img")).toBeNull();
    expect(container.querySelector("td")?.textContent).toBe("<b>Bäckerei</b> & Café <img src=x>");
    expect(container.querySelector("th")?.textContent).toBe("<em>Name</em>");
  });
});
