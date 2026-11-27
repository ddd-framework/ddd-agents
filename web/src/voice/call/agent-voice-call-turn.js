export const turnOfAgentVoiceCall = (call) => {
    if (call.phase === "connecting")
        return "connecting";
    if (call.isMuted)
        return "muted";
    if (call.isMicrophoneSilent)
        return "silentMicrophone";
    if (call.isAgentSpeaking)
        return "agent";
    if (call.isOwnerSpeaking)
        return "owner";
    // The owner said the last words and the agent has not started its answer yet.
    if (call.lastSpeaker === "owner")
        return "thinking";
    return "listening";
};
