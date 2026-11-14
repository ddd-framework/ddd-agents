import { Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import { useCallback, useEffect, useState } from "react";
import { SkeletonLine } from "../../../widgets/_shared/skeleton-primitives";
import type { ComposedViewComponentDefinition, ComposedViewComponentProps } from "../../composed-view-component-types";
import { ApexChart } from "./apex-chart-component";
import {
  buildChartBarRowsModel,
  buildChartFunnelModel,
  buildChartModel,
  readChartDeltaField,
  readChartKind,
} from "./chart-model";
import { ChartBarRows } from "./ChartBarRows/ChartBarRows";
import { ChartFunnelRows } from "./ChartFunnelRows/ChartFunnelRows";
import { readText } from "./data-value-format";
import { useChartPalette } from "./use-chart-palette";
import { useNumberLocale } from "./use-number-locale";
import { DataFootnote } from "./DataFootnote/DataFootnote";
import { DataEmptyLine } from "./DataEmptyLine/DataEmptyLine";
import { useReportDataNodeStatus } from "./use-report-data-node-status";
import styles from "./Chart.module.scss";

/** The light/dark scheme the page is in (`data-color-scheme` on `<html>`), so the palette is re-read on a switch. */
const useColorScheme = (): string | null => {
  const [colorScheme, setColorScheme] = useState<string | null>(() =>
    typeof document === "undefined" ? null : document.documentElement.getAttribute("data-color-scheme"),
  );
  useEffect(() => {
    if (typeof MutationObserver === "undefined") return undefined;
    const observer = new MutationObserver(() =>
      setColorScheme(document.documentElement.getAttribute("data-color-scheme")),
    );
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ["data-color-scheme"] });
    return () => observer.disconnect();
  }, []);
  return colorScheme;
};

/**
 * A remount key that changes when the chart's container width changes. ApexCharts measures its parent once and misses
 * the chat column narrowing when a canvas opens (an app widget that draws an ApexCharts chart keys it the same way).
 */
const useContainerWidthKey = () => {
  const [element, setElement] = useState<HTMLDivElement | null>(null);
  const [widthKey, setWidthKey] = useState(0);
  useEffect(() => {
    if (!element || typeof ResizeObserver === "undefined") return undefined;
    const observer = new ResizeObserver((entries) => {
      const measuredWidth = Math.round(entries[0]?.contentRect.width ?? 0);
      setWidthKey((previousWidth) => (previousWidth === measuredWidth ? previousWidth : measuredWidth));
    });
    observer.observe(element);
    return () => observer.disconnect();
  }, [element]);
  const containerRef = useCallback((container: HTMLDivElement | null) => setElement(container), []);
  return { element, containerRef, widthKey };
};

/**
 * The `Chart` node: line, area, bar (horizontal for a ranking
 * or poll), pie, donut, scatter and heatmap through ApexCharts, in the theme's chart colours, with the legend under
 * the plot (a click on an entry hides its series), tooltips on hover and the values formatted like every data node. In a
 * compact slot (a table cell) it is a sparkline without axes, legend and title. A chart still waiting for its bound
 * rows shows a plot-shaped skeleton; no rows show one muted line and report `empty`; rows without any number in the
 * named series report `failed` (`INVALID_PROP`). The e-mail capture draws it without animation and tooltips.
 *
 * A bar chart with a `deltaField` (a measurement against an earlier one) draws its bars itself
 * ({@link ChartBarRows}): one horizontal bar per category with its value and the signed change at the right, coloured
 * by sign, and the `deltaLabel` line under the bars ("Right: change in percentage points since January 2026"; without
 * one, a `pp` change names its unit in the kit's own line). In a compact slot it stays a sparkline.
 *
 * A funnel (no ApexCharts funnel in the installed 3.35) draws its stages itself ({@link ChartFunnelRows}): centered
 * bars in the written order with each stage's value and its conversion from the stage before. A radar is ApexCharts'
 * radar: one axis per row, one polygon per series, the value axis hidden (the tooltip names the values).
 */
