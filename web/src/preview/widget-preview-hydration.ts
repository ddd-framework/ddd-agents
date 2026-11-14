import {
  getWidgetHydrator,
  listWidgetHydratorTypes,
  registerWidgetHydrator,
  unregisterWidgetHydrator,
} from "../renderer/widget-hydration/widget-hydration.registry";
import type { RegisteredWidget } from "../renderer/widget-hydration/widget-hydration.types";
import { isPendingResponse, isStatusResponse } from "./widget-preview-requests";
import type { WidgetPreviewResponse } from "./widget-preview.types";

/** The fixture's entry for one hydration: the exact derived key first, then the hydrator type (as the capture reads). */
const findFixtureHydration = (
  hydration: Record<string, WidgetPreviewResponse>,
  type: string,
  key: string,
): { found: boolean; value?: WidgetPreviewResponse; hydrationKey: string } => {
  if (Object.prototype.hasOwnProperty.call(hydration, key))
    return { found: true, value: hydration[key], hydrationKey: key };
  if (Object.prototype.hasOwnProperty.call(hydration, type))
    return { found: true, value: hydration[type], hydrationKey: type };
  return { found: false, hydrationKey: key };
};

/** One fixture value as a hydrator result: a copy of the body, a failing `__status`, or a `__pending` that never ends. */
const answerFromFixture = (
  value: WidgetPreviewResponse,
  hydrationKey: string,
  onFailedRequest: (requestKey: string) => void,
): Promise<unknown> => {
  if (isPendingResponse(value)) return new Promise(() => {});
  if (isStatusResponse(value)) {
    onFailedRequest(`HYDRATION ${hydrationKey}`);
    return Promise.reject(new Error(`preview hydration ${value.__status}`));
  }
  return Promise.resolve(structuredClone(value));
};

/**
 * The preview's hydration transport for the `interactive` and `replay` modes: every registered hydrator is replaced by
 * one that answers from the fixture's `hydration` (by derived key, else by type) and otherwise runs the real hydrator,
 * whose requests the request transport answers from `responses`. The widget therefore runs its live code path —
 * `useWidgetHydration` with the store's hydration slice, no frozen capture context — so a self-hydrating canvas widget
 * auto-opens its canvas like in the chat (`CanvasWidgetCard`). The `email` mode keeps the capture's frozen hydration.
 * Returns the disposer that puts the real hydrators back.
 */
export const installWidgetPreviewHydration = (
  hydration: Record<string, WidgetPreviewResponse>,
  onFailedRequest: (requestKey: string) => void,
): (() => void) => {
  const replacedHydrators = new Map<string, RegisteredWidget<unknown, unknown>>();
  for (const type of listWidgetHydratorTypes()) {
    const registered = getWidgetHydrator<unknown, unknown>(type);
    if (!registered) continue;
    replacedHydrators.set(type, registered);
    unregisterWidgetHydrator(type);
    registerWidgetHydrator<unknown, unknown>(type, {
      ...registered,
      hydrator: (payload, context) => {
        const fixtureHydration = findFixtureHydration(hydration, type, registered.deriveKey(type, payload));
        return fixtureHydration.found
          ? answerFromFixture(fixtureHydration.value, fixtureHydration.hydrationKey, onFailedRequest)
          : registered.hydrator(payload, context);
      },
    });
  }
  return () => {
    for (const [type, registered] of replacedHydrators) {
      unregisterWidgetHydrator(type);
      registerWidgetHydrator(type, registered);
    }
  };
};
