import { Button, LoadingSpinner } from "../../provider/components";
import { classNames } from "../../provider/class-names";
import React, { Fragment, useEffect, useState } from "react";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetContext";
import { ConversationWidgetErrorBoundary } from "../../renderer/ConversationWidgetErrorBoundary";
import { canvasBehaviourOf } from "../../renderer/canvas/canvas-behaviours";
import {
  canvasBodies,
  canvasFeatureFlagOf,
} from "../../renderer/canvas/canvasBodies";
import { ConversationWidgetFeatureGate } from "../../renderer/_access/ConversationWidgetFeatureGate";
import { normalizeServerMessageToMarkdown } from "../normalize-server-message-to-markdown";
import type { ChatMessage } from "../chat-message";
import { useAgentsEnvironment } from "../../provider/AgentsProvider";
import classes from "./CanvasPane.module.scss";

interface CanvasPaneProps {
  /** The host's loaded messages — a message-anchored canvas body parses its fence out of its anchor message. */
  messages: ChatMessage[] | null;
  /** Render as a rounded surface card (agent side-split — the pane is inset below the floating top bar). */
  boxed?: boolean;
}

// The canvas slides in via a main-thread react-spring animation. Mounting a heavy body (e.g. an app's full list
// page) DURING that slide blocks the animation frames and makes opening stagger. So we hold the content for a beat — a
// light loader fills the pane while it slides in, then the body mounts.
const CANVAS_OPEN_SETTLE_MS = 350;

const OpeningLoader = () => (
  // Cheap, content-agnostic placeholder shown while the canvas slides in, so the open animation isn't blocked by the
  // body's mount. A centered spinner (not a content-shaped skeleton) because the canvas hosts many different bodies.
  <div className={classes.openingLoader} aria-hidden>
    <LoadingSpinner />
  </div>
);

/**
 * The CANVAS PANE — renders the canvas the chat's slice points at (`canvas`, a pointer to its anchor): the body from
 * `canvasBodies`, keyed on `mountGeneration`, geometry from its behaviour (`canvasBehaviourOf`), the canvas's own feature gate and the
 * shared error boundary. A message-anchored body receives its anchor message's markdown and re-reads its widget's
 * payload from it — nothing but the pointer is stored anywhere.
 */
export const CanvasPane = ({ messages, boxed = false }: CanvasPaneProps) => {
  const CanvasBodyContainer = useAgentsEnvironment().CanvasBodyContainer ?? Fragment;
  const { canvas, closeCanvas, chatAgentSpaceKey } = useConversationWidgetContext();

  // Re-deferred on every open (the mountGeneration changes each time a canvas mounts).
  const mountGeneration = canvas?.mountGeneration;
  const [contentReady, setContentReady] = useState(false);
  useEffect(() => {
    setContentReady(false);
    const timer = window.setTimeout(() => setContentReady(true), CANVAS_OPEN_SETTLE_MS);
    return () => window.clearTimeout(timer);
  }, [mountGeneration]);

  if (!canvas) {
    return null;
  }
  const Body = canvasBodies[canvas.canvasId];

  const behaviour = canvasBehaviourOf(canvas.canvasId);
  const featureFlag = canvasFeatureFlagOf(canvas.canvasId);
  const anchor = canvas.anchor;
  const anchorMessage =
    anchor.kind === "message" ? (messages ?? []).find((message) => message.id === anchor.messageId) : undefined;
  const anchorMessageMarkdown =
    anchorMessage !== undefined ? normalizeServerMessageToMarkdown(anchorMessage.message) : null;
  const body = <Body canvas={canvas} agentSpaceKey={chatAgentSpaceKey} anchorMessageMarkdown={anchorMessageMarkdown} />;

  return (
    <div
      className={classNames(
        classes.root,
        boxed ? classes.rootBoxed : "",
        behaviour?.fullBleed ? classes.rootFullBleedContent : "",
        behaviour?.overlayCloseButton ? classes.rootOverlayCloseButton : "",
      )}
      data-testid="chat-canvas-pane"
      data-canvas-id={canvas.canvasId}
    >
      <div className={classes.closeButtonRow}>
        <Button
          variant="muted"
          // Floating over the body's dark hero image: the light trigger for dark surfaces.
          inverted={behaviour?.overlayCloseButton}
          onDark={behaviour?.overlayCloseButton}
          icon="closeCanvas"
          iconPosition={"right"}
          rounded
          blurred
          // The customer's OWN close: a live re-delivery of this canvas is vetoed until the next send / click.
          onClick={() => closeCanvas(true)}
        >
          Close canvas
        </Button>
      </div>
      <div key={canvas.mountGeneration} className={classes.content}>
        {contentReady ? (
          // The app's wrapper of a canvas body (host application: canvas-hosted list pages must not rewrite the page URL).
          <CanvasBodyContainer>
            <ConversationWidgetErrorBoundary widgetName={`canvas:${canvas.canvasId}`}>
              {featureFlag ? (
                // The canvas owns its gate (not inherited from an opener): a restored or page-opened canvas has no
                // widget mount to inherit it from. A page anchor's agent space is the gate's agent space.
                <ConversationWidgetFeatureGate
                  featureFlag={featureFlag}
                  agentSpaceKeyAttribute={anchor.kind === "page" ? anchor.agentSpaceKey : undefined}
                >
                  {body}
                </ConversationWidgetFeatureGate>
              ) : (
                body
              )}
            </ConversationWidgetErrorBoundary>
          </CanvasBodyContainer>
        ) : (
          <OpeningLoader />
        )}
      </div>
    </div>
  );
};
