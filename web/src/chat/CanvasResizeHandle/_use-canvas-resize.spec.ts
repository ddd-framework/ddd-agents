import { describe, expect, test } from "vitest";
import { canvasWidthLimits, clampCanvasWidth, rubberBand } from "./use-canvas-resize";

describe("canvas resize limits", () => {
  test("the canvas keeps its narrowest and the chat column keeps its own", () => {
    expect(canvasWidthLimits(1400, 0)).toEqual({ min: 420, max: 1040 });
  });

  test("the boxed card's inset widens the pane so the card itself keeps the narrowest", () => {
    expect(canvasWidthLimits(1400, 8)).toEqual({ min: 436, max: 1040 });
  });

  test("clamps a pull to the limits and rounds it", () => {
    expect(clampCanvasWidth(100, 1400, 0)).toBe(420);
    expect(clampCanvasWidth(5000, 1400, 0)).toBe(1040);
    expect(clampCanvasWidth(600.4, 1400, 0)).toBe(600);
  });

  test("a window too narrow for both falls back to the narrowest", () => {
    expect(canvasWidthLimits(700, 0)).toEqual({ min: 420, max: 420 });
  });
});

describe("rubberBand", () => {
  test("stretches towards its maximum, never past it, and keeps the pull's sign", () => {
    expect(rubberBand(0)).toBe(0);
    expect(rubberBand(10)).toBeGreaterThan(0);
    expect(rubberBand(10000)).toBeLessThanOrEqual(44);
    expect(rubberBand(-30)).toBe(-rubberBand(30));
  });
});
