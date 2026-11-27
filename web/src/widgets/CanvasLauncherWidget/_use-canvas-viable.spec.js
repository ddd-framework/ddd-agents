import { act, cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { CANVAS_VIABLE_MIN_WIDTH, CanvasViabilityContext, ElementWidthCanvasViability, useCanvasViable, } from "./use-canvas-viable";
/**
 * The canvas viability of a chat: measured on the chat's own container (a narrow column in a wide window is narrow),
 * the viewport only while the container is not laid out, and the consumers re-render only when it flips.
 */
/** A ResizeObserver whose observed sizes the spec reports by hand. */
const installManualResizeObserver = () => {
    const callbacks = [];
    globalThis.ResizeObserver = class {
        constructor(callback) {
            callbacks.push(callback);
        }
        observe = vi.fn();
        unobserve = vi.fn();
        disconnect = vi.fn();
    };
    return (width) => {
        for (const callback of callbacks) {
            callback([{ contentRect: { width } }], {});
        }
    };
};
const ViabilityProbe = () => <span data-testid="viability">{useCanvasViable() ? "split" : "cover"}</span>;
describe("canvas viability", () => {
    const originalResizeObserver = globalThis.ResizeObserver;
    const originalInnerWidth = window.innerWidth;
    beforeEach(() => {
        Object.defineProperty(window, "innerWidth", { configurable: true, value: 1600 });
    });
    afterEach(() => {
        cleanup();
        globalThis.ResizeObserver = originalResizeObserver;
        Object.defineProperty(window, "innerWidth", { configurable: true, value: originalInnerWidth });
    });
    test("follows the container's width, not the viewport's, and notifies only on a flip", () => {
        const reportWidth = installManualResizeObserver();
        const canvasViability = new ElementWidthCanvasViability();
        const listener = vi.fn();
        canvasViability.subscribe(listener);
        canvasViability.observe(document.createElement("div"));
        // Not laid out yet (jsdom has no layout): the viewport decides.
        expect(canvasViability.isViable()).toBe(true);
        reportWidth(480);
        expect(canvasViability.isViable()).toBe(false);
        reportWidth(470);
        reportWidth(CANVAS_VIABLE_MIN_WIDTH);
        expect(canvasViability.isViable()).toBe(true);
        expect(listener).toHaveBeenCalledTimes(2);
    });
    test("useCanvasViable reads the chat's source when one is provided, the viewport otherwise", () => {
        const reportWidth = installManualResizeObserver();
        const canvasViability = new ElementWidthCanvasViability();
        canvasViability.observe(document.createElement("div"));
        render(<CanvasViabilityContext.Provider value={canvasViability}>
        <ViabilityProbe />
      </CanvasViabilityContext.Provider>);
        expect(screen.getByTestId("viability")).toHaveTextContent("split");
        act(() => reportWidth(480));
        expect(screen.getByTestId("viability")).toHaveTextContent("cover");
        cleanup();
        render(<ViabilityProbe />);
        expect(screen.getByTestId("viability")).toHaveTextContent("split");
    });
});
