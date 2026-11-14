// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { PENDING_FROM_BINDING, renderTextNodeTree } from "./shared/render-text-node-tree";

describe("Title — the heading node of the ComposedView kit", () => {
  test("renders the model's heading at the widget level and reports mounted", () => {
    const { reports } = renderTextNodeTree({ view: { type: "Title", text: "Rechnerische Koalitionsmöglichkeiten" } });
    const heading = screen.getByRole("heading", { name: "Rechnerische Koalitionsmöglichkeiten" });
    expect(heading.getAttribute("aria-level")).toBe("3");
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a section title is one level lower; a bound text follows its state", () => {
    renderTextNodeTree({
      state: { heading: "Kanal-Karussell" },
      view: { type: "Title", text: { $state: "heading" }, level: "section" },
    });
    expect(screen.getByRole("heading", { name: "Kanal-Karussell" }).getAttribute("aria-level")).toBe("4");
  });

  test("a heading waiting for its data is a skeleton and reports pending", () => {
    const { reports } = renderTextNodeTree({
      view: { type: "Title", text: PENDING_FROM_BINDING },
    });
    expect(screen.queryByRole("heading")).toBeNull();
    expect(reports.at(-1)).toEqual({ status: "pending" });
  });

  test("a heading without text is dropped by the reader and the block reports it", () => {
    const { reports, findingCodes } = renderTextNodeTree({ view: { type: "Title" } });
    expect(findingCodes).toEqual(["INVALID_NODE"]);
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_NODE" });
  });

  test("an empty bound heading renders nothing and reports empty", () => {
    const { reports } = renderTextNodeTree({
      state: { heading: "" },
      view: { type: "Title", text: { $state: "heading" } },
    });
    expect(screen.queryByRole("heading")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "empty", code: "NO_DATA" });
  });
});
