import { Text } from "../../../provider/components";
import { escapePlainTextForText } from "../../../provider/environment";
import { HeroValue } from "../../_shared/modern";
import {
  formatDigitalTime,
  formatHourDifference,
  formatUtcOffset,
  formatZonedDate,
  isDaytime,
  readRelativeDay,
  readZoneAbbreviation,
  readZonedWallClock,
  type ClockRelativeDay,
} from "../shared/clock-time";
import type { ClockTimeFormat } from "../shared/clock-widget-payload";
import { AnalogClockFace } from "./AnalogClockFace";
import { DayNightGlyph } from "./DayNightGlyph";
import styles from "./ClockWidget.module.scss";

/** One clock ready to render: a zone the browser knows and the place's label. */
export interface ClockWidgetEntry {
  timeZone: string;
  /** The place — the model's label, or the city derived from the zone id; business content, never translated. */
  label: string;
  /** The model's zone abbreviation; undefined → the browser's short zone name. */
  abbreviation?: string;
}

export interface ClockRowProps {
  clock: ClockWidgetEntry;
  now: Date;
  ownerTimeZone: string;
  timeFormat: ClockTimeFormat;
  language: string;
  dateLocale: string;
  testId?: string;
}

/** One translation key per relative day: the sentence keeps its word order in every language. */
const RELATIVE_DAY_KEYS: Record<ClockRelativeDay, string> = {
  today: "Today, %hourDifference% h",
  yesterday: "Yesterday, %hourDifference% h",
  tomorrow: "Tomorrow, %hourDifference% h",
};

/**
 * One place: the digital time as the hero, the place with its zone abbreviation, the day there and the hour difference
 * to the owner's zone, then the date, the UTC offset and day or night; the analog dial on the right.
 */
export const ClockRow = (props: ClockRowProps) => {
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

  return (
    <div className={styles.row} data-testid="clock-row" data-time-zone={clock.timeZone}>
      <div className={styles.readout}>
        <span className={styles.digitalTime} data-testid="clock-digital-time">
          <HeroValue>{time}</HeroValue>
          {dayPeriod ? (
            <Text role="intro" weight="bold" translate={false}>
              {dayPeriod}
            </Text>
          ) : null}
        </span>
        <Text
          role="body"
          weight="bold"
          translate={false}
          className={styles.wrappingText}
          testId={`${testId}-place`}
        >
          {escapePlainTextForText(placeLine)}
        </Text>
        <Text
          role="help"
          color="var(--agents-fg-light)"
          replacements={{ hourDifference }}
          testId={`${testId}-relative-day`}
        >
          {RELATIVE_DAY_KEYS[readRelativeDay(wallClock, ownerWallClock)]}
        </Text>
        <span className={styles.metaLine}>
          <Text
            role="caption"
            color="var(--agents-fg-light)"
            translate={false}
            testId={`${testId}-date`}
          >
            {dateLine}
          </Text>
          <span className={styles.dayNight}>
            <DayNightGlyph isDaytime={isDaytimeThere} />
            <Text role="caption" color="var(--agents-fg-light)">
              {isDaytimeThere ? "Day" : "Night"}
            </Text>
          </span>
        </span>
      </div>
      <AnalogClockFace wallClock={wallClock} accessibleLabel={`${clock.label} ${digitalTime}`} />
    </div>
  );
};
