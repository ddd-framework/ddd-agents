// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test } from "vitest";
import { act, cleanup, fireEvent, screen } from "@testing-library/react";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../../provider/environment";
import { renderInputSpecTree, stateProbe } from "./shared/composed-input-spec-tree";
import { formatIsoDay, formatIsoDayForReading, parseIsoDay } from "./shared/composed-input-values";

/** The package's default date field: a native date input, its value a `YYYY-MM-DD` day. */
const readDateField = () => document.querySelector<HTMLInputElement>('input[type="date"]');

/** `DatePicker`, the reference date of the analytics studio, with the package's default date field. */
describe("DatePicker — one day from a calendar", () => {
  let environmentBefore: AgentsEnvironment;

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    setAgentsEnvironment({});
  });

  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  test("shows the state day, writes a picked day to the state and reports mounted", () => {
    const { reports, findingCodes } = renderInputSpecTree({
      state: { asOf: "2026-10-25" },
      view: {
        type: "InputSpecStack",
        children: [
          {
            type: "DatePicker",
            label: "Reference date",
            value: { $state: "asOf" },
            onChange: { set: "asOf" },
            min: "2026-01-01",
          },
          stateProbe("asOf"),
        ],
      },
    });
    expect(findingCodes).toEqual([]);
    expect(screen.getByText("Reference date")).toBeTruthy();
    expect(readDateField()?.value).toBe("2026-10-25");
    expect(readDateField()?.min).toBe("2026-01-01");
    act(() => {
      fireEvent.change(readDateField() as HTMLInputElement, { target: { value: "2026-11-02" } });
    });
    expect(screen.getByTestId("input-spec-probe").textContent).toBe('"2026-11-02"');
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  test("a day is written as YYYY-MM-DD and anything else is no day", () => {
    const timestamp = parseIsoDay("2026-10-25");
    expect(timestamp).toBeDefined();
    expect(formatIsoDay(timestamp as number)).toBe("2026-10-25");
    expect(parseIsoDay("2026-02-30")).toBeUndefined();
    expect(parseIsoDay("25.10.2026")).toBeUndefined();
    expect(parseIsoDay(20261025)).toBeUndefined();
  });

  test("on a replay shows the day as plain text in the date format, no calendar", () => {
    renderInputSpecTree(
      {
        state: { asOf: "2026-10-25" },
        view: { type: "DatePicker", label: "Reference date", value: { $state: "asOf" } },
      },
      { interactive: false },
    );
    expect(readDateField()).toBeNull();
    expect(screen.getByText("25/10/2026")).toBeTruthy();
  });

  test("the frozen value uses the date pattern the editable field shows for the location's locale", () => {
    expect(formatIsoDayForReading("2026-10-25", "de-DE")).toBe("25.10.2026");
    expect(formatIsoDayForReading("2026-10-25", "en-GB")).toBe("25/10/2026");
    expect(formatIsoDayForReading("2026-10-25", "en-US")).toBe("10/25/2026");
    expect(formatIsoDayForReading("tomorrow", "de-DE")).toBeUndefined();
  });

  test("an invalid day shows an empty field and still mounts", () => {
    const { reports } = renderInputSpecTree({ view: { type: "DatePicker", value: "tomorrow" } });
    expect(readDateField()?.value).toBe("");
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });
});
