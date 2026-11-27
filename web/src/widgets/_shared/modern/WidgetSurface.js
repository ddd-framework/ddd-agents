import { classNames } from "../../../provider/class-names";
import styles from "./WidgetSurface.module.scss";
/** The surface every modern widget and kit component sits on — one radius, one padding, one shadow per level. */
export const WidgetSurface = (props) => {
    const { children, level = "elevated", compact = false, className, testId } = props;
    return (<div data-surface-level={level} data-testid={testId} className={classNames(styles.surface, styles[level], compact && styles.compact, className)}>
      {children}
    </div>);
};
