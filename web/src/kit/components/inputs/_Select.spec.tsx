// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test } from "vitest";
import { act, cleanup, fireEvent, screen, within } from "@testing-library/react";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../../provider/environment";
import { renderInputSpecTree, stateProbe } from "./shared/composed-input-spec-tree";

/** `Select`, the channel drop-down of the analytics studio, with the package's default drop-down (a native select). */
describe("Select — the drop-down", () => {
  let environmentBefore: AgentsEnvironment;

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    setAgentsEnvironment({});
  });

  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  const channelTree = {
    state: { channel: "all" },
    view: {
      type: "InputSpecStack",
      children: [
        {
          type: "Select",
          label: "Channel",
          options: [
            { id: "all", label: "All channels" },
            { id: "organic", label: "Organic" },
            { id: "paid", label: "Paid" },
          ],
          value: { $state: "channel" },
          onChange: { set: "channel" },
        },
        stateProbe("channel"),
      ],
    },
  };

  test("shows the picked choice and writes a new pick to the state", () => {
    const { reports, findingCodes } = renderInputSpecTree(channelTree);
    expect(findingCodes).toEqual([]);
    expect(screen.getByText("Channel")).toBeTruthy();
    const combobox = screen.getByRole("combobox") as HTMLSelectElement;
    expect(combobox.selectedOptions[0]?.textContent).toBe("All channels");
    act(() => {
      fireEvent.change(combobox, { target: { value: "organic" } });
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe('"organic"');
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("an option written with ChatGPT's `value` key still works (the reader flags it, the block still mounts)", () => {
    const { findingCodes } = renderInputSpecTree({
      state: { channel: "paid" },
      view: { type: "Select", options: [{ value: "paid", label: "Paid" }], value: { $state: "channel" } },
    });
    expect(findingCodes).toEqual(["INLINE_VALUE_WITHOUT_SOURCE"]);
    expect(screen.getByRole("combobox").textContent).toContain("Paid");
  });

  test("a model label with markup characters shows as written, in the menu and the closed field", () => {
    renderInputSpecTree({
      state: { dish: "fish" },
      view: {
        type: "Select",
        label: "Dish <b>today</b>",
        options: [{ id: "fish", label: "Fish & Chips <i>large</i>" }, "Soup"],
        value: { $state: "dish" },
        onChange: { set: "dish" },
      },
    });
    expect(screen.getByText("Dish <b>today</b>")).toBeTruthy();
    const combobox = screen.getByRole("combobox") as HTMLSelectElement;
    expect(combobox.selectedOptions[0]?.textContent).toBe("Fish & Chips <i>large</i>");
    expect(within(combobox).getByRole("option", { name: "Fish & Chips <i>large</i>" })).toBeTruthy();
  });

  test("on a replay and in the e-mail capture shows the picked choice as plain text, no drop-down", () => {
    renderInputSpecTree(channelTree, { interactive: false });
    expect(screen.queryByRole("combobox")).toBeNull();
    expect(screen.getByText("All channels")).toBeTruthy();
    cleanup();
    renderInputSpecTree(channelTree, { interactive: false, isEmailRender: true });
    expect(screen.getByText("All channels")).toBeTruthy();
  });

  test("no usable option renders nothing and reports failed", () => {
    const { reports } = renderInputSpecTree({ view: { type: "Select", options: [] } });
    expect(screen.queryByRole("combobox")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });
});
