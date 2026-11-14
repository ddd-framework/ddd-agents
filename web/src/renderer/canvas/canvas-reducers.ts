import { canvasBehaviourOf } from "./canvas-behaviours";
import { CanvasOpenRequest, CanvasState, LastClosedCanvas, sameCanvasIdentity } from "./canvas-state";
import { CanvasOpenDecision, decideCanvasOpen } from "./decide-canvas-open";

/**
 * The canvas part of a chat slice partition — the two fields every transition reads and writes. Both chat doors carry
 * them (`ConversationState`); the shell is shared, so the state must have the same shape in both partitions.
 */
export interface CanvasSliceState {
  canvas: CanvasState | null;
  lastClosedCanvas: LastClosedCanvas | null;
  /**
   * How many canvases this partition has mounted so far — the source of `mountGeneration`. Never reset by a close,
   * a send or a clear (and exempt from `resetState`), so a late asynchronous write from a body that was replaced,
   * cleared or belonged to the previous conversation can never match a new canvas's generation.
   */
  canvasMountCount: number;
}

/**
 * The lifetime state machine as REDUCERS. Hand-written, not generated setters:
 * a generated setter is a blind overwrite of a snapshot the caller read a render earlier, and two opens in one commit
 * would both see the same stale state. Each function mutates the Immer draft it receives and returns the decision it
 * applied, so the slice can expose them as case reducers and a spec can assert every transition on a plain object.
 */

const toLastClosed = (canvas: CanvasState, closedByCustomer: boolean): LastClosedCanvas => ({
  canvasId: canvas.canvasId,
  instanceId: canvas.instanceId,
  anchor: canvas.anchor,
  mountGeneration: canvas.mountGeneration,
  closedByCustomer,
  ...(canvas.createdEntityId !== undefined ? { createdEntityId: canvas.createdEntityId } : {}),
});

/** Next render key: strictly increasing for the partition's lifetime, so no two mounts ever share a generation. */
const nextMountGeneration = (state: CanvasSliceState): number => {
  state.canvasMountCount += 1;
  return state.canvasMountCount;
};

/**
 * Open a canvas — the ONE way to open, for all four origins. On Accept: the replaced canvas (a different identity)
 * becomes `lastClosedCanvas`; a `lastClosedCanvas` of the accepted identity is consumed (its veto is cleared — the
 * customer, the page or the restore decided to have it back); the created entity survives a re-open of the same
 * identity so a restored editor keeps its draft.
 */
export const applyOpenCanvas = (state: CanvasSliceState, request: CanvasOpenRequest): CanvasOpenDecision => {
  const decision = decideCanvasOpen(canvasBehaviourOf(request.canvasId), state.canvas, state.lastClosedCanvas, request);
  if (decision.kind === "ignore") return decision;

  const previous = state.canvas;
  const mountGeneration = nextMountGeneration(state);
  const carriedCreatedEntityId =
    request.createdEntityId ??
    (previous !== null && sameCanvasIdentity(previous, request) ? previous.createdEntityId : undefined) ??
    (state.lastClosedCanvas !== null && sameCanvasIdentity(state.lastClosedCanvas, request)
      ? state.lastClosedCanvas.createdEntityId
      : undefined);

  if (previous !== null && !sameCanvasIdentity(previous, request)) {
    state.lastClosedCanvas = toLastClosed(previous, false);
  } else if (state.lastClosedCanvas !== null && sameCanvasIdentity(state.lastClosedCanvas, request)) {
    state.lastClosedCanvas = null;
  }
  state.canvas = {
    canvasId: request.canvasId,
    instanceId: request.instanceId,
    anchor: request.anchor,
    origin: request.origin,
    mountGeneration,
    ...(carriedCreatedEntityId !== undefined ? { createdEntityId: carriedCreatedEntityId } : {}),
  };
  return decision;
};

/**
 * Close the open canvas but keep it re-openable: it becomes `lastClosedCanvas`. `byCustomer` marks the customer's own
 * close (the canvas close control) — a live delivery of the same identity is then ignored until a send, a card click
 * or a page hand-over. A shell-driven close (compact mode, the side panel opening, a narrow crossing) passes false.
 */
export const applyCloseCanvas = (state: CanvasSliceState, byCustomer: boolean): void => {
  if (state.canvas === null) return;
  state.lastClosedCanvas = toLastClosed(state.canvas, byCustomer);
  state.canvas = null;
};

/**
 * The SEND boundary: a new user message starts a new turn. A `turn` canvas is over for good — it leaves the open slot
 * AND `lastClosedCanvas` (the card in the transcript is the way back). A `pinned` canvas stays open,
 * and a closed pinned one stays re-openable with its customer veto cleared (the send is a clearing edge).
 */
export const applyResetCanvasForNewTurn = (state: CanvasSliceState): void => {
  if (state.canvas !== null && canvasBehaviourOf(state.canvas.canvasId)?.lifetime === "turn") {
    state.canvas = null;
  }
  if (state.lastClosedCanvas !== null) {
    if (canvasBehaviourOf(state.lastClosedCanvas.canvasId)?.lifetime === "turn") {
      state.lastClosedCanvas = null;
    } else if (state.lastClosedCanvas.closedByCustomer) {
      state.lastClosedCanvas = { ...state.lastClosedCanvas, closedByCustomer: false };
    }
  }
};

/** Forget the canvas entirely — a conversation switch, `closeAgent`, the modal unmount. Nothing is re-openable. */
export const applyClearCanvas = (state: CanvasSliceState): void => {
  state.canvas = null;
  state.lastClosedCanvas = null;
};

export interface CanvasCreatedEntity {
  readonly canvasId: CanvasState["canvasId"];
  readonly instanceId: string;
  /** The generation the body was mounted with — a late write from a body that was replaced meanwhile is dropped. */
  readonly mountGeneration: number;
  readonly createdEntityId: number;
}

/** A body created its entity (the post editor's draft): remember the id on the canvas it was created for. */
export const applySetCanvasCreatedEntityId = (state: CanvasSliceState, created: CanvasCreatedEntity): boolean => {
  const canvas = state.canvas;
  if (canvas === null || !sameCanvasIdentity(canvas, created) || canvas.mountGeneration !== created.mountGeneration) {
    return false;
  }
  state.canvas = { ...canvas, createdEntityId: created.createdEntityId };
  return true;
};
