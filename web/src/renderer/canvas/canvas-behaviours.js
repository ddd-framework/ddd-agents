import { getFurtherCanvasBehaviours } from "../../provider/environment";
import { getCanvasKindIds, readCanvasKindDeclaration } from "./canvas-kind-declarations";
/** Width of the canvas pane (% of the split) when a behaviour declares none. */
export const DEFAULT_CANVAS_WIDTH_PERCENT = 50;
/** The narrowest the customer can pull the canvas (px); pulling further and holding folds the tab into "close". */
export const CANVAS_MIN_WIDTH_PX = 420;
/** What the chat column keeps beside the widest canvas (px). */
export const CHAT_COLUMN_MIN_WIDTH_PX = 360;
/**
 * The boxed canvas card's inset from its pane edge (px) — the pull tab sits on the card's edge, not the pane's. The
 * pane's padding reads it as `--agents-canvas-inset` (`.splitViewPaneBoxed`), so this is the one place it is set.
 */
export const CANVAS_BOXED_INSET_PX = 8;
/** Width of the canvas pane for `layout: "canvasFillsScreen"` — the chat keeps a narrow companion column. */
export const CANVAS_FILLS_SCREEN_WIDTH_PERCENT = 72;
/** The canvases of the package's own widgets. */
export const packageCanvasBehaviours = {
    conversationSearchResults: { lifetime: "turn", layout: "split", narrowViewport: "none", fullBleed: false },
    webSearchResults: { lifetime: "turn", layout: "split", narrowViewport: "none", fullBleed: false },
    // One entity's detail, opened from a ComposedView row (`openEntity`) — a click, never a live delivery.
    entityDetail: { lifetime: "turn", layout: "split", widthPercent: 40, narrowViewport: "none", fullBleed: false },
    // A ComposedView `Map` node, full screen — the widest split a turn canvas takes, the map flush.
    mapFullScreen: { lifetime: "turn", layout: "split", widthPercent: 65, narrowViewport: "none", fullBleed: true },
    // An ImageGallery of many pictures (or `display: "canvas"`): the gallery beside the chat (a phone keeps the card).
    imageGallery: { lifetime: "turn", layout: "split", narrowViewport: "none", fullBleed: false },
};
const hasOwnCanvasBehaviour = (behaviours, canvasId) => Object.prototype.hasOwnProperty.call(behaviours, canvasId);
/** Whether the id is one of the package's own canvases (a registered kind and an app can never take one). */
export const isPackageCanvasId = (canvasId) => hasOwnCanvasBehaviour(packageCanvasBehaviours, canvasId);
/**
 * The names of the widgets a registered canvas kind opens from (`CanvasKind.openedByWidgets`): a stored pointer of the
 * kind restores only while its anchor message still carries one of them. Empty for another canvas (its openers are the
 * registry's `opensCanvases`).
 */
export const canvasKindOpenerWidgetNamesOf = (canvasId) => readCanvasKindDeclaration(canvasId)?.openedByWidgets ?? [];
/**
 * The behaviour of a canvas: the package's own, else the one the app declares
 * (`AgentsEnvironment.furtherCanvasBehaviours`), else the one of a registered canvas kind (`registerCanvasKind`);
 * undefined for an id nobody declared (a stale stored pointer, a typo — the opening rule ignores it). Nobody can replace
 * a package canvas's behaviour; the app's declaration wins over a registered kind's, so a host can tune a kind a
 * further package brings.
 */
export const canvasBehaviourOf = (canvasId) => {
    if (isPackageCanvasId(canvasId)) {
        return packageCanvasBehaviours[canvasId];
    }
    const furtherCanvasBehaviours = getFurtherCanvasBehaviours();
    if (hasOwnCanvasBehaviour(furtherCanvasBehaviours, canvasId))
        return furtherCanvasBehaviours[canvasId];
    return readCanvasKindDeclaration(canvasId)?.behaviour;
};
/** Every declared canvas id: the package's own first, then the app's further ones, then the registered kinds'. */
export const getDeclaredCanvasIds = () => {
    const packageCanvasIds = Object.keys(packageCanvasBehaviours);
    const furtherCanvasIds = Object.keys(getFurtherCanvasBehaviours()).filter((canvasId) => !packageCanvasIds.includes(canvasId));
    const kindCanvasIds = getCanvasKindIds().filter((canvasId) => !packageCanvasIds.includes(canvasId) && !furtherCanvasIds.includes(canvasId));
    return [...packageCanvasIds, ...furtherCanvasIds, ...kindCanvasIds];
};
/** Type guard for a value read from untrusted input (`sessionStorage`, a URL): is it a declared canvas id? */
export const isCanvasId = (value) => typeof value === "string" && canvasBehaviourOf(value) !== undefined;
export const canvasWidthPercentOf = (behaviour) => behaviour.layout === "canvasFillsScreen"
    ? CANVAS_FILLS_SCREEN_WIDTH_PERCENT
    : (behaviour.widthPercent ?? DEFAULT_CANVAS_WIDTH_PERCENT);
