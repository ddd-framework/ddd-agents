import type { ExpressionValue } from "../../../../renderer/widget-tree/expression/evaluate-expression";
import { getDatePatternFromLocale } from "./date-pattern-of-locale";
import type { ComposedViewRuntime } from "../../../composed-view-component-types";

/**
 * The value readers the kit's input components share. Every reader is total: a value the model wrote
 * in another shape gives the documented default, never an exception — the readers on both sides already dropped what
 * the schema refuses, these readers only narrow what is left.
 */

/** One choice of a `Tabs`, `Select` or `RadioGroup`: the id written to the state and the label the owner reads. */
export interface ComposedInputOption {
  id: string | number;
  label: string;
  description?: string;
  disabled: boolean;
}

/** Choices beyond this count are not shown (a drop-down with more is a list, not an input). */
export const MAX_INPUT_OPTIONS = 100;

const readOptionId = (optionObject: Record<string, unknown>): string | number | undefined => {
  // `id` is the documented key; `value` / `key` are what a model transcribing ChatGPT's `<Option value=…>` writes.
  for (const idKey of ["id", "value", "key"]) {
    const candidate = optionObject[idKey];
    if (typeof candidate === "string" && candidate !== "") return candidate;
    if (typeof candidate === "number" && Number.isFinite(candidate)) return candidate;
  }
  return undefined;
};

const readOptionLabel = (optionObject: Record<string, unknown>): string | undefined => {
  for (const labelKey of ["label", "title", "name", "text"]) {
    const candidate = optionObject[labelKey];
    if (typeof candidate === "string" && candidate.trim() !== "") return candidate;
    if (typeof candidate === "number" && Number.isFinite(candidate)) return String(candidate);
  }
  return undefined;
};

const readOption = (optionValue: unknown): ComposedInputOption | undefined => {
  if (typeof optionValue === "string") {
    return optionValue.trim() === "" ? undefined : { id: optionValue, label: optionValue, disabled: false };
  }
  if (typeof optionValue === "number") {
    return Number.isFinite(optionValue) ? { id: optionValue, label: String(optionValue), disabled: false } : undefined;
  }
  if (typeof optionValue !== "object" || optionValue === null || Array.isArray(optionValue)) return undefined;
  const optionObject = optionValue as Record<string, unknown>;
  const label = readOptionLabel(optionObject);
  const id = readOptionId(optionObject) ?? label;
  if (id === undefined || label === undefined) return undefined;
  const description = optionObject.description;
  return {
    id,
    label,
    description: typeof description === "string" && description.trim() !== "" ? description : undefined,
    disabled: optionObject.disabled === true,
  };
};

/**
 * The choices of an `options` prop: a label list (`["Bear", "Base"]`, the label is the id) or objects with an `id` (or
 * `value` / `key`) and a `label`. An entry without a usable id and label is skipped, a repeated id keeps its first entry.
 */
export const readComposedInputOptions = (optionsValue: ExpressionValue | undefined): ComposedInputOption[] => {
  if (!Array.isArray(optionsValue)) return [];
  const options: ComposedInputOption[] = [];
  const seenIds = new Set<string>();
  for (const optionValue of optionsValue) {
    if (options.length >= MAX_INPUT_OPTIONS) break;
    const option = readOption(optionValue);
    if (!option || seenIds.has(String(option.id))) continue;
    seenIds.add(String(option.id));
    options.push(option);
  }
  return options;
};

/** The option whose id equals `value` (a number state matches a numeric-string id and the reverse). */
export const findComposedInputOption = (
  options: ComposedInputOption[],
  value: ExpressionValue | undefined,
): ComposedInputOption | undefined =>
  value === undefined || value === null ? undefined : options.find((option) => String(option.id) === String(value));

/** A text prop, or `undefined` when it is not a non-empty string (a number is shown as written). */
export const readComposedText = (value: ExpressionValue | undefined): string | undefined => {
  if (typeof value === "string") return value.trim() === "" ? undefined : value;
  if (typeof value === "number" && Number.isFinite(value)) return String(value);
  return undefined;
};

/** A finite number prop, or `fallback`. */
export const readComposedNumber = (value: ExpressionValue | undefined, fallback: number): number =>
  typeof value === "number" && Number.isFinite(value) ? value : fallback;

