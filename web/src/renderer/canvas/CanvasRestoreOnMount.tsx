import { useEffect, useRef } from "react";
import type { ChatMessage } from "../../chat/chat-message";
import { normalizeServerMessageToMarkdown } from "../../chat/normalize-server-message-to-markdown";
import { useConversationWidgetContext } from "../ConversationWidgetCallbacksContext";
import { conversationWidgetRegistry } from "../conversationWidgetRegistry";
import { canvasBehaviourOf } from "./canvas-behaviours";
import { payloadHashOfInstanceId } from "./canvas-state";
import { StoredCanvasPointer, clearStoredCanvas, readStoredCanvas } from "./canvasStorage";
import { findWidgetFencesInMarkdown } from "./find-widget-fences-in-markdown";

export interface CanvasRestoreOnMountProps {
  /** The conversation the host shows. Undefined for a cold chat — nothing to restore. */
  conversationId?: number;
  /** The host's loaded messages. A message-anchored restore waits for THIS conversation's page to be in. */
  messages: ChatMessage[] | null;
}

/** Does the anchor message still carry a widget that opens this canvas — the same instance, when the id names one? */
const anchorMessageCarriesCanvasWidget = (
  pointer: StoredCanvasPointer,
  anchorMessage: ChatMessage,
): boolean => {
  const openers = new Set(
    conversationWidgetRegistry.widgets
      .filter((entry) => entry.opensCanvases?.includes(pointer.canvasId))
      .map((entry) => entry.name),
  );
  if (openers.size === 0) return false;
  const payloadHash = payloadHashOfInstanceId(pointer.instanceId);
  return findWidgetFencesInMarkdown(normalizeServerMessageToMarkdown(anchorMessage.message)).some(
    (fence) => openers.has(fence.name) && (payloadHash === undefined || fence.payloadHash === payloadHash),
  );
};

/**
 * Restores the canvas that was open in this conversation — on a reload, in a new tab, and when the customer comes
 * back to the conversation (A → B → A alike). Must render INSIDE the provider (it dispatches
 * through the context) and runs once per conversation, after the mount reset. A PAGE-anchored canvas (an app's
 * editor opened from a page) needs no message and restores as soon as the conversation id is known. A MESSAGE-anchored
 * canvas waits for the conversation's own messages and has three limits:
 *  - the anchor message must be in the loaded page — otherwise the canvas is that far back and irrelevant: the pointer
 *    is dropped, nothing is paged in;
 *  - a `turn` canvas whose anchor message is no longer the newest assistant message is not restored — its turn is over;
 *  - the anchor message must still carry a widget that opens this canvas (a stale or mangled pointer opens nothing).
 */
export const CanvasRestoreOnMount = ({ conversationId, messages }: CanvasRestoreOnMountProps) => {
  const { openCanvas } = useConversationWidgetContext();
  const restoredForConversationIdRef = useRef<number | undefined>(undefined);

  useEffect(() => {
    if (conversationId === undefined || restoredForConversationIdRef.current === conversationId) return;
    const pointer = readStoredCanvas(conversationId);
    if (pointer === null) {
      restoredForConversationIdRef.current = conversationId;
      return;
    }

    const restore = () =>
      openCanvas({
        canvasId: pointer.canvasId,
        instanceId: pointer.instanceId,
        anchor: pointer.anchor,
        origin: "restoredAfterReload",
        justDelivered: false,
        ...(pointer.createdEntityId !== undefined ? { createdEntityId: pointer.createdEntityId } : {}),
      });

    if (pointer.anchor.kind === "page") {
      restoredForConversationIdRef.current = conversationId;
      restore();
      return;
    }

    // Right after a switch the slice still holds the PREVIOUS conversation's messages for a beat — wait until the
    // list belongs to this conversation, or the anchor checks would read the wrong transcript.
    if (messages === null || !messages.some((message) => message.conversationId === conversationId)) return;
    restoredForConversationIdRef.current = conversationId;

    const anchorMessageId = pointer.anchor.messageId;
    const anchorMessage = messages.find((message) => message.id === anchorMessageId);
    const newestAssistantMessage = [...messages].reverse().find((message) => message.role === "ASSISTANT");
    const turnIsOver =
      canvasBehaviourOf(pointer.canvasId)?.lifetime === "turn" && newestAssistantMessage?.id !== anchorMessageId;
    if (anchorMessage === undefined || turnIsOver || !anchorMessageCarriesCanvasWidget(pointer, anchorMessage)) {
      clearStoredCanvas(conversationId);
      return;
    }
    restore();
  }, [conversationId, messages, openCanvas]);

  return null;
};
