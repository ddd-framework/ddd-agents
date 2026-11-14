import type { CanvasBehaviour } from "./canvas-behaviours";
import { CanvasOpenRequest, CanvasState, LastClosedCanvas, sameCanvasIdentity } from "./canvas-state";

/**
 * Why an open request is ignored. Every reason is a sentence the shell could log:
 *  - `unknownCanvas`               no behaviour is declared for the id (a stale stored pointer, a typo)
 *  - `noSurface`                   the host renders no split (`disableSplitView`), so there is nowhere to open
 *  - `replay`                      a delivery-origin request for a message that was NOT just delivered — history
 *                                  replay, a reload, scroll-back; rejected BEFORE anything is read or written
 *  - `alreadyOpen`                 a delivery-origin request for the identity that is already open (the agent re-emits
 *                                  an editor widget after a rebuild) — the open canvas is kept, never remounted
 *  - `customerClosedIt`            the customer closed exactly this identity and nothing has cleared the veto yet
 *  - `noTakeoverOnNarrowViewport`  a live delivery on a narrow viewport of a canvas that declares no overlay — the
 *                                  card stays, the customer taps it
 */
export type CanvasOpenIgnoreReason =
  | "unknownCanvas"
  | "noSurface"
  | "replay"
  | "alreadyOpen"
  | "customerClosedIt"
  | "noTakeoverOnNarrowViewport";

export type CanvasOpenDecision =
  | { readonly kind: "accept" }
  | { readonly kind: "ignore"; readonly reason: CanvasOpenIgnoreReason };

const ACCEPT: CanvasOpenDecision = { kind: "accept" };

const ignore = (reason: CanvasOpenIgnoreReason): CanvasOpenDecision => ({ kind: "ignore", reason });

/**
 * THE opening rule — the one place that answers "may this canvas open now?". Pure: it reads the behaviour, the open
 * canvas, the last closed one and the request, and returns Accept or Ignore. It never mutates; the reducer
 * (`canvas-reducers.ts`) applies an Accept. There is no merge rung: the same identity opened again from a card click
 * is a fresh Accept (remount), and the refresh contract of a pinned work surface is a value the body derives from the
 * message list, not a reducer merge.
 *
 * A `cardClick`, an `openedByPage` and a `restoredAfterReload` request always open (the customer, the page or the
 * stored pointer decided); only `deliveredInMessage` is judged.
 */
export const decideCanvasOpen = (
  behaviour: CanvasBehaviour | undefined,
  openCanvas: CanvasState | null,
  lastClosedCanvas: LastClosedCanvas | null,
  request: CanvasOpenRequest,
): CanvasOpenDecision => {
  if (behaviour === undefined) return ignore("unknownCanvas");
  if (!request.hostHasCanvasSurface) return ignore("noSurface");
  if (request.origin !== "deliveredInMessage") return ACCEPT;

  // Replay is rejected first: a replayed message must not touch the open canvas in any way.
  if (!request.justDelivered) return ignore("replay");
  if (openCanvas !== null && sameCanvasIdentity(openCanvas, request)) return ignore("alreadyOpen");
  if (lastClosedCanvas !== null && lastClosedCanvas.closedByCustomer && sameCanvasIdentity(lastClosedCanvas, request)) {
    return ignore("customerClosedIt");
  }
  if (!request.viewportViable && behaviour.narrowViewport === "none") return ignore("noTakeoverOnNarrowViewport");
  return ACCEPT;
};
