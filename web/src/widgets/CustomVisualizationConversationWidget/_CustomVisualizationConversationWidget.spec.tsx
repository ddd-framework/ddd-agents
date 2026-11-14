// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { ConversationWidgetRenderContext } from "../../renderer/ConversationWidgetRenderContext";
import {
  WidgetRenderReportContext,
  type WidgetRenderReportEntry,
} from "../../renderer/render-report/widget-render-report";
import {
  CUSTOM_VISUALIZATION_FRAME_CHANNEL,
  type CustomVisualizationRenderMessage,
} from "./shared/custom-visualization-frame-protocol";
import { CustomVisualizationConversationWidget } from "./CustomVisualizationConversationWidget";

/**
 * The widget runs the block in a sandboxed frame and listens to it under the frame's policy: only `allow-scripts`, one render
 * message with a per-instance token, heights clamped, messages of other windows or tokens ignored, a frame that stops
 * beating removed — and it reports what it showed.
 */

const solarPayload = JSON.stringify({
  title: "Solar System Explorer",
  description: "Interaktive 3D-Projektion",
  html: '<canvas id="orbits"></canvas>',
  css: "canvas{width:100%}",
  script: "draw();",
  data: { bodies: [{ name: "Erde" }] },
  height: 420,
});

const renderWidget = (payload: string, renderContext: "conversation" | "email" = "conversation") => {
  const reports: WidgetRenderReportEntry[] = [];
  render(
    <ConversationWidgetRenderContext.Provider value={renderContext}>
      <WidgetRenderReportContext.Provider value={{ report: (entry) => reports.push(entry) }}>
        <CustomVisualizationConversationWidget payload={payload} interactive justDelivered />
      </WidgetRenderReportContext.Provider>
    </ConversationWidgetRenderContext.Provider>,
  );
  return reports;
};

const findFrame = (): HTMLIFrameElement => {
  const frame = document.querySelector<HTMLIFrameElement>("iframe[data-custom-visualization-frame]");
  if (!frame) throw new Error("no sandbox frame rendered");
  return frame;
};

/** Loads the frame and returns the render message the widget posted into it. */
const loadFrameAndReadRenderMessage = (frame: HTMLIFrameElement): CustomVisualizationRenderMessage => {
  const frameWindow = frame.contentWindow as Window;
  const postMessageSpy = vi.spyOn(frameWindow, "postMessage").mockImplementation(() => undefined);
  act(() => {
    fireEvent.load(frame);
  });
  expect(postMessageSpy).toHaveBeenCalledTimes(1);
  return postMessageSpy.mock.calls[0][0] as CustomVisualizationRenderMessage;
};

const postFromFrame = (
  frame: HTMLIFrameElement,
  data: Record<string, unknown>,
  source: Window | null = frame.contentWindow,
) => {
  act(() => {
    window.dispatchEvent(new MessageEvent("message", { data, source }));
  });
};

