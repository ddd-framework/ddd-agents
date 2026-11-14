/**
 * The `QuickReplies` fence payload (a backend hard rule): a closed question answered by SHORT
 * pills, each a complete answer the owner could also type. Validated strictly — an invalid payload renders nothing
 * (the widget's own kill switch, same as an unresolved wire name).
 */
export interface QuickRepliesPayload {
  options: string[];
}

export const QUICK_REPLIES_MIN_OPTIONS = 2;
export const QUICK_REPLIES_MAX_OPTIONS = 5;

/** Longer options are cut with an ellipsis for DISPLAY only — the click still sends the option in full. */
export const QUICK_REPLIES_MAX_DISPLAY_LENGTH = 60;

/**
 * `options` must be an array of 2 to 5 strings, each non-empty after trimming and unique (case-insensitive) — a
 * malformed shape or a violated bound drops the whole widget. Options are NOT silently deduplicated: a payload the
 * agent got wrong (two options that only differ by case) is invalid, not quietly repaired.
 */
export const parseQuickRepliesPayload = (raw: string): QuickRepliesPayload | null => {
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }
  if (!parsed || typeof parsed !== "object" || !Array.isArray((parsed as { options?: unknown }).options)) {
    return null;
  }

  const rawOptions = (parsed as { options: unknown[] }).options;
  if (!rawOptions.every((option) => typeof option === "string")) return null;

  const trimmedOptions = (rawOptions as string[]).map((option) => option.trim());
  if (trimmedOptions.some((option) => option.length === 0)) return null;
  if (trimmedOptions.length < QUICK_REPLIES_MIN_OPTIONS || trimmedOptions.length > QUICK_REPLIES_MAX_OPTIONS) {
    return null;
  }

  const seenCaseInsensitive = new Set<string>();
  for (const option of trimmedOptions) {
    const key = option.toLowerCase();
    if (seenCaseInsensitive.has(key)) return null;
    seenCaseInsensitive.add(key);
  }

  return { options: trimmedOptions };
};

/** The rendered pill label — never what is sent (the click always sends the full, untruncated option text). */
export const truncateQuickReplyLabel = (option: string): string =>
  option.length > QUICK_REPLIES_MAX_DISPLAY_LENGTH
    ? `${option.slice(0, QUICK_REPLIES_MAX_DISPLAY_LENGTH - 1)}…`
    : option;
