import { createContext, useContext } from "react";

/**
 * Where a `Map` node renders: `inline` in the chat (the map card with its carousel and the expand
 * control), or `fullScreen` in the `mapFullScreen` canvas (the map filling the pane beside the list of points). The
 * canvas body renders the same node through the same renderer and provides `fullScreen`; nothing else changes.
 */
export type MapPresentation = "inline" | "fullScreen";

export const MapPresentationContext = createContext<MapPresentation>("inline");

export const useMapPresentation = () => useContext(MapPresentationContext);
