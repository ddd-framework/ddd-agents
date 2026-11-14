// @vitest-environment jsdom
import { afterEach, describe, expect, test, vi } from "vitest";
import { act, cleanup, fireEvent, screen } from "@testing-library/react";
import { renderInputSpecTree, stateProbe } from "./shared/composed-input-spec-tree";
import { COPIED_CONFIRMATION_MS } from "./Button.composed";

/** `Button`, "Copy result" / "Reset" of the simulators. */
describe("Button — runs a tree action or writes a value", () => {
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });

  const simulatorTree = {
    state: { visitors: 35000 },
    actions: {
      copyResult: { type: "copy", value: { $state: "visitors" } },
      reset: { type: "resetState" },
    },
    view: {
      type: "InputSpecStack",
      children: [
        { type: "Button", label: "Set 50,000", onClick: { set: "visitors" }, value: 50000, variant: "secondary" },
        { type: "Button", label: "Reset", onClick: { action: "reset" }, icon: "reset", variant: "secondary" },
        { type: "Button", label: "Copy result", onClick: { action: "copyResult" }, icon: "copy", fullWidth: true },
        stateProbe("visitors"),
      ],
    },
  };

  test("a set click writes its value, reset restores the initial state, copy copies the bound value", () => {
    const writeText = vi.fn(async () => {});
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
    const { reports, findingCodes } = renderInputSpecTree(simulatorTree);
    expect(findingCodes).toEqual([]);
    act(() => {
      fireEvent.click(screen.getByText("Set 50,000"));
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe("50000");
    act(() => {
      fireEvent.click(screen.getByText("Copy result"));
    });
    expect(writeText).toHaveBeenCalledWith("50000");
    act(() => {
      fireEvent.click(screen.getByText("Reset"));
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe("35000");
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a copy button confirms the copy, then shows its label again", () => {
    vi.useFakeTimers();
    try {
      const writeText = vi.fn(async () => {});
      Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
      renderInputSpecTree(simulatorTree);
      act(() => {
        fireEvent.click(screen.getByText("Copy result"));
      });
      expect(screen.getByText("Copied")).toBeTruthy();
      expect(screen.queryByText("Copy result")).toBeNull();
      // A button that runs another action never says "Copied".
      act(() => {
        fireEvent.click(screen.getByText("Reset"));
      });
      expect(screen.getAllByText("Copied")).toHaveLength(1);
      act(() => {
        vi.advanceTimersByTime(COPIED_CONFIRMATION_MS);
      });
      expect(screen.getByText("Copy result")).toBeTruthy();
      expect(screen.queryByText("Copied")).toBeNull();
    } finally {
      vi.useRealTimers();
    }
  });

  test("is a plain button, never a form submit", () => {
    renderInputSpecTree(simulatorTree);
    for (const buttonElement of screen.getAllByRole("button"))
      expect(buttonElement.getAttribute("type")).toBe("button");
  });

  test("is absent on a replay and in the e-mail capture", () => {
    renderInputSpecTree(simulatorTree, { interactive: false });
    expect(screen.queryByText("Set 50,000")).toBeNull();
    expect(screen.getByTestId("input-spec-probe").textContent).toBe("35000");
    cleanup();
    renderInputSpecTree(simulatorTree, { interactive: false, isEmailRender: true });
    expect(screen.queryByText("Copy result")).toBeNull();
  });

  test("a button whose action the tree does not declare is dropped by the reader", () => {
    const { findingCodes } = renderInputSpecTree({
      view: { type: "Button", label: "Ask the agent", onClick: { action: "sendChatMessage" } },
    });
    expect(findingCodes).toEqual(["UNKNOWN_BINDING", "INVALID_NODE"]);
    expect(screen.queryByText("Ask the agent")).toBeNull();
  });
});
