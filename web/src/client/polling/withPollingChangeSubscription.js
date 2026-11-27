import { createPollingConversationChangeSubscription, } from "./createPollingConversationChangeSubscription";
/**
 * A binding without push, with the polling default as its subscription. Every port function goes to the binding; the
 * three that make the server start a turn on an existing conversation (post, signal, retry) tell the poller once the
 * server accepted them, so the first read does not wait for the idle interval. A start needs no notice: the chat
 * subscribes to the new conversation afterwards, and a subscription reads at once.
 */
class PollingAgentsConversationClient {
    binding;
    polling;
    getSelectableModels;
    composeSentMessageText;
    getMessageSentPayload;
    getConversationRunState;
    getAccountRealtimeMessages;
    startPurpose;
    listConversationsOfSubject;
    getConversationSubject;
    listSubjectsOfConversations;
    constructor(binding, polling) {
        this.binding = binding;
        this.polling = polling;
        // The optional functions exist exactly when the binding has them (the chat reads their presence).
        this.getSelectableModels = binding.getSelectableModels?.bind(binding);
        this.composeSentMessageText = binding.composeSentMessageText?.bind(binding);
        this.getMessageSentPayload = binding.getMessageSentPayload?.bind(binding);
        this.getConversationRunState = binding.getConversationRunState?.bind(binding);
        this.getAccountRealtimeMessages = binding.getAccountRealtimeMessages?.bind(binding);
        this.startPurpose = binding.startPurpose?.bind(binding);
        this.listConversationsOfSubject = binding.listConversationsOfSubject?.bind(binding);
        this.getConversationSubject = binding.getConversationSubject?.bind(binding);
        this.listSubjectsOfConversations = binding.listSubjectsOfConversations?.bind(binding);
    }
    getConversationMetadata = (conversationId) => this.binding.getConversationMetadata(conversationId);
    getNewestMessagesPage = (conversationId, pageSize) => this.binding.getNewestMessagesPage(conversationId, pageSize);
    getOlderMessagesPage = (conversationId, beforeIndex, pageSize) => this.binding.getOlderMessagesPage(conversationId, beforeIndex, pageSize);
    getChangedMessages = (conversationId, request) => this.binding.getChangedMessages(conversationId, request);
    startConversation = (request) => this.binding.startConversation(request);
    postMessage = async (conversationId, message) => {
        const postedMessage = await this.binding.postMessage(conversationId, message);
        this.polling.notifyLocalActivity(conversationId);
        return postedMessage;
    };
    injectSignal = async (conversationId, signal) => {
        await this.binding.injectSignal(conversationId, signal);
        this.polling.notifyLocalActivity(conversationId);
    };
    retryConversation = async (conversationId) => {
        await this.binding.retryConversation(conversationId);
        this.polling.notifyLocalActivity(conversationId);
    };
    renameConversation = (conversationId, title) => this.binding.renameConversation(conversationId, title);
    deleteConversation = (conversationId) => this.binding.deleteConversation(conversationId);
    listConversations = (agentSpaceKey, options) => this.binding.listConversations(agentSpaceKey, options);
    searchConversations = (text, options) => this.binding.searchConversations(text, options);
    searchConversationMessages = (conversationId, text, options) => this.binding.searchConversationMessages(conversationId, text, options);
    getConversationActivity = (conversationId, turnMessageId) => this.binding.getConversationActivity(conversationId, turnMessageId);
    subscribeToConversationChanges = (conversationId, onSignal, onEnded) => this.polling.subscribeToConversationChanges(conversationId, onSignal, onEnded);
}
/**
 * The client with live updates: a client that brings its own `subscribeToConversationChanges` (a push
 * transport — the host application's Firebase binding) is returned as it is; any other gets the polling default over its
 * `getConversationRunState` when it has one, else over its `getConversationMetadata`
 * (`createPollingConversationChangeSubscription`, `options` tune the intervals). A post, a signal or a retry the server
 * accepted reads at once. The default `AgentChat` calls this by itself.
 */
export const withPollingChangeSubscription = (client, options) => {
    if (client.subscribeToConversationChanges)
        return client;
    return new PollingAgentsConversationClient(client, createPollingConversationChangeSubscription(client.getConversationRunState ? client.getConversationRunState.bind(client) : client.getConversationMetadata.bind(client), options));
};
