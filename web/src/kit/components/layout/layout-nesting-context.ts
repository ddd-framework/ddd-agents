import { createContext, useContext } from "react";

/**
 * What a layout node needs to know about the layout nodes around it:
 *
 * - inside a `Surface` → a nested surface is a FLAT tile, never a second raised surface (two background levels, never
 *   three);
 * - inside a `Pressable` → the content is already one button, so a nested `Pressable` or `Popover` renders without a
 *   control of its own (no button inside a button).
 */
export interface LayoutNesting {
  isInsideSurface: boolean;
  isInsidePressable: boolean;
}

export const LayoutNestingContext = createContext<LayoutNesting>({ isInsideSurface: false, isInsidePressable: false });

export const useLayoutNesting = () => useContext(LayoutNestingContext);