/** Whether the owner may use the control: a live (interactive) message, never a replay or the e-mail capture. */
export const isComposedInputUsable = (runtime: ComposedViewRuntime): boolean =>
  runtime.interactive && !runtime.isEmailRender;

/** The decimals a number is written with (`0.1` → 1, `5` → 0, `0.25` → 2), capped at 6. */
export const countDecimals = (value: number): number => {
  if (!Number.isFinite(value)) return 0;
  const [, fraction = ""] = String(value).split(".");
  return Math.min(fraction.length, 6);
};

/** A number with `decimals` places in the owner's language, `unit` after it: `3` + "%" + 1 → "3.0 %". */
export const formatComposedNumber = (
  value: number,
  decimals: number,
  unit: string | undefined,
  languageCode: string,
): string => {
  const fractionDigits = Math.max(0, Math.min(6, Math.round(decimals)));
  const formatOptions: Intl.NumberFormatOptions = {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  };
  let formattedNumber: string;
  try {
    formattedNumber = new Intl.NumberFormat(languageCode || "en", formatOptions).format(value);
  } catch {
    formattedNumber = new Intl.NumberFormat("en", formatOptions).format(value);
  }
  if (!unit) return formattedNumber;
  // A scale written as "/100" joins the number ("51/100"); any other unit follows a no-break space, so "3.0 %" never
  // wraps between the number and its unit.
  return unit.startsWith("/") ? `${formattedNumber}${unit}` : `${formattedNumber}\u00a0${unit}`;
};

/** A value clamped into `[min, max]` and snapped onto the step grid that starts at `min`. */
export const snapToSliderStep = (value: number, min: number, max: number, step: number): number => {
  const clampedValue = Math.min(Math.max(value, min), max);
  if (!(step > 0)) return clampedValue;
  const snappedValue = min + Math.round((clampedValue - min) / step) * step;
  const decimals = Math.max(countDecimals(step), countDecimals(min));
  return Math.min(Number(snappedValue.toFixed(decimals)), max);
};

const ISO_DAY_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

/** A `YYYY-MM-DD` day as its local midnight in milliseconds, or `undefined` for anything else (`2026-02-30` too). */
export const parseIsoDay = (value: ExpressionValue | undefined): number | undefined => {
  if (typeof value !== "string") return undefined;
  const dayMatch = ISO_DAY_PATTERN.exec(value.trim());
  if (!dayMatch) return undefined;
  const year = Number(dayMatch[1]);
  const monthIndex = Number(dayMatch[2]) - 1;
  const dayOfMonth = Number(dayMatch[3]);
  const localDate = new Date(year, monthIndex, dayOfMonth);
  if (localDate.getMonth() !== monthIndex || localDate.getDate() !== dayOfMonth) return undefined;
  return localDate.getTime();
};

/**
 * The locale as the date pattern table of the component library's `DatePicker` keys it (`de-DE` → `de_DE`): the owner settings
 * write a BCP 47 tag, the picker looks the pattern up by the underscore form and falls back to `DD.MM.YYYY`.
 */
export const toDatePatternLocale = (locale: string): string => locale.replace("-", "_");

/**
 * A `YYYY-MM-DD` day as the owner reads it in `locale` ("25/10/2026" for `en-GB`, "25.10.2026" for `de-DE`) — in the
 * same pattern the editable `DatePicker` shows (`getDatePatternFromLocale`, the table the component library's picker reads), so the
 * frozen value on a replay and in the e-mail matches the field the owner edited; `undefined` for no valid day.
 */
export const formatIsoDayForReading = (isoDay: string | undefined, locale: string): string | undefined => {
  const timestamp = parseIsoDay(isoDay);
  if (timestamp === undefined) return undefined;
  const localDate = new Date(timestamp);
  const twoDigits = (datePart: number) => String(datePart).padStart(2, "0");
  return getDatePatternFromLocale(toDatePatternLocale(locale))
    .replace("YYYY", String(localDate.getFullYear()))
    .replace("MM", twoDigits(localDate.getMonth() + 1))
    .replace("DD", twoDigits(localDate.getDate()));
};

/** A timestamp in milliseconds as its local `YYYY-MM-DD` day. */
export const formatIsoDay = (timestamp: number): string => {
  const localDate = new Date(timestamp);
  const twoDigits = (datePart: number) => String(datePart).padStart(2, "0");
  return `${localDate.getFullYear()}-${twoDigits(localDate.getMonth() + 1)}-${twoDigits(localDate.getDate())}`;
};
