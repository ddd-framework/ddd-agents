import { useEffect, useRef } from "react";
import { canvasBehaviourOf } from "../renderer/canvas/canvas-behaviours";
import { useConversationWidgetContext } from "../renderer/ConversationWidgetContext";

export interface CanvasShellTransitionsInput {
  /** The conversation the view shows; undefined for a cold chat that has none yet. */
  conversationId?: number;
  /** The viewport is wide enough for the side split (`useCanvasViable`). */
  canvasViable: boolean;
}

/**
 * The two SHELL-driven transitions of the canvas model that `ConversationView` owns (the widget-driven ones go through
 * `openCanvas`; the send boundary is the hosts'):
 *
 *  - **A conversation switch** (history select / new chat) makes the previous conversation's canvas stale — it is
 *    cleared so the new conversation starts with a closed canvas. History replay never auto-opens a canvas (the
 *    opening rule rejects replay), and the LEFT conversation keeps its stored pointer for when the customer comes
 *    back. Only a REAL switch between two conversations counts. Not the first mount: a canvas the host opened on open
 *    (the `?agentWidget=` deep-link, a restored canvas) mounts its widget BEFORE this parent effect runs, and an
 *    unconditional reset would tear it down before the customer ever sees it. And not the id ARRIVING on a chat that
 *    had none: that is the same conversation, just persisted now (the first send creates it) — closing the customer's
 *    pinned work surface at that moment would be wrong.
 *  - **The wide → narrow crossing** closes the side-split canvas as a shell close (re-openable, no customer veto).
 *    Keyed on `canvasViable` ONLY (the canvas read through a ref) so it fires on the crossing, NOT when the mobile CTA
 *    opens a canvas on an already narrow viewport. Skipped for a canvas whose behaviour says `narrowViewport:
 *    "overlay"`: its widget renders nothing inline, so closing it on the crossing would leave the chat with no content
 *    at all — it stays open as the full-screen overlay.
 */
export const useCanvasShellTransitions = ({ conversationId, canvasViable }: CanvasShellTransitionsInput): void => {
  const { canvas, closeCanvas, resetCanvas } = useConversationWidgetContext();
  const canvasBehaviour = canvas ? (canvasBehaviourOf(canvas.canvasId) ?? null) : null;

  const previousConversationIdRef = useRef(conversationId);
  useEffect(() => {
    const previousConversationId = previousConversationIdRef.current;
    previousConversationIdRef.current = conversationId;
    if (previousConversationId === undefined || previousConversationId === conversationId) return;
    resetCanvas();
  }, [conversationId, resetCanvas]);

  const canvasClosesOnNarrowRef = useRef(false);
  canvasClosesOnNarrowRef.current = canvasBehaviour !== null && canvasBehaviour.narrowViewport === "none";
  useEffect(() => {
    if (!canvasViable && canvasClosesOnNarrowRef.current) closeCanvas(false);
  }, [canvasViable, closeCanvas]);
};
