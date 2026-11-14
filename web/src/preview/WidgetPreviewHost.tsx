import { Text } from "../provider/components";
import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { conversationWidgetRegistry } from "../renderer/conversationWidgetRegistry";
import { resolveWidgetName } from "../renderer/widget-name-resolver";
import {
  buildWidgetAgentSpaceKeyProps,
  readWidgetAgentSpaceKeyAttribute,
} from "../renderer/_agent-space/widget-agent-space-key-attribute";
import {
  CanvasOpenRequestFromOpener,
  ConversationWidgetCallbacks,
  StandaloneConversationWidgetProvider,
} from "../renderer/ConversationWidgetContext";
import { ConversationWidgetRenderContext } from "../renderer/ConversationWidgetRenderContext";
import { ConversationWidgetErrorBoundary } from "../renderer/ConversationWidgetErrorBoundary";
import {
  WidgetRenderReporter,
  WidgetRenderReportContext,
} from "../renderer/render-report/widget-render-report";
import type { CanvasState } from "../renderer/canvas/canvas-state";
import { canvasBodies } from "../renderer/canvas/canvasBodies";
import { canvasBehaviourOf } from "../renderer/canvas/canvas-behaviours";
import { decideCanvasOpen } from "../renderer/canvas/decide-canvas-open";
import { CANVAS_VIABLE_MIN_WIDTH } from "../widgets/CanvasWidgetCard/use-canvas-viable";
import { WidgetImageRenderContext } from "../image-render/WidgetImageRenderContext";
import { ComposedViewComponentsContext } from "../kit/composed-view-components";
import { buildPreviewComposedViewComponents } from "./preview-stub-components";
import { buildWidgetPreviewFence } from "./widget-preview-fixtures";
import type { WidgetPreviewReadiness } from "./widget-preview-readiness";
import type { WidgetPreviewBootstrap, WidgetPreviewFixture } from "./widget-preview.types";
import styles from "./WidgetPreview.module.scss";

/**
 * The widget preview host: ONE widget from ONE fixture, outside the chat, in the mode the script
 * asks for. Unlike the e-mail capture host it keeps a working canvas pane (an `openCanvas` request mounts the canvas
 * body beside the widget, its anchor message being the fixture's fence) and a composer log (what the widget sent,
 * prefilled or signalled), so the interactions of the ChatGPT reference can be driven and checked. The app mounts it in
 * its store (the capture's store state); every request is served from the fixture (`widget-preview-requests.ts`). The fixture's
 * `hydration` is the capture's frozen hydration only in `email` mode; `interactive` and `replay` serve it through the
 * preview's hydrators (`widget-preview-hydration.ts`), so the widget runs as in the chat (a canvas auto-opens).
 */

/** The key of the agent space a fixture without its own renders for. */
export const PREVIEW_AGENT_SPACE_KEY = 1;
const PREVIEW_MESSAGE_ID = 1;

interface ComposerLogEntry {
  kind: "message" | "prefill" | "signal";
  text: string;
}

export interface WidgetPreviewProps {
  bootstrap: WidgetPreviewBootstrap;
  fixture: WidgetPreviewFixture;
  readiness: WidgetPreviewReadiness;
  hasHydrationError: () => boolean;
}

