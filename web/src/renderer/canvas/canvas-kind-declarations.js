/** The registered canvas kinds by canvas id. Filled before the first render, read at every lookup. */
const canvasKindDeclarations = new Map();
/** Keeps a kind's declaration; a second one for an id replaces the first. Called by `registerCanvasKind` only. */
export const declareCanvasKind = (canvasId, declaration) => {
    canvasKindDeclarations.set(canvasId, {
        behaviour: declaration.behaviour,
        openedByWidgets: [...declaration.openedByWidgets],
    });
};
/** Takes a kind's declaration off again. Called by `unregisterCanvasKind` only. */
export const forgetCanvasKind = (canvasId) => {
    canvasKindDeclarations.delete(canvasId);
};
export const readCanvasKindDeclaration = (canvasId) => canvasKindDeclarations.get(canvasId);
/** The ids of the registered canvas kinds, in the order they were registered. */
export const getCanvasKindIds = () => [...canvasKindDeclarations.keys()];
