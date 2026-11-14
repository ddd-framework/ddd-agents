import { useSyncExternalStore } from "react";

/** One published update: a strictly increasing revision, so a consumer reacts to each publication exactly once. */
export interface EntityUpdate<TUpdate> {
  readonly revision: number;
  readonly update: TUpdate;
}

/**
 * A tiny shared channel between a canvas BODY and the inline widget that opened it: the body publishes
 * "this entity changed" / "this entity was removed", the inline list applies or refetches. Stands in for callbacks
 * captured into a rendered canvas node: a body mounted from the canvas model has no closure into the
 * widget instance, and the widget instance may have been windowed out meanwhile. Module state with
 * `useSyncExternalStore`.
 */
export const createEntityUpdateChannel = <TUpdate>() => {
  let latest: EntityUpdate<TUpdate> | null = null;
  const listeners = new Set<() => void>();
  const subscribe = (listener: () => void) => {
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  };
  const read = () => latest;
  return {
    publish: (update: TUpdate): void => {
      latest = { revision: (latest?.revision ?? 0) + 1, update };
      listeners.forEach((listener) => listener());
    },
    /** The latest publication (null before the first). Re-renders the consumer on every publication. */
    useLatestUpdate: (): EntityUpdate<TUpdate> | null => useSyncExternalStore(subscribe, read, read),
  };
};
