import { Icon, Text } from "../../../../provider/components";
import { escapePlainTextForText } from "../../../../provider/environment";
import type { CSSProperties } from "react";
import type { ChartFunnelModel } from "../chart-model";
import styles from "./ChartFunnelRows.module.scss";

export interface ChartFunnelRowsProps {
  model: ChartFunnelModel;
  /** The chart's accessible name (title and series). */
  accessibleLabel?: string;
  testId?: string;
}

/**
 * The stages of a funnel chart (ChatGPT composes its funnel from SVG): per
 * stage its name and value on one line, under it a centered bar as wide as the stage's share of the largest one, and
 * between two stages the conversion from the stage before. An ordered list, because the stages are a sequence: each
 * item reads "Link opened · 41 · 64 % of the previous stage" to a screen reader. The bar's width travels as a CSS
 * custom property — the one dynamic value, no inline style rule.
 */
export const ChartFunnelRows = (props: ChartFunnelRowsProps) => {
  const { model, accessibleLabel, testId } = props;
  return (
    <ol className={styles.stages} aria-label={accessibleLabel} data-testid={testId}>
      {model.stages.map((stage, stageIndex) => (
        <li
          key={`${stage.label}-${stageIndex}`}
          className={styles.stage}
          data-testid={testId ? `${testId}-stage-${stageIndex}` : undefined}
        >
          {stage.conversionText !== undefined ? (
            <div className={styles.conversion}>
              <Icon className={styles.conversionIcon} name="arrowDown" />
              <Text
                role="caption"
                color="var(--agents-fg-light)"
                className={styles.figure}
                replacements={{ conversion: stage.conversionText }}
              >
                {"%conversion% of the previous stage"}
              </Text>
            </div>
          ) : null}
          <div className={styles.stageHead}>
            <Text role="help" className={styles.label} translate={false}>
              {escapePlainTextForText(stage.label)}
            </Text>
            <Text role="help" weight="bold" className={styles.figure} translate={false}>
              {escapePlainTextForText(stage.valueText)}
            </Text>
          </div>
          <span
            className={styles.bar}
            aria-hidden="true"
            style={{ "--agents-chart-funnel-fraction": `${Math.round(stage.fraction * 1000) / 10}%` } as CSSProperties}
          />
        </li>
      ))}
    </ol>
  );
};
