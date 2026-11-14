import { Icon, type AgentsIconName } from "../../../../provider/components";
import { classNames } from "../../../../provider/class-names";
import styles from "./KpiToneAccent.module.scss";
import { TONE_GRAPHIC_COLOR } from "../data-tones";
import type { DataValueTone } from "../data-value-format";

const TONE_ICON: Partial<Record<DataValueTone, AgentsIconName>> = {
  success: "check",
  warning: "warning",
  danger: "exclamation",
  info: "info",
};

const TONE_CLASS: Partial<Record<DataValueTone, string>> = {
  success: styles.success,
  warning: styles.warning,
  danger: styles.danger,
  info: styles.info,
};

export interface KpiToneAccentProps {
  tone: DataValueTone;
  testId?: string;
}

/**
 * The status accent of a judged KPI: a small tinted badge with the tone's glyph beside the label. The value carries the
 * same tone in its colour, so the badge is decoration. Neutral and muted values show none.
 */
export const KpiToneAccent = (props: KpiToneAccentProps) => {
  const { tone, testId } = props;
  const icon = TONE_ICON[tone];
  if (!icon) return null;
  return (
    <span className={classNames(styles.accent, TONE_CLASS[tone])} aria-hidden="true" data-testid={testId}>
      <Icon pixelSize={14} color={TONE_GRAPHIC_COLOR[tone]} name={icon} />
    </span>
  );
};
