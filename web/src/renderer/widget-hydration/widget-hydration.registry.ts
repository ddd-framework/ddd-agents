import { RegisteredWidget, WidgetHydratorOptions } from "./widget-hydration.types";

const registry = new Map<string, RegisteredWidget<any, any>>();

const defaultIsEqual = <TPayload>(prev: TPayload | undefined, next: TPayload): boolean => {
  if (prev === next) {
    return true;
  }
  try {
    return JSON.stringify(prev) === JSON.stringify(next);
  } catch {
    return false;
  }
};

export const registerWidgetHydrator = <TPayload = unknown, TData = unknown>(
  type: string,
  options: WidgetHydratorOptions<TPayload, TData>,
): void => {
  if (registry.has(type)) {
    console.warn(`[widget-hydration] Hydrator for "${type}" already registered. Overwriting.`);
  }

  registry.set(type, {
    hydrator: options.hydrator,
    deriveKey:
      options.deriveKey ??
      ((t) => {
        return t;
      }),
    isPayloadEqual: options.isPayloadEqual ?? defaultIsEqual,
    refetchOnNewInstance: options.refetchOnNewInstance ?? false,
    staleMs: options.staleMs ?? Number.POSITIVE_INFINITY,
  });
};

export const getWidgetHydrator = <TPayload = unknown, TData = unknown>(
  type: string,
): RegisteredWidget<TPayload, TData> | undefined => {
  return registry.get(type) as RegisteredWidget<TPayload, TData> | undefined;
};

export const hasWidgetHydrator = (type: string): boolean => {
  return registry.has(type);
};

/** Every registered hydrator type — the widget preview answers them from its fixture (`widget-preview-hydration.ts`). */
export const listWidgetHydratorTypes = (): string[] => [...registry.keys()];

export const unregisterWidgetHydrator = (type: string): void => {
  registry.delete(type);
};
