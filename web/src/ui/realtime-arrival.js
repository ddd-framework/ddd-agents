/** The message type of a client tool request on the realtime port (`AgentUIRealtimeMessageTypeProviderService`). */
export const CLIENT_TOOL_REQUEST_MESSAGE_TYPE = 'agent.ui.clientToolRequest';
/** The client tool request a realtime message carries, or null for another type or a payload that does not parse. */
export const readClientToolRequestOfRealtimeMessage = (message) => {
    if (message.messageType !== CLIENT_TOOL_REQUEST_MESSAGE_TYPE)
        return null;
    try {
        const payload = JSON.parse(message.payloadJson);
        if (typeof payload !== 'object' || payload === null)
            return null;
        const { id, requestType, argumentsJson, secondsLeft } = payload;
        if (typeof id !== 'number' || !Number.isSafeInteger(id) || typeof requestType !== 'string')
            return null;
        return {
            id,
            requestType,
            argumentsJson: typeof argumentsJson === 'string' ? argumentsJson : '{}',
            // the transport's own clock: the message's seconds left win when the payload's are older
            secondsLeft: Math.min(typeof secondsLeft === 'number' ? secondsLeft : message.secondsLeft, message.secondsLeft),
        };
    }
    catch {
        return null;
    }
};
/**
 * The arrival adapter for a host transport: hand it every message the transport receives for a conversation; a
 * client tool request goes to the UI client (which runs a request id once, so the run-state read delivering the same
 * request is harmless), any other type to `onOtherMessage`. Returns whether the message was a client tool request.
 */
export const acceptArrivedRealtimeMessage = (conversationId, message, acceptor, sendAnswer, onOtherMessage) => {
    const delivery = readClientToolRequestOfRealtimeMessage(message);
    if (delivery) {
        acceptor.acceptClientToolRequest(conversationId, delivery, sendAnswer);
        return true;
    }
    onOtherMessage?.(conversationId, message);
    return false;
};
/** The pause before the one re-read of `readRunStateAfterClientToolRequestPing` when the first read found nothing. */
export const CLIENT_TOOL_REQUEST_PING_REREAD_DELAY_MS = 400;
/**
 * The arrival adapter for a host transport that only pings (it carries no message, e.g. the per-conversation ping of
 * a client tool request): reads the run state, which hands a waiting request to the UI client; when that read
 * delivered none — the ping overtook the server's write — it reads once more after `rereadDelayMs`, so the request is
 * not left to the next regular read. Resolves with whether a request was delivered.
 */
export const readRunStateAfterClientToolRequestPing = async (conversationId, readRunState, deliveryCounter, rereadDelayMs = CLIENT_TOOL_REQUEST_PING_REREAD_DELAY_MS) => {
    const acceptedBefore = deliveryCounter.countAcceptedClientToolRequests();
    await readRunState(conversationId);
    if (deliveryCounter.countAcceptedClientToolRequests() > acceptedBefore)
        return true;
    await new Promise(resolve => setTimeout(resolve, rereadDelayMs));
    await readRunState(conversationId);
    return deliveryCounter.countAcceptedClientToolRequests() > acceptedBefore;
};
let developmentRequestId = 9000000000000;
/**
 * The dev helper: runs a request through the UI client as if the server had sent it and resolves with the answer, so
 * an application tries its adapters without a model (e.g. from the browser console in development).
 */
export const runClientToolRequestForDevelopment = (acceptor, requestType, parameters = {}, secondsLeft = 30) => new Promise(resolve => {
    developmentRequestId += 1;
    acceptor.acceptClientToolRequest(0, { id: developmentRequestId, requestType, argumentsJson: JSON.stringify(parameters), secondsLeft }, async (_requestId, answer) => resolve(answer));
});
