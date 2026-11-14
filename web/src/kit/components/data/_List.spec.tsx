// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderDataTree } from "./render-data-tree.spec-helper";

describe("List", () => {
  test("short-form entries, then ListItem children with a trailing icon, numbered as one sequence", () => {
    const { container, findings, lastReport } = renderDataTree({
      view: {
        type: "List",
        marker: "number",
        items: [
          "Open on Sunday",
          { title: "Parking", description: "Behind the building", meta: "since 2024", value: "12 spots" },
        ],
        children: [
          {
            type: "ListItem",
            title: "Wi-Fi",
            slots: { trailing: [{ type: "Icon", name: "check", tone: "success", label: "Available" }] },
          },
        ],
      },
    });
    // A short-form entry with a `value` and no `source` is the core's flag for an unsourced inline value — kept, rendered.
    expect(findings.map((finding) => finding.code)).toEqual(["INLINE_VALUE_WITHOUT_SOURCE"]);
    expect(container.querySelector("ol")).toBeTruthy();
    expect(container.querySelectorAll("li")).toHaveLength(3);
    expect(screen.getByText("Behind the building")).toBeTruthy();
    expect(screen.getByText("12 spots")).toBeTruthy();
    expect(screen.getByRole("img", { name: "Available" })).toBeTruthy();
    // Rendered; the value without a source is a note for the model, not a failure.
    expect(lastReport()).toMatchObject({ status: "mounted", code: "INLINE_VALUE_WITHOUT_SOURCE" });
  });

  test("a Repeat of ListItems inside the list", () => {
    const { container, findings } = renderDataTree({
      data: {
        reviews: [
          { author: "Anna", text: "Great coffee" },
          { author: "Ben", text: "Friendly staff" },
        ],
      },
      view: {
        type: "List",
        marker: "bullet",
        children: [
          {
            type: "Repeat",
            items: { $data: "reviews" },
            children: [{ type: "ListItem", title: { $item: "author" }, description: { $item: "text" } }],
          },
        ],
      },
    });
    expect(findings).toEqual([]);
    expect(container.querySelectorAll("ul > li")).toHaveLength(2);
    expect(screen.getByText("Friendly staff")).toBeTruthy();
  });

  test("no entries show one muted line and report empty", () => {
    const { lastReport } = renderDataTree({ data: { none: [] }, view: { type: "List", items: { $data: "none" } } });
    expect(screen.getByText("No data to show.")).toBeTruthy();
    expect(lastReport()).toMatchObject({ status: "empty" });
  });
});
