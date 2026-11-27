import { findBalancedJsonObjectEnd } from "./balanced-json-object";
import { findFencedBlockRanges } from "./repair-malformed-widget-fences";
import { resolveWidgetName } from "./widget-name-resolver";
/**
 * Scan line-starting balanced `{ … }` JSON objects (string-aware brace walk), returning each with its span. Mirrors
 * detect-unrendered-widgets' `extractNakedJsonCandidates`, but keeps offsets so the object can be replaced in place.
 */
const scanLineStartingJsonSpans = (text) => {
    const spans = [];
    const lines = text.split("\n");
    let offset = 0;
    for (const line of lines) {
        const lineStart = offset;
        offset += line.length + 1;
        if (!line.trimStart().startsWith("{"))
            continue;
        const openIndex = lineStart + line.indexOf("{");
        const jsonObjectEnd = findBalancedJsonObjectEnd(text, openIndex);
        if (jsonObjectEnd !== null) {
            spans.push({ json: text.slice(openIndex, jsonObjectEnd + 1), start: openIndex, end: jsonObjectEnd + 1 });
        }
    }
    return spans;
};
/** The string `type` of a JSON object, or null when it is not an object with a non-empty string `type`. */
const widgetTypeOf = (jsonCandidate) => {
    let parsed;
    try {
        parsed = JSON.parse(jsonCandidate);
    }
    catch {
        return null;
    }
    if (typeof parsed !== "object" || parsed === null)
        return null;
    const type = parsed.type;
    return typeof type === "string" && type !== "" ? type : null;
};
export const referenceNakedWidgetJson = (text) => {
    if (!text.includes('"type"'))
        return text;
    const fencedRanges = findFencedBlockRanges(text);
    const isInsideFence = (position) => fencedRanges.some(([start, end]) => position >= start && position < end);
    // Right-to-left so each replacement leaves the earlier spans' offsets valid.
    const spans = scanLineStartingJsonSpans(text)
        .filter((span) => !isInsideFence(span.start))
        .reverse();
    let result = text;
    for (const span of spans) {
        const type = widgetTypeOf(span.json);
        if (type === null || resolveWidgetName(type) === undefined)
            continue; // unknown → leave for the corrective signal
        const fencedBlock = "```widget\n" + span.json + "\n```";
        result = result.slice(0, span.start) + fencedBlock + result.slice(span.end);
    }
    return result;
};
