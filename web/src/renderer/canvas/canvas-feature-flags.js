/**
 * The requirement (an app feature flag, opaque to the package) a CANVAS requires — the same one its opening widget
 * declares in the registry. Owned by the canvas (not read off an opener) because a restored or page-opened canvas has
 * no widget mount to inherit it from; the shell wraps a gated body in `ConversationWidgetFeatureGate`. An app names
 * the requirements of its canvases with `registerCanvasFeatureFlags`; the package's own canvases are ungated.
 */
export const canvasFeatureFlags = {};
/** Adds the requirements of an app's canvases. Runs outside React, before the first render. */
export const registerCanvasFeatureFlags = (featureFlags) => {
    Object.assign(canvasFeatureFlags, featureFlags);
};
export const canvasFeatureFlagOf = (canvasId) => canvasFeatureFlags[canvasId];
