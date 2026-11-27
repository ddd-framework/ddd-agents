/**
 * The glow's voice of a call's turn: the agent or the owner while one of them speaks, the slow breath while the agent
 * thinks or the call connects, quiet for every other turn (listening, muted, a silent microphone).
 */
export const glowVoiceOfAgentVoiceCallTurn = (turn) => {
    if (turn === "agent")
        return "agent";
    if (turn === "owner")
        return "owner";
    if (turn === "thinking" || turn === "connecting")
        return "thinking";
    return "quiet";
};
