import type { AgentsClientError } from "../../contract/conversation";

/**
 * The error card the chat shows. A plain object with string values (not a TypeScript `enum`), so the chat store, the
 * view and an app's older chat state share one vocabulary without a nominal type in between.
 */
export const ConversationErrorType = {
  AI_BUDGET: "AI_BUDGET",
  /**
   * HTTP 429 from an AI endpoint — a per-minute/hour/day usage quota was hit. Distinct from AI_BUDGET (monthly budget):
   * this one clears on its own after the Retry-After window.
   */
  RATE_LIMIT: "RATE_LIMIT",
  NETWORK_ERROR: "NETWORK_ERROR",
  CONVERSATION_NOT_FOUND: "CONVERSATION_NOT_FOUND",
  /**
   * The backend run FAILED (e.g. the model provider returned an error). The raw provider error is admin-only (never
   * shown here); the owner gets a generic "something went wrong" card.
   */
  AGENT_ERROR: "AGENT_ERROR",
  /**
   * The conversation is COMPLETED/CANCELLED and refuses further owner input. Set from the server's stable error code
   * `CONVERSATION_CLOSED` or by the chat's own pre-send guard; the error card owns the copy.
   */
  CONVERSATION_CLOSED: "CONVERSATION_CLOSED",
} as const;

export type ConversationErrorType = (typeof ConversationErrorType)[keyof typeof ConversationErrorType];

/**
 * The error card of a refused send (post or start), read from the port's error. Only the stable outcomes select a
 * dedicated card; every other refusal is a generic network error — the same classification the older chat applies to
 * the server's error codes (`AI_BUDGET_REACHED`, `RATE_LIMIT_EXCEEDED`, `CONVERSATION_CLOSED`).
 */
export const readConversationErrorTypeOfRefusedSend = (error: AgentsClientError): ConversationErrorType => {
  if (error.kind === "budget") return ConversationErrorType.AI_BUDGET;
  if (error.kind === "rateLimit") return ConversationErrorType.RATE_LIMIT;
  if (error.kind === "closed") return ConversationErrorType.CONVERSATION_CLOSED;
  return ConversationErrorType.NETWORK_ERROR;
};
