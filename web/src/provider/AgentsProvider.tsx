import { createContext, type ReactNode, useContext, useMemo, useSyncExternalStore } from "react";
import type { AgentsConversationClient } from "../client/AgentsConversationClient";
import { AgentsConversationClientProvider } from "../client/AgentsConversationClientContext";
import { getDefaultAgentsStateStore } from "../renderer/widget-hydration/widget-hydration-state-store";
import { DEFAULT_AGENTS_COMPONENTS } from "./components/default-components";
import type { AgentsComponents } from "./components/types";
import {
  type AgentsBudgetLimit,
  type AgentsEnvironment,
  type AgentsMapsLoaderState,
  type AgentsOwnerSettings,
  type AgentsStateAction,
  type AgentsTexts,
  DEFAULT_AGENTS_TEXTS,
  getAgentsEnvironment,
  subscribeToAgentsEnvironment,
} from "./environment";

const AgentsEnvironmentContext = createContext<AgentsEnvironment | null>(null);

export interface AgentsProviderProps {
  /** The subtree's environment; members it leaves out fall back to the app-wide one, then to the defaults. */
  environment?: AgentsEnvironment;
  /** The conversation client the chat below reads and sends through (the one required input of a chat). */
  client?: AgentsConversationClient;
  children: ReactNode;
}

const NO_SUBTREE_ENVIRONMENT: AgentsEnvironment = {};

/**
 * Supplies an environment and the conversation client to one subtree. `<AgentsProvider client={client}><AgentChat />
 * </AgentsProvider>` is a whole chat with the package defaults.
 */
export const AgentsProvider = (props: AgentsProviderProps) => {
  const { environment = NO_SUBTREE_ENVIRONMENT, client, children } = props;
  const environmentTree = <AgentsEnvironmentContext.Provider value={environment}>{children}</AgentsEnvironmentContext.Provider>;
  return client ? (
    <AgentsConversationClientProvider client={client}>{environmentTree}</AgentsConversationClientProvider>
  ) : (
    environmentTree
  );
};

const useAppEnvironment = (): AgentsEnvironment =>
  useSyncExternalStore(subscribeToAgentsEnvironment, getAgentsEnvironment, getAgentsEnvironment);

/** The components package code renders: the subtree's replacements over the app's over the package defaults. */
export const useAgentsComponents = (): AgentsComponents => {
  const subtreeEnvironment = useContext(AgentsEnvironmentContext);
  const appEnvironment = useAppEnvironment();
  return useMemo(
    () => ({ ...DEFAULT_AGENTS_COMPONENTS, ...appEnvironment.components, ...subtreeEnvironment?.components }),
    [appEnvironment, subtreeEnvironment],
  );
};

export const useAgentsTexts = (): AgentsTexts => {
  const subtreeEnvironment = useContext(AgentsEnvironmentContext);
  const appEnvironment = useAppEnvironment();
  return subtreeEnvironment?.texts ?? appEnvironment.texts ?? DEFAULT_AGENTS_TEXTS;
};

/** The environment of this subtree: its own members over the app-wide ones. */
export const useAgentsEnvironment = (): AgentsEnvironment => {
  const subtreeEnvironment = useContext(AgentsEnvironmentContext);
  const appEnvironment = useAppEnvironment();
  return useMemo(
    () => (subtreeEnvironment ? { ...appEnvironment, ...subtreeEnvironment } : appEnvironment),
    [appEnvironment, subtreeEnvironment],
  );
};

/** The app's state store, else the package's own widget hydration store. */
const readStateStore = (environment: AgentsEnvironment) => environment.stateStore ?? getDefaultAgentsStateStore();

/** Reads from the state store (`AgentsEnvironment.stateStore`, else the package's default store). */
export const useAgentsStateSelector = <Selected,>(selector: (state: any) => Selected): Selected =>
  readStateStore(useAgentsEnvironment()).useSelector(selector);

/** The dispatch of the state store (`AgentsEnvironment.stateStore`, else the package's default store). */
export const useAgentsStateDispatch = (): ((action: AgentsStateAction) => unknown) =>
  readStateStore(useAgentsEnvironment()).useDispatch();

const NO_OWNER_SETTINGS: AgentsOwnerSettings = {};
const useNoOwnerSettings = (): AgentsOwnerSettings => NO_OWNER_SETTINGS;

/** The owner's language and the active agent space's date and time settings (`AgentsEnvironment.useOwnerSettings`). */
export const useAgentsOwnerSettings = (): AgentsOwnerSettings => {
  const useOwnerSettings = useAgentsEnvironment().useOwnerSettings ?? useNoOwnerSettings;
  return useOwnerSettings();
};

const useNoActiveAgentSpaceKey = (): number | undefined => undefined;

/** The key of the agent space the owner works in (`AgentsEnvironment.useActiveAgentSpaceKey`). */
export const useAgentsActiveAgentSpaceKey = (): number | undefined => {
  const useActiveAgentSpaceKey = useAgentsEnvironment().useActiveAgentSpaceKey ?? useNoActiveAgentSpaceKey;
  return useActiveAgentSpaceKey();
};

const NO_MAPS: AgentsMapsLoaderState = {
  isLoaded: false,
  loadError: new Error("The agents environment has no maps loader."),
  languageCode: undefined,
};
const useNoMapsLoader = (): AgentsMapsLoaderState => NO_MAPS;

/** The map script loader's state (`AgentsEnvironment.useMapsLoader`). */
export const useAgentsMapsLoader = (): AgentsMapsLoaderState => {
  const useMapsLoader = useAgentsEnvironment().useMapsLoader ?? useNoMapsLoader;
  return useMapsLoader();
};

const NO_BUDGET_UPGRADE: AgentsBudgetLimit = { canUpgrade: false, openUpgrade: () => undefined, showsFairUseWording: false };
const useNoBudgetLimit = (): AgentsBudgetLimit => NO_BUDGET_UPGRADE;

/** What the error card of a reached budget offers (`AgentsEnvironment.useBudgetLimit`). */
export const useAgentsBudgetLimit = (): AgentsBudgetLimit => {
  const useBudgetLimit = useAgentsEnvironment().useBudgetLimit ?? useNoBudgetLimit;
  return useBudgetLimit();
};
