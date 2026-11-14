import { type AgentsChatEvent, type AgentsChatState, INITIAL_AGENTS_CHAT_STATE } from "./agents-chat-state";
import { reduceAgentsChatState } from "./reduce-agents-chat-state";

/**
 * The state handle of one chat instance. The app creates one per chat (host application: one per agent
 * window, created before the window's chat mounts) and releases it with the chat; the chat and the app's code outside
 * the chat read and dispatch through the same handle. React reads it through `useAgentsChatSelector`.
 */
export interface AgentsChatStore {
  getState: () => AgentsChatState;
  /** Calls the listener after every state change; returns the unsubscribe. */
  subscribe: (listener: () => void) => () => void;
  dispatch: (event: AgentsChatEvent) => void;
}

/** The plain implementation of `AgentsChatStore`: one state object, replaced by the reducer on every event. */
export class AgentsChatStateContainer implements AgentsChatStore {
  protected state: AgentsChatState;
  protected readonly listeners = new Set<() => void>();

  constructor(initialState: AgentsChatState = INITIAL_AGENTS_CHAT_STATE) {
    this.state = initialState;
  }

  getState = (): AgentsChatState => this.state;

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  };

  dispatch = (event: AgentsChatEvent): void => {
    const nextState = reduceAgentsChatState(this.state, event);
    if (nextState === this.state) return;
    this.state = nextState;
    for (const notifyListener of [...this.listeners]) notifyListener();
  };
}

/** Creates the state handle of one chat instance. */
export const createAgentsChatStore = (initialState?: AgentsChatState): AgentsChatStore =>
  new AgentsChatStateContainer(initialState);
