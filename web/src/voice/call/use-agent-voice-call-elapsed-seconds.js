import { useEffect, useState } from "react";
import { agentVoiceCallElapsedSecondsAt } from "./agent-voice-call-elapsed-seconds";
/** Seconds since the call connected, ticking while it is live; frozen at the end once it ended. */
export const useAgentVoiceCallElapsedSeconds = (call) => {
    const [nowMs, setNowMs] = useState(() => Date.now());
    useEffect(() => {
        if (call.phase !== "live")
            return;
        const timer = window.setInterval(() => setNowMs(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [call.phase]);
    return agentVoiceCallElapsedSecondsAt(call, nowMs);
};
