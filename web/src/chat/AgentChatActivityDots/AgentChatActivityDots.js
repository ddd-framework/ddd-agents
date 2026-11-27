import { classNames } from "../../provider/class-names";
import styles from "./AgentChatActivityDots.module.scss";
/**
 * The three working dots of the activity components, decorative (`aria-hidden`). Coloured by `--agents-fg-light`;
 * still under `prefers-reduced-motion`.
 */
export const AgentChatActivityDots = ({ variant = "bounce" }) => (<div className={classNames(styles.dots, variant === "pulse" ? styles.pulse : styles.bounce)} aria-hidden="true" data-agents-activity-dots={variant}>
    <span />
    <span />
    <span />
  </div>);
