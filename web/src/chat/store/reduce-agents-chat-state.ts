import {
  applyClearCanvas,
  applyCloseCanvas,
  applyOpenCanvas,
  applyResetCanvasForNewTurn,
  applySetCanvasCreatedEntityId,
} from "../../renderer/canvas/canvas-reducers";
import type { AgentsClientError } from "../../contract/conversation";
import type { ChatMessage } from "../chat-message";
import { type AgentsChatEvent, type AgentsChatState, INITIAL_AGENTS_CHAT_STATE } from "./agents-chat-state";
import { ConversationErrorType, readConversationErrorTypeOfRefusedSend } from "./conversation-error-type";
import { mergeChatMessagesByIndex, mergeMessagesPage, tagFreshChatMessages } from "./merge-chat-messages";

/** The error card and the quota wait of a refused send. */
const withRefusedSendError = (state: AgentsChatState, error: AgentsClientError): AgentsChatState => {
  const conversationErrorType = readConversationErrorTypeOfRefusedSend(error);
  return {
    ...state,
    error: conversationErrorType,
    rateLimitRetryAfterSeconds:
      conversationErrorType === ConversationErrorType.RATE_LIMIT ? (error.retryAfterSeconds ?? null) : null,
  };
};

/** The messages a send's answer brings, merged over the loaded ones without the optimistic message (the last one). */
const mergeSendAnswer = (loadedMessages: ChatMessage[] | null, answerMessages: ChatMessage[]): ChatMessage[] => {
  const messagesWithoutOptimisticMessage = loadedMessages ? loadedMessages.slice(0, loadedMessages.length - 1) : [];
  return mergeChatMessagesByIndex(
    messagesWithoutOptimisticMessage,
    tagFreshChatMessages(answerMessages, messagesWithoutOptimisticMessage),
  );
};

/** The canvas transitions mutate the fields of the object they get (`canvas-reducers.ts`); this keeps the state immutable. */
const withCanvasTransition = (
  state: AgentsChatState,
  applyCanvasTransition: (nextState: AgentsChatState) => void,
): AgentsChatState => {
  const nextState = { ...state };
  applyCanvasTransition(nextState);
  const changed =
    nextState.canvas !== state.canvas ||
    nextState.lastClosedCanvas !== state.lastClosedCanvas ||
    nextState.canvasMountCount !== state.canvasMountCount;
  return changed ? nextState : state;
};

/**
 * The chat store's reducer: plain functions over the contract types. Each case is the one of the older
 * chat state (a Redux slice and its cases) it replaces, reading the port's answers instead of the
 * generated client's actions; the replay spec feeds both the same recorded sequence.
 */
