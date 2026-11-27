/** The largest amount the widget accepts — beyond it the float maths stops being exact to the cent. */
export const MAX_CURRENCY_CONVERTER_AMOUNT = 1000000000000;
const ISO_CURRENCY_CODE = /^[A-Z]{3}$/;
const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;
const readFirstField = (fields, aliases) => {
    for (const alias of aliases) {
        if (fields[alias] !== undefined && fields[alias] !== null)
            return fields[alias];
    }
    return undefined;
};
const readCurrencyCode = (value) => {
    if (typeof value !== "string")
        return undefined;
    const upperCaseCode = value.trim().toUpperCase();
    return ISO_CURRENCY_CODE.test(upperCaseCode) ? upperCaseCode : undefined;
};
/** A finite number, or a numeric string (`"1,12"` and `"1.12"` both read as 1.12). */
export const readCurrencyConverterNumber = (value) => {
    if (typeof value === "number")
        return Number.isFinite(value) ? value : undefined;
    if (typeof value !== "string")
        return undefined;
    const trimmedText = value.trim().replace(/\s/g, "").replace(",", ".");
    if (trimmedText === "" || !/^-?\d*\.?\d+$/.test(trimmedText))
        return undefined;
    const parsedNumber = Number(trimmedText);
    return Number.isFinite(parsedNumber) ? parsedNumber : undefined;
};
const readRateDate = (value) => {
    if (typeof value !== "string" || !ISO_DAY.test(value.trim()))
        return undefined;
    const rateDate = value.trim();
    return Number.isNaN(Date.parse(`${rateDate}T00:00:00Z`)) ? undefined : rateDate;
};
export const parseCurrencyConverterPayload = (payload) => {
    if (!payload)
        return null;
    let parsedPayload;
    try {
        parsedPayload = JSON.parse(payload);
    }
    catch {
        return null;
    }
    if (typeof parsedPayload !== "object" || parsedPayload === null || Array.isArray(parsedPayload))
        return null;
    const fields = parsedPayload;
    const fromCurrency = readCurrencyCode(readFirstField(fields, ["fromCurrency", "from_currency", "from", "baseCurrency"]));
    const toCurrency = readCurrencyCode(readFirstField(fields, ["toCurrency", "to_currency", "to", "targetCurrency"]));
    const rate = readCurrencyConverterNumber(readFirstField(fields, ["rate", "exchangeRate", "exchange_rate"]));
    if (!fromCurrency || !toCurrency || rate === undefined || rate <= 0)
        return null;
    const amount = readCurrencyConverterNumber(readFirstField(fields, ["amount", "fromAmount", "value"]));
    return {
        fromCurrency,
        toCurrency,
        amount: amount !== undefined && amount >= 0 && amount <= MAX_CURRENCY_CONVERTER_AMOUNT ? amount : 1,
        rate,
        rateDate: readRateDate(readFirstField(fields, ["rateDate", "rate_date", "date"])),
    };
};
