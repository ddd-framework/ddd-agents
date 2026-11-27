import { readComposedViewTone, } from "../../composed-view-tones";
const DATA_VALUE_FORMATS = ["number", "percent", "currency", "compact", "date", "text"];
const DATA_VALUE_ALIGNS = ["start", "center", "end"];
/** The value as one of `allowed`, or `fallback` (a resolved binding may carry anything). */
const readOneOf = (value, allowed, fallback) => typeof value === "string" && allowed.includes(value) ? value : fallback;
export const readDataValueFormat = (value, fallback = "number") => readOneOf(value, DATA_VALUE_FORMATS, fallback);
export const readDataValueTone = (value, fallback = "neutral") => readComposedViewTone(value, fallback);
export const readDataValueAlign = (value, fallback = "start") => readOneOf(value, DATA_VALUE_ALIGNS, fallback);
/** A finite number from a number or a numeric string ("1207", "36.5"); undefined otherwise. */
export const readFiniteNumber = (value) => {
    if (typeof value === "number")
        return Number.isFinite(value) ? value : undefined;
    if (typeof value === "string" && value.trim() !== "" && /^[-+]?\d*\.?\d+(e[-+]?\d+)?$/i.test(value.trim())) {
        const parsed = Number(value.trim());
        return Number.isFinite(parsed) ? parsed : undefined;
    }
    return undefined;
};
/** A string prop, or undefined (a binding resolved to a non-string, an absent prop). */
export const readText = (value) => typeof value === "string" && value !== "" ? value : typeof value === "number" ? String(value) : undefined;
/** The plain object a resolved prop holds, or undefined. */
export const readObject = (value) => typeof value === "object" && value !== null && !Array.isArray(value)
    ? value
    : undefined;
/** The rows (plain objects) a resolved `data` / `rows` / `items` prop holds; non-object entries are skipped. */
export const readRows = (value) => Array.isArray(value)
    ? value.filter((row) => typeof row === "object" && row !== null && !Array.isArray(row))
    : undefined;
/** The locale the numbers are written in: the app's UI language, `en` when it is unset or invalid. */
export const toNumberLocale = (languageCode) => {
    if (!languageCode)
        return "en";
    try {
        return Intl.NumberFormat.supportedLocalesOf([languageCode.replace("_", "-")])[0] ?? "en";
    }
    catch {
        return "en";
    }
};
const readCurrencyCode = (currency) => typeof currency === "string" && /^[A-Za-z]{3}$/.test(currency) ? currency.toUpperCase() : "EUR";
const readFractionDigits = (decimals) => typeof decimals === "number" && Number.isInteger(decimals) && decimals >= 0 && decimals <= 6 ? decimals : undefined;
const formatNumberWithOptions = (value, options) => {
    const { format = "number", currency, decimals, signed = false, locale } = options;
    const fractionDigits = readFractionDigits(decimals);
    const signDisplay = signed ? "exceptZero" : "auto";
    const digitOptions = fractionDigits === undefined
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
const formatDate = (value, locale) => {
    if (!ISO_DATE.test(value))
        return undefined;
    const date = new Date(value.length === 10 ? `${value}T00:00:00` : value);
    if (Number.isNaN(date.getTime()))
        return undefined;
    return new Intl.DateTimeFormat(locale, { day: "numeric", month: "short", year: "numeric" }).format(date);
};
/**
 * One value as the owner reads it: a number in the locale and `format`, a date in the locale's short form, text as
 * written; the `unit` after it. `undefined` / `null` give the empty string (the caller decides what an empty cell
 * shows); a boolean gives an empty string too — a table shows a boolean as an `Icon`, never as "true".
 */
export const formatDataValue = (value, options) => {
    const { format = "number", unit, locale } = options;
    let formatted;
    const numericValue = format === "text" ? undefined : readFiniteNumber(value);
    if (numericValue !== undefined && format !== "date") {
        formatted = formatNumberWithOptions(numericValue, options);
    }
    else if (typeof value === "string") {
        formatted = format === "date" ? (formatDate(value, locale) ?? value) : value;
    }
    else if (typeof value === "number") {
        formatted = String(value);
    }
    else {
        return "";
    }
    return unit ? `${formatted} ${unit}` : formatted;
};
/** Whether `value` reads as a number — numeric columns and cells align at the end. */
export const isNumericValue = (value) => readFiniteNumber(value) !== undefined;
/** A value clamped to 0..1, or undefined (the `bar` and `heat` props). */
export const readFraction = (value) => {
    const numericValue = readFiniteNumber(value);
    if (numericValue === undefined)
        return undefined;
    return Math.min(1, Math.max(0, numericValue));
};