export const WidgetPreview = (props: WidgetPreviewProps) => {
  const { bootstrap, fixture, readiness, hasHydrationError } = props;
  const widgetRef = useRef<HTMLDivElement>(null);
  const widgetName = resolveWidgetName(bootstrap.widgetName);
  const entry = conversationWidgetRegistry.widgets.find((widget) => widget.name === widgetName);
  const [canvas, setCanvas] = useState<CanvasState | null>(null);
  const [composerLog, setComposerLog] = useState<ComposerLogEntry[]>([]);
  const agentSpaceKey = Number(readWidgetAgentSpaceKeyAttribute(fixture) ?? PREVIEW_AGENT_SPACE_KEY);
  const fence = useMemo(
    () => buildWidgetPreviewFence(widgetName ?? bootstrap.widgetName, fixture),
    [widgetName, bootstrap.widgetName, fixture],
  );
  const isEmail = bootstrap.mode === "email";
  const isInteractive = bootstrap.mode === "interactive";
  // The capture context switches every widget to its static e-mail branch (no canvas, no auto-open): e-mail mode only.
  const frozenHydration =
    isEmail && fixture.hydration && Object.keys(fixture.hydration).length > 0 ? fixture.hydration : null;

  useEffect(() => {
    if (!entry) readiness.fail("UNKNOWN_WIDGET");
    // The whole page document: the canvas pane and a modal (the lightbox renders into the modal root) settle too.
    else if (widgetRef.current) return readiness.observe(document.body, widgetRef.current, hasHydrationError);
  }, [entry, readiness, hasHydrationError]);
  // The block's render report reaches the readiness as in the chat it reaches the collector.
  const renderReporter = useMemo<WidgetRenderReporter>(() => ({ report: readiness.recordRenderReport }), [readiness]);

  const appendComposerLog = useCallback(
    (kind: ComposerLogEntry["kind"], text: string) => setComposerLog((entries) => [...entries, { kind, text }]),
    [],
  );
  // The chat's opening rule decides, as in the conversation slice: a live delivery on a narrow window does not take
  // over for a canvas without an overlay, so the narrow shot shows the inline rows and the "Open in canvas" card.
  const openCanvas = useCallback((request: CanvasOpenRequestFromOpener) => {
    setCanvas((current) => {
      const decision = decideCanvasOpen(canvasBehaviourOf(request.canvasId), current, null, {
        ...request,
        viewportViable: window.innerWidth >= CANVAS_VIABLE_MIN_WIDTH,
        hostHasCanvasSurface: true,
      });
      if (decision.kind === "ignore") return current;
      return {
        canvasId: request.canvasId,
        instanceId: request.instanceId,
        anchor: request.anchor,
        origin: request.origin,
        mountGeneration: (current?.mountGeneration ?? 0) + 1,
      };
    });
  }, []);
  const callbacks = useMemo<Partial<ConversationWidgetCallbacks>>(
    () => ({
      canvas,
      hostHasCanvasSurface: !isEmail,
      openCanvas: isEmail ? () => {} : openCanvas,
      closeCanvas: () => setCanvas(null),
      chatAgentSpaceKey: agentSpaceKey,
      messages: [],
      sendMessageToChat: (text: string) => appendComposerLog("message", text),
      prefillComposer: (text: string) => appendComposerLog("prefill", text),
      injectSignal: (text: string) => {
        appendComposerLog("signal", text);
        return Promise.resolve(true);
      },
    }),
    [canvas, isEmail, openCanvas, agentSpaceKey, appendComposerLog],
  );

  if (!entry) return null;
  const WidgetComponent = entry.component;
  const CanvasBody = canvas ? canvasBodies[canvas.canvasId] : null;
  const widget = (
    <ConversationWidgetErrorBoundary widgetName={entry.name} onCrash={readiness.recordWidgetCrash}>
      <WidgetRenderReportContext.Provider value={renderReporter}>
        <WidgetComponent
          payload={JSON.stringify(fixture.payload)}
          {...buildWidgetAgentSpaceKeyProps(String(agentSpaceKey))}
          interactive={isInteractive}
          fresh={isInteractive}
          justDelivered={isInteractive}
          messageId={String(PREVIEW_MESSAGE_ID)}
          openedByPage={false}
        />
      </WidgetRenderReportContext.Provider>
    </ConversationWidgetErrorBoundary>
  );
  return (
    <StandaloneConversationWidgetProvider callbacks={callbacks}>
      <ConversationWidgetRenderContext.Provider value={isEmail ? "email" : "conversation"}>
        <WidgetImageRenderContext.Provider value={frozenHydration}>
          <div className={styles.page} data-testid="widget-preview-page">
            <div className={styles.chatColumn} data-testid="widget-preview-frame">
              <div
                ref={widgetRef}
                className={styles.widget}
                data-testid="widget-preview-widget"
                inert={isEmail || undefined}
              >
                {fixture.stubComposedViewComponents ? (
                  <ComposedViewComponentsContext.Provider value={buildPreviewComposedViewComponents()}>
                    {widget}
                  </ComposedViewComponentsContext.Provider>
                ) : (
                  widget
                )}
              </div>
              {composerLog.length > 0 ? (
                <div className={styles.composerLog} data-testid="widget-preview-composer-log">
                  {composerLog.map((composerEntry, entryIndex) => (
                    <Text
                      key={entryIndex}
                      role="caption"
                      translate={false}
                      className={styles.composerEntry}
                    >
                      {`${composerEntry.kind}: ${composerEntry.text}`}
                    </Text>
                  ))}
                </div>
              ) : null}
            </div>
            {canvas && CanvasBody ? (
              <div className={styles.canvasPane} data-testid="widget-preview-canvas" data-canvas-id={canvas.canvasId}>
                <CanvasBody
                  key={`${canvas.instanceId}#${canvas.mountGeneration}`}
                  canvas={canvas}
                  agentSpaceKey={agentSpaceKey}
                  anchorMessageMarkdown={fence}
                />
              </div>
            ) : null}
          </div>
        </WidgetImageRenderContext.Provider>
      </ConversationWidgetRenderContext.Provider>
    </StandaloneConversationWidgetProvider>
  );
};
