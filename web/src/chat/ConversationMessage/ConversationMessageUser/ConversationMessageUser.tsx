import { Text } from "../../../provider/components";
import React, { useMemo } from "react";
import { classNames } from "../../../provider/class-names";
import styles from "./ConversationMessageUser.module.scss";
import { splitComposerWidgetFencesFromMessageText } from "../../../renderer/composer-widget-fence";
import { ConversationMessageUserWidgets } from "./ConversationMessageUserWidgets";

export type ConversationMessageUserTheme = "default" | "light" | "dark" | "primary";

export interface ConversationMessageUserProps {
  text: string;
  theme?: ConversationMessageUserTheme;
  isGenericChat?: boolean;
}

export const ConversationMessageUser = (props: ConversationMessageUserProps) => {
  const { text, isGenericChat, theme = "default" } = props;

  // Pull any registered composer-widget fences out of the customer's own message text so they render as
  // read-only widgets above the prose, exactly like the assistant-side remark pipeline renders them in a reply —
  // but WITHOUT running that pipeline over user text (never treat the customer's own prose as Markdown).
  const { text: proseText, widgets } = useMemo(() => splitComposerWidgetFencesFromMessageText(text), [text]);

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
        : "var(--agents-fg-on-dark)";

  return (
    <div className={classNames(styles.message, bubbleClass)} data-testid="chat-message-user">
      <ConversationMessageUserWidgets widgets={widgets} />
      <Text allowNewLines translate={false} color={textColor}>
        {proseText}
      </Text>
    </div>
  );
};
