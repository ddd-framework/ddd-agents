// @vitest-environment jsdom
import { afterEach, describe, expect, test } from "vitest";
import { act, cleanup, fireEvent, screen } from "@testing-library/react";
import { renderInputSpecTree, stateProbe } from "./shared/composed-input-spec-tree";
import { formatComposedNumber, snapToSliderStep } from "./shared/composed-input-values";

/** `Slider`, the sliders of the revenue and MRR simulators. */
describe("Slider — a number between min and max", () => {
  afterEach(cleanup);

  const visitorsTree = {
    state: { visitors: 35000 },
    view: {
      type: "InputSpecStack",
      children: [
        {
          type: "Slider",
          label: "Visitors / month",
          min: 0,
          max: 100000,
          step: 1000,
          value: { $state: "visitors" },
          onChange: { set: "visitors" },
        },
        stateProbe("visitors"),
      ],
    },
  };

  test("shows the label and the formatted value, and moving the thumb writes the new value", () => {
    const { reports, findingCodes } = renderInputSpecTree(visitorsTree);
    expect(findingCodes).toEqual([]);
    expect(screen.getByText("Visitors / month")).toBeTruthy();
    expect(screen.getByText("35,000")).toBeTruthy();
    const thumb = screen.getByRole("slider");
    act(() => {
      fireEvent.change(thumb, { target: { value: 36000 } });
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe("36000");
    expect(screen.getByText("36,000")).toBeTruthy();
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("is frozen on a replay", () => {
    renderInputSpecTree(visitorsTree, { interactive: false });
    act(() => {
      fireEvent.change(screen.getByRole("slider"), { target: { value: 36000 } });
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe("35000");
  });

  test("an invalid range renders nothing and reports failed", () => {
    const { reports } = renderInputSpecTree({ view: { type: "Slider", min: 10, max: 10 } });
    expect(screen.queryByRole("slider")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });

  test("formats with the unit and the step's decimals and snaps onto the step grid", () => {
    expect(formatComposedNumber(3, 1, "%", "en")).toBe("3.0\u00a0%");
    expect(formatComposedNumber(73500, 0, "€", "de")).toBe("73.500\u00a0€");
    expect(formatComposedNumber(51, 0, "/100", "de")).toBe("51/100");
    expect(snapToSliderStep(3.04, 0.5, 8, 0.1)).toBe(3);
    expect(snapToSliderStep(120, 0, 100, 5)).toBe(100);
  });
});
