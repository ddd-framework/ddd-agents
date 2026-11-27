import { readFiniteNumber } from "./data-value-format";
// Only whole numeric values and scores use the hero; numerals in prose remain readable text.
const NUMBER_PATTERN = String.raw `[-+]?(?:\d+(?:[   ]\d{3})*(?:[.,]\d+)?|[.,]\d+)`;
const NUMERIC_SCORE = new RegExp(String.raw `^${NUMBER_PATTERN}(?:\s*\/\s*${NUMBER_PATTERN}|\s*%)?$`);
const RATIO = new RegExp(String.raw `^(${NUMBER_PATTERN})(\s*\/\s*(${NUMBER_PATTERN}))$`);
// A written value may join its headline figure and a remark with a middle dot ("5/5 · 22 recenzii").
const SEGMENT_SEPARATOR = /\s+[·•]\s+/;
/** A written number as a JS number: grouping spaces dropped, a lone comma read as the decimal mark. */
const readWrittenNumber = (written) => {
    const compact = written.replace(/[   ]/g, "");
    return Number(compact.includes(".") ? compact.replace(/,/g, "") : compact.replace(",", "."));
};
const readHeroParts = (lead, secondary) => {
    const ratioMatch = RATIO.exec(lead);
    if (!ratioMatch)
        return { presentation: "hero", lead, secondary };
    const value = readWrittenNumber(ratioMatch[1]);
    const max = readWrittenNumber(ratioMatch[3]);
    const ratio = max > 0 && value >= 0 && value <= max ? { value, max } : undefined;
    return { presentation: "hero", lead: ratioMatch[1], denominator: ratioMatch[2], secondary, ratio };
};
/**
 * How a KPI value is laid out, never what it says: a number or a written score takes the hero, a leading score with a
 * remark splits at its middle dot, any other text stays one headline. Every character of the value is kept.
 */
export const readKpiValueParts = (rawValue, formattedValue, format = "number") => {
    if (format === "date")
        return { presentation: "text", lead: formattedValue };
    if (typeof rawValue === "number") {
        return { presentation: Number.isFinite(rawValue) ? "hero" : "text", lead: formattedValue };
    }
    if (typeof rawValue !== "string" || rawValue === "" || readFiniteNumber(rawValue) !== undefined) {
        return { presentation: "hero", lead: formattedValue };
    }
    const written = formattedValue.trim();
    if (NUMERIC_SCORE.test(written))
        return readHeroParts(written, undefined);
    const separator = SEGMENT_SEPARATOR.exec(written);
    if (separator && NUMERIC_SCORE.test(written.slice(0, separator.index))) {
        return readHeroParts(written.slice(0, separator.index), written.slice(separator.index + separator[0].length));
    }
    return { presentation: "text", lead: formattedValue };
};
/** The presentation alone of a raw value in its format. */
export const readKpiValuePresentation = (value, format = "number") => readKpiValueParts(value, typeof value === "string" ? value : String(value ?? ""), format).presentation;
