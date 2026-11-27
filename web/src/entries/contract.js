// `@mgamadeus/ddd-agents/contract` — the conversation and message types of the conversation client port.
export * from "../contract/conversation";
// What a live update reads, built from the loaded messages: a binding turns it into its own endpoint's filter.
export { buildChangedMessagesRequest } from "../chat/controller/changed-messages-request";
