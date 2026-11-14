// `@mgamadeus/ddd-agents/chat` — the agent chat: the default chat (`AgentChat`), the controller behind it, the chat
// store with its hooks, the default slot components, the conversation view and the chat-bound widget provider.
export { AgentChat, type AgentChatProps } from "../chat/AgentChat/AgentChat";
export {
  AgentChatController,
  type AgentChatActivityHistoryProps,
  type AgentChatActivityProps,
  type AgentChatControllerProps,
  type AgentChatSendModelSource,
} from "../chat/controller/AgentChatController";
export { AgentChatActivityIndicator } from "../chat/AgentChatActivityIndicator/AgentChatActivityIndicator";
export { AgentChatActivityHistory } from "../chat/AgentChatActivityHistory/AgentChatActivityHistory";
export { ConversationClosedBar, type ConversationClosedBarProps } from "../chat/ConversationClosedBar/ConversationClosedBar";
export * from "../chat/store";
export { useAgentsConversationRunStatus } from "../chat/controller/use-conversation-live-updates";
export {
  useConversationMessageSearch,
  useConversationsSearch,
  type ConversationMessageSearchState,
  type ConversationsSearchState,
} from "../chat/controller/use-conversation-search";
export { deriveAgentActivity, type AgentActivity } from "../chat/controller/derive-agent-activity";
export type { ActivityTicker, ActivityTickerTurn } from "../chat/controller/use-activity-ticker";
export type { ChatScrollTarget } from "../chat/controller/chat-scroll-target";
export type { ChatMessage } from "../chat/chat-message";
export type { ChatAssistantCategory, ChatAssistantIdentity, ChatAssistantOpening } from "../chat/chat-assistant-identity";
export type { SendMessageHandler, SendMessageOptions } from "../chat/conversation-send-message";
export { ConversationView, type ConversationViewProps } from "../chat/ConversationView";
export { ConversationErrorCard, type ConversationErrorCardProps } from "../chat/ConversationErrorCard/ConversationErrorCard";
export { ConversationMessageInputAttachmentsErrorBox } from "../chat/ConversationMessageInput/ConversationMessageInputAttachments/ConversationMessageInputAttachmentsErrorBox";
export { ConversationMessageInputAttachmentsPreviewStrip } from "../chat/ConversationMessageInput/ConversationMessageInputAttachments/ConversationMessageInputAttachmentsPreviewStrip";
export { normalizeServerMessageToMarkdown } from "../chat/normalize-server-message-to-markdown";
// The widget provider bound to the chat store (the chat-free callbacks are in `./renderer`) and the canvas restore.
export { ConversationWidgetProvider, type ConversationWidgetProviderProps } from "../renderer/ConversationWidgetContext";
export { CanvasRestoreOnMount, type CanvasRestoreOnMountProps } from "../renderer/canvas/CanvasRestoreOnMount";
