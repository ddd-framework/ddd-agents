import { useEffect, useSyncExternalStore } from "react";
import { answerIncomingAgentVoiceCall, declineIncomingAgentVoiceCall, } from "./answer-incoming-agent-voice-call";
const REFUSAL_SHOWN_MS = 4000;
let snapshot = {
    handledCallIds: new Set(),
    isAnswering: false,
    bandHostCount: 0,
    ringCoverCount: 0,
};
const listeners = new Set();
let refusalTimer;
const updateSnapshot = (changes) => {
    snapshot = { ...snapshot, ...changes };
    listeners.forEach((listener) => listener());
};
const subscribe = (listener) => {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
};
const getSnapshot = () => snapshot;
export const useAgentVoiceIncomingRing = () => useSyncExternalStore(subscribe, getSnapshot, getSnapshot);
/** The incoming ring as it is now, outside React (a test). */
export const readAgentVoiceIncomingRingSnapshot = () => snapshot;
/** What the listener's own card shows (the ring, else a refused Accept) while no chat band can be seen. */
export const pickAgentVoiceIncomingCard = (state) => {
    if (state.bandHostCount > 0 && state.ringCoverCount === 0)
        return undefined;
    if (state.ring !== undefined)
        return "ring";
    return state.refusal !== undefined ? "refusal" : undefined;
};
/** The listener publishes the ring it picked with its caller line (undefined when nothing rings). */
export const publishAgentVoiceIncomingRing = (ring, callerLabel) => {
    if (ring?.callId === snapshot.ring?.callId &&
        ring?.ringExpiresAtUnixMs === snapshot.ring?.ringExpiresAtUnixMs &&
        callerLabel?.text === snapshot.callerLabel?.text &&
        callerLabel?.detail === snapshot.callerLabel?.detail)
        return;
    updateSnapshot({ ring, callerLabel: ring === undefined ? undefined : callerLabel });
};
const markHandled = (handledRing) => {
    const isShownRing = snapshot.ring?.callId === handledRing.callId;
    updateSnapshot({
        handledCallIds: new Set(snapshot.handledCallIds).add(handledRing.callId),
        ring: isShownRing ? undefined : snapshot.ring,
        callerLabel: isShownRing ? undefined : snapshot.callerLabel,
    });
};
const showRefusal = (refusal) => {
    window.clearTimeout(refusalTimer);
    updateSnapshot({ refusal });
    if (refusal === undefined)
        return;
    refusalTimer = window.setTimeout(() => updateSnapshot({ refusal: undefined }), REFUSAL_SHOWN_MS);
};
/**
 * Accept: answers the ring and, when this client won, starts the call on the ring's own conversation and agent space
 * (the ring may come from another agent space than the one the client shows). The application learns the call's
 * conversation through `AgentVoiceCallProvider.onConversationOfCallChanged` and shows it.
 */
export const acceptAgentVoiceIncomingRing = async () => {
    const acceptedRing = snapshot.ring;
    if (!acceptedRing || snapshot.isAnswering)
        return;
    updateSnapshot({ isAnswering: true });
    const answerRefusal = await answerIncomingAgentVoiceCall(acceptedRing);
    markHandled(acceptedRing);
    updateSnapshot({ isAnswering: false });
    showRefusal(answerRefusal);
};
/**
 * Decline: ends the ring for every open client of the account. The backend writes the declined call into its
 * conversation once nothing rings any more.
 */
export const declineAgentVoiceIncomingRing = () => {
    const declinedRing = snapshot.ring;
    if (!declinedRing || snapshot.isAnswering)
        return;
    markHandled(declinedRing);
    void declineIncomingAgentVoiceCall(declinedRing);
};
/** A chat that shows the ring in its band, while hosting: the listener's card stays away unless a cover is open. */
export const useAgentVoiceRingBandHost = (isHosting) => {
    useEffect(() => {
        if (!isHosting)
            return;
        updateSnapshot({ bandHostCount: snapshot.bandHostCount + 1 });
        return () => updateSnapshot({ bandHostCount: snapshot.bandHostCount - 1 });
    }, [isHosting]);
};
/**
 * A surface that covers the chats while covering (a settings sheet over them): their bands are out of sight then, so
 * the listener shows its own card on top instead.
 */
export const useAgentVoiceRingCover = (isCovering) => {
    useEffect(() => {
        if (!isCovering)
            return;
        updateSnapshot({ ringCoverCount: snapshot.ringCoverCount + 1 });
        return () => updateSnapshot({ ringCoverCount: snapshot.ringCoverCount - 1 });
    }, [isCovering]);
};
