import type { AgentsConversationClient } from "../AgentsConversationClient";
import type {
  AgentsConversation,
  ChangedConversationMessages,
  ChangedMessagesRequest,
  ConversationActivity,
  ConversationAttachmentReference,
  ConversationChangeSignal,
  ConversationListQueryOptions,
  ConversationMessageSearchHit,
  ConversationMessageSentPayload,
  ConversationMessagesPage,
  ConversationSearchGroup,
  ConversationSearchOptions,
  ConversationSignal,
  ConversationSubscriptionEndReason,
  PostedMessage,
  SelectableModel,
  StartedConversation,
} from "../../contract/conversation";
import {
  createPollingConversationChangeSubscription,
  type PollingConversationChangeSubscription,
  type PollingConversationChangeSubscriptionOptions,
} from "./createPollingConversationChangeSubscription";

/** A conversation client whose `subscribeToConversationChanges` is present. */
export type AgentsConversationClientWithChangeSubscription = AgentsConversationClient &
  Required<Pick<AgentsConversationClient, "subscribeToConversationChanges">>;

/**
 * A binding without push, with the polling default as its subscription. Every port function goes to the binding; the
 * three that make the server start a turn on an existing conversation (post, signal, retry) tell the poller once the
 * server accepted them, so the first read does not wait for the idle interval. A start needs no notice: the chat
 * subscribes to the new conversation afterwards, and a subscription reads at once.
 */
class PollingAgentsConversationClient implements AgentsConversationClientWithChangeSubscription {
  readonly getSelectableModels?: () => Promise<SelectableModel[]>;
  readonly composeSentMessageText?: (text: string, attachments: ConversationAttachmentReference[] | undefined) => string;
  readonly getMessageSentPayload?: (conversationId: number, messageId: number) => Promise<ConversationMessageSentPayload>;

  constructor(
    protected readonly binding: AgentsConversationClient,
    protected readonly polling: PollingConversationChangeSubscription,
  ) {
    // The optional functions exist exactly when the binding has them (the chat reads their presence).
    this.getSelectableModels = binding.getSelectableModels?.bind(binding);
    this.composeSentMessageText = binding.composeSentMessageText?.bind(binding);
    this.getMessageSentPayload = binding.getMessageSentPayload?.bind(binding);
  }

  getConversationMetadata = (conversationId: number): Promise<AgentsConversation> =>
    this.binding.getConversationMetadata(conversationId);

  getNewestMessagesPage = (conversationId: number, pageSize: number): Promise<ConversationMessagesPage> =>
    this.binding.getNewestMessagesPage(conversationId, pageSize);

  getOlderMessagesPage = (conversationId: number, beforeIndex: number, pageSize: number): Promise<ConversationMessagesPage> =>
    this.binding.getOlderMessagesPage(conversationId, beforeIndex, pageSize);

  getChangedMessages = (conversationId: number, request: ChangedMessagesRequest): Promise<ChangedConversationMessages> =>
    this.binding.getChangedMessages(conversationId, request);

  startConversation = (request: Parameters<AgentsConversationClient["startConversation"]>[0]): Promise<StartedConversation> =>
    this.binding.startConversation(request);

  postMessage = async (
    conversationId: number,
    message: { text: string; model?: string; attachments?: ConversationAttachmentReference[] },
  ): Promise<PostedMessage> => {
    const postedMessage = await this.binding.postMessage(conversationId, message);
    this.polling.notifyLocalActivity(conversationId);
    return postedMessage;
  };

  injectSignal = async (conversationId: number, signal: ConversationSignal): Promise<void> => {
    await this.binding.injectSignal(conversationId, signal);
    this.polling.notifyLocalActivity(conversationId);
  };

  retryConversation = async (conversationId: number): Promise<void> => {
    await this.binding.retryConversation(conversationId);
    this.polling.notifyLocalActivity(conversationId);
  };

  renameConversation = (conversationId: number, title: string): Promise<AgentsConversation> =>
    this.binding.renameConversation(conversationId, title);

  deleteConversation = (conversationId: number): Promise<void> => this.binding.deleteConversation(conversationId);

  listConversations = (agentSpaceKey: number, options?: ConversationListQueryOptions): Promise<AgentsConversation[]> =>
    this.binding.listConversations(agentSpaceKey, options);

  searchConversations = (text: string, options?: ConversationSearchOptions): Promise<ConversationSearchGroup[]> =>
    this.binding.searchConversations(text, options);

  searchConversationMessages = (
    conversationId: number,
    text: string,
    options?: ConversationSearchOptions,
  ): Promise<ConversationMessageSearchHit[]> => this.binding.searchConversationMessages(conversationId, text, options);

  getConversationActivity = (conversationId: number, turnMessageId?: number | null): Promise<ConversationActivity> =>
    this.binding.getConversationActivity(conversationId, turnMessageId);

  subscribeToConversationChanges = (
    conversationId: number,
    onSignal: (signal: ConversationChangeSignal) => void,
    onEnded: (reason: ConversationSubscriptionEndReason) => void,
  ): (() => void) => this.polling.subscribeToConversationChanges(conversationId, onSignal, onEnded);
}

/**
 * The client with live updates: a client that brings its own `subscribeToConversationChanges` (a push
 * transport — the host application's Firebase binding) is returned as it is; any other gets the polling default over its
 * `getConversationMetadata` (`createPollingConversationChangeSubscription`, `options` tune the intervals). The default
 * `AgentChat` calls this by itself.
 */
export const withPollingChangeSubscription = (
  client: AgentsConversationClient,
  options?: PollingConversationChangeSubscriptionOptions,
): AgentsConversationClientWithChangeSubscription => {
  if (client.subscribeToConversationChanges) return client as AgentsConversationClientWithChangeSubscription;
  return new PollingAgentsConversationClient(
    client,
    createPollingConversationChangeSubscription(client.getConversationMetadata.bind(client), options),
  );
};
