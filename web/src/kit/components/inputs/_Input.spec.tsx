// @vitest-environment jsdom
import { afterEach, describe, expect, test } from "vitest";
import { act, cleanup, fireEvent, screen } from "@testing-library/react";
import { renderInputSpecTree, stateProbe } from "./shared/composed-input-spec-tree";

/** `Input`, a one-line text or number field (ChatGPT's Input). */
describe("Input — a one-line field", () => {
  afterEach(cleanup);

  const amountTree = (inputType: string) => ({
    state: { amount: inputType === "number" ? 70 : "" },
    view: {
      type: "InputSpecStack",
      children: [
        {
          type: "Input",
          label: "Basket value",
          inputType,
          value: { $state: "amount" },
          onChange: { set: "amount" },
          placeholder: "0",
        },
        stateProbe("amount"),
      ],
    },
  });

  test("a number field writes a number, and null while it is empty", () => {
    const { reports, findingCodes } = renderInputSpecTree(amountTree("number"));
    expect(findingCodes).toEqual([]);
    const field = screen.getByLabelText("Basket value") as HTMLInputElement;
    expect(field.value).toBe("70");
    act(() => {
      fireEvent.change(field, { target: { value: "85" } });
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe("85");
    act(() => {
      fireEvent.change(field, { target: { value: "" } });
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe("null");
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a text field writes the text and respects maxLength", () => {
    renderInputSpecTree({
      state: { name: "" },
      view: {
        type: "InputSpecStack",
        children: [
          { type: "Input", label: "Name", maxLength: 5, value: { $state: "name" }, onChange: { set: "name" } },
          stateProbe("name"),
        ],
      },
    });
    act(() => {
      fireEvent.change(screen.getByLabelText("Name"), { target: { value: "Bäckerei" } });
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe('"Bäcke"');
  });

  test("on a replay shows the value as plain text, no field", () => {
    renderInputSpecTree(amountTree("number"), { interactive: false });
    expect(screen.queryByRole("spinbutton")).toBeNull();
    expect(document.querySelector("[data-frozen-value]")?.textContent).toBe("70");
    expect(screen.getByText("Basket value")).toBeTruthy();
  });

  test("a field without `value` that sets an undeclared name declares it and shows its own set target", () => {
    const { reports, findingCodes } = renderInputSpecTree({
      view: {
        type: "InputSpecStack",
        children: [{ type: "Input", label: "Phone", onChange: { set: "phone" } }, stateProbe("phone")],
      },
    });
    expect(findingCodes).toEqual([]);
    const field = screen.getByLabelText("Phone") as HTMLInputElement;
    expect(field.value).toBe("");
    expect(screen.getByTestId("input-spec-probe").textContent).toBe("null");
    act(() => {
      fireEvent.change(field, { target: { value: "0561 123" } });
    });
    expect(field.value).toBe("0561 123");
    expect(screen.getByTestId("input-spec-probe").textContent).toBe('"0561 123"');
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a field whose state nothing else reads still renders; the block mounts with an UNREAD_STATE note", () => {
    const { reports, findingCodes } = renderInputSpecTree({
      state: { note: "" },
      view: { type: "Input", label: "Note", value: { $state: "note" }, onChange: { set: "note" } },
    });
    expect(findingCodes).toEqual(["UNREAD_STATE"]);
    expect(screen.getByLabelText("Note")).toBeTruthy();
    expect(reports.at(-1)).toMatchObject({ status: "mounted", code: "UNREAD_STATE" });
  });

  test("an unknown input type is dropped by the reader, the field still renders as text", () => {
    const { findingCodes } = renderInputSpecTree({ view: { type: "Input", label: "Secret", inputType: "password" } });
    expect(findingCodes).toEqual(["INVALID_PROP"]);
    expect((screen.getByLabelText("Secret") as HTMLInputElement).type).toBe("text");
  });
});
