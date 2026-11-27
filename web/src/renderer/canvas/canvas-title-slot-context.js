import { createContext, useContext } from "react";
/**
 * The place beside the canvas pane's close button, for a canvas whose behaviour declares `titleBesideCloseButton`:
 * its body portals its title there. Null outside a canvas pane and for every other canvas.
 */
export const CanvasTitleSlotContext = createContext(null);
export const useCanvasTitleSlot = () => useContext(CanvasTitleSlotContext);
