import {
  readFrozenHydration,
  useWidgetImageRender,
  useWidgetImageLiveHydration,
} from "../../image-render/WidgetImageRenderContext";
import { useCallback, useEffect, useId, useRef } from "react";
import { useAgentsStateDispatch } from "../../provider/AgentsProvider";
import { getWidgetHydrator } from "./widget-hydration.registry";
import { WidgetHydrationSlice, useWidgetMemory } from "./widget-hydration.slice";
import { WidgetHydrationStatus } from "./widget-hydration.types";

interface RuntimeEntry {
  controller: AbortController | null;
  refreshPending?: boolean;
  nextRequestId: number;
  /** Last payload handled for this key — compared via the hydrator's `isPayloadEqual`. */
  lastPayload: unknown;
  /** True once `lastPayload` has been set (distinguishes "never handled" from an undefined payload). */
  hasLastPayload: boolean;
  /**
   * RequestId of the currently in-flight fetch. Used to re-sync Redux memory
   * (which may have been wiped by `allWidgetsReset`) when a later instance
   * dedupes onto the same in-flight request — otherwise the success/failure
   * dispatch is dropped by the requestId guard.
   */
  inFlightRequestId: number | null;
}

const runtime = new Map<string, RuntimeEntry>();

const getRuntime = (key: string): RuntimeEntry => {
  let entry = runtime.get(key);
  if (!entry) {
    entry = {
      controller: null,
      nextRequestId: 0,
      lastPayload: undefined,
      hasLastPayload: false,
      inFlightRequestId: null,
    };
    runtime.set(key, entry);
  }
  return entry;
};

export interface UseWidgetHydrationResult<TData> {
  data: TData | null;
  status: WidgetHydrationStatus;
  isLoading: boolean;
  isError: boolean;
  error: string | null;
  /** Generation of the request the memory last started or settled (0 = none): newer results carry a higher id. */
  requestId: number;
  refetch: () => void;
}

export interface UseWidgetHydrationOptions {
  /**
   * When true, force a fetch on the first render of this instance even if
   * memory holds a successful result for the same key. Used to honor the
   * `fresh` widget prop (streamed agent message → refetch; history
   * replay → reuse cache).
   */
  forceRefresh?: boolean;
  /**
   * Keep rendering the last loaded data for this key while a fetch runs, instead of the loading state. The fetch still
   * runs (and its result replaces the data); only a key with no data yet shows loading. For a snapshot whose copy may
   * already be on screen elsewhere — another agent window opening the same briefing.
   */
  showLastDataWhileRefreshing?: boolean;
}

