import { classNames } from "../../provider/class-names";
import type { ChatAssistantCategory, ChatAssistantIdentity } from "../chat-assistant-identity";
import React, { Fragment, useMemo, useRef } from "react";
import {
  ConversationMessageUser,
  ConversationMessageUserTheme,
} from "../ConversationMessage/ConversationMessageUser/ConversationMessageUser";
import { ConversationMessageWidgets } from "../ConversationMessage/ConversationMessageWidgets/ConversationMessageWidgets";
import { widgetNamesInMarkdown } from "../../renderer/markdown-contains-widget";
import { isWidgetClickSignalSource } from "../../renderer/widget-click-signal-sources";
import { normalizeServerMessageToMarkdown } from "../normalize-server-message-to-markdown";
import { assistantMessageHasRenderableMarkdown } from "../assistant-message-has-renderable-markdown";
import { lastWireMessageIsUnansweredUserMessage } from "../last-wire-message-is-unanswered-user-message";
import { ConversationMessageSystem } from "../ConversationMessage/ConversationMessageSystem/ConversationMessageSystem";
import { ConversationCallMarkerChip } from "../ConversationMessage/ConversationCallMarkerChip/ConversationCallMarkerChip";
import { ConversationTaskInstructionDisclosure } from "../ConversationMessage/ConversationTaskInstructionDisclosure/ConversationTaskInstructionDisclosure";
import { ConversationMessageThinking } from "../ConversationMessage/ConversationMessageWidgets/ConversationMessageThinking/ConversationMessageThinking";
import { ConversationErrorCard } from "../ConversationErrorCard/ConversationErrorCard";
import { ConversationErrorType } from "../store/conversation-error-type";
import type { ChatMessage } from "../chat-message";
import { PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES } from "../last-wire-message-is-unanswered-user-message";
import styles from "../Conversation.module.scss";
import { Ref, RefObject } from "react";

interface ConversationMessageListProps {
  className?: string;
  messages: ChatMessage[] | null;
  currentCategory?: ChatAssistantCategory;
  aiPromptExtension?: ChatAssistantIdentity;
  isDebugMode: boolean;
  aiThinking: boolean;
  /**
   * Custom node mounted at the tail of the message list instead of the default
   * (thinking-only) ConversationMessageThinking. Rendered permanently — used by
   * A host to show the animated agent face, which idles between turns.
   */
  thinkingIndicator?: React.ReactNode;
  /**
   * Hide the assistant header (icon + name + "Assistant") and the copy button
   * on assistant messages. Used by a host, which renders bare messages.
   */
  hideAssistantMeta?: boolean;
  /**
   * Theme for user-message bubbles. When omitted, falls back to the legacy
   * behavior: "light" for generic (no-agent) chat, "default" otherwise. A host
   * forces "dark".
   */
  userMessageTheme?: ConversationMessageUserTheme;
  error: ConversationErrorType | null;
  /** Retry-After seconds of a 429 quota rejection — forwarded to the error card for the "try again in …" wording. */
  rateLimitRetryAfterSeconds?: number | null;
  /**
   * Host-bound retry of a FAILED run. Offered on the error card only while the newest wire
   * message is the customer's own, still unanswered — the persisted message is re-driven server-side.
   */
  onRetryFailedRun?: () => Promise<void>;
  messagesBottomRef: RefObject<HTMLDivElement | null>;
  messagesListRef?: Ref<HTMLDivElement | null>;
  /**
   * Opt-in staggered fade-in of a fresh reply's markdown blocks — see useFreshMessageBlockReveal;
   * forwarded to ConversationMessageWidgets. Default off.
   */
  enableFreshMessageBlockReveal?: boolean;
  /**
   * Id of the ONE user message to render as a collapsed task-instruction disclosure instead of an owner bubble:
   * the machine-composed seed instruction of a SCHEDULE-spawned run (UX overhaul W4 item 19). The host resolves
   * the id (AgentChat: first USER message once the history is loaded to its start); undefined renders every
   * user message normally.
   */
  taskInstructionMessageId?: number;
  /**
   * Host content rendered right after one message of the thread — also after a message the list itself does not show
   * (a work signal that started an agent turn). The agent chat puts each turn's activity card under the message that
   * started the turn. The content is a row of the list's flex column, so the list's gap stands above it; the list
   * publishes that gap as `--chat-messages-gap`. Undefined (every other host) renders the rows exactly as before.
   */
  renderAfterMessage?: (message: ChatMessage) => React.ReactNode;
  /** The backend refuses every widget signal of a closed conversation, so no widget renders its controls. */
  isConversationClosed?: boolean;
}

const WIDGET_VISIBILITY_WINDOW = 15;

