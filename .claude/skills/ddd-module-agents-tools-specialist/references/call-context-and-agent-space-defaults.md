# Call context and agent-space defaults

**Reader:** host developer

Contents
- The provenance a tool receives
- The argument the space fills
- The space a call targets
- The account a tool reads and writes for
- The AI usage envelope and the budget
- Time

## The provenance a tool receives

A tool that declares a parameter typed `AgentToolCallProvenance`
(`DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance`) gets the call context of the agent loop, built by
`AgentToolCallExecutionService::executeToolCall()` for every call. It implements `MCPServerProvidedInput`: the model
never sees it in the schema and can never forge it. On the HTTP path it is an empty instance (all fields null).

| Field or method | What it holds |
|---|---|
| `$sourceUserMessage`, `getSourceUserMessageId()` | the owner message the turn started from |
| `$toolCallOriginMessage`, `getToolCallOriginMessageId()` | the assistant message that emitted the call |
| `$conversationId` | the conversation the call runs in (a sub-agent's own conversation when delegated) |
| `$rootConversationId`, `getTreeRootConversationIdForToolCall()` | the owner's thread at the root of the delegation tree; a tool that must reach the owner targets this |
| `$agentSpace`, `$agentSpaceId` | the space the conversation works for; null for a conversation without a space |
| `$conversationMessages`, `getOwnerThreadMessages()` | the live messages (what a consent check reads); null in a delegation child for the owner thread |
| `$scheduleId` | set when the conversation is a scheduled run |
| `$toolName`, `$toolCallId` | the running tool and the provider's id of this call |
| `$conversation`, `getConversationToWriteInto()` | the live conversation a tool's row is written into |
| `$isEvalHarnessCall` | a call the evaluation harness makes itself; consent gates do not apply to it |
| `isInDelegationChild()`, `isOwnerOnThePhoneLine()` | the two questions a widget-writing tool asks |
| `getTimezone()`, `isTimezoneUnverified()` | the zone the call thinks in |

The provenance is a transient carrier: its held objects carry `HideProperty`, it is never persisted and never
returned to the model.

## The argument the space fills

The package's tools name the space with the argument `agentSpaceId`
(`AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID`). In a conversation WITH a space:

1. The executor fills the argument when the model left it out, or sent 0 or an empty string, before the gates and the
   hydration (`MCPToolExecutorService::applyAgentSpaceDefaultsToToolArguments()` →
   `AgentSpacesService::applyAgentSpaceDefaultsToToolArguments()`). It fills it only when a request DTO of the tool
   declares the property. An argument the model passed always wins — work on another space stays possible.
2. The agent's tool schema puts `DESCRIPTION_PREFIX_OF_TOOL_ARGUMENT_FILLED_FROM_AGENT_SPACE` ("OPTIONAL — omit it …")
   in front of the argument's description (`ToolDefinition::toOpenAiToolSchema()` with
   `AgentSpacesService::getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()`). It would also drop the argument
   from a required list, but the generated schema carries none.

In a conversation without a space and on the HTTP path nothing is filled and the schema stays as declared.

**A host whose tools name the space by an id of its own entity** overrides, in its subclass of
`AgentSpacesService`, `applyAgentSpaceDefaultsToToolArguments()` (fill its argument from the space),
`getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()` (name its argument) and `findAgentSpaceOfToolCall()`
(resolve its argument to a space, with the same usability check). How the package finds such a subclass:
`ddd-module-agents-specialist`, override points. How the space maps onto the
host's entity is the entry skill's (`ddd-module-agents-specialist`).

A tool reads the space from its request DTO, never from `$agentSpaceId` of the provenance: the argument may name
another space, and the executor checked that one.

## The space a call targets

`MCPToolExecutorService::dispatchTool()` asks `AgentSpacesService::findAgentSpaceOfToolCall()` once per call: the
space an argument names, else the conversation's space, else none (the call works at account level). A named space
that does not exist or that the acting account may not use refuses the call before it runs
(`AgentSpacesService::requireAgentSpaceUsableByAccount()`: the space belongs to the account, or the account is an admin with access to
all resources). The answer is handed to every gate — feature flags, automation eligibility, mandate.

## The account a tool reads and writes for

`AgentToolCallProvenance::getConversationOwnerAccountIdOrAuthAccountId($authAccountId)` — the owner of the conversation (a delegation child
carries its root's account), else the authenticated account. Scope rows on this account as a query filter, not on
the read rights alone: the rights are lifted inside many service paths of a turn, and the authenticated account is
not always the owner (an admin driving a conversation).

## The AI usage envelope and the budget

Every tool call runs inside an AI usage envelope of `mgamadeus/ddd-ai` (`AIUsageEnvelopesService`): in the loop the
turn's envelope is reused; on a path without one (HTTP, a console command of the host) the executor opens
`AgentAIUsageScope::ENVELOPE_MCP_TOOL_CALL` for the authenticated account around the dispatch and the rendering. So a
model call a tool makes is attributed without any work in the tool. `WithAIEnvelope` on a tool method is read by
nothing (the executor invokes the method by reflection); a tool that wants a scope of its own opens it itself —
`AIUsageEnvelopesService::openEnvelope($accountId, $scope)`, then `AIUsageEnvelopesService::closeEnvelope($envelopeContext)` in `finally`. A
tool never grants a budget bypass; the grant and the budget are the loop skill's (`ddd-module-agents-loop-specialist`).

## Time

`AgentToolCallProvenance::getTimezone()` is the conversation's zone, or null (HTTP, a conversation without a stored zone: no conversion).
The executor converts arguments and the rendering (schema-and-dtos.md, "Dates and time zones"); the tool body sees
UTC. The clock itself is the context skill's (`ddd-module-agents-context-specialist`).
