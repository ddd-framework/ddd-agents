import { Text } from "../../../provider/components";
import { classNames } from "../../../provider/class-names";
import styles from "./WidgetListRow.module.scss";
/** One row of a list inside a widget surface: title over muted meta, hairline-divided. */
export const WidgetListRow = (props) => {
    const { title, meta, leading, trailing, onClick, className, testId } = props;
    const rowClassName = classNames(styles.row, leading === undefined && styles.withoutLeading, onClick && styles.clickable, className);
    const content = (<>
      {leading}
      <span className={styles.text}>
        <Text role="body" weight="bold" translate={false}>
          {title}
        </Text>
        {meta ? (<Text role="caption" color="var(--agents-fg-light)" translate={false}>
            {meta}
          </Text>) : null}
      </span>
      {trailing ? <span className={styles.trailing}>{trailing}</span> : <span />}
    </>);
    if (onClick) {
        return (<button type="button" className={rowClassName} onClick={onClick} data-testid={testId}>
        {content}
      </button>);
    }
    return (<div className={rowClassName} data-testid={testId}>
      {content}
    </div>);
};
