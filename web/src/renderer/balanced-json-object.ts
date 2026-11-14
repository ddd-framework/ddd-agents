/**
 * The one string-aware brace walk every "how far does this widget JSON payload extend" question in the pipeline
 * needs — the malformed-fence repairer, the naked-JSON referencer and the unrendered-widget detector all call this
 * single copy of the scan.
 */

/**
 * The index of the `}` closing the first `{ … }` JSON object that begins (after optional whitespace) at or after
 * `fromIndex` in `text`, or null when no object starts there or it never balances within `maxScanLength` characters
 * of its start. Quotes and escapes are tracked so a `{`/`}` inside a JSON string value never affects the brace
 * depth (e.g. a widget's `title` field containing literal `{braces}` text).
 */
export const findBalancedJsonObjectEnd = (text: string, fromIndex: number, maxScanLength = 5000): number | null => {
  let objectStart = fromIndex;
  while (objectStart < text.length && /\s/.test(text[objectStart])) objectStart++;
  if (objectStart >= text.length || text[objectStart] !== "{") return null;

  let depth = 0;
  let inString = false;
  let escaped = false;
  const scanEnd = Math.min(text.length, objectStart + maxScanLength);
  for (let index = objectStart; index < scanEnd; index++) {
    const char = text[index];
    if (escaped) {
      escaped = false;
      continue;
    }
    if (char === "\\") {
      escaped = true;
      continue;
    }
    if (char === '"') inString = !inString;
    if (inString) continue;
    if (char === "{") depth++;
    if (char === "}") {
      depth--;
      if (depth === 0) return index;
    }
  }
  return null;
};
