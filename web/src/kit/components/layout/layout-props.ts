import type { ExpressionValue } from "../../../renderer/widget-tree/expression/evaluate-expression";

/**
 * Reads the resolved props of the layout nodes. A prop arrives as a JSON value after its binding was
 * resolved — or `undefined` when it was dropped or is still pending — so every reader falls back to the node's
 * default instead of trusting the type. The closed vocabularies mirror `ComposedViewLayoutSpacing.php`.
 */

export type LayoutGap = "none" | "xsmall" | "small" | "medium" | "large";

const LAYOUT_GAPS: readonly LayoutGap[] = ["none", "xsmall", "small", "medium", "large"];

/** The value when it is one of `allowedValues`, else `fallback`. */
export const readEnumProp = <AllowedValue extends string | number>(
  value: ExpressionValue | undefined,
  allowedValues: readonly AllowedValue[],
  fallback: AllowedValue,
): AllowedValue => (allowedValues.includes(value as AllowedValue) ? (value as AllowedValue) : fallback);

export const readGapProp = (value: ExpressionValue | undefined, fallback: LayoutGap = "medium"): LayoutGap =>
  readEnumProp(value, LAYOUT_GAPS, fallback);

/** A non-empty text (a number is shown as written), else `undefined`. */
export const readTextProp = (value: ExpressionValue | undefined): string | undefined => {
  if (typeof value === "string") return value.trim() === "" ? undefined : value;
  if (typeof value === "number" && Number.isFinite(value)) return String(value);
  return undefined;
};

export const readBooleanProp = (value: ExpressionValue | undefined, fallback: boolean): boolean =>
  typeof value === "boolean" ? value : fallback;

export interface LayoutSourceLink {
  name?: string;
  url?: string;
}

/** A `{"name","url"}` source; the url only when it is http(s). */
export const readSourceLinkProp = (value: ExpressionValue | undefined): LayoutSourceLink | undefined => {
  if (typeof value !== "object" || value === null || Array.isArray(value)) return undefined;
  const sourceObject = value as Record<string, ExpressionValue>;
  const name = readTextProp(sourceObject.name);
  const rawUrl = readTextProp(sourceObject.url);
  const url = rawUrl !== undefined && /^https?:\/\//i.test(rawUrl) ? rawUrl : undefined;
  if (name === undefined && url === undefined) return undefined;
  return { name, url };
};