const ChartNode = (props: ComposedViewComponentProps) => {
  const { props: nodeProps, runtime, isPending, sizeClass, testId } = props;
  const locale = useNumberLocale();
  const colorScheme = useColorScheme();
  const { element, containerRef, widthKey } = useContainerWidthKey();
  const palette = useChartPalette(element, colorScheme);
  const isSparkline = sizeClass === "compact";
  const hasChangePerBar = readChartDeltaField(nodeProps) !== undefined && !isSparkline;
  const isFunnel = readChartKind(nodeProps.kind) === "funnel" && !isSparkline;
  const barRowsModelResult = hasChangePerBar ? buildChartBarRowsModel({ props: nodeProps, locale }) : undefined;
  const funnelModelResult = isFunnel ? buildChartFunnelModel({ props: nodeProps, locale }) : undefined;
  const chartModelResult =
    barRowsModelResult ??
    funnelModelResult ??
    buildChartModel({
      props: nodeProps,
      sizeClass,
      locale,
      palette,
      isEmailRender: runtime.isEmailRender,
      plotWidth: widthKey,
    });
  const drawsItsOwnRows = hasChangePerBar || isFunnel;
  const isWaiting = isPending && chartModelResult.status !== "ok";
  useReportDataNodeStatus(
    runtime,
    isWaiting
      ? "pending"
      : chartModelResult.status === "ok"
        ? "mounted"
        : chartModelResult.status === "empty"
          ? "empty"
          : "failed",
    isWaiting || chartModelResult.status === "ok"
      ? undefined
      : chartModelResult.status === "empty"
        ? "NO_DATA"
        : "INVALID_PROP",
    isWaiting || chartModelResult.status === "ok" ? undefined : chartModelResult.detail,
  );
  const title = readText(nodeProps.title);
  const kind = readChartKind(nodeProps.kind) ?? "line";
  const plottedSeriesLabels =
    barRowsModelResult?.status === "ok"
      ? [barRowsModelResult.model.seriesLabel]
      : funnelModelResult?.status === "ok"
        ? [funnelModelResult.model.seriesLabel]
        : chartModelResult.status === "ok" && "seriesLabels" in chartModelResult.model
          ? chartModelResult.model.seriesLabels
          : [];
  const accessibleLabel =
    chartModelResult.status === "ok" ? [title, plottedSeriesLabels.join(", ")].filter(Boolean).join(": ") : title;
  const deltaLabel = readText(nodeProps.deltaLabel);
  const deltaUnitLine =
    barRowsModelResult?.status === "ok" && barRowsModelResult.model.deltaUnit === "pp"
      ? "Right: change in percentage points"
      : barRowsModelResult?.status === "ok" && barRowsModelResult.model.deltaUnit === "%"
        ? "Right: change in percent"
        : undefined;
  return (
    <figure className={classNames(styles.chart, isSparkline && styles.sparkline)} data-testid={testId}>
      {title && !isSparkline ? (
        <Text role="body" weight="bold" translate={false}>
          {escapePlainTextForText(title)}
        </Text>
      ) : null}
      <div
        ref={containerRef}
        className={styles.plot}
        role={chartModelResult.status === "ok" && !drawsItsOwnRows ? "img" : undefined}
        aria-label={drawsItsOwnRows ? undefined : accessibleLabel}
        data-chart-kind={kind}
      >
        {isWaiting ? (
          <div className={styles.skeleton} aria-busy="true" data-testid={`${testId}-skeleton`}>
            <SkeletonLine width="100%" height={isSparkline ? 32 : 240} radius={12} />
          </div>
        ) : barRowsModelResult?.status === "ok" ? (
          <ChartBarRows model={barRowsModelResult.model} accessibleLabel={accessibleLabel} testId={`${testId}-rows`} />
        ) : funnelModelResult?.status === "ok" ? (
          <ChartFunnelRows
            model={funnelModelResult.model}
            accessibleLabel={accessibleLabel}
            testId={`${testId}-stages`}
          />
        ) : chartModelResult.status === "ok" && "options" in chartModelResult.model ? (
          <ApexChart
            key={`${widthKey}-${chartModelResult.model.apexType}`}
            type={chartModelResult.model.apexType}
            series={chartModelResult.model.series}
            options={chartModelResult.model.options}
            height={chartModelResult.model.height}
            width="100%"
          />
        ) : isSparkline ? null : (
          <DataEmptyLine testId={`${testId}-empty`} />
        )}
      </div>
      {hasChangePerBar && chartModelResult.status === "ok" && deltaLabel ? (
        <Text role="caption" color="var(--agents-fg-light)" translate={false}>
          {escapePlainTextForText(deltaLabel)}
        </Text>
      ) : hasChangePerBar && chartModelResult.status === "ok" && deltaUnitLine ? (
        <Text role="caption" color="var(--agents-fg-light)">
          {deltaUnitLine}
        </Text>
      ) : null}
      {isSparkline ? null : (
        <DataFootnote caption={nodeProps.caption} source={nodeProps.source} isEmailRender={runtime.isEmailRender} />
      )}
    </figure>
  );
};

export const composedViewComponent: ComposedViewComponentDefinition = { nodeType: "Chart", component: ChartNode };
