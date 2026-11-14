import { Text } from "../../provider/components";
import React from "react";
import { WebSearchResultSource } from "./shared/web-search-results-widget-payload";
import { WebSearchAnswer } from "./WebSearchAnswer";
import { WebSearchSourcesList } from "./WebSearchSourcesList";

type WebSearchResultsCanvasProps = {
  query: string;
  answer: string;
  sources: WebSearchResultSource[];
  provider?: string;
};

// Canvas body styles — mirror the ConversationSearchResults / listReviews drawer widgets: a full-height flex column
// whose inner area SCROLLS (flex 1 + overflowY auto) with a horizontal inset (there is no chat bubble gutter in the canvas).
const canvasStyle: React.CSSProperties = {
  width: "100%",
  height: "100%",
  display: "flex",
  flexDirection: "column",
  minHeight: 0,
  background: "var(--agents-bg)",
};
const scrollStyle: React.CSSProperties = { flex: 1, minHeight: 0, overflowY: "auto" };
const contentStyle: React.CSSProperties = { padding: "16px 24px 24px" };

/** The canvas body — the full answer + sources, scrollable + inset like the other canvas widgets. */
export const WebSearchResultsCanvas = (props: WebSearchResultsCanvasProps) => (
  <div style={canvasStyle} data-testid="web-search-results-widget-canvas">
    <div style={scrollStyle}>
      <div style={contentStyle}>
        {props.query ? (
          <Text
            role="title"
            replacements={{ query: props.query }}
            testId="web-search-results-widget-canvas-title"
          >
            {'"%query%"'}
          </Text>
        ) : (
          <Text role="title" testId="web-search-results-widget-canvas-title">
            Web search
          </Text>
        )}
        <div style={{ marginTop: 12 }}>
          <WebSearchAnswer answer={props.answer} />
        </div>
        <WebSearchSourcesList
          sources={props.sources}
          provider={props.provider}
          testId="web-search-results-widget-canvas"
        />
      </div>
    </div>
  </div>
);
