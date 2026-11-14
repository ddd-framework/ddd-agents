import { describe, expect, it } from "vitest";
import { parseCurrencyConverterPayload } from "./currency-converter-widget-payload";
import {
  formatCurrencyAmount,
  formatExactNumber,
  formatRateDate,
  readCurrencyFlag,
  readCurrencyName,
  readCurrencySymbol,
  toCurrencyLocale,
} from "./currency-display";

describe("parseCurrencyConverterPayload", () => {
  it("reads the dictated block", () => {
    expect(
      parseCurrencyConverterPayload(
        JSON.stringify({ fromCurrency: "EUR", toCurrency: "USD", amount: 100, rate: 1.12023, rateDate: "2026-10-25" }),
      ),
    ).toEqual({ fromCurrency: "EUR", toCurrency: "USD", amount: 100, rate: 1.12023, rateDate: "2026-10-25" });
  });

  it("accepts the field names a model renames and lower-case codes", () => {
    expect(
      parseCurrencyConverterPayload(
        JSON.stringify({
          from_currency: "chf",
          to: "eur",
          value: "2450,50",
          exchangeRate: "0.9412",
          date: "2026-09-30",
        }),
      ),
    ).toEqual({ fromCurrency: "CHF", toCurrency: "EUR", amount: 2450.5, rate: 0.9412, rateDate: "2026-09-30" });
  });

  it("defaults the amount to 1 and drops a malformed day", () => {
    expect(
      parseCurrencyConverterPayload(
        JSON.stringify({ fromCurrency: "EUR", toCurrency: "GBP", rate: 0.84, rateDate: "25.10.2026" }),
      ),
    ).toEqual({ fromCurrency: "EUR", toCurrency: "GBP", amount: 1, rate: 0.84, rateDate: undefined });
  });

  it.each([
    ["no rate", { fromCurrency: "EUR", toCurrency: "USD", amount: 100 }],
    ["a zero rate", { fromCurrency: "EUR", toCurrency: "USD", rate: 0 }],
    ["a negative rate", { fromCurrency: "EUR", toCurrency: "USD", rate: -1.1 }],
    ["a code that is no ISO code", { fromCurrency: "EURO", toCurrency: "USD", rate: 1.1 }],
    ["a missing target", { fromCurrency: "EUR", rate: 1.1 }],
  ])("gives null for %s", (_caseName, fields) => {
    expect(parseCurrencyConverterPayload(JSON.stringify(fields))).toBeNull();
  });

  it("never throws on broken input", () => {
    expect(parseCurrencyConverterPayload("{not json")).toBeNull();
    expect(parseCurrencyConverterPayload("[1,2]")).toBeNull();
    expect(parseCurrencyConverterPayload("")).toBeNull();
    expect(parseCurrencyConverterPayload(undefined)).toBeNull();
  });
});

describe("currency display", () => {
  it("formats amounts with the currency's own decimals in the owner's language", () => {
    expect(formatCurrencyAmount(112.023, "USD", "de")).toBe("112,02");
    expect(formatCurrencyAmount(1250000, "USD", "en")).toBe("1,250,000.00");
    expect(formatCurrencyAmount(215547625, "JPY", "en")).toBe("215,547,625");
  });

  it("keeps a rate exact to five decimals", () => {
    expect(formatExactNumber(1.12023, "de")).toBe("1,12023");
    expect(formatExactNumber(0.892675, "en")).toBe("0.892675");
  });

  it("names the currency, its symbol and its flag", () => {
    expect(readCurrencyName("USD", "en")).toBe("US Dollar");
    expect(readCurrencySymbol("EUR", "de")).toBe("€");
    expect(readCurrencyFlag("EUR")).toBe("🇪🇺");
    expect(readCurrencyFlag("USD")).toBe("🇺🇸");
  });

  it("writes the day of the rate in the owner's date style", () => {
    expect(formatRateDate("2026-10-25", "de")).toBe("25.10.2026");
  });

  it("falls back to English for an unset or invalid language", () => {
    expect(toCurrencyLocale(undefined)).toBe("en");
    expect(toCurrencyLocale("de_DE")).toBe("de-DE");
  });
});

describe("the editable amount", () => {
  it("writes the amount grouped, in the owner's marks", async () => {
    const { formatEditableAmount } = await import("./currency-display");
    expect(formatEditableAmount(2450.5, "de")).toBe("2.450,5");
    expect(formatEditableAmount(1250000, "en")).toBe("1,250,000");
  });

  it.each([
    ["250", 250],
    ["250,5", 250.5],
    ["250.5", 250.5],
    ["1.250,75", 1250.75],
    ["1,250.75", 1250.75],
    ["1 250", 1250],
    ["12.", 12],
    ["1,250,000", 1250000],
    ["1.250.000", 1250000],
  ])("reads %s as %s", async (typedText, expectedAmount) => {
    const { parseTypedAmount } = await import("./currency-display");
    expect(parseTypedAmount(typedText)).toBe(expectedAmount);
  });

  it.each([
    ["1,250", "en", 1250],
    ["1.250", "de", 1250],
    ["1,250", "de", 1.25],
    ["1.250", "en", 1.25],
    ["250.5", "de", 250.5],
  ])("reads %s in %s as %s", async (typedText, locale, expectedAmount) => {
    const { parseTypedAmount } = await import("./currency-display");
    expect(parseTypedAmount(typedText, locale)).toBe(expectedAmount);
  });

  it.each(["", "abc", "-5", "1e5", "1,2,x"])("reads %s as no amount", async (typedText) => {
    const { parseTypedAmount } = await import("./currency-display");
    expect(parseTypedAmount(typedText)).toBeUndefined();
  });
});
