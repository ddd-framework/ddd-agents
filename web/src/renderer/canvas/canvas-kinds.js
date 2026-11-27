import { isPackageCanvasId } from "./canvas-behaviours";
import { declareCanvasKind, forgetCanvasKind } from "./canvas-kind-declarations";
import { canvasFeatureFlags, registerCanvasFeatureFlags } from "./canvas-feature-flags";
import { canvasBodies, registerCanvasBodies } from "./canvasBodies";
/**
 * Registers a canvas kind: its behaviour, its body, its opener widgets and its gate. Runs outside React, before the
 * first render; a second registration of an id replaces the first (a hot reload registers again).
 *
 * @throws Error for an id of the package's own canvases
 */
export const registerCanvasKind = (kind) => {
    if (isPackageCanvasId(kind.canvasId)) {
        throw new Error(`The canvas id "${kind.canvasId}" is one of the package's own canvases; a canvas kind needs its own id.`);
    }
    declareCanvasKind(kind.canvasId, { behaviour: kind.behaviour, openedByWidgets: kind.openedByWidgets ?? [] });
    registerCanvasBodies({ [kind.canvasId]: kind.body });
    if (kind.featureFlag !== undefined)
        registerCanvasFeatureFlags({ [kind.canvasId]: kind.featureFlag });
    else
        delete canvasFeatureFlags[kind.canvasId];
};
/** Takes a registered canvas kind off again (tests, a host that disconnects a package). */
export const unregisterCanvasKind = (canvasId) => {
    if (isPackageCanvasId(canvasId))
        return;
    forgetCanvasKind(canvasId);
    delete canvasBodies[canvasId];
    delete canvasFeatureFlags[canvasId];
};
