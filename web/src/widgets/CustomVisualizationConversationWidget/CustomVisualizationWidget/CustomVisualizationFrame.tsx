import { Text } from "../../../provider/components";
import { useEffect, useMemo, useRef, useState } from "react";
import { getSandboxLegacyAliases, translateText } from "../../../provider/environment";
import { SkeletonLine } from "../../_shared/skeleton-primitives";
import {
  useReportWidgetRenderStatus,
  type WidgetRenderReportCode,
  type WidgetRenderStatus,
} from "../../../renderer/render-report/widget-render-report";
import type { CustomVisualizationPayload } from "../shared/custom-visualization-widget-payload";
import { loadCustomVisualizationLibrarySources } from "../shared/custom-visualization-libraries";
import { resolveCustomVisualizationFrameUrl } from "../shared/custom-visualization-sandbox-config";
import { readCustomVisualizationTheme } from "../shared/read-custom-visualization-theme";
import {
  admitCustomVisualizationHeightMessage,
  clampCustomVisualizationHeight,
  createCustomVisualizationInstanceToken,
  CUSTOM_VISUALIZATION_FRAME_CHANNEL,
  CUSTOM_VISUALIZATION_HEARTBEAT_TIMEOUT_MS,
  CUSTOM_VISUALIZATION_READY_TIMEOUT_MS,
  CUSTOM_VISUALIZATION_WATCHDOG_INTERVAL_MS,
  readCustomVisualizationFrameMessage,
  type CustomVisualizationRenderMessage,
} from "../shared/custom-visualization-frame-protocol";
import styles from "./CustomVisualizationFrame.module.scss";

export interface CustomVisualizationFrameProps {
  payload: CustomVisualizationPayload;
  testId?: string;
}

/** Why the frame failed, in one English sentence for the model, and whether the frame was taken out. */
interface CustomVisualizationFrameFailure {
  detail: string;
  frameRemoved: boolean;
}

type CustomVisualizationFramePhase = "loading" | "ready" | "failed";

const FRAME_RENDER_REPORTS: Readonly<
  Record<CustomVisualizationFramePhase, { status: WidgetRenderStatus; code?: WidgetRenderReportCode }>
> = {
  loading: { status: "pending" },
  ready: { status: "mounted" },
  failed: { status: "failed", code: "RENDER_FAILED" },
};

/**
 * The model's visualization in its sandbox: an iframe `sandbox="allow-scripts"` (never same-origin,
 * top-navigation, popups or forms) on the configured frame page, which holds the policy `default-src 'none'`. The host
 * sends ONE render message (html, css, script, the allow-listed library sources, the block's data, the chat's colours)
 * and from then on only listens: heights (clamped, rate-limited), heartbeats, "ready" and script errors — from this
 * frame's `contentWindow` with this instance's token only. A frame that stops beating (an endless loop) or never gets
 * ready is removed and the block reports `failed`; a script error reports `failed` and leaves what rendered.
 */
