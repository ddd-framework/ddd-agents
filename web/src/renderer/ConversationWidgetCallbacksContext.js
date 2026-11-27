import { createContext, useContext, useMemo } from "react";
export const defaultConversationWidgetCallbacks = {
    canvas: null,
    hostHasCanvasSurface: false,
    lastClosedCanvas: null,
    openCanvas: () => { },
    closeCanvas: () => { },
    reopenLastClosedCanvas: () => { },
    setCanvasCreatedEntityId: () => { },
    resetCanvas: () => { },
    resetCanvasForNewTurn: () => { },
    sendMessageToChat: () => { },
    prefillComposer: () => { },
    injectSignal: () => { },
    minimizeChatHost: () => { },
    chatAgentSpaceKey: undefined,
    isConversationRunning: false,
    isConversationClosed: false,
    conversationId: undefined,
    composerWidgets: [],
    addComposerWidget: () => "",
    replaceComposerWidgetFields: () => { },
    removeComposerWidget: () => { },
    clearComposerWidgets: () => { },
    isOnboardingSetupConversation: false,
    messages: null,
};
/** The widget callbacks a chat host (or a standalone host) supplies to every widget below it. */
export const ConversationWidgetContext = createContext(defaultConversationWidgetCallbacks);
/** Static capture host: no conversation, persistence, canvas effects or action callbacks. */
export const ReadOnlyConversationWidgetProvider = (props) => {
    const { children, agentSpaceKey } = props;
    return (<ConversationWidgetContext.Provider value={{ ...defaultConversationWidgetCallbacks, chatAgentSpaceKey: agentSpaceKey }}>
      {children}
    </ConversationWidgetContext.Provider>);
};
/**
 * A host outside the chat that supplies its OWN callbacks — the widget preview keeps its canvas and its
 * composer log itself. Every callback it leaves out keeps the no-op default; no conversation slice is involved.
 */
export const StandaloneConversationWidgetProvider = (props) => {
    const { children, callbacks } = props;
    const value = useMemo(() => ({ ...defaultConversationWidgetCallbacks, ...callbacks }), [callbacks]);
    return <ConversationWidgetContext.Provider value={value}>{children}</ConversationWidgetContext.Provider>;
};
export const useConversationWidgetContext = () => useContext(ConversationWidgetContext);
