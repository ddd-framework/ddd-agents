import { escapePlainTextForText } from "../../../provider/environment";
import type { ApexOptions } from "apexcharts";
import type { ComposedSizeClass } from "../../../renderer/widget-tree/composed-node-schema";
import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import {
  DataValueFormat,
  DataValueTone,
  formatDataValue,
  readDataValueFormat,
  readFiniteNumber,
  readObject,
  readRows,
  readText,
} from "./data-value-format";

/**
 * The `Chart` node's props turned into ApexCharts input — pure, so the spec checks what reaches the
 * chart library without drawing it. Every value goes through `formatDataValue`, so the axis, the tooltip and a KPI
 * tile write the same number the same way.
 */

export type ChartKind = "line" | "area" | "bar" | "pie" | "donut" | "scatter" | "heatmap" | "funnel" | "radar";

const CHART_KINDS: readonly ChartKind[] = [
  "line",
  "area",
  "bar",
  "pie",
  "donut",
  "scatter",
  "heatmap",
  "funnel",
  "radar",
];

export interface ChartSeriesDeclaration {
  field: string;
  label: string;
  /** The model wrote the label — a single named series gets its legend entry (ChatGPT's "MRR"). */
  hasExplicitLabel: boolean;
}

/** The theme colours the chart draws with, resolved from the `--sc-charts-*` and `--fn-*` tokens at render time. */
export interface ChartPalette {
  seriesColors: string[];
  axisTextColor: string;
  /** The main text colour, for the total in a donut's middle. */
  textColor: string;
  gridColor: string;
  surfaceColor: string;
}

export interface ChartModel {
  kind: ChartKind;
  apexType: "line" | "area" | "bar" | "pie" | "donut" | "scatter" | "heatmap" | "radar";
  series: ApexAxisChartSeries | ApexNonAxisChartSeries;
  options: ApexOptions;
  height: number;
  /** The plotted series names, for the accessible label. */
  seriesLabels: string[];
}

export type ChartModelResult =
  | { status: "ok"; model: ChartModel }
  | { status: "empty"; detail: string }
  | { status: "invalid"; detail: string };

export interface ChartModelInput {
  props: Record<string, ExpressionValue>;
  sizeClass: ComposedSizeClass;
  locale: string;
  palette: ChartPalette;
  isEmailRender: boolean;
  /** The measured width of the plot in px (0 before the first measurement): a narrow plot gets fewer numeric ticks. */
  plotWidth?: number;
}

const APEX_HTML_ENTITIES: Record<string, string> = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };

/**
 * A plain text as ApexCharts' legend and tooltip take it: they write their texts as HTML, so the characters that open
 * markup or an attribute are escaped here, whatever the host does to the texts of its own components.
 */
