import { readHandAngles, type ZonedWallClock } from "../shared/clock-time";
import styles from "./ClockWidget.module.scss";

export interface AnalogClockFaceProps {
  wallClock: ZonedWallClock;
  /** The accessible name — the digital time it shows, already formatted. */
  accessibleLabel: string;
}

const CENTER = 50;
const TICK_INDICES = Array.from({ length: 12 }, (unused, tickIndex) => tickIndex);

/**
 * The analog dial of one clock: twelve ticks (the quarters longer), hour and minute hand in the text colour, the second
 * hand and the hub in the call-to-action colour — every colour a theme token, so it flips with the scheme. Purely a
 * picture of the digital time next to it: `role="img"` with that time as its name.
 */
export const AnalogClockFace = (props: AnalogClockFaceProps) => {
  const { wallClock, accessibleLabel } = props;
  const handAngles = readHandAngles(wallClock);
  return (
    <svg className={styles.dial} viewBox="0 0 100 100" role="img" aria-label={accessibleLabel}>
      <circle className={styles.dialFace} cx={CENTER} cy={CENTER} r={48} />
      {TICK_INDICES.map((tickIndex) => (
        <line
          key={tickIndex}
          className={tickIndex % 3 === 0 ? styles.dialQuarterTick : styles.dialTick}
          x1={CENTER}
          y1={tickIndex % 3 === 0 ? 7 : 9}
          x2={CENTER}
          y2={14}
          transform={`rotate(${tickIndex * 30} ${CENTER} ${CENTER})`}
        />
      ))}
      <line
        className={styles.hourHand}
        x1={CENTER}
        y1={CENTER + 6}
        x2={CENTER}
        y2={24}
        transform={`rotate(${handAngles.hour} ${CENTER} ${CENTER})`}
      />
      <line
        className={styles.minuteHand}
        x1={CENTER}
        y1={CENTER + 7}
        x2={CENTER}
        y2={13}
        transform={`rotate(${handAngles.minute} ${CENTER} ${CENTER})`}
      />
      <line
        className={styles.secondHand}
        data-testid="clock-second-hand"
        x1={CENTER}
        y1={CENTER + 10}
        x2={CENTER}
        y2={10}
        transform={`rotate(${handAngles.second} ${CENTER} ${CENTER})`}
      />
      <circle className={styles.dialHub} cx={CENTER} cy={CENTER} r={2.5} />
    </svg>
  );
};
