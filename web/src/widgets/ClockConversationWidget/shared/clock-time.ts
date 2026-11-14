/**
 * The time arithmetic of the `Clock` widget — pure functions over a `Date` and IANA zone ids, computed from the
 * browser's own time-zone data (`Intl.DateTimeFormat`), so nothing is fetched and daylight-saving changes come with the
 * browser. Every function is total: an unknown zone is filtered out before (see {@link isKnownTimeZone}).
 */

/**
 * An IANA id with an area: `Europe/Berlin`, `America/Argentina/Buenos_Aires`, `Etc/GMT+9`. No raw offsets (`+01:00`).
 * Browsers also take abbreviations (`JST`, `IST`) through their ICU aliases — ambiguous across the world, so a
 * single-segment id counts only when it is one of the IANA names below (the backend class keeps the same list).
 */
const IANA_TIME_ZONE_WITH_AREA_SHAPE = /^[A-Za-z][A-Za-z0-9_+-]*(\/[A-Za-z0-9_+-]+)+$/;
const IANA_TIME_ZONES_WITHOUT_AREA: ReadonlySet<string> = new Set([
  "UTC",
  "GMT",
  "CET",
  "EET",
  "WET",
  "MET",
  "EST",
  "MST",
  "HST",
  "EST5EDT",
  "CST6CDT",
  "MST7MDT",
  "PST8PDT",
]);

/** The local hours a sun is shown for (06:00–19:59); a moon outside them. */
const FIRST_DAYTIME_HOUR = 6;
const FIRST_NIGHT_HOUR = 20;

const MILLISECONDS_PER_MINUTE = 60_000;
const MILLISECONDS_PER_DAY = 86_400_000;

/** One zone's wall clock at one instant. */
export interface ZonedWallClock {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
  second: number;
  /** Minutes east of UTC at that instant (Berlin in winter: 60, New York in summer: -240). */
  utcOffsetMinutes: number;
}

/** Whether the place there is today, yesterday or tomorrow, seen from the owner's zone. */
export type ClockRelativeDay = "today" | "yesterday" | "tomorrow";

const wallClockFormatterByTimeZone = new Map<string, Intl.DateTimeFormat>();

const buildWallClockFormatter = (timeZone: string): Intl.DateTimeFormat => {
  const cachedFormatter = wallClockFormatterByTimeZone.get(timeZone);
  if (cachedFormatter) return cachedFormatter;
  const wallClockFormatter = new Intl.DateTimeFormat("en-US", {
    timeZone,
    hourCycle: "h23",
    year: "numeric",
    month: "numeric",
    day: "numeric",
    hour: "numeric",
    minute: "numeric",
    second: "numeric",
  });
  wallClockFormatterByTimeZone.set(timeZone, wallClockFormatter);
  return wallClockFormatter;
};

/** True when the browser knows the zone — the widget drops (and reports) every other id. */
export const isKnownTimeZone = (timeZone: string): boolean => {
  if (!IANA_TIME_ZONE_WITH_AREA_SHAPE.test(timeZone) && !IANA_TIME_ZONES_WITHOUT_AREA.has(timeZone)) return false;
  try {
    buildWallClockFormatter(timeZone);
    return true;
  } catch {
    return false;
  }
};

/** The zone's wall clock at `instant`; the UTC offset is the difference between that wall clock and UTC. */
export const readZonedWallClock = (instant: Date, timeZone: string): ZonedWallClock => {
  const partValueByType: Record<string, number> = {};
  for (const datePart of buildWallClockFormatter(timeZone).formatToParts(instant)) {
    if (datePart.type !== "literal") partValueByType[datePart.type] = Number(datePart.value);
  }
  const hour = (partValueByType.hour ?? 0) % 24;
  const wallClockAsUtcMilliseconds = Date.UTC(
    partValueByType.year ?? 1970,
    (partValueByType.month ?? 1) - 1,
    partValueByType.day ?? 1,
    hour,
    partValueByType.minute ?? 0,
    partValueByType.second ?? 0,
  );
  const instantWholeSecondMilliseconds = Math.floor(instant.getTime() / 1000) * 1000;
  return {
    year: partValueByType.year ?? 1970,
    month: partValueByType.month ?? 1,
    day: partValueByType.day ?? 1,
    hour,
    minute: partValueByType.minute ?? 0,
    second: partValueByType.second ?? 0,
    utcOffsetMinutes: Math.round(
      (wallClockAsUtcMilliseconds - instantWholeSecondMilliseconds) / MILLISECONDS_PER_MINUTE,
    ),
  };
};

const readDayNumber = (wallClock: ZonedWallClock): number =>
  Math.round(Date.UTC(wallClock.year, wallClock.month - 1, wallClock.day) / MILLISECONDS_PER_DAY);

