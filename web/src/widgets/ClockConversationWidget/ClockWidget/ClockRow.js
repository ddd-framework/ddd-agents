import { Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { HeroValue } from "../../_shared/modern";
import { formatDigitalTime, formatHourDifference, formatUtcOffset, formatZonedDate, isDaytime, readRelativeDay, readZoneAbbreviation, readZonedWallClock, } from "../shared/clock-time";
import { AnalogClockFace } from "./AnalogClockFace";
import { DayNightGlyph } from "./DayNightGlyph";
import styles from "./ClockWidget.module.scss";
import { textKey } from "../../../provider/text-key";
/** One translation key per relative day: the sentence keeps its word order in every language. */
const RELATIVE_DAY_KEYS = {
    today: textKey("Today, %hourDifference% h"),
    yesterday: textKey("Yesterday, %hourDifference% h"),
    tomorrow: textKey("Tomorrow, %hourDifference% h"),
};
/**
 * One place: the digital time as the hero, the place with its zone abbreviation, the day there and the hour difference
 * to the owner's zone, then the date, the UTC offset and day or night; the analog dial on the right.
 */
export const ClockRow = (props) => {
    const { clock, now, ownerTimeZone, timeFormat, language, dateLocale, testId = "clock-widget-row" } = props;
    const wallClock = readZonedWallClock(now, clock.timeZone);
    const ownerWallClock = readZonedWallClock(now, ownerTimeZone);
    const { time, dayPeriod } = formatDigitalTime(wallClock, timeFormat, now, clock.timeZone, language);
    const abbreviation = clock.abbreviation ?? readZoneAbbreviation(now, clock.timeZone, language);
    const placeLine = abbreviation ? `${clock.label} (${abbreviation})` : clock.label;
    const hourDifference = formatHourDifference(wallClock.utcOffsetMinutes - ownerWallClock.utcOffsetMinutes);
    const isDaytimeThere = isDaytime(wallClock);
    const dateLine = [formatZonedDate(now, clock.timeZone, dateLocale), formatUtcOffset(wallClock.utcOffsetMinutes)]
        .filter((linePart) => linePart !== "")
        .join(" · ");
    const digitalTime = dayPeriod ? `${time} ${dayPeriod}` : time;
    return (<div className={styles.row} data-testid="clock-row" data-time-zone={clock.timeZone}>
      <div className={styles.readout}>
        <span className={styles.digitalTime} data-testid="clock-digital-time">
          <HeroValue>{time}</HeroValue>
          {dayPeriod ? (<Text role="intro" weight="bold" translate={false}>
              {dayPeriod}
            </Text>) : null}
        </span>
        <Text role="body" weight="bold" translate={false} className={styles.wrappingText} testId={`${testId}-place`}>
          {escapePlainTextForText(placeLine)}
        </Text>
        <Text role="help" color="var(--agents-fg-light)" replacements={{ hourDifference }} testId={`${testId}-relative-day`}>
          {RELATIVE_DAY_KEYS[readRelativeDay(wallClock, ownerWallClock)]}
        </Text>
        <span className={styles.metaLine}>
          <Text role="caption" color="var(--agents-fg-light)" translate={false} testId={`${testId}-date`}>
            {dateLine}
          </Text>
          <span className={styles.dayNight}>
            <DayNightGlyph isDaytime={isDaytimeThere}/>
            <Text role="caption" color="var(--agents-fg-light)">
              {isDaytimeThere ? "Day" : "Night"}
            </Text>
          </span>
        </span>
      </div>
      <AnalogClockFace wallClock={wallClock} accessibleLabel={`${clock.label} ${digitalTime}`}/>
    </div>);
};
