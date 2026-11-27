import { useAgentsActiveAgentSpaceKey } from "../../provider/AgentsProvider";
const toPositiveInteger = (rawValue) => {
    if (rawValue === undefined || rawValue === null || rawValue === "")
        return undefined;
    const numericValue = typeof rawValue === "number" ? rawValue : Number(rawValue);
    if (!Number.isFinite(numericValue) || !Number.isInteger(numericValue) || numericValue <= 0)
        return undefined;
    return numericValue;
};
const parseAgentSpaceKeyAttribute = (agentSpaceKeyAttribute) => {
    // A block without the field is the regular case (most widgets name no agent space): no warning for it.
    if (agentSpaceKeyAttribute === undefined || agentSpaceKeyAttribute === null || agentSpaceKeyAttribute === "") {
        return undefined;
    }
    const agentSpaceKey = toPositiveInteger(agentSpaceKeyAttribute);
    if (agentSpaceKey === undefined) {
        console.warn(`[agent-space] invalid agent space key on a widget block: ${String(agentSpaceKeyAttribute)}`);
    }
    return agentSpaceKey;
};
/**
 * Resolves the agent space a widget block names (`readWidgetAgentSpaceKeyAttribute`) against the agent space the owner
 * works in (`AgentsEnvironment.useActiveAgentSpaceKey`). Returns the key of another agent space only when the agent
 * explicitly names a different one than the active one; otherwise undefined, so the widget keeps its regular behaviour.
 */
export const useResolvedConversationWidgetAgentSpaceKey = (agentSpaceKeyAttribute) => {
    const activeAgentSpaceKey = toPositiveInteger(useAgentsActiveAgentSpaceKey());
    const namedAgentSpaceKey = parseAgentSpaceKeyAttribute(agentSpaceKeyAttribute);
    if (namedAgentSpaceKey === undefined || namedAgentSpaceKey === activeAgentSpaceKey) {
        return { anotherAgentSpaceKey: undefined, activeAgentSpaceKey, isAnotherAgentSpace: false };
    }
    return { anotherAgentSpaceKey: namedAgentSpaceKey, activeAgentSpaceKey, isAnotherAgentSpace: true };
};
