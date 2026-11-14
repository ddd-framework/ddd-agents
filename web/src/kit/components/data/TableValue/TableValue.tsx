import { Icon, Text } from "../../../../provider/components";
import { escapePlainTextForText } from "../../../../provider/environment";
import type { ExpressionValue } from "../../../../renderer/widget-tree/expression/evaluate-expression";
import { DataValueFormat, DataValueTone, formatDataValue } from "../data-value-format";
import { TONE_GRAPHIC_COLOR, TONE_TEXT_COLOR } from "../data-tones";
import { useNumberLocale } from "../use-number-locale";
import { translateText } from "../../../../provider/environment";
import styles from "./TableValue.module.scss";

export interface TableValueProps {
  value: ExpressionValue;
  format: DataValueFormat;
  currency?: string;
  decimals?: number;
  unit?: string;
  signed?: boolean;
  tone?: DataValueTone;
  isBold?: boolean;
}

/**
 * One table value: a formatted number or text in the cell's tone, tabular figures; a boolean as a check (yes, green)
 * or a cross (no, red) with its meaning for screen readers — ChatGPT's majority column. Empty renders nothing.
 */
export const TableValue = (props: TableValueProps) => {
  const { value, format, currency, decimals, unit, signed = false, tone = "neutral", isBold = false } = props;
  const locale = useNumberLocale();
  if (typeof value === "boolean") {
    return (
      <span className={styles.booleanValue} role="img" aria-label={translateText(value ? "Yes" : "No")}>
        <Icon pixelSize={16} color={TONE_GRAPHIC_COLOR[value ? "success" : "danger"]} name={value ? "check" : "close"} />
      </span>
    );
  }
  const formattedValue = formatDataValue(value, { format, currency, decimals, unit, signed, locale });
  if (formattedValue === "") return null;
  return (
    <Text
      role="help"
      weight={isBold ? "bold" : "regular"}
      color={TONE_TEXT_COLOR[tone]}
      className={styles.value}
      translate={false}
    >
      {escapePlainTextForText(formattedValue)}
    </Text>
  );
};
