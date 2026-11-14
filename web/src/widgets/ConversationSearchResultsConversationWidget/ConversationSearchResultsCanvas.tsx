import { ConversationSearchResultsList, Text } from "../../provider/components";
import React from "react";
import { parseConversationSearchResultsPayload } from "./shared/conversation-search-results-widget-payload";

type ConversationSearchResultsCanvasProps = {
  query: string;
  groups: ReturnType<typeof parseConversationSearchResultsPayload>["groups"];
  onOpenConversation: (conversationId: number, messageId?: number) => void;
};

// Canvas body styles — the same as every list canvas body, so the full-screen panel behaves the same:
// a full-height flex column whose inner area SCROLLS (scrollStyle: flex 1 + overflowY auto — without it the content
// overflows the panel with no scrollbar), plus a horizontal inset (contentStyle) because the results list is built
// for the narrow inline chat column where the bubble provides the gutter — in the canvas there is no bubble, so it would
// otherwise render edge-to-edge.
const canvasStyle: React.CSSProperties = {
  width: "100%",
  height: "100%",
  display: "flex",
  flexDirection: "column",
  minHeight: 0,
  background: "var(--agents-bg)",
};
const scrollStyle: React.CSSProperties = {
  flex: 1,
  minHeight: 0,
  overflowY: "auto",
};
const contentStyle: React.CSSProperties = {
  padding: "16px 24px 24px",
};

/** The canvas body — a titled wrapper around the same grouped results, scrollable + inset like the other canvas widgets. */
export const ConversationSearchResultsCanvas = (props: ConversationSearchResultsCanvasProps) => (
  <div style={canvasStyle} data-testid="conversation-search-results-widget-canvas">
    <div style={scrollStyle}>
      <div style={contentStyle} data-testid="conversation-search-results-widget-canvas-results">
        <Text
          role="title"
          replacements={{ query: props.query }}
          testId="conversation-search-results-widget-canvas-title"
        >
          {'Search results for "%query%"'}
        </Text>
        <ConversationSearchResultsList groups={props.groups} query={props.query} onOpenConversation={props.onOpenConversation} />
      </div>
    </div>
  </div>
);
