// @vitest-environment jsdom
import { configureStore } from "@reduxjs/toolkit";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { Provider } from "react-redux";
import { afterEach, describe, expect, test, vi } from "vitest";
import { AgentsProvider } from "../provider/AgentsProvider";
import { ConversationWidgetContext, defaultConversationWidgetCallbacks, } from "../renderer/ConversationWidgetCallbacksContext";
import { WidgetHydrationReducer } from "../renderer/widget-hydration";
import { ComposedViewConversationWidget } from "./ComposedViewConversationWidget";
import { resolvedOpenEntityAction } from "./components/entity/render-entity-tree.spec-helper";
/**
 * `AgentsEnvironment.onOpenEntity`: an `openEntity` action opens the host's own route when the host takes it, and the
 * `entityDetail` canvas when the host leaves it (or has no hook).
 */
const renderEntityLinkWidget = (environment) => {
    const openCanvas = vi.fn();
    const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
    render(<Provider store={store}>
      <AgentsProvider environment={environment}>
        <ConversationWidgetContext.Provider value={{ ...defaultConversationWidgetCallbacks, openCanvas }}>
          <ComposedViewConversationWidget interactive messageId={11} payload={JSON.stringify({
            actions: { openRoma: resolvedOpenEntityAction("call_7:competitor:0", "competitor", "4711") },
            view: { type: "EntityLink", text: "Pizzeria Roma", action: "openRoma" },
        })}/>
        </ConversationWidgetContext.Provider>
      </AgentsProvider>
    </Provider>);
    return openCanvas;
};
describe("openEntity on a host route", () => {
    afterEach(cleanup);
    test("the host's onOpenEntity takes the entity: no canvas opens", () => {
        const onOpenEntity = vi.fn(() => true);
        const openCanvas = renderEntityLinkWidget({ onOpenEntity });
        fireEvent.click(screen.getByRole("button", { name: "Pizzeria Roma" }));
        expect(onOpenEntity).toHaveBeenCalledWith(expect.objectContaining({ entityType: "competitor", entityId: "4711" }));
        expect(openCanvas).not.toHaveBeenCalled();
    });
    test("a host that leaves it (or has no hook) gets the entityDetail canvas", () => {
        const openCanvas = renderEntityLinkWidget({ onOpenEntity: () => false });
        fireEvent.click(screen.getByRole("button", { name: "Pizzeria Roma" }));
        expect(openCanvas).toHaveBeenCalledWith(expect.objectContaining({ canvasId: "entityDetail", instanceId: "entityDetail:competitor:4711" }));
    });
});
