import { findBalancedJsonObjectEnd } from "./balanced-json-object";
import { findFencedBlockRanges } from "./repair-malformed-widget-fences";
import { resolveWidgetName } from "./widget-name-resolver";

/**
 * Weak models (Gemini-3.5-Flash) intermittently emit a widget as a NAKED JSON line —
 * `{ "type": "ExampleWidget" }` — without the ```widget fence the skills instruct. Such a line is not rendered
 * by remarkWidgetFencePlugin (no fence) and not by the bare-line path (no `widget` prefix), so the customer sees raw
 * JSON. `detectUnrenderedWidgets` only DETECTS this (to corrective-signal the model); here we RENDER it: wrap a naked
 * line-starting JSON object whose `type` resolves to a REGISTERED widget in a ```widget fence, so the plugin renders
 * it — no model round-trip, the customer never sees JSON. UNKNOWN types are left untouched (the corrective-signal
 * path still handles `type: "example"` → suggest `ExampleWidget`).
 *
 * Fence-aware: JSON already inside any REAL fenced block (``` or ~~~, CommonMark rules — see
 * `findFencedBlockRanges`) is skipped (never double-fenced). Uses the SAME resolveWidgetName as the render plugin +
 * the detector, so "would render" is decided in one place.
 */

/** A line-starting balanced { … } JSON object and its [start, end) span in the source text. */
interface JsonSpan {
  json: string;
  start: number;
  end: number;
}

/**
 * Scan line-starting balanced `{ … }` JSON objects (string-aware brace walk), returning each with its span. Mirrors
 * detect-unrendered-widgets' `extractNakedJsonCandidates`, but keeps offsets so the object can be replaced in place.
 */
const scanLineStartingJsonSpans = (text: string): JsonSpan[] => {
  const spans: JsonSpan[] = [];
  const lines = text.split("\n");
  let offset = 0;
  for (const line of lines) {
    const lineStart = offset;
    offset += line.length + 1;
    if (!line.trimStart().startsWith("{")) continue;
    const openIndex = lineStart + line.indexOf("{");
    const jsonObjectEnd = findBalancedJsonObjectEnd(text, openIndex);
    if (jsonObjectEnd !== null) {
      spans.push({ json: text.slice(openIndex, jsonObjectEnd + 1), start: openIndex, end: jsonObjectEnd + 1 });
    }
  }
  return spans;
};

/** The string `type` of a JSON object, or null when it is not an object with a non-empty string `type`. */
const widgetTypeOf = (jsonCandidate: string): string | null => {
  let parsed: unknown;
  try {
    parsed = JSON.parse(jsonCandidate);
  } catch {
    return null;
  }
  if (typeof parsed !== "object" || parsed === null) return null;
  const type = (parsed as Record<string, unknown>).type;
  return typeof type === "string" && type !== "" ? type : null;
};

export const referenceNakedWidgetJson = (text: string): string => {
  if (!text.includes('"type"')) return text;

  const fencedRanges = findFencedBlockRanges(text);
  const isInsideFence = (position: number) => fencedRanges.some(([start, end]) => position >= start && position < end);

  // Right-to-left so each replacement leaves the earlier spans' offsets valid.
  const spans = scanLineStartingJsonSpans(text)
    .filter((span) => !isInsideFence(span.start))
    .reverse();

  let result = text;
  for (const span of spans) {
    const type = widgetTypeOf(span.json);
    if (type === null || resolveWidgetName(type) === undefined) continue; // unknown → leave for the corrective signal
    const fencedBlock = "```widget\n" + span.json + "\n```";
    result = result.slice(0, span.start) + fencedBlock + result.slice(span.end);
  }
  return result;
};
