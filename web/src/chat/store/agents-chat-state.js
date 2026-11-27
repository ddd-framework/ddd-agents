export const EMPTY_AGENTS_CHAT_OPENING_REQUEST = {
    userMessage: null,
    startSignalText: null,
    entryPointKey: null,
    deepLinkedWidgetName: null,
};
export const INITIAL_AGENTS_CHAT_STATE = {
    conversation: null,
    messages: null,
    conversationLoading: false,
    conversationLoaded: false,
    hasMoreOlder: false,
    aiThinking: false,
    error: null,
    rateLimitRetryAfterSeconds: null,
    requestedConversationId: null,
    startingNewConversation: false,
    pendingStartToken: null,
    startTokenCount: 0,
    openingRequest: EMPTY_AGENTS_CHAT_OPENING_REQUEST,
    canvas: null,
    lastClosedCanvas: null,
    canvasMountCount: 0,
};