const escapeApexHtml = (text: string): string =>
  text.replace(/[&<>"']/g, (character) => APEX_HTML_ENTITIES[character]);

/** Below this plot width a numeric category axis (a scatter's) gets about three ticks instead of five. */
const NARROW_PLOT_WIDTH = 320;

const HEIGHT_BY_SIZE_CLASS: Record<ComposedSizeClass, number> = { compact: 40, regular: 280, full: 320 };

/** What a per-bar change counts (`ChartComposedViewNode::DELTA_UNITS`): percentage points or percent. */
export type ChartDeltaUnit = "pp" | "%";

const CHART_DELTA_UNITS: readonly ChartDeltaUnit[] = ["pp", "%"];

/** The theme's series colour tokens, one per bar of a single-series bar chart (the distributed colours). */
export const CHART_SERIES_COLOR_TOKENS = [
  "--agents-chart-01",
  "--agents-chart-02",
  "--agents-chart-03",
  "--agents-chart-04",
  "--agents-chart-05",
  "--agents-chart-06",
  "--agents-chart-07",
  "--agents-chart-08",
] as const;

/** One bar of a bar chart with a change per bar. */
export interface ChartBarRow {
  label: string;
  valueText: string;
  /** The filled part of the track, 0 to 1. */
  fraction: number;
  /** The colour token of the bar (`--sc-charts-0N`). */
  colorToken: string;
  /** The signed change ("+2", "-3", "0"); undefined when the row has no number in the delta field. */
  deltaText?: string;
  /** Plus green, minus red, zero (and no change) muted. */
  deltaTone: DataValueTone;
}

export interface ChartBarRowsModel {
  rows: ChartBarRow[];
  /** The plotted series' label, for the accessible label. */
  seriesLabel: string;
  deltaUnit?: ChartDeltaUnit;
}

export type ChartBarRowsModelResult =
  | { status: "ok"; model: ChartBarRowsModel }
  | { status: "empty"; detail: string }
  | { status: "invalid"; detail: string };

/** The row field of the change per bar — set on a bar chart only (another kind ignores it). */
export const readChartDeltaField = (props: Record<string, ExpressionValue>): string | undefined =>
  readChartKind(props.kind) === "bar" ? readText(props.deltaField) : undefined;

export const readChartDeltaUnit = (value: unknown): ChartDeltaUnit | undefined =>
  CHART_DELTA_UNITS.find((deltaUnit) => deltaUnit === value);

/** The tone of a change by its sign: a rise green, a fall red, no change muted. */
export const readChartDeltaTone = (delta: number | undefined): DataValueTone =>
  delta === undefined || delta === 0 ? "muted" : delta > 0 ? "success" : "danger";

/**
 * A bar chart with a change per bar (`deltaField`): one horizontal bar per row of the first plotted
 * series on a track, its value at the right, the signed change after it. The track's end is `max`, else the largest
 * value with a tenth of room (the longest bar never fills the track, as in the reference). A change counted in
 * percent (`deltaUnit` `%`) carries its unit; percentage points and a plain change show the figure alone — the line
 * under the bars (`deltaLabel`) says what it counts.
 */
export const buildChartBarRowsModel = (input: {
  props: Record<string, ExpressionValue>;
  locale: string;
}): ChartBarRowsModelResult => {
  const { props, locale } = input;
  const rows = readRows(props.data) ?? [];
  const xField = readText(props.x) ?? "";
  const deltaField = readChartDeltaField(props) ?? "";
  const seriesDeclaration = readChartSeries(props.series).find((candidate) =>
    rows.some((row) => readFiniteNumber(row[candidate.field]) !== undefined),
  );
  if (rows.length === 0) return { status: "empty", detail: "the Chart has no rows" };
  if (!seriesDeclaration) {
    return { status: "invalid", detail: "no row has a number in the Chart's series" };
  }
  const valueFormat = readDataValueFormat(props.valueFormat);
  const currency = readText(props.currency);
  const deltaUnit = readChartDeltaUnit(props.deltaUnit);
  const plottedRows = rows
    .map((row) => ({ row, value: readFiniteNumber(row[seriesDeclaration.field]) }))
    .filter(
      (plottedRow): plottedRow is { row: (typeof rows)[number]; value: number } => plottedRow.value !== undefined,
    );
  const largestValue = Math.max(0, ...plottedRows.map((plottedRow) => plottedRow.value));
  const axisMax = readFiniteNumber(props.max) ?? largestValue * 1.1;
  return {
    status: "ok",
    model: {
      seriesLabel: seriesDeclaration.label,
      deltaUnit,
      rows: plottedRows.map((plottedRow, rowIndex) => {
        const delta = readFiniteNumber(plottedRow.row[deltaField]);
        return {
          label: readText(plottedRow.row[xField]) ?? "",
          valueText: formatDataValue(plottedRow.value, { format: valueFormat, currency, locale }),
          fraction: axisMax > 0 ? Math.min(1, Math.max(0, plottedRow.value / axisMax)) : 0,
          colorToken: CHART_SERIES_COLOR_TOKENS[rowIndex % CHART_SERIES_COLOR_TOKENS.length],
          deltaText:
            delta === undefined
              ? undefined
              : formatDataValue(delta, { format: deltaUnit === "%" ? "percent" : "number", signed: true, locale }),
          deltaTone: readChartDeltaTone(delta),
        };
      }),
    },
  };
};

/** One stage of a funnel chart: its bar (centered, as wide as its share of the largest stage) and its figures. */
export interface ChartFunnelStage {
  label: string;
  valueText: string;
  /** The bar's width, 0 to 1 of the largest stage. */
  fraction: number;
  /** The stage's value as a percentage of the stage before it ("62 %"); undefined for the first stage. */
  conversionText?: string;
}

export interface ChartFunnelModel {
  stages: ChartFunnelStage[];
  /** The plotted series' label, for the accessible label. */
  seriesLabel: string;
}

export type ChartFunnelModelResult =
  | { status: "ok"; model: ChartFunnelModel }
  | { status: "empty"; detail: string }
  | { status: "invalid"; detail: string };

/** The narrowest bar a stage with a positive value gets, so a small last stage stays visible. */
const MIN_FUNNEL_BAR_FRACTION = 0.04;

/**
 * A funnel chart (`kind` `funnel`): the rows are the stages of a process in their written order, the first plotted
 * series their values; each stage is a centered bar as wide as its share of the largest stage, with its value and its
 * conversion from the stage before it in percent (one decimal at most). A stage without a number is left out.
 */
/**
 * The radius of a radar polygon that leaves room for its axis names (ApexCharts sizes the polygon by the plot alone and
 * clips a long name at the side): half the plot width minus the longest side-axis name, at most what the height
 * leaves under the names and the legend. Undefined before the plot is measured (ApexCharts' own size).
 */
export const readRadarRadius = (input: {
  axisNames: string[];
  plotWidth: number;
  height: number;
  fontSizePx: number;
  hasLegend: boolean;
}): number | undefined => {
  const { axisNames, plotWidth, height, fontSizePx, hasLegend } = input;
  if (plotWidth <= 0 || axisNames.length === 0) return undefined;
  // a side axis points left or right (the top and bottom names only need a line of height)
  const sideAxisNameLengths = axisNames
    .filter((_axisName, axisIndex) => Math.abs(Math.sin((2 * Math.PI * axisIndex) / axisNames.length)) > 0.3)
    .map((axisName) => axisName.length);
  const nameRoom = Math.max(0, ...sideAxisNameLengths) * fontSizePx * 0.5 + 8;
  const heightRoom = (height - (hasLegend ? 48 : 0)) / 2 - fontSizePx * 2;
  return Math.max(40, Math.round(Math.min(plotWidth / 2 - nameRoom, heightRoom)));
};

export const buildChartFunnelModel = (input: {
  props: Record<string, ExpressionValue>;
  locale: string;
}): ChartFunnelModelResult => {
  const { props, locale } = input;
  const rows = readRows(props.data) ?? [];
  const xField = readText(props.x) ?? "";
  const seriesDeclaration = readChartSeries(props.series).find((candidate) =>
    rows.some((row) => readFiniteNumber(row[candidate.field]) !== undefined),
  );
  if (rows.length === 0) return { status: "empty", detail: "the Chart has no rows" };
  if (!seriesDeclaration) return { status: "invalid", detail: "no row has a number in the Chart's series" };
  const valueFormat = readDataValueFormat(props.valueFormat);
  const currency = readText(props.currency);
  const stageValues = rows
    .map((row) => ({ label: readText(row[xField]) ?? "", value: readFiniteNumber(row[seriesDeclaration.field]) }))
    .filter((stageValue): stageValue is { label: string; value: number } => stageValue.value !== undefined);
  const largestValue = Math.max(0, ...stageValues.map((stageValue) => stageValue.value));
  return {
    status: "ok",
    model: {
      seriesLabel: seriesDeclaration.label,
      stages: stageValues.map((stageValue, stageIndex) => {
        const previousValue = stageIndex > 0 ? stageValues[stageIndex - 1].value : undefined;
        const fraction = largestValue > 0 ? Math.min(1, Math.max(0, stageValue.value / largestValue)) : 0;
        return {
          label: stageValue.label,
          valueText: formatDataValue(stageValue.value, { format: valueFormat, currency, locale }),
          fraction: stageValue.value > 0 ? Math.max(MIN_FUNNEL_BAR_FRACTION, fraction) : 0,
          conversionText:
            previousValue !== undefined && previousValue > 0
              ? formatDataValue(Math.round((stageValue.value / previousValue) * 1000) / 10, {
                  format: "percent",
                  locale,
                })
              : undefined,
        };
      }),
    },
  };
};

export const readChartKind = (value: unknown): ChartKind | undefined =>
  CHART_KINDS.find((chartKind) => chartKind === value);

/** The visible series: entries with a string `field`; `visible: false` (often a bound state) hides one. */
export const readChartSeries = (seriesValue: ExpressionValue): ChartSeriesDeclaration[] => {
  if (!Array.isArray(seriesValue)) return [];
  const seriesDeclarations: ChartSeriesDeclaration[] = [];
  for (const seriesEntry of seriesValue) {
    const seriesObject = readObject(seriesEntry);
    const field = readText(seriesObject?.field) ?? readText(seriesObject?.key);
    if (!seriesObject || !field || seriesObject.visible === false) continue;
    const explicitLabel = readText(seriesObject.label);
    seriesDeclarations.push({ field, label: explicitLabel ?? field, hasExplicitLabel: explicitLabel !== undefined });
  }
  return seriesDeclarations;
};

/**
 * With both bounds written, the tick count that gives whole steps (0–45 → 3 ticks of 15); 4 otherwise.
 * Without both bounds ApexCharts picks a nice scale itself (undefined).
 */
const readTickAmountForBounds = (axisMin: number | undefined, axisMax: number | undefined): number | undefined => {
  if (axisMin === undefined || axisMax === undefined || axisMax <= axisMin) return undefined;
  return [3, 4, 5].find((tickAmount) => Number.isInteger((axisMax - axisMin) / tickAmount)) ?? 4;
};

/** The largest plotted number of axis series (0 when none). */
const readLargestValue = (series: ApexAxisChartSeries): number =>
  Math.max(
    0,
    ...series.flatMap((seriesEntry) =>
      (seriesEntry.data as unknown[]).filter((value): value is number => typeof value === "number"),
    ),
  );

/** A step of 1, 2, 2.5 or 5 times a power of ten that is at least `roughStep` — the steps an axis reads naturally. */
const readNiceStep = (roughStep: number): number => {
  const magnitude = 10 ** Math.floor(Math.log10(roughStep));
  return ([1, 2, 2.5, 5, 10].find((factor) => factor * magnitude >= roughStep) ?? 10) * magnitude;
};

/**
 * Round bounds and the tick count for a numeric axis over `values` (ChatGPT's scatter: 900 … 1500 in steps of
 * 150, never 980, 1076, 1172): the bounds snap outward to whole steps of about `targetTickAmount` ticks.
 */
export const readNiceAxisBounds = (
  values: number[],
  targetTickAmount: number,
): { min: number; max: number; tickAmount: number } | undefined => {
  if (values.length === 0) return undefined;
  const smallestValue = Math.min(...values);
  const largestValue = Math.max(...values);
  if (smallestValue === largestValue) return undefined;
  const step = readNiceStep((largestValue - smallestValue) / targetTickAmount);
  const min = Math.floor(smallestValue / step) * step;
  const max = Math.ceil(largestValue / step) * step;
  return { min, max, tickAmount: Math.round((max - min) / step) };
};

const buildValueFormatter =
  (format: DataValueFormat, currency: string | undefined, locale: string) =>
  (value: number | null | undefined): string =>
    value === null || value === undefined ? "" : formatDataValue(value, { format, currency, locale });

/**
 * The ApexCharts series and options for the props, or why there is nothing to draw: no rows / no plottable value
 * (`empty`), or no series field present in the rows (`invalid`).
 */
export const buildChartModel = (input: ChartModelInput): ChartModelResult => {
  const { props, sizeClass, locale, palette, isEmailRender, plotWidth = 0 } = input;
  const writtenKind = readChartKind(props.kind);
  // A funnel draws its own rows (`buildChartFunnelModel`); in a compact slot it is a bar sparkline.
  const kind = writtenKind === "funnel" ? "bar" : writtenKind;
  if (!kind) return { status: "invalid", detail: `the Chart kind "${String(props.kind)}" is not a chart kind` };
  const rows = readRows(props.data) ?? [];
  const xField = readText(props.x) ?? "";
  const seriesDeclarations = readChartSeries(props.series);
  if (rows.length === 0) return { status: "empty", detail: "the Chart has no rows" };
  if (seriesDeclarations.length === 0) return { status: "empty", detail: "the Chart shows no series" };
  const presentSeries = seriesDeclarations.filter((seriesDeclaration) =>
    rows.some((row) => readFiniteNumber(row[seriesDeclaration.field]) !== undefined),
  );
  if (presentSeries.length === 0) {
    return {
      status: "invalid",
      detail: `no row has a number in ${seriesDeclarations.map((seriesDeclaration) => `"${seriesDeclaration.field}"`).join(", ")}`,
    };
  }
  const isSparkline = sizeClass === "compact";
  const valueFormat = readDataValueFormat(props.valueFormat);
  const formatValue = buildValueFormatter(valueFormat, readText(props.currency), locale);
  const categories = rows.map((row) => readText(row[xField]) ?? "");
  const height = HEIGHT_BY_SIZE_CLASS[sizeClass];
  const isHorizontalBar = kind === "bar" && props.horizontal === true;
  const isDistributedBar = kind === "bar" && presentSeries.length === 1;
  const textStyle = { colors: palette.axisTextColor, fontSize: "12px", fontFamily: "inherit" };
  const baseOptions: ApexOptions = {
    chart: {
      type: kind,
      toolbar: { show: false },
      zoom: { enabled: false },
      animations: { enabled: !isEmailRender, speed: 250 },
      sparkline: { enabled: isSparkline },
      background: "transparent",
      fontFamily: "inherit",
      foreColor: palette.axisTextColor,
      stacked: (kind === "bar" || kind === "area") && props.stacked === true,
    },
    colors: palette.seriesColors,
    dataLabels: { enabled: false },
    legend: {
      show:
        !isSparkline &&
        (presentSeries.length > 1 ||
          kind === "pie" ||
          kind === "donut" ||
          presentSeries.some((seriesDeclaration) => seriesDeclaration.hasExplicitLabel)),
      showForSingleSeries: true,
      // ApexCharts writes legend and tooltip texts as HTML: the model's names are escaped there (axis labels are SVG text).
      formatter: (seriesName: string) => escapeApexHtml(String(seriesName)),
      position: "bottom",
      horizontalAlign: "center",
      fontSize: "13px",
      labels: { colors: palette.axisTextColor },
      markers: { radius: 12 },
      itemMargin: { horizontal: 8, vertical: 4 },
    },
    grid: {
      borderColor: palette.gridColor,
      strokeDashArray: 4,
      xaxis: { lines: { show: isHorizontalBar } },
      yaxis: { lines: { show: !isHorizontalBar } },
      padding: { left: 8, right: 24 },
    },
    tooltip: {
      enabled: !isEmailRender,
      theme: "light",
      x: { formatter: (value: number | string) => escapeApexHtml(String(value)) },
      y: {
        formatter: (value: number) => formatValue(value),
        title: {
          formatter: (seriesName: string) => (seriesName ? `${escapeApexHtml(String(seriesName))}: ` : ""),
        },
      },
    },
    stroke: {
      width: kind === "line" || kind === "area" ? 2 : kind === "pie" || kind === "donut" ? 2 : 0,
      curve: "straight",
      colors: kind === "pie" || kind === "donut" ? [palette.surfaceColor] : undefined,
    },
    markers: {
      size: kind === "scatter" ? 6 : kind === "line" && !isSparkline && rows.length <= 24 ? 4 : 0,
      strokeWidth: 0,
    },
    states: { active: { filter: { type: "none" } } },
  };
  const axisMin = readFiniteNumber(props.min);
  const axisMax = readFiniteNumber(props.max);
  const valueAxis = {
    min: axisMin,
    max: axisMax,
    tickAmount: readTickAmountForBounds(axisMin, axisMax),
    forceNiceScale: axisMax === undefined,
    title: { text: readText(props.yLabel), style: { color: palette.axisTextColor, fontWeight: 400 } },
    labels: { formatter: (value: number) => formatValue(value), style: textStyle },
  };
  const seriesLabels = presentSeries.map((seriesDeclaration) => seriesDeclaration.label);
  // The tooltip header names the category of the hovered point (`Jan. 2026`), never ApexCharts' 1-based index.
  const categoryTooltip = {
    ...baseOptions.tooltip,
    x: {
      formatter: (value: number | string, tooltipContext?: { dataPointIndex?: number }) =>
        escapeApexHtml(
          String(
            tooltipContext?.dataPointIndex !== undefined ? (categories[tooltipContext.dataPointIndex] ?? value) : value,
          ),
        ),
    },
  };

  if (kind === "pie" || kind === "donut") {
    const valueField = presentSeries[0].field;
    const slices = rows
      .map((row, rowIndex) => ({ label: categories[rowIndex], value: readFiniteNumber(row[valueField]) }))
      .filter((slice): slice is { label: string; value: number } => slice.value !== undefined && slice.value > 0);
    if (slices.length === 0) return { status: "empty", detail: "the Chart has no positive slice" };
    return {
      status: "ok",
      model: {
        kind,
        apexType: kind,
        series: slices.map((slice) => slice.value),
        height,
        seriesLabels: slices.map((slice) => slice.label),
        options: {
          ...baseOptions,
          labels: slices.map((slice) => slice.label),
          dataLabels: {
            enabled: !isSparkline,
            formatter: (percent: number) => `${Math.round(percent)} %`,
            dropShadow: { enabled: false },
          },
          plotOptions: {
            pie: {
              expandOnClick: false,
              donut: {
                size: "62%",
                // A donut names the whole in its middle: the total under the series' label ("130 · Bewertungen"), a
                // hovered slice's name and value instead. A pie has no middle.
                labels: {
                  show: kind === "donut" && !isSparkline,
                  name: { show: true, fontSize: "13px", color: palette.axisTextColor, offsetY: 20 },
                  value: {
                    show: true,
                    fontSize: "24px",
                    fontWeight: 700,
                    color: palette.textColor,
                    offsetY: -12,
                    formatter: (value: string) => formatValue(Number(value)),
                  },
                  total: {
                    show: true,
                    showAlways: false,
                    label: presentSeries[0].hasExplicitLabel ? escapePlainTextForText(presentSeries[0].label) : "",
                    fontSize: "13px",
                    color: palette.axisTextColor,
                    formatter: () => formatValue(slices.reduce((total, slice) => total + slice.value, 0)),
                  },
                },
              },
            },
          },
        },
      },
    };
  }

  if (kind === "radar") {
    // One axis per data row (named by x), one polygon per series: a profile across its dimensions.
    if (rows.length < 3) return { status: "invalid", detail: "a radar Chart needs at least 3 rows (one per axis)" };
    const valueValues = presentSeries.flatMap((seriesDeclaration) =>
      rows
        .map((row) => readFiniteNumber(row[seriesDeclaration.field]))
        .filter((value): value is number => value !== undefined),
    );
    const radarMax = axisMax ?? readNiceAxisBounds([0, ...valueValues], 4)?.max;
    const radarFontSizePx = plotWidth > 0 && plotWidth < NARROW_PLOT_WIDTH ? 11 : 12;
    const hasRadarLegend = baseOptions.legend?.show === true;
    const radarRadius = isSparkline
      ? undefined
      : readRadarRadius({
          axisNames: categories,
          plotWidth,
          height: Math.max(height, 320),
          fontSizePx: radarFontSizePx,
          hasLegend: hasRadarLegend,
        });
    // A radar the width holds smaller than the height allows (a phone) gets only the height it fills.
    const radarHeight = isSparkline
      ? height
      : Math.min(
          Math.max(height, 320),
          radarRadius === undefined
            ? Number.POSITIVE_INFINITY
            : radarRadius * 2 + radarFontSizePx * 4 + (hasRadarLegend ? 48 : 0) + 16,
        );
    return {
      status: "ok",
      model: {
        kind,
        apexType: "radar",
        series: presentSeries.map((seriesDeclaration) => ({
          name: seriesDeclaration.label,
          data: rows.map((row) => readFiniteNumber(row[seriesDeclaration.field]) ?? 0),
        })),
        height: radarHeight,
        seriesLabels,
        options: {
          ...baseOptions,
          chart: { ...baseOptions.chart, type: "radar" },
          stroke: { width: 2 },
          fill: { opacity: 0.18 },
          markers: { size: isSparkline ? 0 : 3, strokeWidth: 0 },
          plotOptions: {
            radar: {
              size: radarRadius,
              polygons: {
                strokeColors: palette.gridColor,
                connectorColors: palette.gridColor,
                fill: { colors: ["transparent"] },
              },
            },
          },
          tooltip: categoryTooltip,
          xaxis: {
            categories,
            labels: {
              show: !isSparkline,
              style: {
                ...textStyle,
                fontSize: `${radarFontSizePx}px`,
                colors: categories.map(() => palette.axisTextColor),
              },
            },
          },
          yaxis: {
            show: false,
            min: axisMin ?? 0,
            max: radarMax,
            tickAmount: 4,
            labels: { formatter: (value: number) => formatValue(value) },
          },
        },
      },
    };
  }

  if (kind === "heatmap") {
    // One heat row per data row (top to bottom as written), one column per series: a cohort table.
    const heatRows = rows
      .map((row, rowIndex) => ({
        name: categories[rowIndex],
        data: presentSeries.map((seriesDeclaration) => ({
          x: seriesDeclaration.label,
          y: readFiniteNumber(row[seriesDeclaration.field]) ?? null,
        })),
      }))
      .reverse();
    const heatValues = heatRows.flatMap((heatRow) =>
      heatRow.data.map((heatCell) => heatCell.y).filter((value): value is number => value !== null),
    );
    const smallestHeat = Math.min(...heatValues);
    const heatRange = Math.max(...heatValues) - smallestHeat;
    // A count on a dark cell is written in the surface colour, on a pale cell in the text colour (ApexCharts shades a
    // cell by its place between the smallest and the largest value).
    const readHeatLabelColor = (labelContext: { seriesIndex: number; dataPointIndex: number }): string => {
      const heatValue = heatRows[labelContext.seriesIndex]?.data[labelContext.dataPointIndex]?.y;
      return typeof heatValue === "number" && heatRange > 0 && (heatValue - smallestHeat) / heatRange > 0.55
        ? palette.surfaceColor
        : palette.textColor;
    };
    return {
      status: "ok",
      model: {
        kind,
        apexType: "heatmap",
        series: heatRows as ApexAxisChartSeries,
        height: Math.max(height, rows.length * 44 + 16),
        seriesLabels,
        options: {
          ...baseOptions,
          colors: [palette.seriesColors[0]],
          legend: { show: false },
          dataLabels: {
            enabled: !isSparkline,
            formatter: (value: number) => formatValue(value),
            style: { colors: [readHeatLabelColor] as unknown as string[], fontWeight: 500 },
          },
          stroke: { width: 4, colors: [palette.surfaceColor] },
          plotOptions: { heatmap: { radius: 8, shadeIntensity: 0.6, enableShades: true } },
          xaxis: {
            position: "top",
            labels: { style: textStyle },
            axisBorder: { show: false },
            axisTicks: { show: false },
          },
          yaxis: { labels: { style: textStyle } },
        },
      },
    };
  }

  const series: ApexAxisChartSeries =
    kind === "scatter"
      ? presentSeries.map((seriesDeclaration) => ({
          name: seriesDeclaration.label,
          data: rows
            .map((row) => [readFiniteNumber(row[xField]), readFiniteNumber(row[seriesDeclaration.field])])
            .filter((point): point is [number, number] => point[0] !== undefined && point[1] !== undefined),
        }))
      : presentSeries.map((seriesDeclaration) => ({
          name: seriesDeclaration.label,
          data: rows.map((row) => readFiniteNumber(row[seriesDeclaration.field]) ?? null),
        }));
  const scatterAxisBounds =
    kind === "scatter"
      ? readNiceAxisBounds(
          rows.map((row) => readFiniteNumber(row[xField])).filter((value): value is number => value !== undefined),
          plotWidth > 0 && plotWidth < NARROW_PLOT_WIDTH ? 3 : 5,
        )
      : undefined;
  const formatAxisNumber = buildValueFormatter("number", undefined, locale);
  const categoryAxisTitle = { text: readText(props.xLabel), style: { color: palette.axisTextColor, fontWeight: 400 } };
  return {
    status: "ok",
    model: {
      kind: writtenKind ?? kind,
      apexType: kind,
      series,
      height: isHorizontalBar ? Math.max(height, rows.length * 36 + 40) : height,
      seriesLabels,
      options: {
        ...baseOptions,
        tooltip: kind === "scatter" ? baseOptions.tooltip : categoryTooltip,
        fill: kind === "area" ? { type: "gradient", gradient: { opacityFrom: 0.35, opacityTo: 0.02 } } : { opacity: 1 },
        plotOptions: {
          bar: {
            horizontal: isHorizontalBar,
            distributed: isDistributedBar,
            borderRadius: 4,
            columnWidth: "60%",
            barHeight: "60%",
            dataLabels: { position: isHorizontalBar ? "top" : "center" },
          },
        },
        dataLabels: isHorizontalBar
          ? {
              enabled: true,
              formatter: (value: number) => formatValue(value),
              offsetX: 28,
              style: { colors: [palette.axisTextColor], fontWeight: 500 },
            }
          : { enabled: false },
        legend: { ...baseOptions.legend, show: baseOptions.legend?.show === true && !isDistributedBar },
        xaxis:
          kind === "scatter"
            ? {
                type: "numeric",
                min: scatterAxisBounds?.min,
                max: scatterAxisBounds?.max,
                tickAmount: scatterAxisBounds?.tickAmount ?? 5,
                title: categoryAxisTitle,
                labels: {
                  style: textStyle,
                  rotate: 0,
                  hideOverlappingLabels: true,
                  formatter: (value: string) => formatAxisNumber(Number(value)),
                },
              }
            : isHorizontalBar
              ? {
                  categories,
                  title: categoryAxisTitle,
                  labels: { show: false, formatter: (value: string) => formatValue(Number(value)) },
                  axisBorder: { show: false },
                  axisTicks: { show: false },
                }
              : {
                  categories,
                  title: categoryAxisTitle,
                  labels: { style: textStyle, hideOverlappingLabels: true, rotate: 0, trim: false },
                  axisBorder: { show: false },
                  axisTicks: { show: false },
                  tickAmount: categories.length > 12 ? 6 : undefined,
                  tooltip: { enabled: false },
                },
        // Horizontal bars: the value labels sit right of the bar end — the axis leaves room for the longest one.
        yaxis: isHorizontalBar
          ? {
              max: axisMax ?? readLargestValue(series) * 1.15,
              min: axisMin ?? 0,
              labels: { style: { ...textStyle, fontSize: "14px" }, maxWidth: 160 },
            }
          : valueAxis,
      },
    },
  };
};
