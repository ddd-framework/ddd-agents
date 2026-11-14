import {
  ComposedViewTree,
  getComposedViewSubtreeNodes,
  readComposedViewBinding,
} from "../renderer/widget-tree/composed-view-tree";

/**
 * Which data paths of a `ComposedView` tree carry a time the report e-mail must state: the turn-time
 * `$from` snapshots (the tool results the backend wrote into the block when the report message was stored) and the
 * mail-time reads (a `$source` binding or an embedded domain widget, both read live while the e-mail is captured).
 * Inline data carries its own source line and no time.
 */
export interface ComposedViewDataTimes {
  /** The tree binds at least one `$from` tool result. */
  hasTurnTimeSnapshot: boolean;
  /**
   * The newest `resolvedAt` (ISO 8601) the persist-time resolution wrote into a resolved `$from` snapshot, when it
   * wrote one; otherwise undefined and the caption names the report time without a clock time.
   */
  turnTimeResolvedAt: string | undefined;
  /** The tree reads at least one `$source` or embeds a self-hydrating domain widget. */
  hasMailTimeRead: boolean;
}

interface DataTimesCollection {
  hasTurnTimeSnapshot: boolean;
  newestResolvedAtMs: number | undefined;
  hasMailTimeRead: boolean;
}

/** The moment of a resolved `$from` snapshot, or undefined (no `resolvedAt`, or not a readable ISO moment). */
const readSnapshotResolvedAtMs = (fromBindingObject: unknown): number | undefined => {
  if (typeof fromBindingObject !== "object" || fromBindingObject === null || Array.isArray(fromBindingObject)) {
    return undefined;
  }
  const snapshot = fromBindingObject as Record<string, unknown>;
  if (snapshot.state !== "resolved" || typeof snapshot.resolvedAt !== "string") return undefined;
  const resolvedAtMs = Date.parse(snapshot.resolvedAt);
  return Number.isFinite(resolvedAtMs) ? resolvedAtMs : undefined;
};

const collectDataTimesFromValue = (value: unknown, collection: DataTimesCollection): void => {
  const binding = readComposedViewBinding(value);
  if (binding !== null) {
    if (binding.kind === "from") {
      collection.hasTurnTimeSnapshot = true;
      const resolvedAtMs = readSnapshotResolvedAtMs(binding.wireValue.$from);
      if (resolvedAtMs !== undefined) {
        collection.newestResolvedAtMs = Math.max(collection.newestResolvedAtMs ?? resolvedAtMs, resolvedAtMs);
      }
    }
    if (binding.kind === "source") collection.hasMailTimeRead = true;
    return;
  }
  if (Array.isArray(value)) value.forEach((element) => collectDataTimesFromValue(element, collection));
  else if (typeof value === "object" && value !== null) {
    Object.values(value).forEach((nested) => collectDataTimesFromValue(nested, collection));
  }
};

/** The data paths with a time of a checked tree: its datasets and every node's props, embedded widgets included. */
export const readComposedViewDataTimes = (tree: ComposedViewTree): ComposedViewDataTimes => {
  const collection: DataTimesCollection = {
    hasTurnTimeSnapshot: false,
    newestResolvedAtMs: undefined,
    hasMailTimeRead: false,
  };
  collectDataTimesFromValue(tree.data, collection);
  const nodes = tree.view ? getComposedViewSubtreeNodes(tree.view) : [];
  for (const node of nodes) {
    if (node.type === "Widget") collection.hasMailTimeRead = true;
    collectDataTimesFromValue(node.props, collection);
  }
  return {
    hasTurnTimeSnapshot: collection.hasTurnTimeSnapshot,
    turnTimeResolvedAt:
      collection.newestResolvedAtMs === undefined ? undefined : new Date(collection.newestResolvedAtMs).toISOString(),
    hasMailTimeRead: collection.hasMailTimeRead,
  };
};
