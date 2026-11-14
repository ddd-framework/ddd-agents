import React from "react";
import { ConversationComposerWidget } from "../../../renderer/conversation-composer-widget";
import { conversationWidgetRegistry } from "../../../renderer/conversationWidgetRegistry";
import { ConversationWidgetErrorBoundary } from "../../../renderer/ConversationWidgetErrorBoundary";

export interface ConversationMessageUserWidgetsProps {
  /** Composer-widget fences extracted from the customer's own message text (see `splitComposerWidgetFencesFromMessageText`). */
  widgets: ConversationComposerWidget[];
}

/**
 * Read-only echo of the composer widgets the customer sent inside their OWN message — rendered above the prose. Reuses each widget's registered message `component` (the same one an
 * assistant reply would mount via the remark pipeline) directly with the fence's fields as `payload` — NOT the
 * remark/markdown pipeline itself, which never runs for user text (interpreting the customer's own prose as
 * Markdown would change every user bubble). `fresh={false}`: this is always a replay of content already sent, never
 * a live delivery. Each widget renders inside its own `ConversationWidgetErrorBoundary` so a malformed fence never
 * blanks the whole bubble.
 */
export const ConversationMessageUserWidgets = (props: ConversationMessageUserWidgetsProps) => {
  const { widgets } = props;

  if (widgets.length === 0) return null;

  return (
    <>
      {widgets.map((widget) => {
        const entry = conversationWidgetRegistry.widgets.find((candidate) => candidate.name === widget.widgetName);
        if (!entry) return null;
        const Component = entry.component;
        return (
          <ConversationWidgetErrorBoundary key={widget.composerWidgetId} widgetName={entry.name}>
            <Component payload={JSON.stringify(widget.fields)} fresh={false} />
          </ConversationWidgetErrorBoundary>
        );
      })}
    </>
  );
};
