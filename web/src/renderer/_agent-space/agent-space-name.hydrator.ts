import { getAgentsEnvironment } from "../../provider/environment";
import { registerWidgetHydrator } from "../widget-hydration";

export const AGENT_SPACE_NAME_WIDGET_TYPE = "__agent-space-name";

export interface AgentSpaceNameHydrationInput {
  agentSpaceKey: number;
}

export interface AgentSpaceNameData {
  name?: string;
}

/**
 * The name lookup of the header chip on a widget of another agent space. Registered as a widget hydrator, so every
 * widget of a conversation that renders for the same agent space shares one read, and the entry is cleared with the
 * other widget data when the conversation changes. The name comes from the app (`AgentsEnvironment.readAgentSpaceName`);
 * without it the header shows the label of an unnamed agent space.
 */
registerWidgetHydrator<AgentSpaceNameHydrationInput, AgentSpaceNameData>(AGENT_SPACE_NAME_WIDGET_TYPE, {
  deriveKey: (type, payload) => `${type}:${payload.agentSpaceKey}`,
  hydrator: async (input, { signal }) => {
    const name = await getAgentsEnvironment().readAgentSpaceName?.(input.agentSpaceKey, signal);
    if (signal.aborted) throw new DOMException("Aborted", "AbortError");
    return { name };
  },
  refetchOnNewInstance: false,
});
