import { createContext, useContext } from "react";
export const MapPresentationContext = createContext("inline");
export const useMapPresentation = () => useContext(MapPresentationContext);
