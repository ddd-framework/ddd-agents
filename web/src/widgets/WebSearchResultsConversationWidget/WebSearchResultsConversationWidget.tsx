import React, { useMemo } from "react";
import { PayloadConversationWidgetProps } from "../../renderer/conversation-widget-props";
import { CanvasWidgetSwitch } from "../CanvasWidgetCard/CanvasWidgetSwitch";
import { canvasForMessageWidget } from "../CanvasWidgetCard/canvas-for-message-widget";
import { parseWebSearchResultsPayload } from "./shared/web-search-results-widget-payload";
import { WebSearchAnswer } from "./WebSearchAnswer";
import { WebSearchSourcesList } from "./WebSearchSourcesList";
import { WebSearchReferences } from "./WebSearchReferences";
import { useReportWidgetRenderStatus } from "../../renderer/render-report/widget-render-report";

type WebSearchResultsConversationWidgetProps = PayloadConversationWidgetProps;

const inlineStyle: React.CSSProperties = { display: "flex", flexDirection: "column", gap: 8 };

/**
 * The references of a web research answer: the agent writes its answer as prose and puts this widget under it with the
 * pages it used (`sources` only) — rendered as link chips with each site's favicon ({@link WebSearchReferences}).
 * DATA-COMPLETE: it renders entirely from the payload; it NEVER re-runs the search.
 *
 * Legacy form (older stored messages): a payload with an `answer` renders the full answer card below.
 *
 * Renders INLINE in the message (answer markdown + the cited sources, each with its site favicon). It only opens in the
 * canvas side panel if the agent explicitly sets `display: "canvas"` — by default a web answer stays inline.
 */
export const WebSearchResultsConversationWidget = (props: WebSearchResultsConversationWidgetProps) => {
  const { payload, justDelivered = false, messageId } = props;
  const testId = "web-search-results-widget";
  // The canvas is `webSearchResults`: the body re-reads this payload from the anchor message.
  const canvas = canvasForMessageWidget("webSearchResults", messageId, payload);
  const { query, title, provider, display, answer, sources } = useMemo(
    () => parseWebSearchResultsPayload(payload),
    [payload],
  );

  const isEmpty = answer.trim() === "" && sources.length === 0;
  useReportWidgetRenderStatus(
    isEmpty ? "empty" : "mounted",
    isEmpty ? "NO_DATA" : undefined,
    isEmpty ? "The WebSearchResults widget carries no answer and no source with a url." : undefined,
  );

  if (isEmpty) return null;

  // The references form: the agent wrote its answer as prose ABOVE the widget; the widget carries only the
  // pages it used. A stored message with an `answer` keeps the full answer card below.
  if (answer.trim() === "") return <WebSearchReferences sources={sources} testId={testId} />;

  // The source count and the optional provider are interpolated via `%token%` so each stays a single translatable
  // key (never a per-value string). The plural split keeps two clean keys; the provider variant folds the provider in
  // with a comma (no decorative separator in the key).
  const single = sources.length === 1;
  const lowercasedProvider = provider?.toLowerCase();
  const subtitle = lowercasedProvider
    ? single
      ? "1 source, %provider%"
      : "%count% sources, %provider%"
    : single
      ? "1 source"
      : "%count% sources";
  const subtitleReplacements = {
    count: sources.length,
    ...(lowercasedProvider ? { provider: lowercasedProvider } : {}),
  };

  const customTitle = title?.trim() || query;

  return (
    <CanvasWidgetSwitch
      display={display}
      exceedsInlineLimit={false}
      title={customTitle || "Web search"}
      subtitle={subtitle}
      subtitleReplacements={subtitleReplacements}
      icon="search"
      justDelivered={justDelivered}
      canvas={canvas}
      testId={testId}
      renderInline={() => (
        <div style={inlineStyle} data-testid={testId}>
          <WebSearchAnswer answer={answer} />
          <WebSearchSourcesList sources={sources} provider={provider} testId={testId} />
        </div>
      )}
    />
  );
};
