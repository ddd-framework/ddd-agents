import styles from "./VoiceBarsIcon.module.scss";
/**
 * Five sound bars, the tallest in the middle — the mark of an in-app voice call with the agent.
 */
export const VoiceBarsIcon = (props) => {
    const { size = 18 } = props;
    return (<svg className={styles.icon} width={size} height={size} viewBox="0 0 24 24" aria-hidden focusable="false">
      <path d="M3.5 10.5v3M7.75 8v8M12 4.5v15M16.25 8v8M20.5 10.5v3"/>
    </svg>);
};
