import { useEffect, useRef } from "react";
import { isAgentVoiceCallActive, useAgentVoiceCallValue } from "./agent-voice-call-store";
/**
 * Once per call and conversation, tells the application which conversation the call writes into: the one the chat
 * continued, or the one the backend created when the call started from an empty chat or from the agent's ring. The
 * application switches its chat there, so the transcript shows up beside the call. `agentSpaceId` is the call's agent
 * space where the start or the ring named one.
 */
export const useFollowAgentVoiceCallConversation = (onConversationOfCallChanged) => {
    const callPhase = useAgentVoiceCallValue((call) => call.phase);
    const callId = useAgentVoiceCallValue((call) => call.callId);
    const callConversationId = useAgentVoiceCallValue((call) => call.conversationId);
    const callAgentSpaceId = useAgentVoiceCallValue((call) => call.agentSpaceId);
    const reportedCallAndConversationRef = useRef(undefined);
    useEffect(() => {
        if (onConversationOfCallChanged === undefined)
            return;
        if (!isAgentVoiceCallActive(callPhase) || callId === undefined || callConversationId === undefined)
            return;
        const callAndConversation = `${callId}:${callConversationId}`;
        if (reportedCallAndConversationRef.current === callAndConversation)
            return;
        reportedCallAndConversationRef.current = callAndConversation;
        onConversationOfCallChanged(callConversationId, callAgentSpaceId);
    }, [callPhase, callId, callConversationId, callAgentSpaceId, onConversationOfCallChanged]);
};
