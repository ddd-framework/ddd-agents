import { act, cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, test, vi } from "vitest";
import { DEFAULT_AGENTS_COMPONENTS } from "./default-components";
/**
 * The default split view: the open side pane narrows the main pane, and covers it below `coversBelowWidth` — full
 * width, above the main pane, with the covered main pane inert and a named toggle that stays reachable.
 */
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
        for (const callback of callbacks)
            callback([{ contentRect: { width } }], {});
    };
};
describe("DefaultSplitView", () => {
    const originalResizeObserver = globalThis.ResizeObserver;
    afterEach(() => {
        cleanup();
        globalThis.ResizeObserver = originalResizeObserver;
    });
    test("narrows the main pane when wide and covers it below coversBelowWidth", () => {
        const reportWidth = installManualResizeObserver();
        const { SplitView } = DEFAULT_AGENTS_COMPONENTS;
        render(<SplitView isSideOpen coversBelowWidth={850} main={{ width: "100%", narrowedWidth: "60%", content: <span>chat</span> }} side={{ width: "40%", content: <span>canvas</span> }}/>);
        const mainPane = screen.getByText("chat").parentElement;
        const sidePane = screen.getByText("canvas").parentElement;
        act(() => reportWidth(1200));
        expect(mainPane.style.width).toBe("60%");
        expect(sidePane.style.position).toBe("");
        act(() => reportWidth(480));
        expect(mainPane.style.width).toBe("100%");
        expect(sidePane.style.position).toBe("absolute");
        expect(sidePane.style.width).toBe("100%");
    });
    const renderSplitView = (isSideOpen, onToggle = vi.fn(), toggleOptions = {}) => {
        const { SplitView } = DEFAULT_AGENTS_COMPONENTS;
        const { withToggle = true, mainPaneShowsReopen } = toggleOptions;
        return render(<SplitView isSideOpen={isSideOpen} coversBelowWidth={850} sideToggle={withToggle ? { onToggle, verticalPositionPercent: 50, mainPaneShowsReopen } : undefined} main={{
                width: "100%",
                narrowedWidth: "60%",
                content: (<button type="button" data-testid="main-control">
              open
            </button>),
            }} side={{ width: "40%", narrowedWidth: "40%", content: <span>canvas</span> }}/>);
    };
    test("side by side: the divider toggle is named, carries an icon, and the panes keep their widths", () => {
        const reportWidth = installManualResizeObserver();
        const onToggle = vi.fn();
        renderSplitView(true, onToggle);
        act(() => reportWidth(1200));
        const toggle = screen.getByRole("button", { name: "Close canvas" });
        expect(toggle.getAttribute("aria-expanded")).toBe("true");
        expect(toggle.querySelector("[data-icon='closeCanvas']")).not.toBeNull();
        expect(toggle.style.position).toBe("");
        const mainPane = screen.getByTestId("main-control").parentElement;
        const sidePane = screen.getByText("canvas").parentElement;
        expect(mainPane.style.width).toBe("60%");
        expect(mainPane.hasAttribute("inert")).toBe(false);
        expect(mainPane.getAttribute("aria-hidden")).toBeNull();
        expect(sidePane.style.width).toBe("40%");
        expect(sidePane.style.position).toBe("");
        expect(sidePane.getAttribute("tabindex")).toBeNull();
        toggle.click();
        expect(onToggle).toHaveBeenCalledTimes(1);
    });
    test("side by side and closed: the toggle names the re-open", () => {
        const reportWidth = installManualResizeObserver();
        renderSplitView(false);
        act(() => reportWidth(1200));
        const toggle = screen.getByRole("button", { name: "Re-open canvas" });
        expect(toggle.getAttribute("aria-expanded")).toBe("false");
        expect(toggle.querySelector("[data-icon='maximize']")).not.toBeNull();
    });
    test("covered: the canvas takes the full width above the chat, the chat is inert, the named toggle stays", () => {
        const reportWidth = installManualResizeObserver();
        const onToggle = vi.fn();
        renderSplitView(true, onToggle);
        act(() => reportWidth(480));
        const mainPane = screen.getByTestId("main-control").parentElement;
        const sidePane = screen.getByText("canvas").parentElement;
        expect(mainPane.hasAttribute("inert")).toBe(true);
        expect(mainPane.getAttribute("aria-hidden")).toBe("true");
        expect(mainPane.style.width).toBe("100%");
        expect(sidePane.style.position).toBe("absolute");
        expect(sidePane.style.width).toBe("100%");
        expect(sidePane.style.maxWidth).toBe("100%");
        expect(sidePane.style.boxSizing).toBe("border-box");
        expect(sidePane.style.zIndex).toBe("20");
        expect(sidePane.style.isolation).toBe("isolate");
        expect(sidePane.style.background).not.toBe("");
        const toggle = screen.getByRole("button", { name: "Close canvas" });
        expect(sidePane.contains(toggle)).toBe(false);
        expect(mainPane.contains(toggle)).toBe(false);
        expect(toggle.style.position).toBe("absolute");
        expect(Number(toggle.style.zIndex)).toBeGreaterThan(20);
        toggle.click();
        expect(onToggle).toHaveBeenCalledTimes(1);
    });
    test("covering moves the focus into the canvas and back to the chat when the canvas closes", () => {
        const reportWidth = installManualResizeObserver();
        const view = renderSplitView(false);
        act(() => reportWidth(480));
        const mainControl = screen.getByTestId("main-control");
        act(() => mainControl.focus());
        expect(document.activeElement).toBe(mainControl);
        const { SplitView } = DEFAULT_AGENTS_COMPONENTS;
        const rerenderWith = (isSideOpen) => view.rerender(<SplitView isSideOpen={isSideOpen} coversBelowWidth={850} sideToggle={{ onToggle: vi.fn() }} main={{
                width: "100%",
                narrowedWidth: "60%",
                content: (<button type="button" data-testid="main-control">
                open
              </button>),
            }} side={{ width: "40%", content: <span>canvas</span> }}/>);
        act(() => rerenderWith(true));
        const sidePane = screen.getByText("canvas").parentElement;
        expect(document.activeElement).toBe(sidePane);
        act(() => rerenderWith(false));
        expect(document.activeElement).toBe(screen.getByTestId("main-control"));
    });
    test("narrow and closed with a canvas to re-open: the named toggle floats over the chat and re-opens", () => {
        const reportWidth = installManualResizeObserver();
        const onToggle = vi.fn();
        renderSplitView(false, onToggle);
        act(() => reportWidth(480));
        const toggle = screen.getByRole("button", { name: "Re-open canvas" });
        expect(toggle.getAttribute("aria-expanded")).toBe("false");
        expect(toggle.querySelector("[data-icon='maximize']")).not.toBeNull();
        expect(toggle.classList.contains("agents-split-view__toggle--floating")).toBe(true);
        expect(toggle.style.position).toBe("absolute");
        const mainPane = screen.getByTestId("main-control").parentElement;
        expect(mainPane.contains(toggle)).toBe(false);
        expect(mainPane.hasAttribute("inert")).toBe(false);
        expect(screen.queryByText("canvas")).toBeNull();
        toggle.click();
        expect(onToggle).toHaveBeenCalledTimes(1);
    });
    test("narrow and closed without a canvas to re-open: no toggle", () => {
        const reportWidth = installManualResizeObserver();
        renderSplitView(false, vi.fn(), { withToggle: false });
        act(() => reportWidth(480));
        expect(screen.queryByRole("button", { name: "Re-open canvas" })).toBeNull();
        expect(document.querySelector(".agents-split-view__toggle")).toBeNull();
    });
    test("narrow and closed while the chat shows its own re-open control: no second toggle", () => {
        const reportWidth = installManualResizeObserver();
        renderSplitView(false, vi.fn(), { mainPaneShowsReopen: true });
        act(() => reportWidth(480));
        expect(screen.queryByRole("button", { name: "Re-open canvas" })).toBeNull();
        expect(document.querySelector(".agents-split-view__toggle")).toBeNull();
    });
    test("narrow and open: the chat's own re-open control does not hide the close toggle", () => {
        const reportWidth = installManualResizeObserver();
        renderSplitView(true, vi.fn(), { mainPaneShowsReopen: true });
        act(() => reportWidth(480));
        expect(screen.getByRole("button", { name: "Close canvas" }).style.position).toBe("absolute");
    });
    test("wide: the divider toggle is unchanged, open or closed, with or without the chat's own re-open control", () => {
        const reportWidth = installManualResizeObserver();
        const closedView = renderSplitView(false, vi.fn(), { mainPaneShowsReopen: true });
        act(() => reportWidth(1200));
        const reopenToggle = screen.getByRole("button", { name: "Re-open canvas" });
        expect(reopenToggle.style.position).toBe("");
        expect(reopenToggle.classList.contains("agents-split-view__toggle--floating")).toBe(false);
        closedView.unmount();
        renderSplitView(true);
        act(() => reportWidth(1200));
        const closeToggle = screen.getByRole("button", { name: "Close canvas" });
        expect(closeToggle.style.position).toBe("");
        expect(closeToggle.classList.contains("agents-split-view__toggle--floating")).toBe(false);
    });
});
