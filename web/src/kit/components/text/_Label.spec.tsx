// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderTextNodeTree } from "./shared/render-text-node-tree";

describe("Label — the short label node", () => {
  test("renders the model's label untranslated and reports mounted", () => {
    const { reports } = renderTextNodeTree({ view: { type: "Label", text: "Zeitraum" } });
    expect(screen.getByText("Zeitraum").className).toMatch(/label/);
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a label bound to an unknown state is dropped by the reader and reported", () => {
    const { reports, findingCodes } = renderTextNodeTree({ view: { type: "Label", text: { $state: "missing" } } });
    expect(findingCodes).toEqual(["UNKNOWN_BINDING", "INVALID_NODE"]);
    expect(reports.at(-1)).toMatchObject({ status: "failed" });
  });
});
