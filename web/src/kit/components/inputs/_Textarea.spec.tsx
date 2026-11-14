// @vitest-environment jsdom
import { afterEach, describe, expect, test } from "vitest";
import { act, cleanup, fireEvent, screen } from "@testing-library/react";
import { renderInputSpecTree, stateProbe } from "./shared/composed-input-spec-tree";

/** `Textarea`, a multi-line field (ChatGPT's Textarea). */
describe("Textarea — a multi-line field", () => {
  afterEach(cleanup);

  const noteTree = {
    state: { note: "Open on Sundays" },
    view: {
      type: "InputSpecStack",
      children: [
        {
          type: "Textarea",
          label: "Note",
          rows: 4,
          maxLength: 40,
          value: { $state: "note" },
          onChange: { set: "note" },
        },
        stateProbe("note"),
      ],
    },
  };

  test("shows the state text and writes what is typed", () => {
    const { reports, findingCodes } = renderInputSpecTree(noteTree);
    expect(findingCodes).toEqual([]);
    const field = screen.getByLabelText("Note") as HTMLTextAreaElement;
    expect(field.value).toBe("Open on Sundays");
    act(() => {
      fireEvent.change(field, { target: { value: "Closed on Mondays" } });
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe('"Closed on Mondays"');
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("in the e-mail capture shows the text as plain text, no field", () => {
    renderInputSpecTree(noteTree, { interactive: false, isEmailRender: true });
    expect(screen.queryByRole("textbox")).toBeNull();
    expect(screen.getByText("Open on Sundays")).toBeTruthy();
  });
});
