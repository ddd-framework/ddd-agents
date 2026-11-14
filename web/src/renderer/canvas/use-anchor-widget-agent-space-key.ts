import { useMemo } from "react";
import { useResolvedConversationWidgetAgentSpaceKey } from "../_agent-space/use-resolved-conversation-widget-agent-space-key";
import type { CanvasState } from "./canvas-state";
import { payloadHashOfInstanceId } from "./canvas-state";
import { findWidgetFenceByPayloadHash, findWidgetFencesInMarkdown } from "./find-widget-fences-in-markdown";

/**
 * The key of the agent space the fence of the anchor message names — unresolved, exactly what the inline widget
 * receives as its prop. Undefined for a page anchor, a missing anchor message or a fence that names no agent space.
 * For a body whose inline widget resolves the key itself, so both resolve it the same way.
 */
export const useAnchorWidgetFenceAgentSpaceKey = (
  canvas: CanvasState,
  anchorMessageMarkdown: string | null,
  widgetName: string,
): string | undefined =>
  useMemo(() => {
    if (canvas.anchor.kind !== "message" || anchorMessageMarkdown === null) return undefined;
    const payloadHash = payloadHashOfInstanceId(canvas.instanceId);
    const fence =
      payloadHash !== undefined
        ? findWidgetFenceByPayloadHash(anchorMessageMarkdown, widgetName, payloadHash)
        : findWidgetFencesInMarkdown(anchorMessageMarkdown).find((candidate) => candidate.name === widgetName);
    return fence?.agentSpaceKey;
  }, [canvas.anchor, canvas.instanceId, anchorMessageMarkdown, widgetName]);

/**
 * The agent space a message-anchored canvas body works on, resolved the way its inline widget resolves it: the agent
 * space the fence names when it is ANOTHER one than the active one, else the chat's agent space (`chatAgentSpaceKey`,
 * passed by the shell), else the active one. For a page anchor: the anchor's agent space.
 *
 * A fence that names the ACTIVE agent space is dropped here in favour of the chat's agent space — the order of the
 * inline widgets that compute `anotherAgentSpaceKey ?? chatAgentSpaceKey`. A widget whose inline render lets the
 * fence win reads {@link useAnchorWidgetFenceAgentSpaceKey} instead.
 */
export const useAnchorWidgetAgentSpaceKey = (
  canvas: CanvasState,
  anchorMessageMarkdown: string | null,
  widgetName: string,
  chatAgentSpaceKey: number | undefined,
): number | undefined => {
  const fenceAgentSpaceKey = useAnchorWidgetFenceAgentSpaceKey(canvas, anchorMessageMarkdown, widgetName);
  const { anotherAgentSpaceKey, activeAgentSpaceKey } = useResolvedConversationWidgetAgentSpaceKey(fenceAgentSpaceKey);
  if (canvas.anchor.kind === "page") return canvas.anchor.agentSpaceKey;
  return anotherAgentSpaceKey ?? chatAgentSpaceKey ?? activeAgentSpaceKey;
};