/** The canonical widget names an assistant-role message carries (empty for every other row). */
const widgetNamesOfMessage = (message: ChatMessage): string[] =>
  message.role === "ASSISTANT" ? widgetNamesInMarkdown(normalizeServerMessageToMarkdown(message.message)) : [];

/**
 * The id of the newest widget-carrying assistant-role message the owner has not answered yet — null when the newest
 * such message is followed by a USER message or by a widget-click signal (the owner moved on; its controls stay
 * read-only after a reload, as before). Walks from the end so the first widget hit is the newest.
 */
export const findUnansweredWidgetMessageId = (messages: ChatMessage[]): string | number | null => {
  for (let index = messages.length - 1; index >= 0; index -= 1) {
    const message = messages[index];
    if (message.role === "USER") return null;
    if (message.messageType === "SIGNAL" && isWidgetClickSignalSource(message.signalSource)) return null;
    if (widgetNamesOfMessage(message).length > 0) return message.id ?? null;
  }
  return null;
};

/**
 * The ids of every widget-carrying assistant-role message that a LATER assistant-role message supersedes with a
 * widget of the same name. Pure, order-based: the newest widget of a type is the live one.
 */
export const findSupersededWidgetMessageIds = (messages: ChatMessage[]): Set<string | number> => {
  const supersededIds = new Set<string | number>();
  const newestMessageIdByWidgetName = new Map<string, string | number>();
  for (let index = messages.length - 1; index >= 0; index -= 1) {
    const message = messages[index];
    if (message.id == null) continue;
    for (const widgetName of widgetNamesOfMessage(message)) {
      const newestMessageId = newestMessageIdByWidgetName.get(widgetName);
      if (newestMessageId === undefined) {
        newestMessageIdByWidgetName.set(widgetName, message.id);
      } else if (newestMessageId !== message.id) {
        // a second fence of the same type INSIDE one message is not a successor of itself
        supersededIds.add(message.id);
      }
    }
  }
  return supersededIds;
};

