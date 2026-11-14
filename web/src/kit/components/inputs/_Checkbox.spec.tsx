// @vitest-environment jsdom
import { afterEach, describe, expect, test } from "vitest";
import { act, cleanup, fireEvent, screen } from "@testing-library/react";
import { renderInputSpecTree, stateProbe } from "./shared/composed-input-spec-tree";

/** `Checkbox`, "show comparison values" of the analytics studio. */
describe("Checkbox — one option on or off", () => {
  afterEach(cleanup);

  const benchmarkTree = {
    state: { benchmark: true },
    view: {
      type: "InputSpecStack",
      children: [
        {
          type: "Checkbox",
          label: "Show comparison values (90 % of the visitors)",
          checked: { $state: "benchmark" },
          onChange: { set: "benchmark" },
        },
        stateProbe("benchmark"),
      ],
    },
  };

  test("is ticked from the state and writes false when cleared", () => {
    const { reports, findingCodes } = renderInputSpecTree(benchmarkTree);
    expect(findingCodes).toEqual([]);
    const checkbox = screen.getByRole("checkbox") as HTMLInputElement;
    expect(checkbox.checked).toBe(true);
    act(() => {
      fireEvent.click(screen.getByText("Show comparison values (90 % of the visitors)"));
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe("false");
    expect(checkbox.checked).toBe(false);
    expect(screen.getByText("Show comparison values (90 % of the visitors)")).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("is frozen on a replay", () => {
    renderInputSpecTree(benchmarkTree, { interactive: false });
    act(() => {
      fireEvent.click(screen.getByText("Show comparison values (90 % of the visitors)"));
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe("true");
  });

  test("a checkbox without a label is dropped by the reader", () => {
    const { reports, findingCodes } = renderInputSpecTree({ view: { type: "Checkbox" } });
    expect(findingCodes).toEqual(["INVALID_NODE"]);
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_NODE" });
  });
});
