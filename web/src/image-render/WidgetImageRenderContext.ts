import { createContext, useContext } from "react";
import type { createWidgetImageReadiness } from "./widget-image-readiness";

/** Null in the regular app. Supplied results apply only in explicit frozen-test mode. */
export const WidgetImageRenderContext = createContext<Record<string, unknown> | null>(null);
export const useWidgetImageRender = () => useContext(WidgetImageRenderContext);
export const WidgetImageLiveHydrationContext = createContext(false);
export const useWidgetImageLiveHydration = () => useContext(WidgetImageLiveHydrationContext);
export const WidgetImageReadinessContext = createContext<ReturnType<typeof createWidgetImageReadiness> | null>(null);

export const readFrozenHydration = (hydration: Record<string, unknown>, type: string, key: string): unknown => {
  if (Object.prototype.hasOwnProperty.call(hydration, key)) return hydration[key];
  if (Object.prototype.hasOwnProperty.call(hydration, type)) return hydration[type];
  throw new Error("MISSING_FROZEN_HYDRATION");
};