export const CustomVisualizationFrame = (props: CustomVisualizationFrameProps) => {
  const { payload, testId = "custom-visualization-widget" } = props;
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const themeScopeRef = useRef<HTMLDivElement>(null);
  const [instanceToken] = useState(createCustomVisualizationInstanceToken);
  const frameUrl = useMemo(resolveCustomVisualizationFrameUrl, []);
  const [librarySources, setLibrarySources] = useState<string[] | null>(payload.libraries.length === 0 ? [] : null);
  const [isFrameLoaded, setIsFrameLoaded] = useState(false);
  const [phase, setPhase] = useState<CustomVisualizationFramePhase>("loading");
  const [failure, setFailure] = useState<CustomVisualizationFrameFailure | null>(null);
  const [frameHeight, setFrameHeight] = useState(payload.initialHeight);
  const renderMessageSentRef = useRef(false);
  const mountedAtRef = useRef(Date.now());
  const lastHeartbeatAtRef = useRef<number | null>(null);
  const appliedHeightTimesRef = useRef<number[]>([]);

  const failFrame = (detail: string, frameRemoved: boolean) => {
    setFailure((currentFailure) => currentFailure ?? { detail, frameRemoved });
    setPhase("failed");
  };

  useEffect(() => {
    if (payload.libraries.length === 0) return undefined;
    let isCurrent = true;
    loadCustomVisualizationLibrarySources(payload.libraries).then(
      (loadedSources) => {
        if (isCurrent) setLibrarySources(loadedSources);
      },
      () => {
        if (isCurrent) failFrame("A library of the CustomVisualization could not be loaded.", true);
      },
    );
    return () => {
      isCurrent = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [payload.libraries.join(",")]);

  // The one render message, once the frame page has loaded and the libraries are there.
  useEffect(() => {
    const frameWindow = iframeRef.current?.contentWindow;
    if (!isFrameLoaded || librarySources === null || renderMessageSentRef.current || !frameWindow) return;
    renderMessageSentRef.current = true;
    const theme = readCustomVisualizationTheme(themeScopeRef.current);
    const sandboxLegacyAliases = getSandboxLegacyAliases();
    const renderMessage: CustomVisualizationRenderMessage = {
      channel: CUSTOM_VISUALIZATION_FRAME_CHANNEL,
      type: "render",
      token: instanceToken,
      html: payload.html,
      css: payload.css,
      script: payload.script,
      librarySources,
      data: payload.data,
      theme: theme.variables,
      colorScheme: theme.colorScheme,
      visualizationGlobalNames: sandboxLegacyAliases.visualizationGlobalNames ?? [],
      themeVariablePrefixes: sandboxLegacyAliases.themeVariablePrefixes ?? [],
    };
    // The frame's origin is opaque, so no target origin can name it; the message goes to this frame's window only.
    frameWindow.postMessage(renderMessage, "*");
  }, [isFrameLoaded, librarySources, instanceToken, payload]);

  useEffect(() => {
    const receiveFrameMessage = (event: MessageEvent) => {
      const frameMessage = readCustomVisualizationFrameMessage(
        event,
        iframeRef.current?.contentWindow ?? null,
        instanceToken,
      );
      if (frameMessage === null) return;
      if (frameMessage.type === "heartbeat") {
        lastHeartbeatAtRef.current = Date.now();
      } else if (frameMessage.type === "ready") {
        lastHeartbeatAtRef.current = Date.now();
        setPhase((currentPhase) => (currentPhase === "loading" ? "ready" : currentPhase));
      } else if (frameMessage.type === "height") {
        if (admitCustomVisualizationHeightMessage(appliedHeightTimesRef.current, Date.now())) {
          setFrameHeight(clampCustomVisualizationHeight(frameMessage.height));
        }
      } else {
        failFrame(`The visualization's script failed: ${frameMessage.message.slice(0, 200)}`, false);
      }
    };
    window.addEventListener("message", receiveFrameMessage);
    return () => window.removeEventListener("message", receiveFrameMessage);
  }, [instanceToken]);

  // The watchdog: a frame that never gets ready, or that stops beating, is taken out. Paused while the tab is hidden
  // (the browser throttles the frame's timers there), restarted when it shows again.
  useEffect(() => {
    if (failure?.frameRemoved) return undefined;
    const restartDeadlinesWhenShown = () => {
      if (document.visibilityState !== "visible") return;
      const now = Date.now();
      if (lastHeartbeatAtRef.current !== null) lastHeartbeatAtRef.current = now;
      if (phase === "loading") mountedAtRef.current = now;
    };
    const checkDeadlines = () => {
      if (document.visibilityState === "hidden") return;
      const now = Date.now();
      if (phase === "loading" && now - mountedAtRef.current > CUSTOM_VISUALIZATION_READY_TIMEOUT_MS) {
        failFrame("The visualization did not finish loading in time and was closed.", true);
        return;
      }
      const lastHeartbeatAt = lastHeartbeatAtRef.current;
      if (lastHeartbeatAt !== null && now - lastHeartbeatAt > CUSTOM_VISUALIZATION_HEARTBEAT_TIMEOUT_MS) {
        failFrame("The visualization's script stopped responding (an endless loop?) and was closed.", true);
      }
    };
    const watchdogInterval = window.setInterval(checkDeadlines, CUSTOM_VISUALIZATION_WATCHDOG_INTERVAL_MS);
    document.addEventListener("visibilitychange", restartDeadlinesWhenShown);
    return () => {
      window.clearInterval(watchdogInterval);
      document.removeEventListener("visibilitychange", restartDeadlinesWhenShown);
    };
  }, [phase, failure?.frameRemoved]);

  const renderReport = FRAME_RENDER_REPORTS[phase];
  useReportWidgetRenderStatus(renderReport.status, renderReport.code, failure?.detail);

  if (failure?.frameRemoved) {
    return (
      <Text role="help" color="var(--agents-fg-light)" testId={`${testId}-closed`}>
        {"The visualization stopped working and was closed."}
      </Text>
    );
  }

  return (
    <div ref={themeScopeRef} className={styles.frameArea}>
      <div className={styles.frameBox} data-frame-phase={phase} data-testid={`${testId}-frame-box`}>
        {/* The frame's height is the one value the sandbox decides (clamped); it cannot live in a stylesheet. */}
        <iframe
          ref={iframeRef}
          className={styles.frame}
          data-testid={`${testId}-frame`}
          style={{ height: frameHeight }}
          src={frameUrl}
          sandbox="allow-scripts"
          referrerPolicy="no-referrer"
          title={payload.title || translateText("Custom visualization")}
          data-custom-visualization-frame=""
          onLoad={() => setIsFrameLoaded(true)}
        />
        {phase === "loading" ? (
          <span className={styles.loadingCover} aria-busy="true" data-testid={`${testId}-skeleton`}>
            <SkeletonLine height={frameHeight} radius={12} />
          </span>
        ) : null}
      </div>
      {phase === "failed" ? (
        <Text role="caption" color="var(--agents-fg-light)" testId={`${testId}-error`}>
          {"Part of this visualization could not run."}
        </Text>
      ) : null}
    </div>
  );
};