/** The calendar day at the clock's place compared with the owner's: one day apart at most (UTC−12 … UTC+14). */
export const readRelativeDay = (clockWallClock: ZonedWallClock, ownerWallClock: ZonedWallClock): ClockRelativeDay => {
  const dayDifference = readDayNumber(clockWallClock) - readDayNumber(ownerWallClock);
  if (dayDifference > 0) return "tomorrow";
  if (dayDifference < 0) return "yesterday";
  return "today";
};

/** `+0`, `+8`, `−5`, `+5:30` — hours (and minutes) the clock's zone is ahead of the owner's. */
export const formatHourDifference = (differenceMinutes: number): string => {
  const sign = differenceMinutes < 0 ? "−" : "+";
  const absoluteMinutes = Math.abs(differenceMinutes);
  const hours = Math.floor(absoluteMinutes / 60);
  const minutes = absoluteMinutes % 60;
  return minutes === 0 ? `${sign}${hours}` : `${sign}${hours}:${String(minutes).padStart(2, "0")}`;
};

/** `UTC+1`, `UTC−4`, `UTC+5:30`, and `UTC` for no offset. */
export const formatUtcOffset = (utcOffsetMinutes: number): string =>
  utcOffsetMinutes === 0 ? "UTC" : `UTC${formatHourDifference(utcOffsetMinutes)}`;

/** The sun is shown from 06:00 to 19:59 local time, the moon otherwise. */
export const isDaytime = (wallClock: ZonedWallClock): boolean =>
  wallClock.hour >= FIRST_DAYTIME_HOUR && wallClock.hour < FIRST_NIGHT_HOUR;

/** The place a zone id names: `America/New_York` → `New York`, `UTC` → `UTC`. */
export const readCityOfTimeZone = (timeZone: string): string => {
  const zoneSegments = timeZone.split("/");
  return (zoneSegments[zoneSegments.length - 1] ?? timeZone).replace(/_/g, " ");
};

const readShortZoneName = (instant: Date, timeZone: string, locale: string): string | undefined => {
  try {
    const zoneName = new Intl.DateTimeFormat(locale, { timeZone, timeZoneName: "short" })
      .formatToParts(instant)
      .find((datePart) => datePart.type === "timeZoneName")
      ?.value.trim();
    return zoneName && !/^(GMT|UTC)/.test(zoneName) ? zoneName : undefined;
  } catch {
    return undefined;
  }
};

/**
 * The browser's short zone name in the owner's locale (`MEZ`), else in US English (`EDT` — most locales name foreign
 * zones only by offset), or undefined when both know only an offset (`GMT+9`): the UTC offset has its own line anyway.
 */
export const readZoneAbbreviation = (instant: Date, timeZone: string, locale: string): string | undefined =>
  readShortZoneName(instant, timeZone, locale) ?? readShortZoneName(instant, timeZone, "en-US");

/** The digital time: `02:58` (24 h, zero-padded) or `2:58` plus the locale's day period (`AM`, `PM`) for 12 h. */
export const formatDigitalTime = (
  wallClock: ZonedWallClock,
  timeFormat: "24h" | "12h",
  instant: Date,
  timeZone: string,
  locale: string,
): { time: string; dayPeriod?: string } => {
  const minute = String(wallClock.minute).padStart(2, "0");
  if (timeFormat === "24h") return { time: `${String(wallClock.hour).padStart(2, "0")}:${minute}` };
  let dayPeriod: string | undefined;
  try {
    dayPeriod = new Intl.DateTimeFormat(locale, { timeZone, hour: "numeric", hour12: true })
      .formatToParts(instant)
      .find((datePart) => datePart.type === "dayPeriod")
      ?.value.trim();
  } catch {
    dayPeriod = undefined;
  }
  return {
    time: `${wallClock.hour % 12 || 12}:${minute}`,
    dayPeriod: dayPeriod || (wallClock.hour < 12 ? "AM" : "PM"),
  };
};

/** The date at the clock's place in the owner's date locale: `So., 26. Okt.` / `Sun, Oct 26`. */
export const formatZonedDate = (instant: Date, timeZone: string, dateLocale: string): string => {
  try {
    return new Intl.DateTimeFormat(dateLocale, { timeZone, weekday: "short", day: "numeric", month: "short" }).format(
      instant,
    );
  } catch {
    return "";
  }
};

/** The capture moment of an e-mail render, in the owner's zone and date locale: `26.10.2026, 02:58`. */
export const formatCaptureMoment = (instant: Date, timeZone: string, dateLocale: string, timeFormat: "24h" | "12h") => {
  try {
    return new Intl.DateTimeFormat(dateLocale, {
      timeZone,
      dateStyle: "medium",
      timeStyle: "short",
      hour12: timeFormat === "12h",
    }).format(instant);
  } catch {
    return instant.toISOString();
  }
};

/** The angles of the three hands in degrees, clockwise from 12. */
export const readHandAngles = (wallClock: ZonedWallClock): { hour: number; minute: number; second: number } => ({
  hour: ((wallClock.hour % 12) + wallClock.minute / 60) * 30,
  minute: (wallClock.minute + wallClock.second / 60) * 6,
  second: wallClock.second * 6,
});
