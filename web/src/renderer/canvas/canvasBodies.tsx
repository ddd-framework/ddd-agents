import type React from "react";
import type { CanvasId } from "./canvas-behaviours";
import type { CanvasBodyProps } from "./canvas-body-props";
import { ConversationSearchResultsCanvasBody } from "../../widgets/ConversationSearchResultsConversationWidget/ConversationSearchResultsCanvasBody";
import { WebSearchResultsCanvasBody } from "../../widgets/WebSearchResultsConversationWidget/WebSearchResultsCanvasBody";
import { EntityDetailCanvasBody } from "../../kit/entity-detail/EntityDetailCanvasBody";
import { MapFullScreenCanvasBody } from "../../kit/map-full-screen/MapFullScreenCanvasBody";

export type { CanvasBodyProps } from "./canvas-body-props";
export { canvasFeatureFlagOf } from "./canvas-feature-flags";

/**
 * THE CANVAS BODIES — canvas id → the React component the shell mounts in the canvas pane. The one module that knows
 * both the canvas ids and React components, and it is imported ONLY by `CanvasPane` (the shell), never by
 * the slice, the behaviours or a widget — that is the import layering that keeps the store-init temporal-dead-zone
 * cycle away. The bodies themselves are small: a body that hosts a heavy page (an app's list page or editor)
 * lazy-loads that page, exactly as its widget did. Every canvas has a body; nothing
 * else hands the pane a rendered node.
 *
 * The package's own bodies are listed here; an app adds the bodies of its canvases with `registerCanvasBodies` before
 * the first render. The object is filled in place, so the shell reads every registration.
 */
export const canvasBodies = {
  conversationSearchResults: ConversationSearchResultsCanvasBody,
  webSearchResults: WebSearchResultsCanvasBody,
  entityDetail: EntityDetailCanvasBody,
  mapFullScreen: MapFullScreenCanvasBody,
} as Record<CanvasId, React.ComponentType<CanvasBodyProps>>;

/** Adds an app's canvas bodies (canvas id → body). Runs outside React, before the first render. */
export const registerCanvasBodies = (
  bodies: Readonly<Partial<Record<CanvasId, React.ComponentType<CanvasBodyProps>>>>,
): void => {
  Object.assign(canvasBodies, bodies);
};
