// @vitest-environment jsdom

import { WidgetHydrationReducer } from "../../../renderer/widget-hydration";
import { getComposedNodeSchema } from "../../../renderer/widget-tree/composed-node-schema";
import { readComposedViewTree } from "../../../renderer/widget-tree/composed-view-tree";
import { configureStore } from "@reduxjs/toolkit";
import { fireEvent, screen } from "@testing-library/react";
import { Provider } from "react-redux";
import { describe, expect, test, vi } from "vitest";
import { ComposedViewRenderer } from "../../ComposedViewRenderer";
import type { DataValueFormat } from "./data-value-format";
import { readKpiValueParts, readKpiValuePresentation } from "./kpi-value-parts";
import { renderDataTree } from "./render-data-tree.spec-helper";

/**
 * Matches the element that shows a formatted change: the change is its whole text, or its text after the direction
 * glyph the environment's `TrendDelta` may put in front of it in the same element (`▲ +5 %`).
 */
const readDeltaText = (deltaText: string): RegExp =>
  new RegExp(`^(?:[▲▼]\\s)?${deltaText.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}$`);

describe("KpiTile", () => {
  test.each([
    [8, "number", "hero"],
    [0, "number", "hero"],
    [-12.5, "number", "hero"],
    ["12.5", "number", "hero"],
    ["61,4/100", "number", "hero"],
    ["-1 234,5 / 100", "text", "hero"],
    ["+12.5%", "text", "hero"],
    [113498, "currency", "hero"],
    [8200, "compact", "hero"],
    ["61,4/100", "text", "hero"],
    ["Verified", "number", "text"],
    ["Verificat · publicat", "number", "text"],
    ["5/5 · 22 recenzii", "text", "hero"],
    ["19 din 20 corecte", "number", "text"],
    ["Știință, încredere și țară", "number", "text"],
    ["2026-11-06", "date", "text"],
    [12, "date", "text"],
    [null, "number", "hero"],
    ["", "number", "hero"],
    ["unknown 12 status", "currency", "text"],
    [Infinity, "number", "text"],
  ] as [unknown, DataValueFormat, "hero" | "text"][])("presents %s (%s) as %s", (value, format, expected) => {
    expect(readKpiValuePresentation(value, format)).toBe(expected);
  });

  test.each(["Verificat · publicat", "19 din 20 corecte", "Știință & <b>țară</b>"])(
    "preserves text and tone for %s without hero sizing",
    (value) => {
      const { container } = renderDataTree({
        view: { type: "KpiTile", id: "status", label: "Status", value, format: "text", tone: "success" },
      });
      expect(screen.getByText(value)).toBeInTheDocument();
      expect(container.querySelector("[data-hero-value]")).toBeNull();
      expect(screen.getByTestId("composed-view-widget-kpitile-status-value").className).toContain("textSuccess");
      expect(screen.queryByTestId("composed-view-widget-kpitile-status-unit")).toBeNull();
      expect(screen.queryByTestId("composed-view-widget-kpitile-status-footnote")).toBeNull();
    },
  );

  test.each([
    ["61,4/100", { lead: "61,4", denominator: "/100", ratio: { value: 61.4, max: 100 } }],
    ["5/5 · 22 recenzii", { lead: "5", denominator: "/5", secondary: "22 recenzii", ratio: { value: 5, max: 5 } }],
    ["1 234,5 / 2 000", { lead: "1 234,5", denominator: " / 2 000", ratio: { value: 1234.5, max: 2000 } }],
    ["12 • top 3 in Kassel", { lead: "12", secondary: "top 3 in Kassel" }],
    ["120/100", { lead: "120", denominator: "/100", ratio: undefined }],
    ["-3/5", { lead: "-3", denominator: "/5", ratio: undefined }],
    ["42 %", { lead: "42 %" }],
  ] as [string, Record<string, unknown>][])(
    "splits the written score %s without changing a character",
    (value, expected) => {
      const parts = readKpiValueParts(value, value, "text");
      expect(parts).toMatchObject({ presentation: "hero", ...expected });
      expect(
        `${parts.lead}${parts.denominator ?? ""}${parts.secondary ? ` · ${parts.secondary}` : ""}`.replace(
          " • ",
          " · ",
        ),
      ).toBe(value.replace(" • ", " · "));
    },
  );

  test.each(["19 din 20 corecte", "Verificat · publicat", "Top 3 · 12 keywords"])(
    "keeps %s one text headline",
    (value) => {
      expect(readKpiValueParts(value, value, "number")).toEqual({ presentation: "text", lead: value });
    },
  );

  test("a written score shows its hero, the muted denominator, the remark and a meter", () => {
    const { container } = renderDataTree({
      view: { type: "KpiTile", id: "reviews", label: "Reviews", value: "5/5 · 22 recenzii", unit: "Google" },
    });
    const prefix = "composed-view-widget-kpitile-reviews";
    expect(screen.getByTestId(`${prefix}-value`)).toHaveTextContent("5/5");
    expect(container.querySelector("[data-hero-value]")).toContainElement(screen.getByTestId(`${prefix}-value`));
    expect(screen.getByTestId(`${prefix}-value-secondary`)).toHaveTextContent("22 recenzii");
    expect(screen.getByTestId(`${prefix}-unit`)).toHaveTextContent("22 recenziiGoogle");
    expect(screen.getByTestId(`${prefix}-meter`)).toHaveAttribute("aria-hidden", "true");
  });

  test("a value out of its range or without a score draws no meter", () => {
    renderDataTree({
      view: {
        type: "Stack",
        children: [
          { type: "KpiTile", id: "over", label: "Score", value: "120/100" },
          { type: "KpiTile", id: "plain", label: "Rank", value: 12 },
        ],
      },
    });
    expect(screen.getByTestId("composed-view-widget-kpitile-over-value")).toHaveTextContent("120/100");
    expect(screen.queryByTestId("composed-view-widget-kpitile-over-meter")).toBeNull();
    expect(screen.queryByTestId("composed-view-widget-kpitile-plain-meter")).toBeNull();
  });

  test.each([
    ["success", true],
    ["warning", true],
    ["danger", true],
    ["info", true],
    ["neutral", false],
    ["muted", false],
  ])("a %s tone shows a status accent: %s", (tone, shown) => {
    renderDataTree({ view: { type: "KpiTile", id: "toned", label: "Listings", value: "19 din 20 corecte", tone } });
    expect(Boolean(screen.queryByTestId("composed-view-widget-kpitile-toned-accent"))).toBe(shown);
  });

  test("keeps zero and optional parts while omitting a tool-name footer", () => {
    renderDataTree({
      view: {
        type: "KpiTile",
        id: "zero",
        label: "Reviews",
        value: 0,
        unit: "%",
        delta: 0,
        deltaLabel: "vs previous month",
        detail: "Updated today",
        higherIsBetter: false,
        source: { name: "getReviews" },
      },
    });
    const prefix = "composed-view-widget-kpitile-zero";
    expect(screen.getByTestId(`${prefix}-value`)).toHaveTextContent("0");
    for (const part of ["label", "value-block", "unit", "delta", "detail"])
      expect(screen.getByTestId(`${prefix}-${part}`)).toBeInTheDocument();
    expect(screen.queryByTestId(`${prefix}-footnote`)).toBeNull();
    expect(screen.queryByText("getReviews")).toBeNull();
    expect(screen.queryByRole("link")).toBeNull();
  });

  test.each([
    ["61,4/100", "text", "61,4/100", true],
    ["12.5", "number", "12.5", true],
    [113498, "currency", "€113,498", true],
    [8200, "compact", "8.2K", true],
    ["2026-11-06", "date", "Nov 6, 2026", false],
  ] as [string | number, DataValueFormat, string, boolean][])(
    "renders %s in %s format without changing content or hierarchy",
    (value, format, expected, isHero) => {
      const { container } = renderDataTree({
        view: { type: "KpiTile", id: "formatted", label: "Value", value, format, currency: "EUR" },
      });
      expect(screen.getByTestId("composed-view-widget-kpitile-formatted-value")).toHaveTextContent(expected);
      expect(Boolean(container.querySelector("[data-hero-value]"))).toBe(isHero);
    },
  );

  test("pending transitions to mounted and empty without keeping the skeleton", () => {
    const fields = {
      view: {
        type: "KpiTile",
        id: "replay",
        label: "Reviews",
        value: { $from: { toolCall: "call_7", path: "$.total", state: "pending" } },
      },
    };
    const { rerender, lastReport, reports } = renderDataTree(fields);
    const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
    expect(lastReport()).toMatchObject({ status: "pending" });
    expect(screen.getByTestId("composed-view-widget-kpitile-replay-skeleton")).toHaveAttribute("aria-busy", "true");
    for (const value of [22, "Verificat · publicat", null]) {
      const tree = readComposedViewTree(
        { state: { missing: null }, view: { ...fields.view, value: value === null ? { $state: "missing" } : value } },
        getComposedNodeSchema(),
      );
      rerender(
        <Provider store={store}>
          <ComposedViewRenderer
            tree={tree}
            interactive
            isEmailRender={false}
            onReport={(entry) => reports.push(entry)}
          />
        </Provider>,
      );
      expect(screen.queryByTestId("composed-view-widget-kpitile-replay-skeleton")).toBeNull();
      expect(screen.getByTestId("composed-view-widget-kpitile-replay-value")).toHaveTextContent(
        value === null ? "—" : String(value),
      );
      expect(lastReport()).toMatchObject({ status: value === null ? "empty" : "mounted" });
    }
  });
  test("shows the label, the formatted value, the change and the detail", () => {
    const { findings, lastReport } = renderDataTree({
      view: {
        type: "KpiTile",
        label: "MRR month 12",
        value: 113498,
        format: "currency",
        currency: "EUR",
        delta: 5,
        deltaFormat: "percent",
        deltaLabel: "per month",
        detail: "12-month model",
        source: { name: "Billing export", url: "https://example.com/billing" },
      },
    });
    expect(findings).toEqual([]);
    expect(screen.getByText("MRR month 12")).toBeTruthy();
    expect(screen.getByText("€113,498")).toBeTruthy();
    expect(screen.getByText(readDeltaText("+5 %"))).toBeTruthy();
    expect(screen.getByText("per month")).toBeTruthy();
    expect(screen.getByText("12-month model")).toBeTruthy();
    expect(screen.getByRole("button", { name: /Billing export/ })).toBeInTheDocument();
    expect(lastReport()).toEqual({ status: "mounted" });
  });

  test("the change carries its sign: a gain a plus, a loss a minus, a rank change too", () => {
    renderDataTree({
      view: {
        type: "Stack",
        children: [
          { type: "KpiTile", label: "Visitors", value: 8200, delta: 12.4, deltaFormat: "percent" },
          { type: "KpiTile", label: "Bounce rate", value: 41, delta: -0.49, higherIsBetter: false },
          { type: "KpiTile", label: "Local rank", value: 4, delta: 6 },
        ],
      },
    });
    expect(screen.getByText(readDeltaText("+12.4 %"))).toBeTruthy();
    expect(screen.getByText(readDeltaText("-0.49"))).toBeTruthy();
    expect(screen.getByText(readDeltaText("+6"))).toBeTruthy();
  });

  test("a value bound to a computed expression follows the state", () => {
    const { findings } = renderDataTree({
      state: { customers: 800 },
      computed: { mrr: "customers * 79" },
      view: { type: "KpiTile", label: "MRR", value: { $computed: "mrr" }, format: "number" },
    });
    expect(findings).toEqual([]);
    expect(screen.getByText("63,200")).toBeTruthy();
  });

  test("a value that resolved to nothing shows a dash and reports empty", () => {
    const { findings, lastReport } = renderDataTree({
      state: { missing: null },
      view: { type: "KpiTile", label: "Conversions", value: { $state: "missing" } },
    });
    expect(findings).toEqual([]);
    expect(screen.getByText("—")).toBeTruthy();
    expect(lastReport()).toMatchObject({ status: "empty", code: "NO_DATA" });
  });

  test("a source in the e-mail capture renders no footer", () => {
    renderDataTree(
      {
        view: {
          type: "KpiTile",
          id: "email",
          label: "Visitors",
          value: 8200,
          source: { name: "GA4", url: "https://example.com" },
        },
      },
      { isEmailRender: true },
    );
    expect(screen.queryByRole("link")).toBeNull();
    expect(screen.queryByText("GA4")).toBeNull();
    expect(screen.queryByTestId("composed-view-widget-kpitile-email-footnote")).toBeNull();
  });

  test.each([
    undefined,
    { name: "getRankings" },
    { name: "Rankings", url: "https://" },
    { name: "Rankings", url: "/rankings" },
  ])("renders no footer for source %j", (source) => {
    renderDataTree({ view: { type: "KpiTile", id: "source", label: "Rank", value: 4, ...(source ? { source } : {}) } });
    expect(screen.queryByTestId("composed-view-widget-kpitile-source-footnote")).toBeNull();
    expect(screen.queryByRole("link")).toBeNull();
  });

  test.each(["Rankings report", undefined])("links a valid source with name %s and stable ids", (name) => {
    renderDataTree({
      view: {
        type: "KpiTile",
        id: "source",
        label: "Rank",
        value: 4,
        source: { ...(name ? { name } : {}), url: "https://example.com/rankings" },
      },
    });
    const button = screen.getByRole("button", { name: name ?? "example.com" });
    expect(screen.getByTestId("composed-view-widget-kpitile-source-footnote-source-0")).toContainElement(button);
    expect(screen.getByTestId("composed-view-widget-kpitile-source-footnote")).toContainElement(button);
    const open = vi.spyOn(window, "open").mockImplementation(() => null);
    try {
      fireEvent.click(button);
      expect(open).toHaveBeenCalledExactlyOnceWith("https://example.com/rankings", "_blank", "noopener,noreferrer");
    } finally {
      open.mockRestore();
    }
  });
});
