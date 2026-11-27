import { createContext, useContext } from "react";
export const LayoutNestingContext = createContext({ isInsideSurface: false, isInsidePressable: false });
export const useLayoutNesting = () => useContext(LayoutNestingContext);
