import { describe, expect, test } from "vitest";
import { formatDataValue, readFiniteNumber, readFraction, toNumberLocale } from "./data-value-format";

describe("formatDataValue", () => {
  test("numbers in the locale and format", () => {
    expect(formatDataValue(1207, { locale: "de" })).toBe("1.207");
    expect(formatDataValue(4.32, { format: "percent", locale: "de" })).toBe("4,32 %");
    expect(formatDataValue(113498, { format: "currency", currency: "EUR", locale: "en" })).toBe("€113,498");
    expect(formatDataValue(39500000, { format: "compact", locale: "en" })).toBe("39.5M");
    expect(formatDataValue(2, { signed: true, locale: "en" })).toBe("+2");
    expect(formatDataValue(0, { signed: true, locale: "en" })).toBe("0");
    expect(formatDataValue(12, { unit: "km", locale: "en" })).toBe("12 km");
    expect(formatDataValue("36.5", { format: "percent", decimals: 1, locale: "en" })).toBe("36.5 %");
  });

  test("dates, text and empty values", () => {
    expect(formatDataValue("2026-10-25", { format: "date", locale: "en" })).toBe("Oct 25, 2026");
    expect(formatDataValue("CSU", { locale: "en" })).toBe("CSU");
    expect(formatDataValue("007", { format: "text", locale: "en" })).toBe("007");
    expect(formatDataValue(undefined, { locale: "en" })).toBe("");
    expect(formatDataValue(true, { locale: "en" })).toBe("");
  });

  test("readers never throw", () => {
    expect(readFiniteNumber("1e3")).toBe(1000);
    expect(readFiniteNumber("12 km")).toBeUndefined();
    expect(readFiniteNumber(Number.POSITIVE_INFINITY)).toBeUndefined();
    expect(readFraction(1.4)).toBe(1);
    expect(toNumberLocale("not a locale ✗")).toBe("en");
    expect(toNumberLocale("de_DE")).toBe("de-DE");
  });
});
