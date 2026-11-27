/**
 * The one start the chat should send from the two hand-over fields (the opening request's `userMessage` and
 * `startSignalText`). The app's openers keep at most one of the two set — each clears the
 * other's leftover on the way in — but the message still wins here if both somehow are, since the customer's own words
 * are the stronger request.
 */
export const pickPendingStartRequest = (pendingStartUserMessage, pendingStartSignalText) => {
    if (pendingStartUserMessage) {
        return { kind: "message", text: pendingStartUserMessage };
    }
    if (pendingStartSignalText) {
        return { kind: "signal", text: pendingStartSignalText };
    }
    return null;
};
/**
 * Whether the chat should send `pendingStartRequest` as the start of a NEW conversation right now. Called on every
 * relevant state change — NOT once on mount: the widget is often already open (and mounted) when an entry point
 * outside it hands over a request, which is what BUF-4009 was.
 *
 * "Not yet" (false) and "never" (false) deliberately look the same to the caller: the request stays in the slice, and
 * this is asked again on the next change. A conversation still being open is the "not yet" case — Agent drops to a new
 * chat when a request arrives, because every incentive CTA promises a fresh chat for its job, and this then says yes
 * on the cold chat that follows.
 */
export const isChatReadyToSendPendingStartRequest = ({ pendingStartRequest, activeConversationId, loadedConversationId, startingNewConversation, alreadySentStartRequest, }) => {
    if (!pendingStartRequest) {
        return false;
    }
    // A conversation is open (or still loaded while the new chat is being set up) — the request must never be appended
    // to it. A start already in flight would be dropped by the send handler's own in-flight guard, losing the request.
    if (activeConversationId || loadedConversationId || startingNewConversation) {
        return false;
    }
    return (alreadySentStartRequest?.kind !== pendingStartRequest.kind ||
        alreadySentStartRequest.text !== pendingStartRequest.text);
};
