import { useAgentsStateSelector } from "../../provider/AgentsProvider";
import type { WidgetHydrationSliceState, WidgetMemory } from "./widget-hydration.types";

// The reducer and its action creators live in `widget-hydration.reducer.ts` (no provider import, so the package's
// default state store can build on them); this module adds the hooks that read the state through the provider.
export { WidgetHydrationReducer, WidgetHydrationSlice } from "./widget-hydration.reducer";

/** A store state that carries the widget hydration state. */
interface StateWithWidgetHydration {
  AIExtensionsWidgetHydration?: WidgetHydrationSliceState;
}

export const selectWidgetMemory = (key: string) => {
  return (state: StateWithWidgetHydration): WidgetMemory | undefined => {
    return state.AIExtensionsWidgetHydration?.widgets[key];
  };
};

export const useWidgetMemory = (key: string) => {
  return useAgentsStateSelector((state: StateWithWidgetHydration) => {
    return state.AIExtensionsWidgetHydration?.widgets[key];
  });
};
