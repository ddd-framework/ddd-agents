import { ModelText } from "../../ModelText/ModelText";
import styles from "./MediaCaption.module.scss";
/** The lines under a picture or a video: the title (card title, bold) over a muted caption; nothing when both are empty. */
export const MediaCaption = (props) => {
    const { title, caption } = props;
    if (!title && !caption)
        return null;
    return (<span className={styles.caption}>
      {title ? (<ModelText role="body" weight="bold" className={styles.wrap}>
          {title}
        </ModelText>) : null}
      {caption ? (<ModelText role="caption" color="var(--agents-fg-light)" className={styles.wrap}>
          {caption}
        </ModelText>) : null}
    </span>);
};
