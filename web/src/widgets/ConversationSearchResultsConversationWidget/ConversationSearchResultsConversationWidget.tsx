import { ConversationSearchResultsList, Text } from "../../provider/components";
import { escapePlainTextForText } from "../../provider/environment";
import React, { useMemo } from "react";
import { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { useConversationWidgetContext } from "../../renderer/ConversationWidgetCallbacksContext";
import { CanvasWidgetSwitch } from "../CanvasWidgetCard/CanvasWidgetSwitch";
import { canvasForMessageWidget } from "../CanvasWidgetCard/canvas-for-message-widget";
import { parseConversationSearchResultsPayload } from "./shared/conversation-search-results-widget-payload";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";
import { WidgetSurface } from "../_shared/modern";
import styles from "./ConversationSearchResultsConversationWidget.module.scss";

type ConversationSearchResultsConversationWidgetProps = PayloadConversationWidgetProps;

/** ≤ this many matching conversations render inline in the chat (1-5 short results); more open in the canvas side panel. */
const INLINE_GROUP_LIMIT = 5;

/**
 * Renders the result of `searchConversations` (cross-chat semantic recall) — the grouped "search all my chats" view
 * (the replaceable `ConversationSearchResultsList`; the app supplies the component its chat's own search panel uses). DATA-COMPLETE: it
 * renders entirely from the payload the agent passes (query + grouped hits); it NEVER re-runs the search.
 *
 * Few groups → inline in the message; many → opens in the canvas side panel (or whatever `display` forces). The
 * open-conversation arrow jumps to the source chat via the widget context's `onOpenConversation` seam.
 *
 * The canvas is `conversationSearchResults`: the body (`ConversationSearchResultsCanvasBody`) re-reads this payload
 * from the anchor message. Without a message id (a composer echo, a streaming reply) the card opens nothing.
 */
export const ConversationSearchResultsConversationWidget = (
  props: ConversationSearchResultsConversationWidgetProps,
) => {
  const { payload, justDelivered = false, messageId } = props;
  const testId = "conversation-search-results-widget";
  const { onOpenConversation } = useConversationWidgetContext();
  const canvas = canvasForMessageWidget("conversationSearchResults", messageId, payload);
  const { query, title, display, groups } = useMemo(() => parseConversationSearchResultsPayload(payload), [payload]);

  const openConversation = (conversationId: number, messageId?: number) =>
    onOpenConversation?.(conversationId, messageId);

  const isEmpty = groups.length === 0;
  useReportWidgetRenderStatus(
    isEmpty ? "empty" : "mounted",
    isEmpty ? "NO_DATA" : undefined,
    isEmpty ? "The ConversationSearchResults widget carries no conversation with a readable hit." : undefined,
  );

  if (isEmpty) return null;

  const customTitle = title?.trim();
  const headline = customTitle || 'Search results for "%query%"';
  // The query is model-written: the component library's Text parses a string as HTML, so it goes in escaped.
  const headlineReplacements = customTitle ? undefined : { query: escapePlainTextForText(query) };

  return (
    <CanvasWidgetSwitch
      display={display}
      exceedsInlineLimit={groups.length > INLINE_GROUP_LIMIT}
      title={headline}
      titleReplacements={headlineReplacements}
      subtitle={"%count% conversations matched"}
      subtitleReplacements={{ count: groups.length }}
      icon="message"
      justDelivered={justDelivered}
      canvas={canvas}
      testId={testId}
      renderInline={() => (
        // The chat's inline results sit on one surface with their headline and match count (the canvas body keeps
        // the bare list — the canvas pane is the surface there).
        <WidgetSurface className={styles.inlineResults} testId={testId}>
          <div className={styles.header}>
            <Text
              role="intro"
              weight="bold"
              translate={!customTitle}
              replacements={headlineReplacements}
              testId={`${testId}-title`}
            >
              {customTitle ? escapePlainTextForText(customTitle) : headline}
            </Text>
            <Text
              role="caption"
              color="var(--agents-fg-light)"
              replacements={{ count: groups.length }}
              testId={`${testId}-count`}
            >
              {"%count% conversations matched"}
            </Text>
          </div>
          <div data-testid={`${testId}-results`}>
            <ConversationSearchResultsList groups={groups} query={query} onOpenConversation={openConversation} />
          </div>
        </WidgetSurface>
      )}
    />
  );
};
