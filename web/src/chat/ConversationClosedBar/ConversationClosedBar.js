import { Icon, Text } from "../../provider/components";
import styles from "./ConversationClosedBar.module.scss";
/**
 * The package default in place of the composer on a closed conversation (its run state ended it: COMPLETED or
 * CANCELLED; the server refuses further input). A status line, no action — an app that offers one replaces the bar
 * through `AgentChat`'s `inputReplacementSlot`.
 */
export const ConversationClosedBar = (props) => {
    const { text = "This conversation is closed." } = props;
    return (<div className={styles.bar} data-testid="agent-chat-closed-bar">
      <Icon name="check" color="--agents-success-500"/>
      <Text>{text}</Text>
    </div>);
};
