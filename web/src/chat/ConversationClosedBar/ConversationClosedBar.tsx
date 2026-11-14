import { Icon, Text } from "../../provider/components";
import styles from "./ConversationClosedBar.module.scss";

export interface ConversationClosedBarProps {
  /** The status line; a text key. Default "This conversation is closed.". */
  text?: string;
}

/**
 * The package default in place of the composer on a closed conversation (its run state ended it: COMPLETED or
 * CANCELLED; the server refuses further input). A status line, no action — an app that offers one replaces the bar
 * through `AgentChat`'s `inputReplacementSlot`.
 */
export const ConversationClosedBar = (props: ConversationClosedBarProps) => {
  const { text = "This conversation is closed." } = props;
  return (
    <div className={styles.bar} data-testid="agent-chat-closed-bar">
      <Icon name="check" color="--agents-success-500" />
      <Text>{text}</Text>
    </div>
  );
};
