import React, { useMemo } from "react";
import type { CanvasBodyProps } from "../../renderer/canvas/canvas-body-props";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { useAnchorWidgetPayload } from "../../renderer/canvas/use-anchor-widget-payload";
import { parseConversationSearchResultsPayload } from "./shared/conversation-search-results-widget-payload";
import { ConversationSearchResultsCanvas } from "./ConversationSearchResultsCanvas";

/**
 * The `conversationSearchResults` CANVAS BODY (`canvasBodies.tsx`): re-reads the `ConversationSearchResults` fence of
 * the anchor message — the same payload the inline widget rendered from — and shows the grouped results. Nothing was
 * stored for it but the pointer. Renders nothing when the anchor message no longer carries the fence.
 */
export const ConversationSearchResultsCanvasBody = ({ canvas, anchorMessageMarkdown }: CanvasBodyProps) => {
  const { onOpenConversation } = useConversationWidgetContext();
  const payload = useAnchorWidgetPayload(canvas, anchorMessageMarkdown, "ConversationSearchResults");
  const parsed = useMemo(
    () => (payload === undefined ? null : parseConversationSearchResultsPayload(payload)),
    [payload],
  );
  if (parsed === null || parsed.groups.length === 0) return null;
  return (
    <ConversationSearchResultsCanvas
      query={parsed.query}
      groups={parsed.groups}
      onOpenConversation={(conversationId, messageId) => onOpenConversation?.(conversationId, messageId)}
    />
  );
};
