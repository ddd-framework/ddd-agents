import { useMemo } from "react";
import { CHART_SERIES_COLOR_TOKENS } from "./chart-model";
/** Fallbacks for a host without the theme stylesheet (a spec): the direct-channel values are irrelevant there. */
const FALLBACK_SERIES_COLOR = "#4f7fe8";
const FALLBACK_TEXT_COLOR = "#6b7280";
const FALLBACK_STRONG_TEXT_COLOR = "#111827";
const FALLBACK_GRID_COLOR = "#e5e7eb";
const FALLBACK_SURFACE_COLOR = "#ffffff";
const readToken = (computedStyle, token, fallback) => computedStyle?.getPropertyValue(token).trim() || fallback;
/**
 * The chart's colours, read from the theme tokens (`--agents-chart-01` … `--agents-chart-08` for the series,
 * `--agents-fg-light` for the axis text, `--agents-fg` for a donut's total,
 * `--agents-divider-gl` for the grid, `--agents-bg-surface` for the slice gaps). ApexCharts writes colours into SVG attributes
 * and computes shades from them, where a `var()` does not resolve — so the values are read once per scheme from the
 * element's computed style, never written as hex in the component.
 */
export const useChartPalette = (element, colorScheme) => useMemo(() => {
    const computedStyle = element && typeof window !== "undefined" ? window.getComputedStyle(element) : undefined;
    return {
        seriesColors: CHART_SERIES_COLOR_TOKENS.map((token) => readToken(computedStyle, token, FALLBACK_SERIES_COLOR)),
        axisTextColor: readToken(computedStyle, "--agents-fg-light", FALLBACK_TEXT_COLOR),
        textColor: readToken(computedStyle, "--agents-fg", FALLBACK_STRONG_TEXT_COLOR),
        gridColor: readToken(computedStyle, "--agents-divider-gl", FALLBACK_GRID_COLOR),
        surfaceColor: readToken(computedStyle, "--agents-bg-surface", FALLBACK_SURFACE_COLOR),
    };
    // The scheme is read so a light/dark switch re-reads the tokens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
}, [element, colorScheme]);
