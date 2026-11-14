import { createContext, type ReactNode, useContext } from "react";
import type { AgentsConversationClient } from "./AgentsConversationClient";

const AgentsConversationClientContext = createContext<AgentsConversationClient | null>(null);

export interface AgentsConversationClientProviderProps {
  /** The app's binding of the conversation client port. */
  client: AgentsConversationClient;
  children?: ReactNode;
}

/**
 * Puts the app's conversation client in reach of the package's chat hooks (search, activity, conversation list). The
 * app mounts it around the chat; until the provider carries the `client` member, this is where the
 * hooks read it from.
 */
export const AgentsConversationClientProvider = (props: AgentsConversationClientProviderProps) => {
  const { client, children } = props;
  return <AgentsConversationClientContext.Provider value={client}>{children}</AgentsConversationClientContext.Provider>;
};

/** The conversation client the app mounted; throws when a chat hook renders outside `AgentsConversationClientProvider`. */
export const useAgentsConversationClient = (): AgentsConversationClient => {
  const client = useContext(AgentsConversationClientContext);
  if (client === null) {
    throw new Error("A chat hook rendered outside AgentsConversationClientProvider: the app has to mount its conversation client.");
  }
  return client;
};

/** The conversation client the app mounted, or null outside `AgentsConversationClientProvider`. */
export const useOptionalAgentsConversationClient = (): AgentsConversationClient | null =>
  useContext(AgentsConversationClientContext);