beforeEach(() => {
  vi.useFakeTimers({ toFake: ["setInterval", "clearInterval", "setTimeout", "clearTimeout", "Date"] });
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("CustomVisualizationConversationWidget", () => {
  test("runs the block in a frame sandboxed to scripts only and sends it one render message", () => {
    const reports = renderWidget(solarPayload);
    const frame = findFrame();
    expect(frame.getAttribute("sandbox")).toBe("allow-scripts");
    expect(frame.getAttribute("referrerpolicy")).toBe("no-referrer");
    expect(frame.getAttribute("title")).toBe("Solar System Explorer");
    expect(screen.getByText("Solar System Explorer")).toBeTruthy();
    expect(reports.at(-1)?.status).toBe("pending");

    const renderMessage = loadFrameAndReadRenderMessage(frame);
    expect(renderMessage).toMatchObject({
      channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL,
      type: "render",
      html: '<canvas id="orbits"></canvas>',
      script: "draw();",
      librarySources: [],
      data: { bodies: [{ name: "Erde" }] },
    });
    expect(renderMessage.token).toMatch(/^[0-9a-f]{32}$/);

    postFromFrame(frame, { channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL, token: renderMessage.token, type: "ready" });
    expect(reports.at(-1)?.status).toBe("mounted");
  });

  test("applies the frame's heights clamped and ignores messages of other windows and forged tokens", () => {
    renderWidget(solarPayload);
    const frame = findFrame();
    const { token } = loadFrameAndReadRenderMessage(frame);
    expect(frame.style.height).toBe("420px");

    postFromFrame(frame, { channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL, token, type: "height", height: 99999 });
    expect(frame.style.height).toBe("1200px");
    postFromFrame(frame, { channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL, token, type: "height", height: 12 });
    expect(frame.style.height).toBe("80px");

    postFromFrame(frame, { channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL, token: "forged", type: "height", height: 500 });
    postFromFrame(frame, { channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL, token, type: "height", height: 500 }, window);
    expect(frame.style.height).toBe("80px");
  });

  test("a frame that stops beating is removed and the block reports failed", () => {
    const reports = renderWidget(solarPayload);
    const frame = findFrame();
    const { token } = loadFrameAndReadRenderMessage(frame);
    postFromFrame(frame, { channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL, token, type: "heartbeat" });
    postFromFrame(frame, { channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL, token, type: "ready" });
    act(() => {
      vi.advanceTimersByTime(4000);
    });
    expect(document.querySelector("iframe[data-custom-visualization-frame]")).not.toBeNull();

    act(() => {
      vi.advanceTimersByTime(2000);
    });
    expect(document.querySelector("iframe[data-custom-visualization-frame]")).toBeNull();
    expect(screen.getByText("The visualization stopped working and was closed.")).toBeTruthy();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "RENDER_FAILED" });
    expect(reports.at(-1)?.detail).toContain("stopped responding");
  });

  test("a frame that never gets ready is removed after the ready deadline", () => {
    const reports = renderWidget(solarPayload);
    loadFrameAndReadRenderMessage(findFrame());
    act(() => {
      vi.advanceTimersByTime(16000);
    });
    expect(document.querySelector("iframe[data-custom-visualization-frame]")).toBeNull();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "RENDER_FAILED" });
  });

  test("a script error reports failed with the error line and keeps what rendered", () => {
    const reports = renderWidget(solarPayload);
    const frame = findFrame();
    const { token } = loadFrameAndReadRenderMessage(frame);
    postFromFrame(frame, {
      channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL,
      token,
      type: "error",
      message: "draw is not defined",
    });
    expect(document.querySelector("iframe[data-custom-visualization-frame]")).not.toBeNull();
    expect(screen.getByText("Part of this visualization could not run.")).toBeTruthy();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "RENDER_FAILED" });
    expect(reports.at(-1)?.detail).toContain("draw is not defined");
  });

  test("the model's title is shown as written, never parsed as markup", () => {
    renderWidget(JSON.stringify({ title: '<b onmouseover="x()">Orbits</b>', html: "<p></p>" }));
    expect(screen.getByText('<b onmouseover="x()">Orbits</b>')).toBeTruthy();
  });

  test("a block it does not run renders one muted line and reports why", () => {
    const reports = renderWidget(
      JSON.stringify({ title: "Chart.js", script: "new Chart();", libraries: ["chart.js"] }),
    );
    expect(document.querySelector("iframe")).toBeNull();
    expect(screen.getByText("This visualization could not be shown.")).toBeTruthy();
    expect(reports.at(-1)).toMatchObject({ status: "failed", code: "INVALID_PROP" });
    expect(reports.at(-1)?.detail).toContain("chart.js");
  });

  test("in the e-mail it renders the static preview without a frame", () => {
    const reports = renderWidget(solarPayload, "email");
    expect(document.querySelector("iframe")).toBeNull();
    expect(screen.getByText("Solar System Explorer")).toBeTruthy();
    expect(screen.getByText("Interactive visualization — open the conversation to use it.")).toBeTruthy();
    expect(reports.at(-1)?.status).toBe("mounted");
  });
});
