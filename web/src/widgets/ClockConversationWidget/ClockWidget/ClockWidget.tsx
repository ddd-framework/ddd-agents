import { Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { WidgetSurface } from "../../_shared/modern";
import type { ClockTimeFormat } from "../shared/clock-widget-payload";
import { ClockRow, type ClockWidgetEntry } from "./ClockRow";
import styles from "./ClockWidget.module.scss";

export interface ClockWidgetProps {
  clocks: ClockWidgetEntry[];
  /** The model's headline, shown as written. */
  title?: string;
  now: Date;
  ownerTimeZone: string;
  timeFormat: ClockTimeFormat;
  language: string;
  dateLocale: string;
  /** Set in an e-mail render: the frozen capture moment the "As of" line names. */
  captureMoment?: string;
  testId?: string;
}

/**
 * The presentational clock: one elevated surface, one row per place divided by hairlines (one row = the single clock,
 * several = the world clock), the optional title above and, in an e-mail, the "As of" line below. Knows nothing about
 * payloads or the registry.
 */
export const ClockWidget = (props: ClockWidgetProps) => {
  const {
    clocks,
    title,
    now,
    ownerTimeZone,
    timeFormat,
    language,
    dateLocale,
    captureMoment,
    testId = "clock-widget",
  } = props;
  return (
    <WidgetSurface className={styles.root} testId={testId}>
      {title ? (
        <Text
          role="intro"
          weight="bold"
          translate={false}
          className={styles.wrappingText}
          testId={`${testId}-title`}
        >
          {escapePlainTextForText(title)}
        </Text>
      ) : null}
      <div className={styles.rows} data-testid={`${testId}-rows`}>
        {clocks.map((clock, clockIndex) => (
          <ClockRow
            key={`${clock.timeZone}-${clockIndex}`}
            testId={`${testId}-row-${clock.timeZone}`}
            clock={clock}
            now={now}
            ownerTimeZone={ownerTimeZone}
            timeFormat={timeFormat}
            language={language}
            dateLocale={dateLocale}
          />
        ))}
      </div>
      {captureMoment ? (
        <Text
          role="caption"
          color="var(--agents-fg-light)"
          replacements={{ captureMoment }}
          testId={`${testId}-as-of`}
        >
          {"As of %captureMoment%"}
        </Text>
      ) : null}
    </WidgetSurface>
  );
};
