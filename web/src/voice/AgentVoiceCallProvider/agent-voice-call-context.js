import { createContext, useCallback, useContext } from "react";
import { useAgentsTexts } from "../../provider/AgentsProvider";
import { getAgentDisplayName } from "../../provider/environment";
/** Without a provider: no ring source (nothing rings). */
export const AgentVoiceCallContext = createContext({ ringSource: undefined });
/** The ring source of the provider above (undefined: the application has no ring transport). */
export const useAgentVoiceRingSource = () => useContext(AgentVoiceCallContext).ringSource;
/**
 * The text function of the voice components: the texts of the environment around (`AgentsEnvironment.texts`, a
 * subtree's own over the app-wide ones) with `%agentName%` filled with the name the owner knows the agent by
 * (`AgentsEnvironment.agentDisplayName`).
 */
export const useAgentVoiceTranslateText = () => {
    const texts = useAgentsTexts();
    return useCallback((key, replacements) => texts.translate(key, { agentName: getAgentDisplayName(), ...replacements }), [texts]);
};
