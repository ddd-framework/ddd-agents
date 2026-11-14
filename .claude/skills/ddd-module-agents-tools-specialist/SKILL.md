---
name: ddd-module-agents-tools-specialist
description: "Writes MCP tools of mgamadeus/ddd-agents for the agent and MCP clients. Covers MCPPackage, MCPBundle, the MCPController mount, the skill binding, request and response DTOs and the generated schema, MCPToolExecutorService and the tool-call dispatch, markdown results, failure texts and gate refusals, images, result and schema size, the call context AgentToolCallProvenance and agentSpaceId defaults, execution ceilings, parallel calls in child processes and the child-process command, loop-control fields, tools that write a widget, the askOwner closed question and its forced answer step, the meta-tool contract and every tool attribute (MCPToolEffect, MCPToolSummary, MCPToolExecutionTimeout, MCPToolParallelSafe, MCPToolRequiresOwnerConfirmation, MCPToolRequiresMandate, MCPRequiresFeatureFlags). Use when adding or changing an MCP tool or package, exposing a capability to the agent, a tool times out, a read may run in parallel, a tool result or schema is too large, a tool is not found or ambiguous, or testing a tool."
metadata:
  author: mgamadeus
  version: "0.1.0"
  module: mgamadeus/ddd-agents
---

# Agents Tools Specialist

How to write an MCP tool that the agent of `mgamadeus/ddd-agents` and an external MCP client can both call, and how
the package runs it. The entry skill (`ddd-module-agents-specialist`) installs the module; this skill starts where a
host adds its first tool.

## Scope

| This skill owns | Classes |
|---|---|
| The tool layer | `MCPPackage`, `MCPBundle`, `MCPTool`, every attribute and interface in `Entities/MCPPackages/` |
| Schema and documentation | `MCPSchema`, `MCPObjectProperty`, `MCPObjectDefinition`, `MCPPackageDocumentation`, `MCPBundleDocumentation`, `ToolDefinition`, `ToolDefinitions` |
| The MCP DTOs and the HTTP mount | `MCPController`, `DDD\Presentation\Mcp\Dtos\MCPRequestDto`, `MCPToolCallParams`, `MCPToolResponse`, `MCPResponseDto`, `JsonRpcError` |
| The executor | `MCPToolExecutorService`, `ToolResult`, `ToolResultImage`, `ToolCallImageStore`, `ToolResultUntrustedSegments` |
| Dispatch and execution in the loop | `AgentToolCallDispatchService`, `AgentToolCallExecutionService`, `AgentToolCallProvenance`, `AgentRepeatedToolCallCheck`, `ToolCall`, `AIConversationToolCallHistory`, `AgentCompletedToolCallFollowUpsService`, `AgentToolResultFactsService` |
| Parallel calls | `AgentToolCallForkJoinService`, `AgentToolCallFork`, `AIConversationToolCallBatch`, `agentToolCallChildProcess.php` |
| The meta-tool contract and the owner question | `AgentMetaTool`, `AgentMetaTools`, `AgentMetaToolCallAnswer`, `AgentAfterStepRequest`, `AskOwnerTool`, `AskOwnerQuestionCatalogue` |

Not here, one sentence each: owner consent before an outward action is `ddd-module-agents-decisions-and-guards-specialist`
(a tool declares it needs it with `MCPToolRequiresOwnerConfirmation`); standing permissions are
`ddd-module-agents-autonomy-specialist` (a tool declares it with `MCPToolRequiresMandate`); widget classes and reading
an answer are `ddd-module-agents-widgets-specialist` (this skill gives the return contract of a tool that writes one);
each meta tool's own mechanism belongs to the skill that owns it (this skill gives the contract they share); the skill
file that binds a package is `ddd-module-agents-skills-and-purposes-specialist`; tool eviction and the prompt cache
are `ddd-module-agents-context-specialist`.

## Full class names a host extends, implements or calls

| Namespace | Classes |
|---|---|
| `DDD\Domain\AI\Entities\MCPPackages` | `MCPPackage`, `MCPBundle`, `MCPTool` and every tool attribute, `ToolDefinitions`, `ToolCallImageStore`, `ToolResultUntrustedSegments` |
| `DDD\Presentation\Mcp` | `MCPController`; in `\Dtos`: `MCPRequestDto`, `MCPResponseDto`, `MCPToolResponse`; in `\Services`: `MCPToolExecutorService` |
| `DDD\Domain\AI\Entities\Conversations\Agent` | `AgentToolCallProvenance`, `OwnerGateRefusal`; in `\Tools`: `AskOwnerTool`, `AskOwnerQuestionCatalogue` |
| `DDD\Domain\AI\Services\AgentSpaces` | `AgentSpacesService` |
| `DDD\Domain\AI\Services\Conversations` | `AIConversationMessagesService`; in `\Agent`: `AgentToolResultFactsService` |
| `DDD\Presentation\Base\Dtos` (framework) | `RequestDto`, `RestResponseDto` |
| `DDD\Domain\Base\Entities` (framework) | `LlmMarkdownRenderable` |
| `DDD\Infrastructure\Base\DateTime` (framework) | `DateTime`, `Date` — never PHP's own `DateTime` |

