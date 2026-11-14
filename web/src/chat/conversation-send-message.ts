import type { ConversationAttachmentReference } from "../contract/conversation";
import { ConversationComposerWidget } from "../renderer/conversation-composer-widget";

/**
 * Everything a chat send carries alongside the plain message text. The object is always passed
 * (a bare `{}` when there is nothing extra) so callers stop juggling a growing tail of positional
 * optional arguments — every extra becomes a named, individually-optional field here instead.
 */
export type SendMessageOptions = {
  /**
   * Invisible START context: a model-visible, user-invisible SIGNAL prepended before the
   * (possibly empty) user message when a dashboard-widget CTA starts the conversation, so the agent's
   * first turn already has it. Empty/omitted for a normal typed message.
   */
  startSignalText?: string;

  /**
   * Uploaded media-library images that ride along with this message. ConversationMessageInput never builds these —
   * it forwards the host-provided `outgoingAttachmentImages`.
   */
  attachments?: ConversationAttachmentReference[];

  /**
   * Composer widgets sent alongside this message — informational for hosts (the message TEXT is already the
   * record: `onSendMessage` receives it with each widget's ```widget fence appended, see
   * `buildMessageTextWithComposerWidgets`). ConversationMessageInput never builds these — it forwards the
   * host-provided `outgoingComposerWidgets`.
   */
  composerWidgets?: ConversationComposerWidget[];

  /**
   * The stable key of the product entry point a NEW conversation is started from (e.g. `hub.starter.R2`): the start
   * reopens the recent conversation of that entry point instead of starting an identical one. Ignored when the
   * message goes into an existing conversation.
   */
  entryPointKey?: string;
};

/**
 * Signature shared by every chat "send" callback across the Conversation components (ConversationMessageInput →
 * ConversationViewBody → ConversationView) and their hosts (AgentChat, Conversation, …). The message text stays a
 * required positional argument; everything else lives in the mandatory `options` object.
 *
 * May return `true`/`false` when the host can tell whether the send was actually accepted (dispatched/posted) or
 * dropped by a caller-side guard (a closed conversation, an in-flight start) — see AgentChat's `handleSendMessage`,
 * whose result feeds `ConversationWidgetCallbacks.sendMessageToChat`. `void` (most callers) reports nothing.
 */
export type SendMessageHandler = (message: string, options: SendMessageOptions) => void | boolean;
