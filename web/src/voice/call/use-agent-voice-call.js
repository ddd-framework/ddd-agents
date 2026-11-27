import { useMemo } from "react";
import { useAgentVoiceCallSnapshot } from "./agent-voice-call-store";
import { useAgentVoiceCallElapsedSeconds } from "./use-agent-voice-call-elapsed-seconds";
/**
 * The voice chat as it is now: the phase, the call and its conversation, the spoken lines, mute, the end reason and
 * the elapsed seconds. It renders again every second while the call is live; a component that needs one field only
 * reads it with `useAgentVoiceCallValue`, one that needs no clock with `useAgentVoiceCallSnapshot`.
 */
export const useAgentVoiceCall = () => {
    const call = useAgentVoiceCallSnapshot();
    const elapsedSeconds = useAgentVoiceCallElapsedSeconds(call);
    return useMemo(() => ({ ...call, elapsedSeconds }), [call, elapsedSeconds]);
};
