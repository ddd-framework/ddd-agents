import { useMemo } from "react";
import type { CanvasState } from "./canvas-state";
import { payloadHashOfInstanceId } from "./canvas-state";
import { findWidgetFenceByPayloadHash, findWidgetFencesInMarkdown } from "./find-widget-fences-in-markdown";

/**
 * The payload string of the widget a message-anchored canvas was opened from: the fence of `widgetName` in the anchor
 * message whose payload hash the canvas instance id carries (`canvasInstanceIdForMessagePayload`); when the instance
 * id carries no hash (one canvas per message), the first fence of that name. Undefined for a page anchor, a missing
 * anchor message, or a message that no longer carries the fence — the body then renders nothing.
 */
export const useAnchorWidgetPayload = (
  canvas: CanvasState,
  anchorMessageMarkdown: string | null,
  widgetName: string,
): string | undefined =>
  useMemo(() => {
    if (canvas.anchor.kind !== "message" || anchorMessageMarkdown === null) return undefined;
    const payloadHash = payloadHashOfInstanceId(canvas.instanceId);
    if (payloadHash !== undefined) {
      return findWidgetFenceByPayloadHash(anchorMessageMarkdown, widgetName, payloadHash)?.payload;
    }
    return findWidgetFencesInMarkdown(anchorMessageMarkdown).find((fence) => fence.name === widgetName)?.payload;
  }, [canvas.anchor, canvas.instanceId, anchorMessageMarkdown, widgetName]);
