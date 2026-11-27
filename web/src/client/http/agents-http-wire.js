/**
 * The wire shapes of the module's own conversation routes (`AgentConversationReadsTrait`, `AgentConversationWritesTrait`,
 * `AgentConversationStartTrait`, `AgentSelectableModelsTrait`): the response DTOs and the entities they serialize, as far
 * as the HTTP binding reads them. Every field is optional on the wire — a read hides some (the forensics outside an admin
 * session, the summary on message rows), and an application may derive the classes — so the mappers
 * (`agents-http-mappers.ts`) default each one. An entity set arrives as `{ elements: [...] }`; the mappers also take a
 * plain array.
 */
export {};
