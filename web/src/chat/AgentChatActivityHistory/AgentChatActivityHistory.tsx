import { Text } from "../../provider/components";
import type { AgentChatActivityHistoryProps } from "../controller/AgentChatController";
import styles from "./AgentChatActivityHistory.module.scss";

/**
 * The package default of a finished turn's kept activity lines under the message that started the turn (`AgentChat`
 * with `keepActivityHistoryAfterAnswer`): one muted line per step, oldest first.
 */
export const AgentChatActivityHistory = (props: AgentChatActivityHistoryProps) => {
  const { turn } = props;
  if (turn === null || turn.lines.length === 0) return null;
  return (
    <div className={styles.history} data-testid="agent-chat-activity-history">
      {turn.lines.map((line) => (
        <Text key={`${line.toolCallMessageId}-${line.state}`} role="help" color="--agents-fg-light" translate={false}>
          {line.line}
        </Text>
      ))}
    </div>
  );
};
