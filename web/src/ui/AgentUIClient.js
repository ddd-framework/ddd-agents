import { createAgentUIClientId } from "./agent-ui-state";
import { ClientToolRequestRunner } from "./ClientToolRequestRunner";
/**
 * The UI side of `Agent.UI` in one object: a client id per mount, the UI state source, and the runner that executes
 * the delivered requests through the application's four adapters. Framework-free; the React hook
 * (`useAgentUIClient`) creates one per mount.
 */
export class AgentUIClient {
    clientId;
    runner;
    uiStateSource;
    adapters;
    acceptedClientToolRequestCount = 0;
    constructor(adapters, uiStateSource = null, clientId = createAgentUIClientId()) {
        this.clientId = clientId;
        this.adapters = adapters;
        this.runner = new ClientToolRequestRunner(adapters);
        this.uiStateSource = uiStateSource;
    }
    /**
     * The source's UI state with the agent window's mode (the window adapter) and the browser window's size, unless the
     * source reports them itself: the agent learns whether its window covers the page and whether a panel fits beside it.
     */
    readUIState() {
        try {
            const uiState = this.uiStateSource?.readUIState() ?? null;
            if (uiState === null)
                return null;
            const completedUIState = { ...uiState };
            const agentWindowMode = uiState.agentWindow ?? this.adapters.agentWindow?.getMode?.() ?? null;
            if (agentWindowMode !== null)
                completedUIState.agentWindow = agentWindowMode;
            if (typeof window !== 'undefined') {
                completedUIState.viewportWidth = uiState.viewportWidth ?? Math.round(window.innerWidth);
                completedUIState.viewportHeight = uiState.viewportHeight ?? Math.round(window.innerHeight);
            }
            return completedUIState;
        }
        catch {
            return null;
        }
    }
    acceptClientToolRequest(_conversationId, delivery, sendAnswer) {
        this.acceptedClientToolRequestCount += 1;
        this.runner.accept(delivery, sendAnswer);
    }
    /** How many client tool requests were delivered to this client so far (a repeat delivery counts again). */
    countAcceptedClientToolRequests() {
        return this.acceptedClientToolRequestCount;
    }
    /** Resolves when every accepted request was answered (tests). */
    whenIdle() {
        return this.runner.whenIdle();
    }
}
