import { useRef } from "react";
import { AgentUIClient } from "./AgentUIClient";
/**
 * Adapters that always answer from the newest set an application passed (`readAdapters`): the runner keeps one object
 * while the application may build a new one on every render. The optional adapters, and the navigator's optional
 * methods of the screen actions (`currentTarget`, `applyFilters`), are present exactly when the newest set has them,
 * so the runner's "this client cannot do that" answers stay true.
 */
export const createLatestScreenAdapters = (readAdapters) => {
    const latestAdapters = {
        targets: { listTargets: () => readAdapters().targets.listTargets() },
        navigator: {
            describeBlockers: () => readAdapters().navigator.describeBlockers(),
            open: (target, request) => readAdapters().navigator.open(target, request),
            currentLocation: () => readAdapters().navigator.currentLocation(),
        },
        reader: { read: options => readAdapters().reader.read(options) },
        highlighter: {
            mark: (references, note) => readAdapters().highlighter.mark(references, note),
            clear: () => readAdapters().highlighter.clear(),
        },
        isVisible: () => readAdapters().isVisible(),
    };
    Object.defineProperties(latestAdapters.navigator, {
        currentTarget: {
            enumerable: true,
            get: () => (readAdapters().navigator.currentTarget ? () => readAdapters().navigator.currentTarget?.() ?? null : undefined),
        },
        applyFilters: {
            enumerable: true,
            get: () => {
                const applyFilters = readAdapters().navigator.applyFilters;
                if (!applyFilters)
                    return undefined;
                return (target, filters) => readAdapters().navigator.applyFilters?.(target, filters);
            },
        },
    });
    Object.defineProperties(latestAdapters, {
        picture: { enumerable: true, get: () => readAdapters().picture },
        agentWindow: { enumerable: true, get: () => readAdapters().agentWindow },
        pathGuide: { enumerable: true, get: () => readAdapters().pathGuide },
        actions: { enumerable: true, get: () => readAdapters().actions },
        requestHandlers: { enumerable: true, get: () => readAdapters().requestHandlers },
    });
    return latestAdapters;
};
/**
 * One `AgentUIClient` per mount of the agent window: a stable client id and the runner of the delivered requests. The
 * adapters are read through the latest render (a ref), so an application may pass a new object on every render. Hand
 * the result to the conversation client binding (`createAgentsHttpConversationClient({ uiClient })`).
 */
export const useAgentUIClient = (adapters, uiStateSource = null) => {
    const adaptersRef = useRef(adapters);
    adaptersRef.current = adapters;
    const uiStateSourceRef = useRef(uiStateSource);
    uiStateSourceRef.current = uiStateSource;
    const clientRef = useRef(null);
    if (clientRef.current === null) {
        const latestAdapters = createLatestScreenAdapters(() => adaptersRef.current);
        clientRef.current = new AgentUIClient(latestAdapters, { readUIState: () => uiStateSourceRef.current?.readUIState() ?? null });
    }
    return clientRef.current;
};
