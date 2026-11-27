import { INITIAL_AGENTS_CHAT_STATE } from "./agents-chat-state";
import { reduceAgentsChatState } from "./reduce-agents-chat-state";
/** The plain implementation of `AgentsChatStore`: one state object, replaced by the reducer on every event. */
export class AgentsChatStateContainer {
    state;
    listeners = new Set();
    constructor(initialState = INITIAL_AGENTS_CHAT_STATE) {
        this.state = initialState;
    }
    getState = () => this.state;
    subscribe = (listener) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };
    dispatch = (event) => {
        const nextState = reduceAgentsChatState(this.state, event);
        if (nextState === this.state)
            return;
        this.state = nextState;
        for (const notifyListener of [...this.listeners])
            notifyListener();
    };
}
/** Creates the state handle of one chat instance. */
export const createAgentsChatStore = (initialState) => new AgentsChatStateContainer(initialState);
