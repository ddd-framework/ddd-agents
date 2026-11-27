import { describe, expect, it } from "vitest";
import { WidgetHydrationReducer } from "./widget-hydration.reducer";
describe("the widget hydration reducer in a redux 5 host store", () => {
    it("is a reducer over redux 5's unknown action, and the package's actions are such actions", () => {
        const reducerOverUnknownAction = WidgetHydrationReducer;
        const packageAction = { type: "agents/unrelated" };
        const unknownAction = packageAction;
        const initialState = reducerOverUnknownAction(undefined, { type: "@@INIT" });
        expect(reducerOverUnknownAction(initialState, unknownAction)).toBe(initialState);
    });
});
