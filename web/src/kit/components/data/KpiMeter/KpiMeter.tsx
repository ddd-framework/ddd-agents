import styles from "./KpiMeter.module.scss";
import { SegmentBar } from "../../../../provider/components";

export interface KpiMeterProps {
  value: number;
  max: number;
  /** The fill colour (a tone's graphic colour). */
  color: string;
  testId?: string;
}

/**
 * The thin meter under a written score ("61,4/100", "5/5"): the `SegmentBar` component, filled part and track. It
 * repeats the figure the hero already states, so assistive technology skips it.
 */
export const KpiMeter = (props: KpiMeterProps) => {
  const { value, max, color, testId } = props;
  const segments = [
    { value, color },
    { value: max - value, color: "var(--agents-kpi-meter-track)" },
  ].filter((segment) => segment.value > 0);
  return (
    <div className={styles.meter} aria-hidden="true" data-testid={testId}>
      <SegmentBar segments={segments} height={6} />
    </div>
  );
};
