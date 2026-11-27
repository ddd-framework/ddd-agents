import { useAgentsTexts } from "../../provider/AgentsProvider";
import { classNames } from "../../provider/class-names";
import { Text } from "../../provider/components";
import { AgentChatActivityDots } from "../AgentChatActivityDots/AgentChatActivityDots";
import { DEFAULT_AGENT_CHAT_ACTIVITY_TIMING } from "../controller/chat-activity-timing";
import styles from "./AgentChatActivityStack.module.scss";
/** The lines the stack keeps on screen: the newest one and about three readable ranks above it, the oldest under the mask. */
export const ACTIVITY_STACK_VISIBLE_LINES = 4;
/** The shortest and the longest time a line takes to move up one rank; between them a quarter of the line pace. */
const MIN_RANK_MOVE_MS = 160;
const MAX_RANK_MOVE_MS = 520;
/**
 * The stacked activity feed at the tail of the thread, a drop-in `ThinkingIndicator` of `AgentChat`
 * (`<AgentChat ThinkingIndicator={AgentChatActivityStack} />`): one generated sentence per tool call of the running
 * turn, the newest at the bottom at full size, the older ones drifting up, smaller and fainter under a top mask.
 * Before the first sentence it shows the calm dots and "Working on it…"; once a sentence is told, only sentences. It
 * fades out over the success beat when the turn ends, and shows nothing between turns or after a failed turn.
 *
 * The moving layer is decorative (`aria-hidden`); a polite status region carries the newest sentence. The motion
 * follows the chat's timing (`activityTiming`: the line pace and the success beat), stays still under
 * `prefers-reduced-motion`, and every colour comes from the `--agents-*` variables.
 */
export const AgentChatActivityStack = (props) => {
    const { active, failed, activityTicker, activityTiming = DEFAULT_AGENT_CHAT_ACTIVITY_TIMING } = props;
    const texts = useAgentsTexts();
    const { phase, currentTurn } = activityTicker;
    const lines = currentTurn?.lines ?? [];
    const isWorking = !failed && (active || phase === "live");
    const isFadingOut = !failed && phase === "done" && lines.length > 0;
    if (!isWorking && !isFadingOut)
        return null;
    const stackedLines = lines.slice(-ACTIVITY_STACK_VISIBLE_LINES);
    const newestSentence = lines.length > 0 ? lines[lines.length - 1].line : null;
    const rankMoveMs = Math.min(MAX_RANK_MOVE_MS, Math.max(MIN_RANK_MOVE_MS, Math.round(activityTiming.linePaceMs / 4)));
    const timingStyle = {
        "--agents-activity-stack-move-duration": `${rankMoveMs}ms`,
        "--agents-activity-stack-fade-duration": `${activityTiming.successBeatMs}ms`,
    };
    return (<div className={classNames(styles.stack, isFadingOut && styles.fadingOut)} style={timingStyle} data-testid="agent-chat-activity-stack" data-phase={isFadingOut ? "done" : "live"}>
      <div className={styles.visual} aria-hidden="true">
        {stackedLines.length === 0 ? (<div className={styles.waiting}>
            <AgentChatActivityDots variant="pulse"/>
            <Text role="help" color="--agents-fg-light">
              Working on it…
            </Text>
          </div>) : (<ol className={styles.lines}>
            {stackedLines.map((line, index) => {
                const rank = stackedLines.length - 1 - index;
                return (<li key={line.toolCallMessageId} className={styles.line} data-rank={rank}>
                  <Text role="help" color={rank === 0 ? "--agents-fg" : "--agents-fg-light"} translate={false} ellipsis>
                    {line.line}
                  </Text>
                </li>);
            })}
          </ol>)}
      </div>
      <div className={styles.statusRegion} role="status" aria-live="polite" data-testid="agent-chat-activity-stack-status">
        {newestSentence ?? texts.translate("Working on it…")}
      </div>
    </div>);
};
