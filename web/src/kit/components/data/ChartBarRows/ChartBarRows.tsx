import { Text } from "../../../../provider/components";
import { escapePlainTextForText } from "../../../../provider/environment";
import type { CSSProperties } from "react";
import type { ChartBarRowsModel } from "../chart-model";
import { TONE_TEXT_COLOR } from "../data-tones";
import styles from "./ChartBarRows.module.scss";

export interface ChartBarRowsProps {
  model: ChartBarRowsModel;
  /** The chart's accessible name (title and series). */
  accessibleLabel?: string;
  testId?: string;
}

/**
 * The bars of a bar chart with a change per bar: per row the category, the bar on
 * its track in the row's chart colour, the value, and the signed change coloured by its sign. A table, because it is
 * one: each row reads "CSU · 36 % · -3" to a screen reader. The bar's length and colour travel as CSS custom
 * properties — the one dynamic value, no inline style rule.
 */
export const ChartBarRows = (props: ChartBarRowsProps) => {
  const { model, accessibleLabel, testId } = props;
  const hasDeltas = model.rows.some((row) => row.deltaText !== undefined);
  return (
    <table className={styles.rows} aria-label={accessibleLabel} data-testid={testId}>
      <tbody>
        {model.rows.map((row, rowIndex) => (
          <tr
            key={`${row.label}-${rowIndex}`}
            className={styles.row}
            data-testid={testId ? `${testId}-row-${rowIndex}` : undefined}
          >
            <th scope="row" className={styles.label}>
              <Text role="help" className={styles.labelText} translate={false}>
                {escapePlainTextForText(row.label)}
              </Text>
            </th>
            <td className={styles.track} aria-hidden="true">
              <span
                className={styles.bar}
                style={
                  {
                    "--agents-chart-bar-fraction": `${Math.round(row.fraction * 1000) / 10}%`,
                    "--agents-chart-bar-color": `var(${row.colorToken})`,
                  } as CSSProperties
                }
              />
            </td>
            <td className={styles.value}>
              <Text
                role="help"
                weight="medium"
                className={styles.figure}
                translate={false}
              >
                {escapePlainTextForText(row.valueText)}
              </Text>
            </td>
            {hasDeltas ? (
              <td className={styles.delta}>
                {row.deltaText !== undefined ? (
                  <Text
                    role="help"
                    color={TONE_TEXT_COLOR[row.deltaTone]}
                    className={styles.figure}
                    translate={false}
                  >
                    {escapePlainTextForText(row.deltaText)}
                  </Text>
                ) : null}
              </td>
            ) : null}
          </tr>
        ))}
      </tbody>
    </table>
  );
};
