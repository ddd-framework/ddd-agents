/**
 * The ONE tone list of the composable kit (the backend twin is `ComposedViewTone.php`):
 * the judgement or role a text, a badge, a value, an icon or a map status line takes. Every node with a `tone` prop
 * reads it through {@link readComposedViewTone}, so a model that learned one word uses it everywhere. The words some
 * nodes accepted before the list existed stay accepted on the wire (both tree readers list them as allowed values) and
 * read as their tone here: `default` (Text) is `neutral`, `positive` / `negative` (Map status lines) are `success` /
 * `danger`.
 */
export const COMPOSED_VIEW_TONES = ["neutral", "muted", "success", "warning", "danger", "info"] as const;

export type ComposedViewTone = (typeof COMPOSED_VIEW_TONES)[number];

/** The older words, accepted on the wire and read as their tone. */
export const COMPOSED_VIEW_TONE_ALIASES: Readonly<Record<string, ComposedViewTone>> = {
  default: "neutral",
  positive: "success",
  negative: "danger",
};

const isComposedViewTone = (value: string): value is ComposedViewTone =>
  (COMPOSED_VIEW_TONES as readonly string[]).includes(value);

/** The tone a prop names — one of the list or an alias — else `fallback` (a bound value may hold anything). */
export const readComposedViewTone = (value: unknown, fallback: ComposedViewTone = "neutral"): ComposedViewTone => {
  if (typeof value !== "string") return fallback;
  if (isComposedViewTone(value)) return value;
  return Object.prototype.hasOwnProperty.call(COMPOSED_VIEW_TONE_ALIASES, value)
    ? COMPOSED_VIEW_TONE_ALIASES[value]
    : fallback;
};

/** The tone a prop names, or `undefined` when it names none (a status line without a tone stays plain). */
export const readOptionalComposedViewTone = (value: unknown): ComposedViewTone | undefined =>
  typeof value === "string" && (isComposedViewTone(value) || value in COMPOSED_VIEW_TONE_ALIASES)
    ? readComposedViewTone(value)
    : undefined;

/** The text colour of a tone, for the component library's `Text`'s `color` prop — judgement text in the 900 step (500 on dark). */
export const COMPOSED_VIEW_TONE_TEXT_COLOR: Readonly<Record<ComposedViewTone, string>> = {
  neutral: "var(--agents-fg)",
  muted: "var(--agents-fg-light)",
  success: "light-dark(var(--agents-success-900), var(--agents-success-500))",
  warning: "light-dark(var(--agents-warning-900), var(--agents-warning-500))",
  danger: "light-dark(var(--agents-danger-900), var(--agents-danger-500))",
  info: "light-dark(var(--agents-info-900), var(--agents-info-500))",
};

/** The graphic colour of a tone — an icon fill in the 500 step. */
export const COMPOSED_VIEW_TONE_GRAPHIC_COLOR: Readonly<Record<ComposedViewTone, string>> = {
  neutral: "var(--agents-fg)",
  muted: "var(--agents-fg-light)",
  success: "var(--agents-success-500)",
  warning: "var(--agents-warning-500)",
  danger: "var(--agents-danger-500)",
  info: "var(--agents-info-500)",
};
