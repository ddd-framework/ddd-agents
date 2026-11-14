// @vitest-environment jsdom
import { describe, expect, test } from "vitest";
import { screen } from "@testing-library/react";
import { renderDataTree } from "./render-data-tree.spec-helper";

describe("Icon", () => {
  test("a labelled icon is an image with its name", () => {
    const { findings, lastReport } = renderDataTree({
      view: { type: "Icon", name: "check", tone: "success", label: "Majority" },
    });
    expect(findings).toEqual([]);
    expect(screen.getByRole("img", { name: "Majority" })).toBeTruthy();
    expect(lastReport()).toEqual({ status: "mounted" });
  });

  test("a bound name that is not an icon renders nothing and reports failed", () => {
    const { container, findings, lastReport } = renderDataTree({
      state: { iconName: "rocket" },
      view: { type: "Icon", name: { $state: "iconName" } },
    });
    expect(findings).toEqual([]);
    expect(container.querySelector("[role=img]")).toBeNull();
    expect(lastReport()).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });

  test("an unknown literal name is dropped by the reader", () => {
    const { findings, lastReport } = renderDataTree({ view: { type: "Icon", name: "rocket" } });
    expect(findings.map((finding) => finding.code)).toEqual(["INVALID_PROP", "INVALID_NODE"]);
    expect(lastReport()).toMatchObject({ status: "failed" });
  });
});
