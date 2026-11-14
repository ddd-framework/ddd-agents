// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { cleanup, renderHook, waitFor } from "@testing-library/react";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { getWidgetHydrator, registerWidgetHydrator, unregisterWidgetHydrator } from "../../renderer/widget-hydration/widget-hydration.registry";
import { createAgentsWidgetHydrationStateStore } from "../../renderer/widget-hydration/widget-hydration-state-store";
import { useWidgetHydration } from "../../renderer/widget-hydration/use-widget-hydration";
import { installWidgetHydrationStandIns, type WidgetHydrationStandIns } from "./widget-hydration-stand-ins";

const REGISTERED_TYPE = "StandInSpecRegistered";
const UNREGISTERED_TYPE = "StandInSpecUnregistered";

describe("installWidgetHydrationStandIns", () => {
  let environmentBefore: AgentsEnvironment;
  let standIns: WidgetHydrationStandIns | undefined;
  const realHydrator = vi.fn(async (payload: { itemId: number }) => ({ fromRealHydrator: payload.itemId }));

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    setAgentsEnvironment({ stateStore: createAgentsWidgetHydrationStateStore() });
    registerWidgetHydrator<{ itemId: number }, unknown>(REGISTERED_TYPE, {
      hydrator: realHydrator,
      deriveKey: (type, payload) => `${type}:${payload.itemId}`,
    });
  });

  afterEach(() => {
    standIns?.restore();
    standIns = undefined;
    unregisterWidgetHydrator(REGISTERED_TYPE);
    realHydrator.mockClear();
    cleanup();
    setAgentsEnvironment(environmentBefore);
  });

  test("answers a registered type by its derived key before its type, and runs the real hydrator for the rest", async () => {
    standIns = installWidgetHydrationStandIns({ [`${REGISTERED_TYPE}:1`]: { byKey: true } });
    const byKey = renderHook(() => useWidgetHydration(REGISTERED_TYPE, { itemId: 1 }));
    await waitFor(() => expect(byKey.result.current.data).toEqual({ byKey: true }));
    const unanswered = renderHook(() => useWidgetHydration(REGISTERED_TYPE, { itemId: 2 }));
    await waitFor(() => expect(unanswered.result.current.data).toEqual({ fromRealHydrator: 2 }));
    expect(realHydrator).toHaveBeenCalledTimes(1);
  });

  test("answers a failed read with error and a pending read with loading", async () => {
    standIns = installWidgetHydrationStandIns({
      [`${REGISTERED_TYPE}:3`]: { __status: 500 },
      [`${REGISTERED_TYPE}:4`]: { __pending: true },
    });
    const failed = renderHook(() => useWidgetHydration(REGISTERED_TYPE, { itemId: 3 }));
    await waitFor(() => expect(failed.result.current.status).toBe("error"));
    const pending = renderHook(() => useWidgetHydration(REGISTERED_TYPE, { itemId: 4 }));
    expect(pending.result.current.isLoading).toBe(true);
  });

  test("hydrates a type nothing registered, and restore() puts the registry back", async () => {
    standIns = installWidgetHydrationStandIns({ [UNREGISTERED_TYPE]: { rows: [1, 2] } });
    const standIn = renderHook(() => useWidgetHydration(UNREGISTERED_TYPE, {}));
    await waitFor(() => expect(standIn.result.current.data).toEqual({ rows: [1, 2] }));

    standIns.restore();
    standIns = undefined;
    expect(getWidgetHydrator(UNREGISTERED_TYPE)).toBeUndefined();
    expect(getWidgetHydrator(REGISTERED_TYPE)?.hydrator).toBe(realHydrator);
  });
});
