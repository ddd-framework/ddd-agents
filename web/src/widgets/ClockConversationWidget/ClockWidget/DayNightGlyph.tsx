import styles from "./ClockWidget.module.scss";

export interface DayNightGlyphProps {
  isDaytime: boolean;
}

const SUN_RAY_ANGLES = [0, 45, 90, 135, 180, 225, 270, 315];

/**
 * A sun or a moon (the component library has neither icon) beside the "Day" / "Night" word — decoration only, the word carries the
 * meaning, so the glyph is hidden from assistive technology.
 */
export const DayNightGlyph = (props: DayNightGlyphProps) => {
  const { isDaytime } = props;
  if (isDaytime) {
    return (
      <svg className={styles.dayNightGlyph} viewBox="0 0 16 16" aria-hidden="true" data-testid="clock-sun">
        <circle className={styles.sunDisc} cx={8} cy={8} r={3.2} />
        {SUN_RAY_ANGLES.map((rayAngle) => (
          <line
            key={rayAngle}
            className={styles.sunRay}
            x1={8}
            y1={1.5}
            x2={8}
            y2={3.2}
            transform={`rotate(${rayAngle} 8 8)`}
          />
        ))}
      </svg>
    );
  }
  return (
    <svg className={styles.dayNightGlyph} viewBox="0 0 16 16" aria-hidden="true" data-testid="clock-moon">
      <path className={styles.moon} d="M10.8 2.2a6 6 0 1 0 3 9.6 5 5 0 0 1-3-9.6z" />
    </svg>
  );
};