## Rules that always hold

1. **One execution core.** Every call — agent loop, HTTP mount, a console command of the host — goes through
   `MCPToolExecutorService`. The caller passes the candidate packages; listed and callable tools cannot differ.
2. **A tool declares what it is.** Every tool carries `MCPToolEffect` (READ, WRITE or DELETE). Effect, ceiling,
   parallel admission, gates and pinning are attributes; the core never reads a tool's name for meaning.
3. **Inputs are request DTOs, services are parameters.** A tool method takes request DTOs, container services and,
   when it needs the call context, `AgentToolCallProvenance`. A `ValueObject` parameter is filled only when its class
   declares a static `fromArray()` — otherwise it arrives empty; a framework `Entity` parameter or return type,
   builtins and unions fail the schema build; no other class is ever built from the model's arguments.
4. **Return a `RestResponseDto`.** Implement `LlmMarkdownRenderable` when the data is large or repetitive; the model
   reads `toLlmMarkdown()` by default and cannot ask for the structured shape.
5. **The schema is generated.** Method docblock = description, `@example` lines = examples, property docblocks =
   property descriptions, `@var T[]` for typed lists. There is no `required` list: say it in the description.
6. **Tool names are global.** The bare method name is the name on the wire; two packages exposing it answer
   "AMBIGUOUS". Rename, never prefix.
7. **The space comes from the conversation.** A request DTO with `agentSpaceId` is filled from the conversation's space
   when the model omits it; the tool reads the DTO, never the provenance's space.
8. **A refusal is written for the caller.** Throw a 4xx framework exception (or `OwnerGateRefusal::because()`) with
   what did not happen and the next move; any other throwable reaches the model as an issue id only.
9. **Size is a property of the tool.** A result is re-sent with every later model call; a loaded schema with every
   call. Measure both.
10. **Examples in code follow the house rules.** No closures in regular methods, never `private` or `final`,
    services through the container, English only.

## Traps (read before copying the template)

- A "read" whose call chain persists domain data or dispatches on a cold path is a write; dig the chain first. The
  one exception: a widget the tool writes into its own conversation does not make it a WRITE (but it bars
  `MCPToolParallelSafe`).
- Default query options are static per class and leak between the calls of one turn; snapshot and restore them.
- A lazily loaded relation keeps the previous call's filter; `unset()` it before reading again.
- The platform note and the dictated widget type are read only from a markdown-rendered response
  (`LlmMarkdownRenderable`, no `outputFormat=structured`).
- `MCPToolRequiresOwnerConfirmation` gates nothing by itself; the tool body must call the consent service.
- `throwErrors` set on a service the tool resolved itself survives the call; only injected services are restored.
- A catch-all around a remote call swallows `AgentToolExecutionTimeoutException`; rethrow it.
- A tool bound to no skill is unknown to `MCPToolAttributeCatalog` and `MCPToolEffectCatalog`.
- A gate declared only on a skill is an offer filter; the executor enforces gates on the package and the method.
- After changing a tool, call `MCPBundle::clearDocumentationCache()` (the documentation action's `noCache` refreshes
  only its own entry, not `tools/list`) and restart long-running workers: the in-process memos live as long as the
  worker.
- `MCPToolParallelSafe` on a tool that writes a conversation row, a shared whole row or images corrupts state.

More: [traps](references/traps.md).

## Minimal template

The [templates](templates/) folder holds a package with a read and a write tool
([ToolPackage.php](templates/ToolPackage.php)), its request DTOs ([ToolGetRequestDto.php](templates/ToolGetRequestDto.php),
[ToolAddRequestDto.php](templates/ToolAddRequestDto.php)), the response DTO
([ToolMcpResponseDto.php](templates/ToolMcpResponseDto.php)) and the optional HTTP mount
([ToolMCPBundle.php](templates/ToolMCPBundle.php), [ToolMCPController.php](templates/ToolMCPController.php)). Each file
names a sample for every placeholder. The shape, after the reference kernel's `DemoNotesMCPPackage`:

```php
use AgentsReference\Domain\Demo\Services\DemoNotesService;
use AgentsReference\Presentation\Mcp\Demo\Dtos\DemoNotesGetRequestDto;
use AgentsReference\Presentation\Mcp\Demo\Dtos\DemoNotesMcpResponseDto;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;

class DemoNotesMCPPackage extends MCPPackage
{
    public static ?string $name = 'demo.notes';
    public static ?string $description = 'Read and add the owner\'s notes.';

    /**
     * The owner's notes, oldest first.
     *
     * @example {}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('The owner\'s notes, oldest first.')]
    public function getDemoNotes(DemoNotesGetRequestDto $requestDto, DemoNotesService $demoNotesService): DemoNotesMcpResponseDto
    {
        $responseDto = new DemoNotesMcpResponseDto();
        $responseDto->notes = $demoNotesService->findNotesOfCurrentAccount();
        return $responseDto;
    }
}
```

