// @vitest-environment jsdom
import { renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { CHART_SERIES_COLOR_TOKENS } from "./chart-model";
import { useChartPalette } from "./use-chart-palette";
const createThemedElement = (properties) => {
    const element = document.createElement("div");
    Object.entries(properties).forEach(([name, value]) => element.style.setProperty(name, value));
    document.body.appendChild(element);
    return element;
};
describe("useChartPalette", () => {
    afterEach(() => {
        document.body.innerHTML = "";
    });
    it("reads the series colours from the --agents-chart-0N tokens and the text and surface colours from theirs", () => {
        const element = createThemedElement({
            "--agents-chart-01": "#112233",
            "--agents-chart-08": "#445566",
            "--agents-fg-light": "#010101",
            "--agents-fg": "#020202",
            "--agents-divider-gl": "#030303",
            "--agents-bg-surface": "#040404",
        });
        const { result } = renderHook(() => useChartPalette(element, "light"));
        expect(result.current.seriesColors).toHaveLength(CHART_SERIES_COLOR_TOKENS.length);
        expect(result.current.seriesColors[0]).toBe("#112233");
        expect(result.current.seriesColors[7]).toBe("#445566");
        expect(result.current.axisTextColor).toBe("#010101");
        expect(result.current.textColor).toBe("#020202");
        expect(result.current.gridColor).toBe("#030303");
        expect(result.current.surfaceColor).toBe("#040404");
    });
    it("falls back to the defaults for a token the host does not set, and without an element", () => {
        const element = createThemedElement({ "--agents-chart-01": "#112233" });
        const { result } = renderHook(() => useChartPalette(element, "light"));
        const { result: withoutElement } = renderHook(() => useChartPalette(null, "light"));
        expect(result.current.seriesColors[1]).toBe("#4f7fe8");
        expect(result.current.gridColor).toBe("#e5e7eb");
        expect(withoutElement.current.seriesColors).toEqual(Array(CHART_SERIES_COLOR_TOKENS.length).fill("#4f7fe8"));
        expect(withoutElement.current.surfaceColor).toBe("#ffffff");
    });
});
