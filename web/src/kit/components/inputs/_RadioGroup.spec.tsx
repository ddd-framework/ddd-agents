// @vitest-environment jsdom
import { afterEach, describe, expect, test } from "vitest";
import { act, cleanup, fireEvent, screen } from "@testing-library/react";
import { renderInputSpecTree, stateProbe } from "./shared/composed-input-spec-tree";

/** `RadioGroup`, one of a few explained choices (ChatGPT's RadioGroup). */
describe("RadioGroup — radio buttons", () => {
  afterEach(cleanup);

  const goalTree = {
    state: { goal: "calls" },
    view: {
      type: "InputSpecStack",
      children: [
        {
          type: "RadioGroup",
          label: "Goal",
          options: [
            { id: "calls", label: "More calls", description: "Phone calls from Google" },
            { id: "visits", label: "More visits" },
          ],
          value: { $state: "goal" },
          onChange: { set: "goal" },
        },
        stateProbe("goal"),
      ],
    },
  };

  test("shows the choices with their explanation and writes the pick", () => {
    const { reports, findingCodes } = renderInputSpecTree(goalTree);
    expect(findingCodes).toEqual([]);
    expect(screen.getByText("Phone calls from Google")).toBeTruthy();
    expect((screen.getByLabelText(/More calls/) as HTMLInputElement).checked).toBe(true);
    act(() => {
      fireEvent.click(screen.getByLabelText("More visits"));
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe('"visits"');
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("is frozen in the e-mail capture", () => {
    renderInputSpecTree(goalTree, { interactive: false, isEmailRender: true });
    act(() => {
      fireEvent.click(screen.getByLabelText("More visits"));
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe('"calls"');
  });

  test("no usable option renders nothing and reports failed", () => {
    const { reports } = renderInputSpecTree({ view: { type: "RadioGroup", options: [{ id: "x" }] } });
    expect(screen.queryByRole("radio")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });
});
