/**
 * The values the `VideoCard` node reads from its resolved props, each reader total: a value that
 * does not fit is `undefined`, never a throw.
 */

/** A view count: a finite whole number of zero or more. */
export const readViewCount = (value: unknown): number | undefined =>
  typeof value === "number" && Number.isFinite(value) && value >= 0 ? Math.floor(value) : undefined;

/** A view count in the locale's compact notation: `18234` → `18K` (en), `18.234` (de), `1.2M` (en). */
export const formatViewCount = (viewCount: number, locale: string): string =>
  new Intl.NumberFormat(locale, { notation: "compact", maximumFractionDigits: 1 }).format(viewCount);

const PUBLISHED_DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

/** A `Y-m-d` publish day in the locale's short form (`Feb 8, 2025`, `8. Feb. 2025`); anything else is `undefined`. */
export const formatPublishedDay = (value: unknown, locale: string): string | undefined => {
  if (typeof value !== "string") return undefined;
  const dayParts = PUBLISHED_DAY.exec(value.trim());
  if (!dayParts) return undefined;
  const monthIndex = Number(dayParts[2]) - 1;
  const publishedDay = new Date(Number(dayParts[1]), monthIndex, Number(dayParts[3]));
  if (Number.isNaN(publishedDay.getTime()) || publishedDay.getMonth() !== monthIndex) return undefined;
  return new Intl.DateTimeFormat(locale, { day: "numeric", month: "short", year: "numeric" }).format(publishedDay);
};
