import { AGENT_VOICE_CALL_END_REASON_ENDED_BY_AGENT, AGENT_VOICE_CALL_END_REASON_LIMIT_REACHED, } from "../call/agent-voice-call-end-reasons";
import { textKey } from "../../provider/text-key";
/** An ended voice chat that was connected: who or what ended it (the call's end reason, when the server ended it). */
export const endedAgentVoiceCallTitleTextKey = (call) => {
    if (call.endReason === AGENT_VOICE_CALL_END_REASON_ENDED_BY_AGENT)
        return textKey("%agentName% ended the voice chat");
    if (call.endReason === AGENT_VOICE_CALL_END_REASON_LIMIT_REACHED)
        return textKey("Voice chat limit reached");
    return textKey("Voice chat ended");
};
/**
 * The band's title after a voice chat (`phase` `ended` or `failed`): a failed one says so; an ended one that was
 * connected says who or what ended it; one hung up before it connected (nothing was said) was cancelled.
 */
export const agentVoiceCallOutcomeTitleTextKey = (call) => {
    if (call.phase === "failed")
        return textKey("Voice chat failed");
    return call.connectedAtMs !== undefined ? endedAgentVoiceCallTitleTextKey(call) : textKey("Voice chat cancelled");
};
