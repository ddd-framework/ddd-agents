// `@mgamadeus/ddd-agents/client` — the conversation client port, the React context that hands it to package code and
// the polling default of the live updates for a binding without push.
export * from "../client/AgentsConversationClient";
export * from "../client/AgentsConversationClientContext";
export * from "../client/polling/createPollingConversationChangeSubscription";
export * from "../client/polling/withPollingChangeSubscription";
// The HTTP binding of the module's own conversation routes: the factory, the wire shapes of the routes and the mappers
// from the wire onto the contract (for a host that writes its own binding over the same routes).
export * from "../client/http/createAgentsHttpConversationClient";
export * from "../client/http/agents-http-wire";
export * from "../client/http/agents-http-mappers";
export { composeAttachmentMessageText } from "../client/compose-attachment-message-text";
