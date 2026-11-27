import { useEffect } from "react";
import { useOptionalAgentsConversationClient } from "../../client/AgentsConversationClientContext";
import { checkAgentVoiceCallEndedOnServer, isAgentVoiceCallActive, useAgentVoiceCallValue } from "./agent-voice-call-store";
/** The change signal fires for every transcript row too: at most one call-state read per this long. */
const CALL_STATE_READ_THROTTLE_MS = 1200;
/**
 * Ends the voice chat here when the SERVER ended the call (the agent hung up, a limit, the reaper): listens to the
 * change signal of the CALL's conversation, independent of the conversation the chat shows, while the call is active,
 * and on a signal reads the call's state. The backend writes the call's end before its call-end marker and the signal,
 * so the read after the signal sees the end. The signal is throttled here (one read per 1.2 s at most, the last signal
 * of a burst still read) and the store runs one read at a time.
 *
 * The signal comes from `subscribeToConversationChanges` when the application passes one, otherwise from the
 * conversation client in the React context (its `subscribeToConversationChanges`). Without
 * either nothing is listened to: the call still ends through the store's own triggers (the provider's `session.closed`,
 * the event channel closing, the media path dropping). `AgentVoiceCallProvider` mounts the hook.
 */
export const useAgentVoiceCallServerEnd = (subscribeToConversationChanges) => {
    const conversationClient = useOptionalAgentsConversationClient();
    const isCallActive = useAgentVoiceCallValue((call) => isAgentVoiceCallActive(call.phase));
    const callConversationId = useAgentVoiceCallValue((call) => call.conversationId);
    const callId = useAgentVoiceCallValue((call) => call.callId);
    useEffect(() => {
        if (!isCallActive || callConversationId === undefined || callId === undefined)
            return;
        let lastReadAtMs = Number.NEGATIVE_INFINITY;
        let trailingReadTimer;
        const readCallState = () => {
            lastReadAtMs = Date.now();
            void checkAgentVoiceCallEndedOnServer();
        };
        const readCallStateThrottled = () => {
            const waitMs = lastReadAtMs + CALL_STATE_READ_THROTTLE_MS - Date.now();
            if (waitMs <= 0) {
                readCallState();
                return;
            }
            if (trailingReadTimer !== undefined)
                return;
            trailingReadTimer = window.setTimeout(() => {
                trailingReadTimer = undefined;
                readCallState();
            }, waitMs);
        };
        const unsubscribe = subscribeToConversationChanges !== undefined
            ? subscribeToConversationChanges(callConversationId, readCallStateThrottled)
            : conversationClient?.subscribeToConversationChanges?.(callConversationId, readCallStateThrottled, readCallStateThrottled);
        return () => {
            window.clearTimeout(trailingReadTimer);
            unsubscribe?.();
        };
    }, [isCallActive, callConversationId, callId, subscribeToConversationChanges, conversationClient]);
};
