// `@mgamadeus/ddd-agents/chat` — the agent chat: the default chat (`AgentChat`), the controller behind it, the chat
// store with its hooks, the default slot components, the conversation view and the chat-bound widget provider.
export { AgentChat } from "../chat/AgentChat/AgentChat";
export { AgentChatController, } from "../chat/controller/AgentChatController";
// The conversation client of the chat around (the `client` of `AgentChat` or the one `AgentsProvider` mounted).
export { useAgentsConversationClient, useOptionalAgentsConversationClient, } from "../client/AgentsConversationClientContext";
export { AgentChatActivityIndicator, } from "../chat/AgentChatActivityIndicator/AgentChatActivityIndicator";
export { AgentChatActivityStack, ACTIVITY_STACK_VISIBLE_LINES } from "../chat/AgentChatActivityStack/AgentChatActivityStack";
export { AgentChatActivityDots, } from "../chat/AgentChatActivityDots/AgentChatActivityDots";
export { AgentChatActivityHistory } from "../chat/AgentChatActivityHistory/AgentChatActivityHistory";
export { ConversationClosedBar } from "../chat/ConversationClosedBar/ConversationClosedBar";
export * from "../chat/store";
export { useAgentsConversationRunStatus } from "../chat/controller/use-conversation-live-updates";
export { useConversationMessageSearch, useConversationsSearch, } from "../chat/controller/use-conversation-search";
export { deriveAgentActivity } from "../chat/controller/derive-agent-activity";
export { ConversationView } from "../chat/ConversationView";
export { ConversationErrorWidget } from "../chat/ConversationErrorWidget/ConversationErrorWidget";
/** @deprecated Renamed to `ConversationErrorWidget` (and `ConversationErrorWidgetProps`). */
export { ConversationErrorWidget as ConversationErrorCard, } from "../chat/ConversationErrorWidget/ConversationErrorWidget";
export { ConversationMessageInputAttachmentsErrorBox } from "../chat/ConversationMessageInput/ConversationMessageInputAttachments/ConversationMessageInputAttachmentsErrorBox";
export { ConversationMessageInputAttachmentsPreviewStrip } from "../chat/ConversationMessageInput/ConversationMessageInputAttachments/ConversationMessageInputAttachmentsPreviewStrip";
export { normalizeServerMessageToMarkdown } from "../chat/normalize-server-message-to-markdown";
// The SIGNAL sources an app's own message rows read: the phone call's started / ended markers and every source that
// owes no work (never makes a failed run retryable).
export { PHONE_CALL_LIFECYCLE_SIGNAL_SOURCES, SIGNAL_SOURCES_THAT_OWE_NO_WORK, } from "../chat/last-wire-message-is-unanswered-user-message";
// The widget provider bound to the chat store (the chat-free callbacks are in `./renderer`), the canvas restore and the
// mount of a widget a page opens (`?agentWidget=`) with its feature gate and error boundary.
export { ConversationWidgetProvider } from "../renderer/ConversationWidgetContext";
export { CanvasRestoreOnMount } from "../renderer/canvas/CanvasRestoreOnMount";
export { ConversationDeepLinkedWidget, } from "../renderer/ConversationDeepLinkedWidget";
// The building blocks a host composes its own chat surface from: the canvas pane, the message list, the message rows,
// the input with its attachment shapes, and the activity ticker an app's own activity face paces itself by.
export { CanvasPane } from "../chat/CanvasPane/CanvasPane";
export { ConversationMessageList, findUnansweredWidgetMessageId, } from "../chat/ConversationMessageList/ConversationMessageList";
export { ConversationMessageUser, } from "../chat/ConversationMessage/ConversationMessageUser/ConversationMessageUser";
export { ConversationMessageWidgets, } from "../chat/ConversationMessage/ConversationMessageWidgets/ConversationMessageWidgets";
export { ConversationMessageSystem, } from "../chat/ConversationMessage/ConversationMessageSystem/ConversationMessageSystem";
export { ConversationMessageInput, MAX_MESSAGE_LENGTH, } from "../chat/ConversationMessageInput/ConversationMessageInput";
export { ConversationMessageInputAttachmentPreviewWidget, } from "../chat/ConversationMessageInput/ConversationMessageInputAttachments/ConversationMessageInputAttachmentPreviewWidget";
export { useActivityTicker, SUCCESS_BEAT_MS } from "../chat/controller/use-activity-ticker";
export { DEFAULT_AGENT_CHAT_ACTIVITY_TIMING, resolveAgentChatActivityTiming, } from "../chat/controller/chat-activity-timing";
export { formatElapsedClock, isStepStillRunning } from "../chat/controller/activity-ticker";
