// @vitest-environment jsdom
import type { ReactNode } from "react";
import { act, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { AgentsProvider } from "../../provider/AgentsProvider";
import type { AgentsOwnerSettings } from "../../provider/environment";
import { ConversationWidgetRenderContext } from "../../renderer/ConversationWidgetRenderContext";
import {
  WidgetRenderReportContext,
  type WidgetRenderReportEntry,
} from "../../renderer/render-report/widget-render-report";
import { ClockConversationWidget } from "./ClockConversationWidget";
import {
  formatHourDifference,
  formatUtcOffset,
  isDaytime,
  isKnownTimeZone,
  readCityOfTimeZone,
  readRelativeDay,
  readZonedWallClock,
} from "./shared/clock-time";
import { MAX_CLOCKS, parseClockPayload } from "./shared/clock-widget-payload";

// The reference night: 02:58 CET in Berlin, the first night after the DST change.
const REFERENCE_INSTANT = new Date("2026-10-26T01:58:00Z");

const WORLD_CLOCK_PAYLOAD = JSON.stringify({
  title: "Berlin, New York, Tokio",
  clocks: [
    { timeZone: "Europe/Berlin", label: "Berlin, Deutschland" },
    { timeZone: "America/New_York", label: "New York, NY, USA" },
    { timeZone: "Asia/Tokyo", label: "Tokio, Japan" },
  ],
});

/** The owner settings the host supplies (`AgentsEnvironment.useOwnerSettings`). */
const buildOwnerSettings = (ownerTimeZone: string | null, usesAmPm = false): AgentsOwnerSettings => ({
  languageCode: "en",
  dateLocale: "en-GB",
  usesAmPm,
  timeZone: ownerTimeZone,
});

const renderClock = (
  payload: string,
  options: { ownerTimeZone?: string | null; useAmPm?: boolean; email?: boolean } = {},
) => {
  const reports: WidgetRenderReportEntry[] = [];
  const reporter = { report: (entry: WidgetRenderReportEntry) => reports.push(entry) };
  const ownerSettings = buildOwnerSettings(
    options.ownerTimeZone === undefined ? "Europe/Berlin" : options.ownerTimeZone,
    options.useAmPm,
  );
  const wrap = (children: ReactNode) => (
    <AgentsProvider environment={{ useOwnerSettings: () => ownerSettings }}>
      <ConversationWidgetRenderContext.Provider value={options.email ? "email" : "conversation"}>
        <WidgetRenderReportContext.Provider value={reporter}>{children}</WidgetRenderReportContext.Provider>
      </ConversationWidgetRenderContext.Provider>
    </AgentsProvider>
  );
  const view = render(wrap(<ClockConversationWidget payload={payload} interactive />));
  return { ...view, reports };
};

const readSecondHandRotation = (rowIndex = 0): string | null =>
  screen.getAllByTestId("clock-second-hand")[rowIndex]?.getAttribute("transform") ?? null;

describe("parseClockPayload", () => {
  it("reads the canonical shape", () => {
    expect(parseClockPayload(WORLD_CLOCK_PAYLOAD)).toEqual({
      title: "Berlin, New York, Tokio",
      clocks: [
        { timeZone: "Europe/Berlin", label: "Berlin, Deutschland" },
        { timeZone: "America/New_York", label: "New York, NY, USA" },
        { timeZone: "Asia/Tokyo", label: "Tokio, Japan" },
      ],
    });
  });

  it("accepts the aliases a weak model writes — ChatGPT's own field names, plain ids, one clock at the top level", () => {
    expect(
      parseClockPayload(
        JSON.stringify({
          time_format: "12h",
          zones: [
            { parameters: { tz_name: "Asia/Tokyo", location: "Tokio", tz_alias: "JST" } },
            "Europe/Paris",
            { city: "No zone" },
          ],
        }),
      ),
    ).toEqual({
      timeFormat: "12h",
      clocks: [{ timeZone: "Asia/Tokyo", label: "Tokio", abbreviation: "JST" }, { timeZone: "Europe/Paris" }],
    });
    expect(parseClockPayload(JSON.stringify({ tz: "Asia/Tokyo", label: "Tokio" })).clocks).toEqual([
      { timeZone: "Asia/Tokyo", label: "Tokio" },
    ]);
  });

  it("caps the list and never throws on malformed input", () => {
    const manyClocks = Array.from({ length: 20 }, () => ({ timeZone: "UTC" }));
    expect(parseClockPayload(JSON.stringify({ clocks: manyClocks })).clocks).toHaveLength(MAX_CLOCKS);
    expect(parseClockPayload("{not json")).toEqual({ clocks: [] });
    expect(parseClockPayload(JSON.stringify([1, 2]))).toEqual({ clocks: [] });
    expect(parseClockPayload(JSON.stringify({ clocks: "Europe/Berlin" }))).toEqual({ clocks: [] });
  });
});

describe("clock-time", () => {
  it("knows IANA ids and refuses abbreviations, raw offsets and invented zones", () => {
    expect(isKnownTimeZone("Europe/Berlin")).toBe(true);
    expect(isKnownTimeZone("America/Argentina/Buenos_Aires")).toBe(true);
    expect(isKnownTimeZone("UTC")).toBe(true);
    expect(isKnownTimeZone("JST")).toBe(false);
    expect(isKnownTimeZone("+01:00")).toBe(false);
    expect(isKnownTimeZone("Mars/Olympus_Mons")).toBe(false);
  });

  it("reads the wall clock, the UTC offset, the relative day and day or night of each zone", () => {
    const berlin = readZonedWallClock(REFERENCE_INSTANT, "Europe/Berlin");
    const newYork = readZonedWallClock(REFERENCE_INSTANT, "America/New_York");
    const tokyo = readZonedWallClock(REFERENCE_INSTANT, "Asia/Tokyo");
    expect([berlin.hour, berlin.minute, berlin.utcOffsetMinutes]).toEqual([2, 58, 60]);
    expect([newYork.hour, newYork.minute, newYork.utcOffsetMinutes]).toEqual([21, 58, -240]);
    expect([tokyo.hour, tokyo.minute, tokyo.utcOffsetMinutes]).toEqual([10, 58, 540]);
    expect(readRelativeDay(newYork, berlin)).toBe("yesterday");
    expect(readRelativeDay(tokyo, berlin)).toBe("today");
    expect(readRelativeDay(berlin, newYork)).toBe("tomorrow");
    expect(isDaytime(berlin)).toBe(false);
    expect(isDaytime(tokyo)).toBe(true);
  });

  it("formats differences and offsets, half hours included", () => {
    expect(formatHourDifference(0)).toBe("+0");
    expect(formatHourDifference(-300)).toBe("−5");
    expect(formatHourDifference(270)).toBe("+4:30");
    expect(formatUtcOffset(0)).toBe("UTC");
    expect(formatUtcOffset(60)).toBe("UTC+1");
    expect(formatUtcOffset(330)).toBe("UTC+5:30");
    expect(readCityOfTimeZone("America/New_York")).toBe("New York");
  });
});

describe("ClockConversationWidget", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
    vi.setSystemTime(REFERENCE_INSTANT);
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("renders the world clock: time, place, day and difference to the owner's zone per row", () => {
    const { reports } = renderClock(WORLD_CLOCK_PAYLOAD);
    expect(screen.getByText("Berlin, New York, Tokio")).toBeTruthy();
    expect(screen.getAllByTestId("clock-row")).toHaveLength(3);
    const digitalTimes = screen.getAllByTestId("clock-digital-time").map((digitalTime) => digitalTime.textContent);
    expect(digitalTimes).toEqual(["02:58", "21:58", "10:58"]);
    expect(screen.getByText(/^Berlin, Deutschland/)).toBeTruthy();
    expect(screen.getByText(/Yesterday, −5 h/)).toBeTruthy();
    expect(screen.getByText(/Today, \+8 h/)).toBeTruthy();
    expect(screen.getByText(/UTC−4/)).toBeTruthy();
    expect(screen.getAllByTestId("clock-moon")).toHaveLength(2);
    expect(screen.getAllByTestId("clock-sun")).toHaveLength(1);
    expect(reports.at(-1)).toEqual({ status: "mounted" });
  });

  it("ticks every second in the browser — no request, the second hand moves", () => {
    renderClock(WORLD_CLOCK_PAYLOAD);
    const rotationBefore = readSecondHandRotation();
    act(() => {
      vi.advanceTimersByTime(1000);
    });
    expect(readSecondHandRotation()).not.toBe(rotationBefore);
    act(() => {
      vi.advanceTimersByTime(60_000);
    });
    expect(screen.getAllByTestId("clock-digital-time")[0]?.textContent).toBe("02:59");
  });

  it("shows the 12-hour format the owner settings prefer, and the payload's format wins over it", () => {
    renderClock(JSON.stringify({ clocks: [{ timeZone: "America/New_York", label: "New York" }] }), { useAmPm: true });
    expect(screen.getByTestId("clock-digital-time").textContent).toMatch(/^9:58\s?PM$/);
  });

  it("drops an unknown zone id, renders the rest and reports failed with the dropped id", () => {
    const { reports } = renderClock(
      JSON.stringify({
        clocks: [
          { timeZone: "Asia/Tokyo", label: "Tokio" },
          { timeZone: "JST", label: "Osaka" },
        ],
      }),
    );
    expect(screen.getAllByTestId("clock-row")).toHaveLength(1);
    expect(reports.at(-1)).toEqual({
      status: "failed",
      code: "INVALID_PROP",
      detail: "Unknown time zone ids dropped: JST.",
    });
  });

  it("renders one muted line and reports failed when no zone is known, empty when there is none", () => {
    const failedRender = renderClock(JSON.stringify({ clocks: ["Mars/Olympus_Mons"] }));
    expect(screen.getByText("The time zones of this clock are unknown.")).toBeTruthy();
    expect(failedRender.reports.at(-1)?.status).toBe("failed");
    failedRender.unmount();
    const emptyRender = renderClock(JSON.stringify({ clocks: [] }));
    expect(screen.getByText("No time zone to show.")).toBeTruthy();
    expect(emptyRender.reports.at(-1)).toEqual({
      status: "empty",
      code: "NO_DATA",
      detail: "The Clock widget carries no time zone.",
    });
  });

  it("falls back to the browser's zone when the owner settings have none", () => {
    renderClock(JSON.stringify({ clocks: [{ timeZone: "Asia/Tokyo" }] }), { ownerTimeZone: null });
    expect(screen.getByText(/^Tokyo/)).toBeTruthy();
    expect(screen.getAllByTestId("clock-row")).toHaveLength(1);
  });

  it("freezes at the capture moment in an e-mail render and says so", () => {
    renderClock(WORLD_CLOCK_PAYLOAD, { email: true });
    expect(screen.getByText(/As of .*02:58/)).toBeTruthy();
    const rotationBefore = readSecondHandRotation();
    act(() => {
      vi.advanceTimersByTime(5000);
    });
    expect(readSecondHandRotation()).toBe(rotationBefore);
  });
});
