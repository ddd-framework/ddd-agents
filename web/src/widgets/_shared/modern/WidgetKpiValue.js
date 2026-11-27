import { classNames } from "../../../provider/class-names";
import styles from "./WidgetKpiValue.module.scss";
import { HeroRow, HeroValue, SkeletonText, WidgetText } from "../../../provider/components";
/**
 * The KPI value of the chat widgets and the kit's KPI node: the hero scale (the app's dashboard scale) (48px bold,
 * tabular, scaled down to fit) re-used, never a second type scale. The label is known before the data, so it is never
 * a skeleton. A text value (18px, `title`) sits on the bottom of the same hero line box as a number, so the value
 * lines and the lines under them align across a row of KPIs.
 */
export const WidgetKpiValue = (props) => {
    const { label, value, unit, delta, loading = false, valuePresentation = "hero", accent, meter, testId } = props;
    const labelText = (<WidgetText variant="caption" tone="light" translate={false} testId={testId && `${testId}-label`}>
      {label}
    </WidgetText>);
    return (<div className={styles.kpi}>
      {accent ? (<div className={styles.labelRow}>
          {labelText}
          {accent}
        </div>) : (labelText)}
      <div className={classNames(styles.valueBlock, valuePresentation === "text" && styles.textBlock)} data-testid={testId && `${testId}-value-block`}>
        {loading ? (<SkeletonText width="3em" height={48}/>) : valuePresentation === "text" ? (<WidgetText variant="title" translate={false} className={styles.textValue}>
            {value}
          </WidgetText>) : (<HeroRow>
            <HeroValue>{value}</HeroValue>
          </HeroRow>)}
      </div>
      {meter && !loading ? meter : null}
      {(unit || delta) && !loading ? (<div className={styles.unitRow}>
          {unit ? (<WidgetText variant="caption" tone="light" translate={false} testId={testId && `${testId}-unit`}>
              {unit}
            </WidgetText>) : null}
          {delta}
        </div>) : null}
    </div>);
};
