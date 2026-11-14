import React, { useContext, useEffect, useMemo } from "react";
import styles from "./ConversationMarkdown.module.scss";
import { ConversationWidgetEntry } from "./conversationWidgetRegistry";
import { ConversationWidgetFeatureGate } from "./_access/ConversationWidgetFeatureGate";
import { readWidgetAgentSpaceKeyAttribute } from "./_agent-space/widget-agent-space-key-attribute";
import { ConversationWidgetErrorBoundary } from "./ConversationWidgetErrorBoundary";
import { WindowedWidget } from "./WindowedWidget/WindowedWidget";
import { WidgetRenderReportContext } from "./render-report/widget-render-report";
import { WidgetRenderReportCollectorContext } from "./render-report/widget-render-report-collector";

export type ConversationWidgetRenderProps = Record<string, unknown> & {
  children?: React.ReactNode;
  fresh?: boolean;
  interactive?: boolean;
  justDelivered?: boolean;
  messageId?: string | number;
};

interface ConversationWidgetHostProps {
  widget: ConversationWidgetEntry;
  /** Forwarded into the widget (and as its deprecated alias `fresh`): the widget's controls may be used. */
  interactive: boolean;
  /** Forwarded into the widget: the host message was delivered live right now — the one-shot side-effect gate. */
  justDelivered: boolean;
  /** Id of the host message, forwarded so a widget can identify the canvas it opens. */
  messageId?: string | number;
  /** When true, the widget renders as a lightweight placeholder (cheap scroll). */
  hideWidgets: boolean;
  /** Parsed markdown attributes for this widget instance. */
  conversationWidgetRenderProps: ConversationWidgetRenderProps;
}

/**
 * Single render path for every widget. Each concern is applied as a sequential gate
 * (innermost → outermost):
 *   1. the widget itself, with `interactive` / `justDelivered`
 *   2. feature-flag gate
 *   3. viewport-windowing (mount near the viewport, placeholder when far — WindowedWidget)
 *   4. block spacing wrapper
 *
 * A block of a JUST-DELIVERED message is also registered with the chat's render-report collector: it
 * counts as `mounted` unless the widget reports otherwise through `useReportWidgetRenderStatus`, and a crash caught by
 * the error boundary reports `failed` (`RENDER_FAILED`). A replayed message reports nothing.
 */
export const ConversationWidgetHost = ({
  widget,
  interactive,
  justDelivered,
  messageId,
  hideWidgets,
  conversationWidgetRenderProps,
}: ConversationWidgetHostProps) => {
  const { component: Component, featureFlag, upsellTrigger } = widget;
  const renderReportCollector = useContext(WidgetRenderReportCollectorContext);
  // The block's offset in the message markdown (react-markdown hands every component its hast node) — its identity and
  // its document order in the render report.
  const sourceOffset = (
    conversationWidgetRenderProps.node as { position?: { start?: { offset?: number } } } | undefined
  )?.position?.start?.offset;
  const isReported = renderReportCollector !== null && justDelivered && messageId !== undefined;
  const renderReporter = useMemo(
    () =>
      isReported && messageId !== undefined
        ? renderReportCollector.reporterForBlock(messageId, widget.name, sourceOffset ?? 0)
        : null,
    [isReported, renderReportCollector, messageId, widget.name, sourceOffset],
  );
  useEffect(() => {
    if (isReported && messageId !== undefined)
      renderReportCollector.registerBlock(messageId, widget.name, sourceOffset ?? 0);
  }, [isReported, renderReportCollector, messageId, widget.name, sourceOffset]);

  let node = (
    <Component
      {...conversationWidgetRenderProps}
      fresh={interactive}
      interactive={interactive}
      justDelivered={justDelivered}
      messageId={messageId}
    />
  );

  if (featureFlag) {
    node = (
      <ConversationWidgetFeatureGate
        featureFlag={featureFlag}
        UpsellTrigger={upsellTrigger}
        agentSpaceKeyAttribute={readWidgetAgentSpaceKeyAttribute(conversationWidgetRenderProps)}
      >
        {node}
      </ConversationWidgetFeatureGate>
    );
  }

  // Viewport-windowing: a widget is mounted only while near the viewport and collapses to a height-preserving
  // placeholder when far away — so a long conversation keeps a constant number of widgets mounted. The INTERACTIVE
  // widget (the live reply, and the newest reply after a reload) is pinned — never unmounted, so its controls stay
  // and its one-shot effects don't re-fire; older widgets start from the cheap index-window guess and reveal/hide as
  // they scroll in and out. The feature gate lives INSIDE, so it only runs once the widget is actually mounted.
  node = (
    // Pinned on delivery as well as on controls: a just-delivered widget that lost its controls (a newer widget of the
    // same type superseded it) must not unmount when scrolled past, or its remount re-fires the one-shot canvas open.
    <WindowedWidget initialRevealed={!hideWidgets} pinned={interactive || justDelivered}>
      {node}
    </WindowedWidget>
  );

  // Outermost gate: widget props come from LLM output, so any throw inside the
  // widget (or its gates) must not unmount the whole chat message.
  node = (
    <ConversationWidgetErrorBoundary
      widgetName={widget.name}
      onCrash={() =>
        renderReporter?.report({ status: "failed", code: "RENDER_FAILED", detail: "the widget threw while rendering" })
      }
    >
      <WidgetRenderReportContext.Provider value={renderReporter}>{node}</WidgetRenderReportContext.Provider>
    </ConversationWidgetErrorBoundary>
  );

  return <div className={styles.conversationWidgetWrapper}>{node}</div>;
};
