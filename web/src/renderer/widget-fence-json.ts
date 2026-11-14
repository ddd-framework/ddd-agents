import { exceedsJsonContainerDepth } from "./widget-tree/bounded-json";

/**
 * The JSON of a widget block as every frontend reader decodes it — the twin of the backend's
 * `WidgetBlocks::buildWidgetJsonWithoutSurplusClosers()`: a block whose JSON does not decode is read
 * once more without the surplus closing brackets a model wrote at its end (one `}` too many after a correct tree), up to
 * {@link MAX_SURPLUS_CLOSERS} trailing `}` / `]` dropped one by one. The parity fixture `composed-view-parity.json`
 * (`surplusCloserCases`) holds both readers equal.
 */

/** The most trailing `}` / `]` a block read drops to decode a block (the backend's `WidgetBlocks::MAX_SURPLUS_CLOSERS`). */
export const MAX_SURPLUS_CLOSERS = 3;

/** The decoded value of `jsonText` when it is a JSON object no deeper than the backend decodes; undefined otherwise. */
const decodeJsonObject = (jsonText: string): Record<string, unknown> | undefined => {
  let decoded: unknown;
  try {
    decoded = JSON.parse(jsonText);
  } catch {
    return undefined;
  }
  if (typeof decoded !== "object" || decoded === null || Array.isArray(decoded) || exceedsJsonContainerDepth(decoded)) {
    return undefined;
  }
  return decoded as Record<string, unknown>;
};

/**
 * The block JSON without its surplus trailing closers, or null when it decodes as written or when no shortened text
 * decodes to a JSON object — exactly the backend's answer.
 */
export const readWidgetJsonWithoutSurplusClosers = (widgetJson: string): string | null => {
  if (decodeJsonObject(widgetJson) !== undefined) return null;
  let candidateJson = widgetJson.trimEnd();
  for (let droppedCloserCount = 0; droppedCloserCount < MAX_SURPLUS_CLOSERS; droppedCloserCount++) {
    const lastCharacter = candidateJson.slice(-1);
    if (lastCharacter !== "}" && lastCharacter !== "]") return null;
    candidateJson = candidateJson.slice(0, -1).trimEnd();
    if (decodeJsonObject(candidateJson) !== undefined) return candidateJson;
  }
  return null;
};

/** The block's JSON object — as written, or without its surplus trailing closers; undefined for a malformed block. */
export const parseWidgetJsonObject = (widgetJson: string): Record<string, unknown> | undefined =>
  decodeJsonObject(widgetJson) ?? decodeJsonObject(readWidgetJsonWithoutSurplusClosers(widgetJson) ?? "");
