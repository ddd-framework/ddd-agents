// @vitest-environment jsdom
import { afterEach, describe, expect, test } from "vitest";
import { act, cleanup, fireEvent, screen } from "@testing-library/react";
import { renderInputSpecTree, stateProbe } from "./shared/composed-input-spec-tree";

/** `Tabs`, the segmented control of the analytics dashboard. */
describe("Tabs — the segmented control", () => {
  afterEach(cleanup);

  const periodTree = {
    state: { period: "7d" },
    view: {
      type: "InputSpecStack",
      children: [
        {
          type: "Tabs",
          label: "Period",
          options: [
            { id: "7d", label: "7 days" },
            { id: "30d", label: "30 days" },
          ],
          value: { $state: "period" },
          onChange: { set: "period" },
        },
        stateProbe("period"),
      ],
    },
  };

  test("shows the choices, writes the picked id to the state and reports mounted", () => {
    const { reports, findingCodes } = renderInputSpecTree(periodTree);
    expect(findingCodes).toEqual([]);
    expect(screen.getByText("Period")).toBeTruthy();
    expect(screen.getByRole("tab", { name: "7 days" }).getAttribute("aria-selected")).toBe("true");
    act(() => {
      fireEvent.click(screen.getByRole("tab", { name: "30 days" }));
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe('"30d"');
    expect(screen.getByRole("tab", { name: "30 days" }).getAttribute("aria-selected")).toBe("true");
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a label list works as the choices and a tab without a binding keeps its own pick", () => {
    renderInputSpecTree({ view: { type: "Tabs", options: ["Bear", "Base", "Bull"] } });
    expect(screen.getByRole("tab", { name: "Bear" }).getAttribute("aria-selected")).toBe("true");
    act(() => {
      fireEvent.click(screen.getByRole("tab", { name: "Bull" }));
    });
    expect(screen.getByRole("tab", { name: "Bull" }).getAttribute("aria-selected")).toBe("true");
  });

  test("is frozen on a replay: a click changes nothing", () => {
    renderInputSpecTree(periodTree, { interactive: false });
    act(() => {
      fireEvent.click(screen.getByRole("tab", { name: "30 days" }));
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe('"7d"');
  });

  test("no usable option renders nothing and reports failed", () => {
    const { reports } = renderInputSpecTree({ view: { type: "Tabs", options: [{ description: "no label" }, ""] } });
    expect(screen.queryByRole("tab")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });
});
