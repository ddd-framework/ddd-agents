import { useAgentsStateSelector } from "../../provider/AgentsProvider";
// The reducer and its action creators live in `widget-hydration.reducer.ts` (no provider import, so the package's
// default state store can build on them); this module adds the hooks that read the state through the provider.
export { WidgetHydrationReducer, WidgetHydrationSlice } from "./widget-hydration.reducer";
export const selectWidgetMemory = (key) => {
    return (state) => {
        return state.AIExtensionsWidgetHydration?.widgets[key];
    };
};
export const useWidgetMemory = (key) => {
    return useAgentsStateSelector((state) => {
        return state.AIExtensionsWidgetHydration?.widgets[key];
    });
};
