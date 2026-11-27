import { createContext, useCallback, useContext, useSyncExternalStore } from "react";
const AgentsChatStoreContext = createContext(null);
/** Puts one chat instance's state handle in reach of the chat and of the app's components inside it. */
export const AgentsChatStoreProvider = (props) => {
    const { chatStore, children } = props;
    return <AgentsChatStoreContext.Provider value={chatStore}>{children}</AgentsChatStoreContext.Provider>;
};
/** The state handle of the chat this component renders in; throws outside `AgentsChatStoreProvider`. */
export const useAgentsChatStore = () => {
    const chatStore = useContext(AgentsChatStoreContext);
    if (chatStore === null) {
        throw new Error("A chat component rendered outside AgentsChatStoreProvider: the app has to mount its chat store.");
    }
    return chatStore;
};
/** The state handle of the chat this component renders in, or null outside a chat. */
export const useOptionalAgentsChatStore = () => useContext(AgentsChatStoreContext);
const subscribeToNothing = () => () => undefined;
/**
 * Reads one value of a chat instance's state and re-renders when it changes. The selector must return a value that
 * keeps its identity while the state does not change (a state field, not a new object). Without a store it returns
 * `valueWithoutStore`.
 */
export const useAgentsChatSelector = (chatStore, selector, valueWithoutStore) => {
    const subscribe = chatStore ? chatStore.subscribe : subscribeToNothing;
    const readSelectedValue = useCallback(() => (chatStore ? selector(chatStore.getState()) : valueWithoutStore), [chatStore, selector, valueWithoutStore]);
    return useSyncExternalStore(subscribe, readSelectedValue, readSelectedValue);
};
/** Reads one value of the state of the chat this component renders in. */
export const useAgentsChatState = (selector) => useAgentsChatSelector(useAgentsChatStore(), selector);
