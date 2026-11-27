/** The held rings with `ring` at the end: a ring that arrives replaces the one of the same call, so a call rings once. */
export const withAgentVoiceIncomingRing = (heldRings, ring) => [...heldRings.filter((heldRing) => heldRing.callId !== ring.callId), ring];
/** The held rings without the ring of the call `callId`: a cleared ring goes at once. */
export const withoutAgentVoiceIncomingRingOfCall = (heldRings, callId) => heldRings.filter((heldRing) => heldRing.callId !== callId);
/**
 * The held rings whose deadline is after `nowMs`. The same list when none has passed its deadline, so a tick of the
 * clock that drops nothing changes nothing.
 */
export const agentVoiceIncomingRingsInDeadlineAt = (heldRings, nowMs) => {
    const ringsInDeadline = heldRings.filter((heldRing) => heldRing.ringExpiresAtUnixMs > nowMs);
    return ringsInDeadline.length === heldRings.length ? heldRings : ringsInDeadline;
};
