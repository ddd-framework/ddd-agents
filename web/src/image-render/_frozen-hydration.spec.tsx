import React from "react";
import { createRoot } from "react-dom/client";
import { act } from "react";
import { configureStore } from "@reduxjs/toolkit";
import { Provider } from "react-redux";
import { expect, it, vi } from "vitest";
import { WidgetImageRenderContext, WidgetImageLiveHydrationContext } from "./WidgetImageRenderContext";
import { useWidgetHydration } from "../renderer/widget-hydration/use-widget-hydration";
import { WidgetHydrationReducer } from "../renderer/widget-hydration/widget-hydration.slice";
import { registerWidgetHydrator } from "../renderer/widget-hydration/widget-hydration.registry";

it("renders frozen data synchronously without calling the live hydrator, even on refresh", async () => {
  const hydrator = vi.fn().mockResolvedValue("live");
  registerWidgetHydrator("capture-test", {
    deriveKey: () => "capture-test:456",
    hydrator,
    staleMs: 0,
    isPayloadEqual: () => true,
  });
  const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
  const Fixture = () => {
    const result = useWidgetHydration<string>("capture-test", {});
    return (
      <button onClick={result.refetch}>
        {result.status}:{result.data}
      </button>
    );
  };
  const node = document.createElement("div");
  const root = createRoot(node);
  try {
    await act(async () =>
      root.render(
        <Provider store={store}>
          <WidgetImageRenderContext.Provider value={{ "capture-test:456": "frozen" }}>
            <Fixture />
          </WidgetImageRenderContext.Provider>
        </Provider>,
      ),
    );
    expect(node.textContent).toBe("success:frozen");
    await act(async () => node.querySelector("button")?.click());
    expect(hydrator).not.toHaveBeenCalled();
  } finally {
    await act(async () => root.unmount());
  }
});

it.each([false, true])("keeps the agent's hydration working with live capture=%s", async (capture) => {
  const hydrator = vi.fn().mockResolvedValue("live");
  registerWidgetHydrator("ordinary-test", {
    deriveKey: () => `ordinary-test:${capture}`,
    hydrator,
    staleMs: 60000,
    isPayloadEqual: () => true,
  });
  const store = configureStore({ reducer: { AIExtensionsWidgetHydration: WidgetHydrationReducer } });
  const Fixture = () => {
    const result = useWidgetHydration<string>("ordinary-test", {});
    return (
      <div>
        {result.status}:{result.data}
      </div>
    );
  };
  const node = document.createElement("div");
  const root = createRoot(node);
  try {
    await act(async () =>
      root.render(
        <Provider store={store}>
          <WidgetImageRenderContext.Provider value={capture ? {} : null}>
            <WidgetImageLiveHydrationContext.Provider value={capture}>
              <Fixture />
            </WidgetImageLiveHydrationContext.Provider>
          </WidgetImageRenderContext.Provider>
        </Provider>,
      ),
    );
    expect(node.textContent).toBe("success:live");
    expect(hydrator).toHaveBeenCalledOnce();
  } finally {
    await act(async () => root.unmount());
  }
});
