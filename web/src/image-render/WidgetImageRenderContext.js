import { createContext, useContext } from "react";
/** Null in the regular app. Supplied results apply only in explicit frozen-test mode. */
export const WidgetImageRenderContext = createContext(null);
export const useWidgetImageRender = () => useContext(WidgetImageRenderContext);
export const WidgetImageLiveHydrationContext = createContext(false);
export const useWidgetImageLiveHydration = () => useContext(WidgetImageLiveHydrationContext);
export const WidgetImageReadinessContext = createContext(null);
export const readFrozenHydration = (hydration, type, key) => {
    if (Object.prototype.hasOwnProperty.call(hydration, key))
        return hydration[key];
    if (Object.prototype.hasOwnProperty.call(hydration, type))
        return hydration[type];
    throw new Error("MISSING_FROZEN_HYDRATION");
};