export const useWidgetHydration = <TData = unknown, TPayload = unknown>(
  type: string,
  payload: TPayload,
  options: UseWidgetHydrationOptions = {},
): UseWidgetHydrationResult<TData> => {
  const { forceRefresh = false, showLastDataWhileRefreshing = false } = options;
  const dispatch = useAgentsStateDispatch();
  const instanceId = useId();

  const frozenHydration = useWidgetImageRender();
  const liveCapture = useWidgetImageLiveHydration();
  const hydrator = getWidgetHydrator<TPayload, TData>(type);
  const key = hydrator?.deriveKey(type, payload) ?? type;
  const registered = frozenHydration === null || liveCapture ? hydrator : undefined;

  const memory = useWidgetMemory(key);

  const runHydrationRef = useRef<((force: boolean) => void) | null>(null);
  const runHydration = useCallback(
    (force: boolean) => {
      if (!registered) {
        console.warn(`[widget-hydration] No hydrator registered for "${type}"`);
        return;
      }

      const entry = getRuntime(key);

      dispatch(WidgetHydrationSlice.instanceAttached({ key, instanceId }));

      // ONE in-flight request per key: a second consumer JOINS it instead of aborting it — an abort here cancelled a
      // sibling widget's read of the same run and left its memory in "error". A FORCED refresh
      // that meets an in-flight request is not dropped either: it is remembered and issued as a new generation the
      // moment the in-flight one settles, so a refresh after a mutation never accepts the older snapshot as fresh.
      if (entry.controller) {
        if (force) {
          entry.refreshPending = true;
        }
        return;
      }
      const controller = new AbortController();
      entry.controller = controller;
      const requestId = ++entry.nextRequestId;
      entry.inFlightRequestId = requestId;

      dispatch(
        WidgetHydrationSlice.hydrationStarted({
          key,
          instanceId,
          requestId,
        }),
      );

      registered
        .hydrator(payload, { signal: controller.signal })
        .then((data) => {
          if (controller.signal.aborted) {
            return;
          }
          entry.controller = null;
          entry.inFlightRequestId = null;
          dispatch(WidgetHydrationSlice.hydrationSucceeded({ key, requestId, data }));
          if (entry.refreshPending) {
            entry.refreshPending = false;
            runHydrationRef.current?.(true);
          }
        })
        .catch((err: unknown) => {
          if (controller.signal.aborted) {
            return;
          }
          entry.controller = null;
          entry.inFlightRequestId = null;
          const message = err instanceof Error ? err.message : String(err);
          dispatch(WidgetHydrationSlice.hydrationFailed({ key, requestId, error: message }));
          if (entry.refreshPending) {
            entry.refreshPending = false;
            runHydrationRef.current?.(true);
          }
        });
    },
    [dispatch, instanceId, key, payload, registered, type],
  );

  runHydrationRef.current = runHydration;

  const payloadJson = (() => {
    try {
      return JSON.stringify(payload);
    } catch {
      return null;
    }
  })();

  const lastHandledPayloadRef = useRef<string | null>(null);

  useEffect(() => {
    if (!registered) {
      return;
    }
    const entry = getRuntime(key);

    // Payload comparison goes through the hydrator's `isPayloadEqual` (e.g. only
    // the raw payload and the agent space key) so key-order/normalization noise in derived
    // payload fields doesn't trigger spurious refetches.
    const payloadChanged =
      !entry.hasLastPayload || !registered.isPayloadEqual(entry.lastPayload as TPayload | undefined, payload);
    const isFirstMountForKey = memory === undefined;
    const isIdle = memory?.status === "idle" || !memory;
    const isStale =
      memory?.status === "success" &&
      memory.lastLoadedAt !== null &&
      Date.now() - memory.lastLoadedAt > registered.staleMs;

    dispatch(WidgetHydrationSlice.instanceAttached({ key, instanceId }));

    const isFirstRenderOfInstance = lastHandledPayloadRef.current === null;
    // `payloadChanged` applies on first render too — `willStartHydrationOnMount`
    // below already promises a fetch in that case (renders the skeleton), so
    // skipping it here would freeze the widget on its skeleton forever.
    const shouldStart =
      isFirstMountForKey ||
      isIdle ||
      isStale ||
      payloadChanged ||
      (forceRefresh && isFirstRenderOfInstance) ||
      (registered.refetchOnNewInstance && memory?.status === "success");

    if (shouldStart) {
      // Dedupe concurrent mounts: if a request for this key is already in-flight
      // with the same payload, attach this instance to it instead of firing a
      // duplicate (which would also abort the original via runHydration's force path).
      if (entry.controller && !payloadChanged) {
        // Re-sync memory's requestId with the in-flight fetch. Required when
        // `allWidgetsReset` wiped memory mid-flight — `instanceAttached` above
        // recreated memory with default `requestId=0`, so without this the
        // in-flight success/failure dispatch would be dropped by the guard.
        if (entry.inFlightRequestId !== null) {
          dispatch(
            WidgetHydrationSlice.hydrationStarted({
              key,
              instanceId,
              requestId: entry.inFlightRequestId,
            }),
          );
        }
        lastHandledPayloadRef.current = payloadJson;
        return;
      }
      entry.lastPayload = payload;
      entry.hasLastPayload = true;
      lastHandledPayloadRef.current = payloadJson;
      runHydration(true);
    } else {
      lastHandledPayloadRef.current = payloadJson;
    }
    // `memory === undefined` re-fires the effect if Redux memory is wiped
    // externally (e.g. `allWidgetsReset` on conversation switch) while this
    // instance was mid-flight — otherwise the in-flight fetch's result is
    // dropped by the requestId guard and the widget stays loading forever.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, payloadJson, memory === undefined]);

  const refetch = useCallback(() => {
    runHydration(true);
  }, [runHydration]);

  const status: WidgetHydrationStatus = memory?.status ?? "idle";

  // Synchronously determine whether *this render* will trigger hydration via
  // the effect below — so the caller can show the loading state on the first
  // render instead of briefly flashing stale `memory.data` from a previous
  // instance, then transitioning into the skeleton on the next render.
  const willStartHydrationOnMount = (() => {
    if (!registered) return false;
    if (lastHandledPayloadRef.current !== null) return false; // only on first render
    const entry = runtime.get(key);
    const payloadChanged =
      !entry || !entry.hasLastPayload || !registered.isPayloadEqual(entry.lastPayload as TPayload | undefined, payload);
    const isFirstMountForKey = memory === undefined;
    const isIdle = !memory || memory.status === "idle";
    const isStale =
      memory?.status === "success" &&
      memory.lastLoadedAt !== null &&
      Date.now() - memory.lastLoadedAt > registered.staleMs;
    return (
      isFirstMountForKey ||
      isIdle ||
      isStale ||
      payloadChanged ||
      forceRefresh ||
      (registered.refetchOnNewInstance && memory?.status === "success")
    );
  })();

  const showsLastData = showLastDataWhileRefreshing && memory?.data !== null && memory?.data !== undefined;
  const isLoading =
    !showsLastData && ((status === "loading" && memory?.loadingInstanceId === instanceId) || willStartHydrationOnMount);

  if (frozenHydration !== null && !liveCapture) {
    return {
      data: readFrozenHydration(frozenHydration, type, key) as TData | null,
      status: "success",
      isLoading: false,
      isError: false,
      error: null,
      requestId: 0,
      refetch: () => {},
    };
  }

  return {
    data: willStartHydrationOnMount && !showsLastData ? null : ((memory?.data ?? null) as TData | null),
    status,
    isLoading,
    isError: status === "error",
    error: memory?.error ?? null,
    requestId: memory?.requestId ?? 0,
    refetch,
  };
};
