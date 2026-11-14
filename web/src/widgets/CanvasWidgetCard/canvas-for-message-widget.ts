import type { CanvasId } from "../../renderer/canvas/canvas-behaviours";
import {
  canvasInstanceIdForMessagePayload,
  messageAnchor,
} from "../../renderer/canvas/canvas-state";
import type { CanvasWidgetCardCanvas } from "./CanvasWidgetCard";

/**
 * The canvas a payload widget in a message opens: anchored on the host message, one instance per message + payload.
 * Undefined while the widget does not know its message (the reply is still streaming, a composer echo, a story that
 * mounts the widget without a message) — the card then renders but opens nothing.
 */
export const canvasForMessageWidget = (
  canvasId: CanvasId,
  messageId: string | number | undefined,
  payload: string,
): CanvasWidgetCardCanvas | undefined => {
  const numericMessageId = messageId === undefined ? Number.NaN : Number(messageId);
  if (!Number.isFinite(numericMessageId)) return undefined;
  return {
    canvasId,
    instanceId: canvasInstanceIdForMessagePayload(canvasId, numericMessageId, payload),
    anchor: messageAnchor(numericMessageId),
  };
};
