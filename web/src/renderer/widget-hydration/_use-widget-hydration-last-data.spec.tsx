// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { act, cleanup, renderHook, waitFor } from "@testing-library/react";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { registerWidgetHydrator } from "./widget-hydration.registry";
import { createAgentsWidgetHydrationStateStore } from "./widget-hydration-state-store";
import { useWidgetHydration } from "./use-widget-hydration";

/**
 * A second copy of a snapshot (another agent window) refetches on mount but keeps the first copy's data on screen
 * with `showLastDataWhileRefreshing`; without it, the refetch shows the loading state.
 */

const TYPE = "LastDataSpecSnapshot";

const setup = () => {
  let fetchCount = 0;
  const resolvers: ((value: { count: number }) => void)[] = [];
  const hydrator = vi.fn(
    () =>
      new Promise<{ count: number }>((resolve) => {
        fetchCount += 1;
        const count = fetchCount;
        resolvers.push(() => resolve({ count }));
      }),
  );
  registerWidgetHydrator<{ itemId: number }, { count: number }>(TYPE, { hydrator });
  // Every test starts from an empty hydration state: its own store, as the environment's state store.
  setAgentsEnvironment({ stateStore: createAgentsWidgetHydrationStateStore() });
  const resolveNext = async () => {
    await act(async () => {
      resolvers.shift()?.({ count: 0 });
    });
  };
  return { hydrator, resolveNext };
};

const payload = { itemId: 7 };

describe("useWidgetHydration — showLastDataWhileRefreshing", () => {
  let environmentBefore: AgentsEnvironment;

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
  });

  afterEach(() => {
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  test("a second, force-refreshing copy shows the loaded data at once and still refetches", async () => {
    const { hydrator, resolveNext } = setup();
    const first = renderHook(() => useWidgetHydration(TYPE, payload));
    await resolveNext();
    await waitFor(() => expect(first.result.current.data).toEqual({ count: 1 }));

    const second = renderHook(
      () => useWidgetHydration(TYPE, payload, { forceRefresh: true, showLastDataWhileRefreshing: true }),
    );
    expect(second.result.current.isLoading).toBe(false);
    expect(second.result.current.data).toEqual({ count: 1 });
    expect(first.result.current.isLoading).toBe(false);
    expect(hydrator).toHaveBeenCalledTimes(2);

    await resolveNext();
    await waitFor(() => expect(second.result.current.data).toEqual({ count: 2 }));
    expect(first.result.current.data).toEqual({ count: 2 });
  });

  test("without it, the force-refreshing copy shows the loading state until its fetch lands", async () => {
    const { resolveNext } = setup();
    const first = renderHook(() => useWidgetHydration(TYPE, payload));
    await resolveNext();
    await waitFor(() => expect(first.result.current.data).toEqual({ count: 1 }));

    const second = renderHook(() => useWidgetHydration(TYPE, payload, { forceRefresh: true }));
    expect(second.result.current.isLoading).toBe(true);
  });

  test("a key with no data yet shows the loading state even with the option", () => {
    setup();
    const only = renderHook(
      () => useWidgetHydration(TYPE, { itemId: 8 }, { forceRefresh: true, showLastDataWhileRefreshing: true }),
    );
    expect(only.result.current.isLoading).toBe(true);
    expect(only.result.current.data).toBeNull();
  });
});
