import type { ChatMessage } from "./chat-message";

/** The phone call's started / ended markers — the call marker chip, structural context, never owed work. */
export const PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES: readonly string[] = ["PHONE_CALL_STARTED", "PHONE_CALL_ENDED"];
/** The loop dropped a repeat of an already delivered text — bookkeeping behind the agent's answer, never owed work. */
const REPEATED_TEXT_SUPPRESSED_SIGNAL_SOURCE = "REPEATED_TEXT_SUPPRESSED";
/** The platform wrote a widget on its own (e.g. a follow-up question under the final text) — a notice, never owed work. */
const PLATFORM_WIDGET_EMITTED_SIGNAL_SOURCE = "PLATFORM_WIDGET_EMITTED";
/** The frontend's render report of a message's widgets — context for the next turn, never owed work. */
const FRONTEND_WIDGET_RENDER_REPORT_SIGNAL_SOURCE = "FRONTEND_WIDGET_RENDER_REPORT";
/**
 * The SIGNAL rows that owe no work — the backend's `owesWork() === false` on a SIGNAL row, pinned by
 * `signal-source-parity.json` (`_signal-source-parity.spec.ts`).
 */
export const SIGNAL_SOURCES_THAT_OWE_NO_WORK: readonly string[] = [
  ...PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES,
  REPEATED_TEXT_SUPPRESSED_SIGNAL_SOURCE,
  PLATFORM_WIDGET_EMITTED_SIGNAL_SOURCE,
  FRONTEND_WIDGET_RENDER_REPORT_SIGNAL_SOURCE,
];

/**
 * Whether the newest WIRE message is the customer's own, still unanswered — the backend's `hasUnansweredInboundMessage`
 * seen from the frontend, walked the same way: ERROR / CHECKPOINT rows are bookkeeping (never rendered, never an
 * answer); a TOOL_MESSAGE (a tool's own widget to the customer, stored role ASSISTANT) is NOT the agent's answer — a
 * customer question followed only by a tool's status widget stays owed; the phone-call lifecycle chips are structural
 * context, never owed work, and so is the platform's notice that it wrote a widget on its own; any other trailing SIGNAL (a widget click, a memory-conflict clarify) IS owed work, exactly
 * like a user message. Only then does a failed run have something to RETRY — the persisted
 * message is re-driven server-side instead of asking the customer to send it again. A walk that diverges from the
 * backend's shows the error card without the button while the backend would accept the retry — keep the two in step.
 *
 * The slice keeps `messages` ordered by `indexInConversation`; the sort here is belt-and-suspenders for a caller that
 * hands in an unordered page, not a fix for an upstream ordering defect.
 */
export const lastWireMessageIsUnansweredUserMessage = (messages: ChatMessage[] | null): boolean => {
  if (!messages?.length) {
    return false;
  }
  const sorted = [...messages].sort((a, b) => (a.indexInConversation ?? 0) - (b.indexInConversation ?? 0));
  for (let position = sorted.length - 1; position >= 0; position--) {
    const message = sorted[position];
    if (
      message.messageType === "ERROR" ||
      message.messageType === "CHECKPOINT" ||
      message.messageType === "TOOL_MESSAGE"
    ) {
      continue;
    }
    if (message.messageType === "SIGNAL") {
      if (SIGNAL_SOURCES_THAT_OWE_NO_WORK.includes(message.signalSource ?? "")) {
        continue;
      }
      return true;
    }
    return message.authorType === "USER";
  }
  return false;
};
