import { sameCanvasIdentity } from "./canvas-state";
const ACCEPT = { kind: "accept" };
const ignore = (reason) => ({ kind: "ignore", reason });
/**
 * THE opening rule — the one place that answers "may this canvas open now?". Pure: it reads the behaviour, the open
 * canvas, the last closed one and the request, and returns Accept or Ignore. It never mutates; the reducer
 * (`canvas-reducers.ts`) applies an Accept. There is no merge rung: the same identity opened again from a card click
 * is a fresh Accept (remount), and the refresh contract of a pinned work surface is a value the body derives from the
 * message list, not a reducer merge.
 *
 * A `widgetClick`, an `openedByPage` and a `restoredAfterReload` request always open (the customer, the page or the
 * stored pointer decided); only `deliveredInMessage` is judged.
 */
export const decideCanvasOpen = (behaviour, openCanvas, lastClosedCanvas, request) => {
    if (behaviour === undefined)
        return ignore("unknownCanvas");
    if (!request.hostHasCanvasSurface)
        return ignore("noSurface");
    if (request.origin !== "deliveredInMessage")
        return ACCEPT;
    // Replay is rejected first: a replayed message must not touch the open canvas in any way.
    if (!request.justDelivered)
        return ignore("replay");
    if (openCanvas !== null && sameCanvasIdentity(openCanvas, request))
        return ignore("alreadyOpen");
    if (lastClosedCanvas !== null && lastClosedCanvas.closedByCustomer && sameCanvasIdentity(lastClosedCanvas, request)) {
        return ignore("customerClosedIt");
    }
    if (!request.viewportViable && behaviour.narrowViewport === "none")
        return ignore("noTakeoverOnNarrowViewport");
    return ACCEPT;
};
