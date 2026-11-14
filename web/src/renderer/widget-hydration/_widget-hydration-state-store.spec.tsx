// @vitest-environment jsdom
import { act, cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, test } from "vitest";
import { useAgentsStateSelector } from "../../provider/AgentsProvider";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { classNames } from "../../provider/class-names";
import { WidgetHydrationSlice } from "./widget-hydration.slice";
import { createAgentsWidgetHydrationStateStore, getDefaultAgentsStateStore } from "./widget-hydration-state-store";

/** The package's own widget hydration store: the default when the app supplies no state store. */

const StatusOfKey = ({ hydrationKey }: { hydrationKey: string }) => {
  const status = useAgentsStateSelector(
    (state: { AIExtensionsWidgetHydration?: { widgets: Record<string, { status: string }> } }) =>
      state.AIExtensionsWidgetHydration?.widgets[hydrationKey]?.status ?? "none",
  );
  return <span data-testid="status">{status}</span>;
};

describe("the package's widget hydration store", () => {
  let appEnvironment: AgentsEnvironment;

  beforeEach(() => {
    appEnvironment = getAgentsEnvironment();
    setAgentsEnvironment({});
  });

  afterEach(() => {
    // Unmount first: the app's environment would re-render the chat with hooks that need the app's own store.
    cleanup();
    setAgentsEnvironment(appEnvironment);
  });

  test("is what package code reads and dispatches through when the app supplies no state store", () => {
    render(<StatusOfKey hydrationKey="spec-key" />);
    expect(screen.getByTestId("status").textContent).toBe("none");

    act(() => {
      getDefaultAgentsStateStore().useDispatch()(
        WidgetHydrationSlice.hydrationStarted({ key: "spec-key", instanceId: "spec", requestId: 1 }),
      );
    });
    expect(screen.getByTestId("status").textContent).toBe("loading");
  });

  test("keeps its state when an action is not a hydration action", () => {
    const stateStore = createAgentsWidgetHydrationStateStore();
    const dispatch = stateStore.useDispatch();
    expect(dispatch({ type: "app/chatSurfaceOpenChanged", payload: true })).toEqual({
      type: "app/chatSurfaceOpenChanged",
      payload: true,
    });
  });
});

describe("classNames", () => {
  test("joins set strings and the keys of an object whose values are truthy, in order", () => {
    expect(classNames("card", undefined, { selected: true, disabled: false }, "", null, "wide")).toBe("card selected wide");
  });
});