export const ConversationMessageList = ({
  className,
  messages,
  currentCategory,
  aiPromptExtension,
  isDebugMode,
  aiThinking,
  thinkingIndicator,
  hideAssistantMeta,
  userMessageTheme,
  error,
  rateLimitRetryAfterSeconds,
  onRetryFailedRun,
  messagesBottomRef,
  messagesListRef,
  enableFreshMessageBlockReveal,
  taskInstructionMessageId,
  renderAfterMessage,
  isConversationClosed = false,
}: ConversationMessageListProps) => {
  const list = messages ?? [];
  const hideWidgetsBeforeIndex = Math.max(0, list.length - WIDGET_VISIBILITY_WINDOW);
  const userTheme: ConversationMessageUserTheme =
    userMessageTheme ?? (aiPromptExtension === undefined ? "light" : "default");

  // Reload promotion: on resume-from-history the slice never tags messages `fresh` (those are history), so the
  // newest reply's widgets would render read-only — a customer who reloads mid-setup would find the question's
  // buttons gone. Evaluated ONCE the first time messages arrive: the newest assistant-role message that carries a
  // widget is rendered INTERACTIVE, provided the owner has not answered it yet (no later USER message, no later
  // widget-click signal). "Newest widget-carrying", not "newest assistant message": a widget a TOOL writes (e.g. a
  // confirm widget of the app's first-run setup) is indexed before the agent's own line under it, so it is never the last
  // message — and read-only it would tell the owner the match "was already handled" while the setup waits for that
  // very click. It is NOT rendered `justDelivered`: that flag is the one-shot side-effect gate (canvas open, draft
  // creation) and must never re-fire on a reload — a canvas that was open comes back through the canvas model's own
  // restore, not through this promotion. (Detection lives here, in the render tree — importing the widget registry
  // into the store-init slice case forms a TDZ cycle.)
  const reloadReopenMessageIdRef = useRef<string | number | null | undefined>(undefined);
  if (reloadReopenMessageIdRef.current === undefined && list.length > 0) {
    reloadReopenMessageIdRef.current = findUnansweredWidgetMessageId(list);
  }
  // A widget the owner already moved past: a LATER assistant-role message carrying a widget of the SAME type retires
  // the earlier one's controls (the newest identify widget is the live one — the owner corrected the seed, a new
  // search wrote a new widget; the superseded one must not accept a confirm for the wrong business). Recomputed as
  // the list grows, so it also applies in-session where the slice keeps the earlier message `fresh`.
  const supersededWidgetMessageIds = useMemo(() => findSupersededWidgetMessageIds(messages ?? []), [messages]);
  const renderMessageRow = (message: ChatMessage, index: number): React.ReactNode => {
    const hideWidgets = index < hideWidgetsBeforeIndex;
    // Phone-call lifecycle signals are the ONE user-visible signal kind: the timeline renders them as a
    // centered call marker chip (started / ended + duration / not reached).
    if (message.messageType === "SIGNAL" && PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES.includes(message.signalSource ?? "")) {
      return (
        <ConversationCallMarkerChip
          key={message.id ?? index}
          signalSource={message.signalSource ?? ""}
          signalText={message.message ?? ""}
        />
      );
    }
    // Every OTHER out-of-band SIGNAL message (nudge, memory-conflict, widget events) is model-only —
    // never shown to the customer. Stored role=SYSTEM, so this guard precedes the role branches.
    if (message.messageType === "SIGNAL") return null;
    // ERROR messages carry the raw internal failure (e.g. a provider 400 with the full error body) — never shown to
    // the customer. The user-facing "something went wrong" is the ConversationErrorCard below, rendered from runState=FAILED.
    if (message.messageType === "ERROR") return null;

    let messageNode: React.ReactNode = null;
    if (message.role === "USER") {
      // Hide tool-result reports from the chat UI (they are model-internal)
      if (message.message?.startsWith("/toolresult ")) return null;
      // The SCHEDULE-run seed instruction is machine-composed — render it as a collapsed disclosure, never as
      // the owner's own words (see taskInstructionMessageId).
      messageNode =
        taskInstructionMessageId != null && message.id === taskInstructionMessageId ? (
          <ConversationTaskInstructionDisclosure instructionText={message.message ?? ""} />
        ) : (
          <ConversationMessageUser text={message.message ?? ""} theme={userTheme} />
        );
    } else if (message.role === "ASSISTANT") {
      // A text-less assistant turn has nothing to show the customer, and rendering it anyway emits an empty
      // markdown container that still consumes a full `$x3` message-list gap. Its agentic trace fields are
      // the admin console's concern (AgentConversationInspector, its own timeline), not this chat's.
      if (!assistantMessageHasRenderableMarkdown(message.message)) return null;
      messageNode = (
        <ConversationMessageWidgets
          key={message.id}
          interactive={
            !isConversationClosed &&
            !(message.id != null && supersededWidgetMessageIds.has(message.id)) &&
            ((message.fresh ?? false) || (message.id != null && message.id === reloadReopenMessageIdRef.current))
          }
          justDelivered={message.fresh ?? false}
          aiPromptExtensionCategory={currentCategory}
          aiPromptExtension={aiPromptExtension}
          message={message.message}
          messageId={message.id}
          hideWidgets={hideWidgets}
          hideAssistantMeta={hideAssistantMeta}
          enableFreshMessageBlockReveal={enableFreshMessageBlockReveal}
        />
      );
    } else if (message.role === "SYSTEM" && isDebugMode) {
      messageNode = (
        <ConversationMessageSystem
          aiPromptExtensionCategory={currentCategory}
          aiPromptExtension={aiPromptExtension}
          message={message.message!}
        />
      );
    }
    if (messageNode === null) return null;

    // `display: contents`-free anchor row carrying the message id, so search results (and any other consumer) can
    // locate a message in the DOM and scroll it into view. The row is a flex column so user bubbles keep their
    // `align-self: flex-end` right-alignment and assistant messages keep their full width.
    return (
      <div
        key={message.id ?? `pending-${message.role}-${index}`}
        className={styles.messageRow}
        data-message-id={message.id ?? undefined}
        // Role marker so the scroll logic can locate the last USER row (the question) to pin it to the top on send.
        data-message-role={message.role}
      >
        {messageNode}
      </div>
    );
  };

  return (
    <div className={classNames(styles.chatMessages, className)} ref={messagesListRef}>
      {list.map((message, index) =>
        renderAfterMessage ? (
          // Keyed like the row itself and present for every row, so a node that appears after a message never
          // changes the row's position in the tree (and never remounts the message and its widgets).
          <Fragment key={message.id ?? `pending-${message.role}-${index}`}>
            {renderMessageRow(message, index)}
            {renderAfterMessage(message)}
          </Fragment>
        ) : (
          renderMessageRow(message, index)
        ),
      )}
      {/* A custom indicator is always mounted (it idles between turns); the
          default one only appears while the agent is thinking. */}
      {thinkingIndicator ?? (
        (aiThinking ? (
          <ConversationMessageThinking
            aiPromptExtensionCategory={currentCategory}
            aiPromptExtension={aiPromptExtension}
          />
        ) : null)
      )}

      {error !== null ? (
        <ConversationErrorCard
          errorType={error!}
          rateLimitRetryAfterSeconds={rateLimitRetryAfterSeconds}
          onRetry={
            error === ConversationErrorType.AGENT_ERROR && lastWireMessageIsUnansweredUserMessage(messages)
              ? onRetryFailedRun
              : undefined
          }
        />
      ) : null}
      <div className={styles.messagesBottom} ref={messagesBottomRef} />
    </div>
  );
};
