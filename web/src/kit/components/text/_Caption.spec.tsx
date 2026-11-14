// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { PENDING_FROM_BINDING, renderTextNodeTree } from "./shared/render-text-node-tree";

describe("Caption — the muted secondary text node", () => {
  test("renders the caption and reports mounted", () => {
    const { reports } = renderTextNodeTree({ view: { type: "Caption", text: "Stand: 19. Oktober 2026" } });
    expect(screen.getByText("Stand: 19. Oktober 2026")).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a caption inside a Text is inline", () => {
    renderTextNodeTree({ view: { type: "Text", text: "94", children: [{ type: "Caption", text: "Sitze" }] } });
    expect(screen.getByText("Sitze")).toBeTruthy();
  });

  test("pending and empty are reported", () => {
    const pending = renderTextNodeTree({
      view: { type: "Caption", text: PENDING_FROM_BINDING },
    });
    expect(pending.reports.at(-1)).toEqual({ status: "pending" });
    const empty = renderTextNodeTree({ state: { note: "" }, view: { type: "Caption", text: { $state: "note" } } });
    expect(empty.reports.at(-1)).toMatchObject({ status: "empty" });
  });
});
