import { Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { WidgetSurface } from "../../_shared/modern";
import { ClockRow } from "./ClockRow";
import styles from "./ClockWidget.module.scss";
/**
 * The presentational clock: one elevated surface, one row per place divided by hairlines (one row = the single clock,
 * several = the world clock), the optional title above and, in an e-mail, the "As of" line below. Knows nothing about
 * payloads or the registry.
 */
export const ClockWidget = (props) => {
    const { clocks, title, now, ownerTimeZone, timeFormat, language, dateLocale, captureMoment, testId = "clock-widget", } = props;
    return (<WidgetSurface className={styles.root} testId={testId}>
      {title ? (<Text role="intro" weight="bold" translate={false} className={styles.wrappingText} testId={`${testId}-title`}>
          {escapePlainTextForText(title)}
        </Text>) : null}
      <div className={styles.rows} data-testid={`${testId}-rows`}>
        {clocks.map((clock, clockIndex) => (<ClockRow key={`${clock.timeZone}-${clockIndex}`} testId={`${testId}-row-${clock.timeZone}`} clock={clock} now={now} ownerTimeZone={ownerTimeZone} timeFormat={timeFormat} language={language} dateLocale={dateLocale}/>))}
      </div>
      {captureMoment ? (<Text role="caption" color="var(--agents-fg-light)" replacements={{ captureMoment }} testId={`${testId}-as-of`}>
          {"As of %captureMoment%"}
        </Text>) : null}
    </WidgetSurface>);
};