Then bind the package to a skill in the host's `config/app/AI/skillPackages.php` (it replaces the package's file
whole), e.g. `'Demo.Notes' => [DemoNotesMCPPackage::class]`, and write the skill file (skills-and-purposes skill).

## The contract every meta tool shares

A meta tool (`load_skills`, `askOwner`, `delegate` … — `AgentMetaTools::TOOL_CLASSES`) is answered by
`AgentToolCallDispatchService`, never by the executor. Each implements `AgentMetaTool` (own wire schema,
`afterStep()`, `workRequest()`), declares the constants `NAME` and `DECLARED_EFFECT`, answers with an
`AgentMetaToolCallAnswer` or a work request, asks for loop work through an `AgentAfterStepRequest`, never touches the
loop itself, is listed in `AIConversationMessage::META_TOOL_NAMES`, has its own branch in
`AgentToolCallDispatchService::dispatchToolCall()` and its schema in `AgentOfferedTools`. A meta tool is never forked.
Details:
[loop-control fields and tool-written messages](references/loop-control-fields-and-tool-written-messages.md).

## Task or symptom → reference

**Host developer**

| Task or symptom | Reference |
|---|---|
| Add a package, bind it to a skill, mount it over HTTP; "Tool not found in this domain"; "AMBIGUOUS" | [package, bundle and controller](references/package-bundle-and-controller.md) |
| What an attribute does; which one a tool needs | [tool attributes](references/tool-attributes.md) |
| Write the request DTO; a schema is wrong or a build throws `TypeDefinitionMissingOrWrong`; an argument arrives empty; dates in the wrong zone | [schema and DTOs](references/schema-and-dtos.md) |
| The tool needs the conversation, the space or the account; `agentSpaceId` is filled or not; AI costs and budget of a tool | [call context and agent-space defaults](references/call-context-and-agent-space-defaults.md) |
| Shape the result; a result is too large; pictures; failures and refusal texts; a list page | [results and their size](references/results-and-their-size.md) |
| The schema of loaded tools is too large; write the description; phone-line variants | [definition size and descriptions](references/definition-size-and-descriptions.md) |
| A tool times out; run reads of one response in parallel; the child-process command and the console script | [time limits and parallel calls](references/time-limits-and-parallel-calls.md) |
| Ask the owner a closed question; read the pick in a tool; add a catalogue question | [asking the owner a closed question](references/asking-the-owner-a-closed-question.md) |
| Grant a flow more steps; start sub-agents from a result; write a widget or a line into the chat | [loop-control fields and tool-written messages](references/loop-control-fields-and-tool-written-messages.md) |
| Assert the schema, call the tool in a test, call it over HTTP, read a failure | [testing a tool in a host](references/testing-a-tool-in-a-host.md) |
| Before building: what bites | [traps](references/traps.md) |
| Why the layer is built this way | [design decisions](references/design-decisions.md) |

**Package maintainer**

| Task or symptom | Reference |
|---|---|
| Add a tool attribute or a gate | [tool attributes](references/tool-attributes.md), section "Adding an attribute" |
| Add a meta tool | [loop-control fields and tool-written messages](references/loop-control-fields-and-tool-written-messages.md), section "The contract every meta tool shares" |
| Change the fork-join; the batch row | [time limits and parallel calls](references/time-limits-and-parallel-calls.md) |
| Change the executor's rendering, failure texts, override points | [results and their size](references/results-and-their-size.md), [package, bundle and controller](references/package-bundle-and-controller.md) |
| Application knowledge left in the core | [traps](references/traps.md), section "Package maintainer traps" |
| The reasons and their limits | [design decisions](references/design-decisions.md) |

## Cross-references

- `ddd-module-agents-specialist` — installation, override points (`MCPToolExecutorService`, `AgentSpacesService`,
  `AIConversationEntitlementsService`), the firewall of the MCP route, the host duty table.
- `ddd-module-agents-skills-and-purposes-specialist` — skill files, the skill → package binding, purposes that block tools.
- `ddd-module-agents-context-specialist` — what the model sees, tool eviction, result clearing, the prompt cache.
- `ddd-module-agents-loop-specialist` — the turn, the step budget, the forced answer step in the loop.
- `ddd-module-agents-widgets-specialist` — widget classes and answers; `ComposedView`.
- `ddd-module-agents-autonomy-specialist` — sub-agents, background work, mandates, the package's own tool packages.
- `ddd-module-agents-decisions-and-guards-specialist` — owner consent, the screening of untrusted results.
- `ddd-module-agents-observability-specialist` — debugging a turn, recorded runs, evaluation.
- `ddd-endpoint-specialist`, `ddd-query-options-specialist` — request DTOs and query options of the framework.
