export interface SectionHydrationResult<T> {
  data: T | null;
  isLoading: boolean;
  error?: string | null;
}

export type SectionHydrator<T> = () => SectionHydrationResult<T>;

/**
 * Prefer payload-provided value; fall back to a hydrator result; undefined otherwise.
 * Callers should call the fallback hook unconditionally (React hook rules) and pass
 * its result here.
 */
export const pickSection = <T>(payloadValue: T | undefined, fallback: SectionHydrationResult<T>): T | undefined => {
  return payloadValue ?? fallback.data ?? undefined;
};

/**
 * True when payload is missing the value AND the fallback is still loading.
 */
export const isSectionLoading = <T>(payloadValue: T | undefined, fallback: SectionHydrationResult<T>): boolean => {
  return payloadValue === undefined && fallback.isLoading;
};

/**
 * Field-level merge of two section values. Payload fields (when defined) win
 * over hydrated fields; undefined payload fields are filled from the hydrated
 * value. Use when the agent sends partial section data (e.g. only a CTA) and
 * the client hydrates the remaining fields.
 */
export const mergeSectionValues = <T extends object>(
  payloadValue: T | undefined,
  hydratedValue: T | undefined,
): T | undefined => {
  if (!hydratedValue) {
    return payloadValue;
  }
  if (!payloadValue) {
    return hydratedValue;
  }

  const defined: Partial<T> = {};
  for (const key of Object.keys(payloadValue) as Array<keyof T>) {
    const v = payloadValue[key];
    if (v !== undefined) {
      defined[key] = v;
    }
  }
  return { ...hydratedValue, ...defined } as T;
};

/**
 * Field-level merge for object sections. Payload fields (when defined) win over
 * fallback fields. Undefined payload fields are filled from the fallback. Use
 * this when the agent sends partial section data (e.g. only a CTA) and the
 * client needs to hydrate the remaining fields from app state.
 */
export const mergeSection = <T extends object>(
  payloadValue: T | undefined,
  fallback: SectionHydrationResult<T>,
): T | undefined => {
  return mergeSectionValues(payloadValue, fallback.data ?? undefined);
};

/**
 * True when the payload provides every field in `requiredKeys` — the section
 * can render straight from the agent payload without waiting for hydration.
 */
export const sectionCoversKeys = <T extends object>(
  payloadValue: T | undefined,
  requiredKeys: ReadonlyArray<keyof T>,
): boolean => {
  if (!payloadValue) {
    return false;
  }
  return requiredKeys.every((k) => payloadValue[k] !== undefined);
};

/**
 * True when the fallback is still loading AND the payload has not provided
 * every expected field (listed in `requiredKeys`). Use together with
 * `mergeSection` to decide whether to show a skeleton for the section.
 */
export const isSectionIncomplete = <T extends object>(
  payloadValue: T | undefined,
  fallback: SectionHydrationResult<T>,
  requiredKeys: ReadonlyArray<keyof T>,
): boolean => {
  if (!fallback.isLoading) {
    return false;
  }
  if (!payloadValue) {
    return true;
  }
  return requiredKeys.some((k) => {
    return (payloadValue as T)[k] === undefined;
  });
};
