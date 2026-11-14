/**
 * How a currency and its numbers read for the owner — all from the browser's `Intl`, in the app's
 * UI language: the currency's name ("US-Dollar"), its symbol ("$"), its flag, an amount, a rate and the day of a rate.
 */

/** The locale the numbers are written in: the app's UI language, `en` when it is unset or invalid. */
export const toCurrencyLocale = (languageCode: string | undefined): string => {
  if (!languageCode) return "en";
  try {
    return Intl.NumberFormat.supportedLocalesOf([languageCode.replace("_", "-")])[0] ?? "en";
  } catch {
    return "en";
  }
};

/** The currency's name in the locale ("Euro", "US-Dollar"); the code itself when the browser knows no name. */
export const readCurrencyName = (currencyCode: string, locale: string): string => {
  try {
    return new Intl.DisplayNames([locale], { type: "currency" }).of(currencyCode) ?? currencyCode;
  } catch {
    return currencyCode;
  }
};

/** The currency's narrow symbol ("€", "$", "CHF"); the code when there is none. */
export const readCurrencySymbol = (currencyCode: string, locale: string): string => {
  try {
    const currencyPart = new Intl.NumberFormat(locale, {
      style: "currency",
      currency: currencyCode,
      currencyDisplay: "narrowSymbol",
    })
      .formatToParts(0)
      .find((part) => part.type === "currency");
    return currencyPart?.value ?? currencyCode;
  } catch {
    return currencyCode;
  }
};

/**
 * The flag emoji of the currency's country — the first two letters of an ISO 4217 code are the ISO 3166 country
 * (`USD` → US); the euro takes the EU flag. Decorative only: the name next to it carries the meaning.
 */
export const readCurrencyFlag = (currencyCode: string): string => {
  const countryCode = currencyCode === "EUR" ? "EU" : currencyCode.slice(0, 2);
  if (!/^[A-Z]{2}$/.test(countryCode)) return "";
  return String.fromCodePoint(...[...countryCode].map((letter) => 0x1f1e6 + letter.charCodeAt(0) - 65));
};

/** An amount with the currency's own number of decimals (2 for EUR, 0 for JPY), grouped for the locale. */
export const formatCurrencyAmount = (amount: number, currencyCode: string, locale: string): string => {
  try {
    return new Intl.NumberFormat(locale, { style: "currency", currency: currencyCode, currencyDisplay: "code" })
      .formatToParts(amount)
      .filter((part) => part.type !== "currency" && part.type !== "literal")
      .map((part) => part.value)
      .join("")
      .trim();
  } catch {
    return amount.toFixed(2);
  }
};

/** An exact figure without currency rounding (a rate, the exact product): up to six significant digits after rounding. */
export const formatExactNumber = (value: number, locale: string): string => {
  const maximumFractionDigits = value >= 1000 ? 2 : value >= 1 ? 5 : 6;
  try {
    return new Intl.NumberFormat(locale, { maximumFractionDigits }).format(value);
  } catch {
    return String(value);
  }
};

/** The day of a rate (`2026-10-25`) in the locale's medium date style ("25.10.2026", "Oct 25, 2026"). */
export const formatRateDate = (rateDate: string, locale: string): string => {
  try {
    return new Intl.DateTimeFormat(locale, { dateStyle: "medium", timeZone: "UTC" }).format(
      new Date(`${rateDate}T00:00:00Z`),
    );
  } catch {
    return rateDate;
  }
};

/** The amount as it rests in the field: grouped for the locale (`1,250,000`, `1.250.000`), its decimals kept. */
export const formatEditableAmount = (amount: number, locale: string): string => {
  try {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: 6 }).format(amount);
  } catch {
    return String(amount);
  }
};

const readNumberMarks = (locale: string): { groupMark: string; decimalMark: string } => {
  try {
    const parts = new Intl.NumberFormat(locale).formatToParts(12345.6);
    return {
      groupMark: parts.find((part) => part.type === "group")?.value ?? ",",
      decimalMark: parts.find((part) => part.type === "decimal")?.value ?? ".",
    };
  } catch {
    return { groupMark: ",", decimalMark: "." };
  }
};

/**
 * The amount the owner typed, or undefined when it is no number: spaces and apostrophes are dropped. When both `.` and
 * `,` occur the LAST one is the decimal mark (`1,250.75`, `1.250,75`); a mark that repeats groups thousands; a lone
 * mark is the decimal mark, except the locale's own grouping mark between a 1-3 digit head and exactly three digits
 * (`1,250` in English is 1250, `1.250` in German too).
 */
export const parseTypedAmount = (typedText: string, locale = "en"): number | undefined => {
  const compactText = typedText.replace(/[\s'’\u00a0\u202f]/g, "");
  if (compactText === "") return undefined;
  const marks = compactText.match(/[.,]/g) ?? [];
  const lastMarkIndex = Math.max(compactText.lastIndexOf("."), compactText.lastIndexOf(","));
  const { groupMark } = readNumberMarks(locale);
  const isGrouping =
    marks.length > 1 && new Set(marks).size === 1
      ? true
      : marks.length === 1 && compactText[lastMarkIndex] === groupMark && /^\d{1,3}[.,]\d{3}$/.test(compactText);
  const normalizedText = isGrouping
    ? compactText.replace(/[.,]/g, "")
    : lastMarkIndex < 0
      ? compactText
      : `${compactText.slice(0, lastMarkIndex).replace(/[.,]/g, "")}.${compactText.slice(lastMarkIndex + 1)}`;
  if (!/^\d*\.?\d+$|^\d+\.$/.test(normalizedText)) return undefined;
  const typedAmount = Number(normalizedText);
  return Number.isFinite(typedAmount) ? typedAmount : undefined;
};
