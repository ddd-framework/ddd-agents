import { createContext, useContext, useMemo, useSyncExternalStore } from "react";
import { AgentsConversationClientProvider } from "../client/AgentsConversationClientContext";
import { getDefaultAgentsStateStore } from "../renderer/widget-hydration/widget-hydration-state-store";
import { DEFAULT_AGENTS_COMPONENTS } from "./components/default-components";
import { DEFAULT_AGENTS_TEXTS, getAgentsEnvironment, subscribeToAgentsEnvironment, } from "./environment";
const AgentsEnvironmentContext = createContext(null);
const NO_SUBTREE_ENVIRONMENT = {};
/**
 * Supplies an environment and the conversation client to one subtree. `<AgentsProvider client={client}><AgentChat />
 * </AgentsProvider>` is a whole chat with the package defaults.
 */
export const AgentsProvider = (props) => {
    const { environment = NO_SUBTREE_ENVIRONMENT, client, children } = props;
    const environmentTree = <AgentsEnvironmentContext.Provider value={environment}>{children}</AgentsEnvironmentContext.Provider>;
    return client ? (<AgentsConversationClientProvider client={client}>{environmentTree}</AgentsConversationClientProvider>) : (environmentTree);
};
const useAppEnvironment = () => useSyncExternalStore(subscribeToAgentsEnvironment, getAgentsEnvironment, getAgentsEnvironment);
/** The components package code renders: the subtree's replacements over the app's over the package defaults. */
export const useAgentsComponents = () => {
    const subtreeEnvironment = useContext(AgentsEnvironmentContext);
    const appEnvironment = useAppEnvironment();
    return useMemo(() => ({ ...DEFAULT_AGENTS_COMPONENTS, ...appEnvironment.components, ...subtreeEnvironment?.components }), [appEnvironment, subtreeEnvironment]);
};
export const useAgentsTexts = () => {
    const subtreeEnvironment = useContext(AgentsEnvironmentContext);
    const appEnvironment = useAppEnvironment();
    return subtreeEnvironment?.texts ?? appEnvironment.texts ?? DEFAULT_AGENTS_TEXTS;
};
/** The environment of this subtree: its own members over the app-wide ones. */
export const useAgentsEnvironment = () => {
    const subtreeEnvironment = useContext(AgentsEnvironmentContext);
    const appEnvironment = useAppEnvironment();
    return useMemo(() => (subtreeEnvironment ? { ...appEnvironment, ...subtreeEnvironment } : appEnvironment), [appEnvironment, subtreeEnvironment]);
};
/** The app's state store, else the package's own widget hydration store. */
const readStateStore = (environment) => environment.stateStore ?? getDefaultAgentsStateStore();
/** Reads from the state store (`AgentsEnvironment.stateStore`, else the package's default store). */
export const useAgentsStateSelector = (selector) => readStateStore(useAgentsEnvironment()).useSelector(selector);
/** The dispatch of the state store (`AgentsEnvironment.stateStore`, else the package's default store). */
export const useAgentsStateDispatch = () => readStateStore(useAgentsEnvironment()).useDispatch();
const NO_OWNER_SETTINGS = {};
const useNoOwnerSettings = () => NO_OWNER_SETTINGS;
/** The owner's language and the active agent space's date and time settings (`AgentsEnvironment.useOwnerSettings`). */
export const useAgentsOwnerSettings = () => {
    const useOwnerSettings = useAgentsEnvironment().useOwnerSettings ?? useNoOwnerSettings;
    return useOwnerSettings();
};
const useNoActiveAgentSpaceKey = () => undefined;
/** The key of the agent space the owner works in (`AgentsEnvironment.useActiveAgentSpaceKey`). */
export const useAgentsActiveAgentSpaceKey = () => {
    const useActiveAgentSpaceKey = useAgentsEnvironment().useActiveAgentSpaceKey ?? useNoActiveAgentSpaceKey;
    return useActiveAgentSpaceKey();
};
const NO_MAPS = {
    isLoaded: false,
    loadError: new Error("The agents environment has no maps loader."),
    languageCode: undefined,
};
const useNoMapsLoader = () => NO_MAPS;
/** The map script loader's state (`AgentsEnvironment.useMapsLoader`). */
export const useAgentsMapsLoader = () => {
    const useMapsLoader = useAgentsEnvironment().useMapsLoader ?? useNoMapsLoader;
    return useMapsLoader();
};
const NO_BUDGET_UPGRADE = { canUpgrade: false, openUpgrade: () => undefined, showsFairUseWording: false };
const useNoBudgetLimit = () => NO_BUDGET_UPGRADE;
/** What the error card of a reached budget offers (`AgentsEnvironment.useBudgetLimit`). */
export const useAgentsBudgetLimit = () => {
    const useBudgetLimit = useAgentsEnvironment().useBudgetLimit ?? useNoBudgetLimit;
    return useBudgetLimit();
};