export const reduceAgentsChatState = (state: AgentsChatState, event: AgentsChatEvent): AgentsChatState => {
  switch (event.type) {
    case "reset":
      // The opening request is set from outside before the chat mounts (a cold chat resets as it comes up); the canvas
      // mount count and the start token count are monotonic sources of identities: all three survive.
      return {
        ...INITIAL_AGENTS_CHAT_STATE,
        openingRequest: state.openingRequest,
        canvasMountCount: state.canvasMountCount,
        startTokenCount: state.startTokenCount,
      };

    case "conversationMetadataRequested":
    case "messagesPageRequested":
      return state.requestedConversationId === event.conversationId
        ? state
        : { ...state, requestedConversationId: event.conversationId };

    case "conversationMetadataReceived":
      // Dropped after a reset or when another conversation was asked for since: a late answer would put the old
      // conversation back, and the next send would post into it instead of starting a new one.
      if (state.requestedConversationId === null || state.requestedConversationId !== event.conversationId) {
        return state;
      }
      return { ...state, conversation: event.conversation, conversationLoaded: true };

    case "conversationMetadataFailed":
      // A metadata read is best-effort: the chat stays as it is.
      return { ...state, conversationLoaded: true };

    case "messagesPageReceived": {
      if (state.requestedConversationId === null || state.requestedConversationId !== event.conversationId) {
        return state;
      }
      if (state.conversation !== null && state.conversation.id !== event.conversationId) {
        return state;
      }
      return {
        ...state,
        messages: mergeMessagesPage(state.messages, event.messages, event.conversationId),
        hasMoreOlder: event.hasMoreOlder ?? state.hasMoreOlder,
        conversationLoaded: true,
      };
    }

    case "messagePostRequested":
      return {
        ...state,
        conversationLoading: true,
        conversationLoaded: false,
        aiThinking: true,
        error: null,
        rateLimitRetryAfterSeconds: null,
        messages: [...(state.messages ?? []), event.message],
      };

    case "messagePostReceived":
      if (event.conversationId !== state.conversation?.id) {
        return state;
      }
      // The reply arrives through live updates, not in this answer: `aiThinking` stays as it is.
      return {
        ...state,
        messages: mergeSendAnswer(state.messages, event.posted.messages),
        conversationLoading: false,
        conversationLoaded: true,
        error: null,
      };

    case "messagePostFailed":
      return withRefusedSendError(
        { ...state, conversationLoading: false, conversationLoaded: true, aiThinking: false },
        event.error,
      );

    case "startRequested":
      return {
        ...state,
        conversation: null,
        conversationLoading: true,
        conversationLoaded: false,
        aiThinking: true,
        error: null,
        rateLimitRetryAfterSeconds: null,
        startingNewConversation: true,
        startTokenCount: state.startTokenCount + 1,
        pendingStartToken: state.startTokenCount + 1,
        // A signal-only start (an empty visible message carries the start signal) seeds no empty bubble.
        messages: event.message.message ? [event.message] : [],
      };

    case "startReceived": {
      if (event.startToken !== state.pendingStartToken) {
        return state;
      }
      const { started } = event;
      if (started.reopenedExisting) {
        // The recent conversation of the start's entry point: nothing was appended, no turn started. Its messages are
        // not in the answer — the controller reads its newest page — so the optimistic bubble goes.
        return {
          ...state,
          conversation: started.conversation,
          messages: [],
          conversationLoading: false,
          conversationLoaded: true,
          aiThinking: false,
          startingNewConversation: false,
          pendingStartToken: null,
        };
      }
      // The reply arrives through live updates: `aiThinking` stays on.
      return {
        ...state,
        conversation: started.conversation,
        messages: mergeSendAnswer(state.messages, started.messages),
        conversationLoading: false,
        conversationLoaded: true,
        startingNewConversation: false,
        pendingStartToken: null,
      };
    }

    case "startFailed":
      return withRefusedSendError(
        {
          ...state,
          conversation: null,
          conversationLoading: false,
          conversationLoaded: true,
          aiThinking: false,
          startingNewConversation: false,
          pendingStartToken: null,
        },
        event.error,
      );

    case "errorSet":
      return state.error === event.error ? state : { ...state, error: event.error };

    case "aiThinkingSet":
      return state.aiThinking === event.aiThinking ? state : { ...state, aiThinking: event.aiThinking };

    case "openingRequestSet":
      return { ...state, openingRequest: { ...state.openingRequest, ...event.openingRequest } };

    case "canvasOpened":
      return withCanvasTransition(state, (nextState) => {
        applyOpenCanvas(nextState, event.request);
      });

    case "canvasClosed":
      return withCanvasTransition(state, (nextState) => applyCloseCanvas(nextState, event.byCustomer));

    case "canvasResetForNewTurn":
      return withCanvasTransition(state, applyResetCanvasForNewTurn);

    case "canvasCleared":
      return withCanvasTransition(state, applyClearCanvas);

    case "canvasCreatedEntityIdSet":
      return withCanvasTransition(state, (nextState) => {
        applySetCanvasCreatedEntityId(nextState, event.created);
      });
  }
};
