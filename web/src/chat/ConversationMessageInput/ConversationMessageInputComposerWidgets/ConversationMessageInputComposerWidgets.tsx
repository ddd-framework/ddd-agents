import React from "react";
import { classNames } from "../../../provider/class-names";
import styles from "./ConversationMessageInputComposerWidgets.module.scss";
import { useConversationWidgetContext } from "../../../renderer/ConversationWidgetContext";
import { conversationWidgetRegistry } from "../../../renderer/conversationWidgetRegistry";

export interface ConversationMessageInputComposerWidgetsProps {
  testId?: string;
}

/**
 * Generic strip of composer widgets above the chat input's text area — the composer-side twin of
 * `ConversationMessageInputAttachmentsPreviewStrip`, same grid-rows 0fr→1fr reveal. Reads `composerWidgets` from the
 * widget context itself (so every ConversationView host gets it for free, mirroring the deleted selection chip),
 * and for each widget resolves the registry entry by `widgetName` and renders its `composerComponent` with
 * `{ composerWidgetId, fields, replaceFields, remove }`. An unknown name, or an entry without a `composerComponent`,
 * renders nothing for that widget — the same kill switch the fence plugin applies.
 */
export const ConversationMessageInputComposerWidgets = (props: ConversationMessageInputComposerWidgetsProps) => {
  const { testId = "chat-input" } = props;
  const { composerWidgets, replaceComposerWidgetFields, removeComposerWidget } = useConversationWidgetContext();

  const renderableWidgets = composerWidgets.flatMap((widget) => {
    const entry = conversationWidgetRegistry.widgets.find((candidate) => candidate.name === widget.widgetName);
    return entry?.composerComponent ? [{ widget, Component: entry.composerComponent }] : [];
  });

  return (
    <div
      className={classNames(styles.stripReveal, { [styles.stripRevealOpen]: renderableWidgets.length > 0 })}
      data-testid={`${testId}-composer-widgets-strip`}
    >
      <div className={styles.stripRevealInner}>
        <div className={styles.strip}>
          {renderableWidgets.map(({ widget, Component }) => (
            <Component
              key={widget.composerWidgetId}
              composerWidgetId={widget.composerWidgetId}
              fields={widget.fields}
              replaceFields={(nextFields) => replaceComposerWidgetFields(widget.composerWidgetId, nextFields)}
              remove={() => removeComposerWidget(widget.composerWidgetId)}
              testId={`${testId}-composer-widget-${widget.composerWidgetId}`}
            />
          ))}
        </div>
      </div>
    </div>
  );
};
