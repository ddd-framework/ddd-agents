// @vitest-environment jsdom
import { beforeEach, describe, expect, test, vi } from "vitest";
import { fireEvent, screen, within } from "@testing-library/react";
import type { ApexOptions } from "apexcharts";
import { renderDataTree } from "./render-data-tree.spec-helper";
import { buildChartModel, readRadarRadius } from "./chart-model";

interface RecordedChart {
  type: string;
  series: unknown;
  options: ApexOptions;
  height: number;
}

const recordedCharts: RecordedChart[] = [];

// ApexCharts draws into SVG with layout jsdom does not have; the stub records what reaches it.
vi.mock("react-apexcharts", () => ({
  default: (props: RecordedChart) => {
    recordedCharts.push(props);
    return <div data-testid="apex-chart" data-type={props.type} />;
  },
}));

const lastChart = () => recordedCharts[recordedCharts.length - 1];

const months = [
  { month: "Jan 2024", csu: 40, afd: 15 },
  { month: "Jan 2026", csu: 39, afd: 19 },
  { month: "Oct 2026", csu: 36, afd: 21 },
];

describe("Chart", () => {
  beforeEach(() => {
    recordedCharts.length = 0;
  });

  test("a line chart: one series per field, categories from x, values formatted as percent", () => {
    const { findings, lastReport } = renderDataTree({
      data: { months },
      view: {
        type: "Chart",
        kind: "line",
        data: { $data: "months" },
        x: "month",
        series: [
          { field: "csu", label: "CSU" },
          { field: "afd", label: "AfD" },
        ],
        valueFormat: "percent",
        title: "Development of the main parties",
        caption: "Infratest dimap / BayernTREND",
        source: { name: "www.infratest-dimap.de", url: "https://www.infratest-dimap.de/" },
      },
    });
    expect(findings).toEqual([]);
    expect(lastChart().type).toBe("line");
    expect(lastChart().series).toEqual([
      { name: "CSU", data: [40, 39, 36] },
      { name: "AfD", data: [15, 19, 21] },
    ]);
    expect(lastChart().options.xaxis?.categories).toEqual(["Jan 2024", "Jan 2026", "Oct 2026"]);
    const yAxis = lastChart().options.yaxis as { labels: { formatter: (value: number) => string } };
    expect(yAxis.labels.formatter(15)).toBe("15 %");
    expect(lastChart().options.legend?.show).toBe(true);
    expect(screen.getByRole("img", { name: "Development of the main parties: CSU, AfD" })).toBeTruthy();
    expect(screen.getByText("Infratest dimap / BayernTREND")).toBeTruthy();
    // The footer shows the source by name, linked to its page in a new tab.
    const sourceLink = screen.getByRole("link", { name: "www.infratest-dimap.de" });
    expect(sourceLink.getAttribute("href")).toBe("https://www.infratest-dimap.de/");
    expect(sourceLink.getAttribute("target")).toBe("_blank");
    expect(lastReport()).toEqual({ status: "mounted" });
  });

  test("a series hidden by a bound state appears when the state turns true", () => {
    const { findings } = renderDataTree({
      state: { benchmark: false },
      data: { months },
      actions: {},
      view: {
        type: "Chart",
        kind: "area",
        data: { $data: "months" },
        x: "month",
        series: [
          { field: "csu", label: "CSU" },
          { field: "afd", label: "AfD", visible: { $state: "benchmark" } },
        ],
        source: { name: "Infratest dimap" },
      },
    });
    expect(findings).toEqual([]);
    expect((lastChart().series as Array<{ name: string }>).map((series) => series.name)).toEqual(["CSU"]);
  });

  test("a horizontal poll bar: distributed colours, value labels, no legend", () => {
    renderDataTree({
      view: {
        type: "Chart",
        kind: "bar",
        horizontal: true,
        data: [
          { party: "CSU", share: 36 },
          { party: "AfD", share: 21 },
        ],
        x: "party",
        series: [{ field: "share", label: "Share" }],
        valueFormat: "percent",
      },
    });
    expect(lastChart().options.plotOptions?.bar?.horizontal).toBe(true);
    expect(lastChart().options.plotOptions?.bar?.distributed).toBe(true);
    expect(lastChart().options.dataLabels?.enabled).toBe(true);
    // A percentage bar writes its unit at the bar end ("36 %"), like ChatGPT's channel bars.
    const dataLabelFormatter = lastChart().options.dataLabels?.formatter as (value: number) => string;
    expect(dataLabelFormatter(36)).toBe("36 %");
    expect(lastChart().options.legend?.show).toBe(false);
  });

  test("a poll against the last election: one bar per party with its value and the signed change, toned by sign", () => {
    const { findings, lastReport } = renderDataTree({
      view: {
        type: "Chart",
        kind: "bar",
        horizontal: true,
        title: "Current poll",
        data: [
          { party: "CSU", share: 36, change: -3 },
          { party: "AfD", share: 21, change: 2 },
          { party: "SPD", share: 8, change: 0 },
          { party: "Others", share: 7 },
        ],
        x: "party",
        series: [{ field: "share", label: "Share" }],
        valueFormat: "percent",
        deltaField: "change",
        deltaUnit: "pp",
        deltaLabel: "Right: change in percentage points since January 2026",
        caption: "Infratest dimap / BR24, 20 Oct 2026 · 1,207 respondents",
        source: { name: "www.infratest-dimap.de", url: "https://www.infratest-dimap.de/" },
      },
    });
    expect(findings).toEqual([]);
    expect(recordedCharts).toHaveLength(0);
    const bars = screen.getByRole("table", { name: "Current poll: Share" });
    const rowTexts = within(bars)
      .getAllByRole("row")
      .map((row) => row.textContent);
    expect(rowTexts).toEqual(["CSU36 %-3", "AfD21 %+2", "SPD8 %0", "Others7 %"]);
    expect(within(bars).getByText("-3").style.color).toContain("--agents-danger-900");
    expect(within(bars).getByText("+2").style.color).toContain("--agents-success-900");
    expect(within(bars).getByText("0").style.color).toContain("--agents-fg-light");
    // The longest bar leaves a tenth of its track free, as the reference draws it.
    const csuBar = bars.querySelector('td[aria-hidden="true"] span') as HTMLElement;
    expect(csuBar.style.getPropertyValue("--agents-chart-bar-fraction")).toBe("90.9%");
    expect(screen.getByText("Right: change in percentage points since January 2026")).toBeTruthy();
    expect(screen.getByText("Infratest dimap / BR24, 20 Oct 2026 · 1,207 respondents")).toBeTruthy();
    expect(screen.getByRole("link", { name: "www.infratest-dimap.de" })).toBeTruthy();
    expect(lastReport()).toEqual({ status: "mounted" });
  });

  test("a change in percent carries its unit; without a label a pp change names its unit in the kit's line", () => {
    const percentChange = renderDataTree({
      view: {
        type: "Chart",
        kind: "bar",
        data: [{ month: "October", visits: 1840, change: 12.5 }],
        x: "month",
        series: [{ field: "visits" }],
        deltaField: "change",
        deltaUnit: "%",
        source: { name: "Google Analytics" },
      },
    });
    expect(screen.getByText("+12.5 %")).toBeTruthy();
    expect(screen.getByText("Right: change in percent")).toBeTruthy();
    percentChange.unmount();

    renderDataTree({
      view: {
        type: "Chart",
        kind: "bar",
        data: [{ party: "CSU", share: 36, change: -3 }],
        x: "party",
        series: [{ field: "share" }],
        deltaField: "change",
        deltaUnit: "pp",
        source: { name: "Infratest dimap" },
      },
    });
    expect(screen.getByText("Right: change in percentage points")).toBeTruthy();
  });

  test("figures without a source still render and are reported to the model as a note", () => {
    const { findings, lastReport } = renderDataTree({
      view: {
        type: "Chart",
        kind: "bar",
        data: [{ party: "CSU", share: 36 }],
        x: "party",
        series: [{ field: "share" }],
      },
    });
    expect(findings.map((finding) => finding.code)).toEqual(["INLINE_VALUE_WITHOUT_SOURCE"]);
    expect(lastChart().type).toBe("bar");
    expect(lastReport()).toMatchObject({ status: "mounted", code: "INLINE_VALUE_WITHOUT_SOURCE" });
    expect(lastReport().detail).toContain("view: a Chart shows figures written inline without `source`");
  });

  test("a pie takes the first series over the x names; a heatmap writes one row per data row", () => {
    renderDataTree({
      view: {
        type: "Chart",
        kind: "donut",
        data: [
          { c: "Organic", v: 4190 },
          { c: "Paid", v: 2620 },
        ],
        x: "c",
        series: [{ field: "v" }],
      },
    });
    expect(lastChart().series).toEqual([4190, 2620]);
    expect(lastChart().options.labels).toEqual(["Organic", "Paid"]);

    renderDataTree({
      view: {
        type: "Chart",
        kind: "heatmap",
        data: [
          { cohort: "May", m0: 100, m1: 82 },
          { cohort: "June", m0: 100, m1: null },
        ],
        x: "cohort",
        series: [
          { field: "m0", label: "M0" },
          { field: "m1", label: "M1" },
        ],
      },
    });
    expect(lastChart().series).toEqual([
      {
        name: "June",
        data: [
          { x: "M0", y: 100 },
          { x: "M1", y: null },
        ],
      },
      {
        name: "May",
        data: [
          { x: "M0", y: 100 },
          { x: "M1", y: 82 },
        ],
      },
    ]);
  });

  test("in a table cell the chart is a sparkline", () => {
    renderDataTree({
      view: {
        type: "Table",
        children: [
          {
            type: "TableRow",
            children: [
              { type: "TableCell", value: "AAPL" },
              {
                type: "TableCell",
                children: [
                  {
                    type: "Chart",
                    kind: "line",
                    data: [
                      { t: "1", p: 349 },
                      { t: "2", p: 347 },
                    ],
                    x: "t",
                    series: [{ field: "p" }],
                    title: "hidden",
                  },
                ],
              },
            ],
          },
        ],
      },
    });
    expect(lastChart().options.chart?.sparkline?.enabled).toBe(true);
    expect(lastChart().height).toBe(40);
    expect(screen.queryByText("hidden")).toBeNull();
  });

  test("no rows report empty; rows without numbers in the series report failed", () => {
    const empty = renderDataTree({
      data: { none: [] },
      view: { type: "Chart", kind: "bar", data: { $data: "none" }, x: "x", series: [{ field: "v" }] },
    });
    expect(empty.lastReport()).toMatchObject({ status: "empty", code: "NO_DATA" });
    expect(screen.getByText("No data to show.")).toBeTruthy();
    empty.unmount();

    const invalid = renderDataTree({
      view: { type: "Chart", kind: "bar", data: [{ x: "a", v: "high" }], x: "x", series: [{ field: "v" }] },
    });
    expect(invalid.lastReport()).toMatchObject({ status: "failed", code: "INVALID_PROP" });
    fireEvent.click(document.body);
  });

  test("the e-mail capture draws without animation and tooltips", () => {
    renderDataTree(
      { view: { type: "Chart", kind: "line", data: months, x: "month", series: [{ field: "csu" }] } },
      { isEmailRender: true },
    );
    expect(lastChart().options.chart?.animations?.enabled).toBe(false);
    expect(lastChart().options.tooltip?.enabled).toBe(false);
  });

  test("series names reach the legend and tooltip HTML escaped", () => {
    renderDataTree({
      view: {
        type: "Chart",
        kind: "line",
        data: months,
        x: "month",
        series: [{ field: "csu", label: "<img src=x onerror=alert(1)>" }],
      },
    });
    const legendFormatter = lastChart().options.legend?.formatter as (seriesName: string) => string;
    expect(legendFormatter("<img src=x>")).toBe("&lt;img src=x&gt;");
    const tooltipX = lastChart().options.tooltip?.x as unknown as { formatter: (value: string) => string };
    expect(tooltipX.formatter("A & B")).toBe("A &amp; B");
  });

  test("the tooltip header names the hovered category, not the point's index", () => {
    renderDataTree({ view: { type: "Chart", kind: "line", data: months, x: "month", series: [{ field: "csu" }] } });
    const tooltipX = lastChart().options.tooltip?.x as unknown as {
      formatter: (value: number, context?: { dataPointIndex?: number }) => string;
    };
    expect(tooltipX.formatter(2, { dataPointIndex: 1 })).toBe("Jan 2026");
  });

  test("a funnel draws its stages in order: centered bars, each value and the conversion from the stage before", () => {
    const { findings, lastReport } = renderDataTree({
      view: {
        type: "Chart",
        kind: "funnel",
        title: "Review invitations",
        data: [
          { stage: "Invitations sent", count: 64 },
          { stage: "E-mail opened", count: 41 },
          { stage: "Link clicked", count: 19 },
          { stage: "Review written", count: 5 },
        ],
        x: "stage",
        series: [{ field: "count", label: "Patients" }],
        source: { name: "Example Review Invitations" },
      },
    });
    expect(findings).toEqual([]);
    expect(recordedCharts).toHaveLength(0);
    const stages = screen.getByRole("list", { name: "Review invitations: Patients" });
    expect(
      within(stages)
        .getAllByRole("listitem")
        .map((stage) => stage.textContent),
    ).toEqual([
      "Invitations sent64",
      "64.1 % of the previous stageE-mail opened41",
      "46.3 % of the previous stageLink clicked19",
      "26.3 % of the previous stageReview written5",
    ]);
    // The bar is the stage's own hidden span; the conversion line's icon may render a hidden span as well.
    const bars = Array.from(stages.querySelectorAll(':scope > li > span[aria-hidden="true"]')) as HTMLElement[];
    expect(bars.map((bar) => bar.style.getPropertyValue("--agents-chart-funnel-fraction"))).toEqual([
      "100%",
      "64.1%",
      "29.7%",
      "7.8%",
    ]);
    expect(lastReport()).toEqual({ status: "mounted" });
  });

  test("a funnel in a table cell is a bar sparkline", () => {
    renderDataTree({
      view: {
        type: "Table",
        children: [
          {
            type: "TableRow",
            children: [
              {
                type: "TableCell",
                children: [
                  {
                    type: "Chart",
                    kind: "funnel",
                    data: [
                      { s: "a", v: 10 },
                      { s: "b", v: 4 },
                    ],
                    x: "s",
                    series: [{ field: "v" }],
                    source: { name: "Example Review Invitations" },
                  },
                ],
              },
            ],
          },
        ],
      },
    });
    expect(lastChart().type).toBe("bar");
    expect(lastChart().options.chart?.sparkline?.enabled).toBe(true);
  });

  test("a radar: one axis per row, one polygon per series; fewer than three axes report failed", () => {
    const { findings, lastReport, unmount } = renderDataTree({
      view: {
        type: "Chart",
        kind: "radar",
        data: [
          { dimension: "Photos", own: 55, competitors: 75 },
          { dimension: "Hours", own: 100, competitors: 95 },
          { dimension: "Reviews", own: 92, competitors: null },
        ],
        x: "dimension",
        series: [
          { field: "own", label: "Our practice" },
          { field: "competitors", label: "Competitors" },
        ],
        valueFormat: "percent",
        max: 100,
        source: { name: "Google Business Profile" },
      },
    });
    expect(findings).toEqual([]);
    expect(lastChart().type).toBe("radar");
    expect(lastChart().series).toEqual([
      { name: "Our practice", data: [55, 100, 92] },
      { name: "Competitors", data: [75, 95, 0] },
    ]);
    expect(lastChart().options.xaxis?.categories).toEqual(["Photos", "Hours", "Reviews"]);
    expect(lastChart().options.yaxis).toMatchObject({ show: false, min: 0, max: 100 });
    expect(lastChart().options.legend?.show).toBe(true);
    expect(lastReport()).toEqual({ status: "mounted" });
    unmount();

    const twoAxes = renderDataTree({
      view: {
        type: "Chart",
        kind: "radar",
        data: [
          { d: "a", v: 1 },
          { d: "b", v: 2 },
        ],
        x: "d",
        series: [{ field: "v" }],
        source: { name: "Google Business Profile" },
      },
    });
    expect(twoAxes.lastReport()).toMatchObject({ status: "failed", code: "INVALID_PROP" });
  });

  test("the radar radius leaves room for the side-axis names and fits the height", () => {
    const axisNames = ["Photos", "Opening hours", "Services", "Attributes", "Reviews", "Posts"];
    expect(readRadarRadius({ axisNames, plotWidth: 0, height: 320, fontSizePx: 12, hasLegend: true })).toBeUndefined();
    // the height limits a wide plot: (320 - 48) / 2 - 24
    expect(readRadarRadius({ axisNames, plotWidth: 528, height: 320, fontSizePx: 12, hasLegend: true })).toBe(112);
    // the width limits a phone: 296 / 2 - (13 × 11 × 0.5 + 8)
    expect(readRadarRadius({ axisNames, plotWidth: 296, height: 320, fontSizePx: 11, hasLegend: true })).toBe(69);
  });

  test("a donut names the total in its middle under the series label; a pie does not", () => {
    renderDataTree({
      view: {
        type: "Chart",
        kind: "donut",
        data: [
          { platform: "Google", reviews: 87 },
          { platform: "Jameda", reviews: 32 },
          { platform: "Facebook", reviews: 11 },
        ],
        x: "platform",
        series: [{ field: "reviews", label: "Reviews" }],
        source: { name: "Example Reviews" },
      },
    });
    const donutLabels = lastChart().options.plotOptions?.pie?.donut?.labels;
    expect(donutLabels?.show).toBe(true);
    expect(donutLabels?.total?.label).toBe("Reviews");
    expect((donutLabels?.total?.formatter as unknown as () => string)()).toBe("130");

    renderDataTree({
      view: {
        type: "Chart",
        kind: "pie",
        data: [{ platform: "Google", reviews: 87 }],
        x: "platform",
        series: [{ field: "reviews" }],
        source: { name: "Example Reviews" },
      },
    });
    expect(lastChart().options.plotOptions?.pie?.donut?.labels?.show).toBe(false);
  });

  test("a heatmap writes the count of a dark cell in the surface colour, of a pale one in the text colour", () => {
    renderDataTree({
      view: {
        type: "Chart",
        kind: "heatmap",
        data: [
          { day: "Mon", morning: 64, evening: 12 },
          { day: "Tue", morning: 40, evening: 20 },
        ],
        x: "day",
        series: [
          { field: "morning", label: "8–10" },
          { field: "evening", label: "18–20" },
        ],
        source: { name: "Google Business Profile" },
      },
    });
    const labelColors = lastChart().options.dataLabels?.style?.colors as unknown as Array<
      (labelContext: { seriesIndex: number; dataPointIndex: number }) => string
    >;
    // heat rows run bottom-up: series 1 is Mon
    expect(labelColors[0]({ seriesIndex: 1, dataPointIndex: 0 })).toBe("#ffffff");
    expect(labelColors[0]({ seriesIndex: 1, dataPointIndex: 1 })).toBe("#111827");
  });

  test("a narrow scatter plot gets about three numeric ticks", () => {
    const scatterModel = buildChartModel({
      props: {
        kind: "scatter",
        data: [
          { visits: 980, rate: 3.5 },
          { visits: 1460, rate: 5.3 },
        ],
        x: "visits",
        series: [{ field: "rate" }],
      },
      sizeClass: "regular",
      locale: "en",
      palette: {
        seriesColors: ["#000"],
        axisTextColor: "#000",
        textColor: "#000",
        gridColor: "#000",
        surfaceColor: "#fff",
      },
      isEmailRender: false,
      plotWidth: 250,
    });
    expect(scatterModel.status === "ok" && scatterModel.model.options.xaxis).toMatchObject({
      min: 800,
      max: 1600,
      tickAmount: 4,
    });
  });

  test("a scatter chart's numeric axis snaps to round bounds and steps", () => {
    renderDataTree({
      view: {
        type: "Chart",
        kind: "scatter",
        data: [
          { visits: 980, rate: 3.5 },
          { visits: 1120, rate: 4 },
          { visits: 1460, rate: 5.3 },
        ],
        x: "visits",
        series: [{ field: "rate", label: "Conversion Rate" }],
      },
    });
    expect(lastChart().options.xaxis).toMatchObject({ type: "numeric", min: 900, max: 1500, tickAmount: 6 });
  });
});
