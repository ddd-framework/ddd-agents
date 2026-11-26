import { Text } from "../../../provider/components";
import React, { useMemo } from "react";
import { classNames } from "../../../provider/class-names";
import { escapePlainTextForText } from "../../../provider/environment";
import styles from "./ConversationMessageUser.module.scss";
import { splitComposerWidgetFencesFromMessageText } from "../../../renderer/composer-widget-fence";
import type { ConversationComposerWidget } from "../../../renderer/conversation-composer-widget";
import { conversationWidgetRegistry } from "../../../renderer/conversationWidgetRegistry";
import { ConversationMessageUserWidgets } from "./ConversationMessageUserWidgets";

export type ConversationMessageUserTheme = "default" | "light" | "dark" | "primary";

export interface ConversationMessageUserProps {
  text: string;
  theme?: ConversationMessageUserTheme;
  /** @deprecated Never read: a customer message looks the same in every chat. Kept so existing callers compile. */
  isGenericChat?: boolean;
}

/** True when the widget's registry entry shows its echo on its own above the bubble (e.g. attached images). */
const isShownAboveBubble = (widget: ConversationComposerWidget): boolean =>
  conversationWidgetRegistry.widgets.find((entry) => entry.name === widget.widgetName)?.customerMessagePlacement ===
  "aboveBubble";

export const ConversationMessageUser = (props: ConversationMessageUserProps) => {
  const { text, theme = "default" } = props;

  // Pull any registered composer-widget fences out of the customer's own message text so they render as
  // read-only widgets above the prose, exactly like the assistant-side remark pipeline renders them in a reply —
  // but WITHOUT running that pipeline over user text (never treat the customer's own prose as Markdown).
  // The prose is the customer's own text: it reaches the text component as plain text (`escapePlainTextForText`), so
  // an app's text component that reads a string as markup shows it as written.
  const { text: proseText, widgets } = useMemo(() => splitComposerWidgetFencesFromMessageText(text), [text]);
  const widgetsAboveBubble = useMemo(() => widgets.filter(isShownAboveBubble), [widgets]);
  const widgetsInsideBubble = useMemo(() => widgets.filter((widget) => !isShownAboveBubble(widget)), [widgets]);

  const bubbleClass =
    theme === "light"
      ? styles.whiteBubble
      : theme === "dark"
        ? styles.darkBubble
        : theme === "primary"
          ? styles.primaryBubble
          : undefined;

  const textColor =
    theme === "light"
      ? "var(--agents-fg)"
      : theme === "dark"
        ? "var(--agents-chat-user-bubble-text)"
        : "var(--agents-fg-on-accent)";

  const bubble = (
    <div className={classNames(styles.message, bubbleClass)} data-testid="chat-message-user">
      <ConversationMessageUserWidgets widgets={widgetsInsideBubble} />
      {/* A widget-only message (e.g. images sent without text) has no prose line to render. */}
      {proseText !== "" && (
        <Text testId="conversation-message-user-text" allowNewLines translate={false} color={textColor}>
          {escapePlainTextForText(proseText)}
        </Text>
      )}
    </div>
  );

  if (widgetsAboveBubble.length === 0) return bubble;

  // Widgets placed above the bubble (e.g. attached images) stand on their own, right-aligned; a message that is nothing
  // but such widgets shows no bubble at all.
  return (
    <div className={styles.messageWithWidgetsAboveBubble}>
      <ConversationMessageUserWidgets widgets={widgetsAboveBubble} />
      {(proseText !== "" || widgetsInsideBubble.length > 0) && bubble}
    </div>
  );
};
