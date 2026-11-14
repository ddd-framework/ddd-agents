import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";
import {
  ComposedViewTone,
  readComposedViewTone,
} from "../../composed-view-tones";

/**
 * How the data nodes show a value — the frontend twin of `ComposedViewDataValueFormat.php`: the
 * closed `format`, `tone` and `align` words every data node accepts, and the one formatter they all use, so a number
 * reads the same in a KPI tile, a table cell and a chart axis. Pure functions, no React.
 */

export type DataValueFormat = "number" | "percent" | "currency" | "compact" | "date" | "text";
/** The kit's one tone list (`composed-view-tones.ts`). */
export type DataValueTone = ComposedViewTone;
export type DataValueAlign = "start" | "center" | "end";

const DATA_VALUE_FORMATS: readonly DataValueFormat[] = ["number", "percent", "currency", "compact", "date", "text"];
const DATA_VALUE_ALIGNS: readonly DataValueAlign[] = ["start", "center", "end"];

/** The value as one of `allowed`, or `fallback` (a resolved binding may carry anything). */
const readOneOf = <T extends string>(value: unknown, allowed: readonly T[], fallback: T): T =>
  typeof value === "string" && (allowed as readonly string[]).includes(value) ? (value as T) : fallback;

export const readDataValueFormat = (value: unknown, fallback: DataValueFormat = "number"): DataValueFormat =>
  readOneOf(value, DATA_VALUE_FORMATS, fallback);
export const readDataValueTone = (value: unknown, fallback: DataValueTone = "neutral"): DataValueTone =>
  readComposedViewTone(value, fallback);
export const readDataValueAlign = (value: unknown, fallback: DataValueAlign = "start"): DataValueAlign =>
  readOneOf(value, DATA_VALUE_ALIGNS, fallback);

/** A finite number from a number or a numeric string ("1207", "36.5"); undefined otherwise. */
export const readFiniteNumber = (value: unknown): number | undefined => {
  if (typeof value === "number") return Number.isFinite(value) ? value : undefined;
  if (typeof value === "string" && value.trim() !== "" && /^[-+]?\d*\.?\d+(e[-+]?\d+)?$/i.test(value.trim())) {
    const parsed = Number(value.trim());
    return Number.isFinite(parsed) ? parsed : undefined;
  }
  return undefined;
};

/** A string prop, or undefined (a binding resolved to a non-string, an absent prop). */
export const readText = (value: unknown): string | undefined =>
  typeof value === "string" && value !== "" ? value : typeof value === "number" ? String(value) : undefined;

/** The plain object a resolved prop holds, or undefined. */
export const readObject = (value: unknown): Record<string, ExpressionValue> | undefined =>
  typeof value === "object" && value !== null && !Array.isArray(value)
    ? (value as Record<string, ExpressionValue>)
    : undefined;

/** The rows (plain objects) a resolved `data` / `rows` / `items` prop holds; non-object entries are skipped. */
export const readRows = (value: unknown): Record<string, ExpressionValue>[] | undefined =>
  Array.isArray(value)
    ? value.filter(
        (row): row is Record<string, ExpressionValue> => typeof row === "object" && row !== null && !Array.isArray(row),
      )
    : undefined;

/** The locale the numbers are written in: the app's UI language, `en` when it is unset or invalid. */
export const toNumberLocale = (languageCode: string | undefined): string => {
  if (!languageCode) return "en";
  try {
    return Intl.NumberFormat.supportedLocalesOf([languageCode.replace("_", "-")])[0] ?? "en";
  } catch {
    return "en";
  }
};

export interface DataValueFormatOptions {
  format?: DataValueFormat;
  currency?: string;
  decimals?: number;
  /** A unit written after the value ("km", "visits"). */
  unit?: string;
  /** A positive number gets a plus sign. */
  signed?: boolean;
  locale: string;
}

const readCurrencyCode = (currency: string | undefined): string =>
  typeof currency === "string" && /^[A-Za-z]{3}$/.test(currency) ? currency.toUpperCase() : "EUR";

const readFractionDigits = (decimals: number | undefined): number | undefined =>
  typeof decimals === "number" && Number.isInteger(decimals) && decimals >= 0 && decimals <= 6 ? decimals : undefined;

const formatNumberWithOptions = (value: number, options: DataValueFormatOptions): string => {
  const { format = "number", currency, decimals, signed = false, locale } = options;
  const fractionDigits = readFractionDigits(decimals);
  const signDisplay: Intl.NumberFormatOptions["signDisplay"] = signed ? "exceptZero" : "auto";
  const digitOptions: Intl.NumberFormatOptions =
    fractionDigits === undefined
      ? { maximumFractionDigits: 2 }
      : { minimumFractionDigits: fractionDigits, maximumFractionDigits: fractionDigits };
  switch (format) {
    case "currency":
      return new Intl.NumberFormat(locale, {
        style: "currency",
        currency: readCurrencyCode(currency),
        signDisplay,
        ...(fractionDigits === undefined
          ? {
              minimumFractionDigits: 0,
              maximumFractionDigits: Number.isInteger(value) || Math.abs(value) >= 1000 ? 0 : 2,
            }
          : digitOptions),
      }).format(value);
    case "percent":
      // The value IS the percentage (36 → 36 %); Intl's percent style would multiply by 100.
      return `${new Intl.NumberFormat(locale, { signDisplay, ...digitOptions }).format(value)} %`;
    case "compact":
      return new Intl.NumberFormat(locale, {
        notation: "compact",
        signDisplay,
        maximumFractionDigits: fractionDigits ?? 1,
      }).format(value);
    default:
      return new Intl.NumberFormat(locale, { signDisplay, ...digitOptions }).format(value);
  }
};

const ISO_DATE = /^\d{4}-\d{2}-\d{2}(T[\d:.]+(Z|[+-]\d{2}:?\d{2})?)?$/;

const formatDate = (value: string, locale: string): string | undefined => {
  if (!ISO_DATE.test(value)) return undefined;
  const date = new Date(value.length === 10 ? `${value}T00:00:00` : value);
  if (Number.isNaN(date.getTime())) return undefined;
  return new Intl.DateTimeFormat(locale, { day: "numeric", month: "short", year: "numeric" }).format(date);
};

/**
 * One value as the owner reads it: a number in the locale and `format`, a date in the locale's short form, text as
 * written; the `unit` after it. `undefined` / `null` give the empty string (the caller decides what an empty cell
 * shows); a boolean gives an empty string too — a table shows a boolean as an `Icon`, never as "true".
 */
export const formatDataValue = (value: unknown, options: DataValueFormatOptions): string => {
  const { format = "number", unit, locale } = options;
  let formatted: string;
  const numericValue = format === "text" ? undefined : readFiniteNumber(value);
  if (numericValue !== undefined && format !== "date") {
    formatted = formatNumberWithOptions(numericValue, options);
  } else if (typeof value === "string") {
    formatted = format === "date" ? (formatDate(value, locale) ?? value) : value;
  } else if (typeof value === "number") {
    formatted = String(value);
  } else {
    return "";
  }
  return unit ? `${formatted} ${unit}` : formatted;
};

/** Whether `value` reads as a number — numeric columns and cells align at the end. */
export const isNumericValue = (value: unknown): boolean => readFiniteNumber(value) !== undefined;

/** A value clamped to 0..1, or undefined (the `bar` and `heat` props). */
export const readFraction = (value: unknown): number | undefined => {
  const numericValue = readFiniteNumber(value);
  if (numericValue === undefined) return undefined;
  return Math.min(1, Math.max(0, numericValue));
};
