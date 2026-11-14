import { createContext, useContext } from "react";

/** Presentation scope, independent of hydration and a conversation's interactive/replay state. */
export const ConversationWidgetRenderContext = createContext<"conversation" | "email">("conversation");

export const useIsEmailWidgetRender = () => useContext(ConversationWidgetRenderContext) === "email";
