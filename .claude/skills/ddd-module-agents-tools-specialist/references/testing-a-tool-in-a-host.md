# Testing a tool in a host

**Reader:** host developer

Contents
- What the package gives you, and what it does not
- 1. The schema
- 2. The call through the executor
- 3. The call over HTTP
- 4. Debugging a failure
- 5. The tool in a conversation
- Checklist before a tool is done

## What the package gives you, and what it does not

The package ships the execution core and its own unit tests; it ships no console command to list or call tools and
no evaluation runner. A host builds its tests on the classes below; an evaluation suite with cases and a judge is the
observability skill's topic (`ddd-module-agents-observability-specialist`).

## 1. The schema

Assert what the model will read, in a unit test of the host:

```php
use AgentsReference\Presentation\Mcp\Demo\DemoNotesMCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;

$toolDefinition = ToolDefinitions::forPackages([DemoNotesMCPPackage::class])->getByName('getDemoNotes');
$wireSchema = $toolDefinition->toOpenAiToolSchema();
self::assertSame('getDemoNotes', $wireSchema['function']['name']);
self::assertArrayNotHasKey('agentToolCallProvenance', $wireSchema['function']['parameters']['properties']);
self::assertNotNull($toolDefinition->effect);
```

Keep host-wide tests that the package does not ship: every `MCPTool` method declares an `MCPToolEffect`; tool names
are unique across all packages (the host's and the package's own); the set of tools with
`MCPToolRequiresOwnerConfirmation` and with `MCPToolParallelSafe` is frozen, so a new member is a reviewed change;
every package of the skill binding builds its documentation without `TypeDefinitionMissingOrWrong`.

## 2. The call through the executor

The executor (`DDD\Presentation\Mcp\Services\MCPToolExecutorService`) is a container service; take it from the
container, never construct it:

```php
use AgentsReference\Presentation\Mcp\Demo\DemoNotesMCPPackage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;

/** @var MCPToolExecutorService $mCPToolExecutorService */
$mCPToolExecutorService = DDDService::instance()->getService(MCPToolExecutorService::class);
$toolResult = $mCPToolExecutorService->execute('getDemoNotes', [], [DemoNotesMCPPackage::class]);
self::assertFalse($toolResult->isError);
```

- `execute()` returns the `ToolResult` the loop feeds back (JSON-encoded `MCPToolResponse`, images split off);
  `callTool()` returns the `MCPToolResponse` itself.
- Authenticate the test account first: a call without a space is checked against the authenticated account, and the
  executor opens its fallback usage envelope for it.
- To run a call as the loop does, pass an `AgentToolCallProvenance` as the last argument; for a deterministic harness
  call on a space, `AgentToolCallProvenance::forEvalHarness($agentSpace)` (consent gates do not apply, nothing is
  written into a conversation).
- A test of the arguments alone: `buildMethodArguments()` with a `ReflectionMethod` of the tool, as the package's own
  `AgentToolCallProvenanceInjectionTest` does.
- Measure the size here: the length of `ToolResult::$content` divided by four, with the markdown and with
  `'outputFormat' => 'structured'`.

## 3. The call over HTTP

JSON-RPC on the host's mount, with a bearer; the answer is one SSE frame (`event: message`, then `data:` lines):

```bash
post(){ curl -s "$BASE/<mount>" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "$1" | sed -n 's/^data: //p'; }
post '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'                       | jq -r '.result.tools[].name'
post '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"getDemoNotes","arguments":{}}}' | jq '.result'
```

The tool list without JSON-RPC: the controller's `GET …/documentation` action (`buildDocumentationResponse()`).
Its `?noCache=1` refreshes only that action's own cache entry; after a schema change call
`MCPBundle::clearDocumentationCache()`, so `tools/list` is rebuilt too.

## 4. Debugging a failure

- A tool's internal error answers with an issue id only; read the throwable in the host's issues log.
- `?errors_as_json` on the MCP URL rethrows the tool's exception to the host's JSON error handling, with its stack —
  but only for an admin with access to all resources (`MCPController::mayCallerReadRawToolErrors()`); for every other
  caller the parameter has no effect. Never leave it in a client's configuration.
- In the agent loop every failed call is appended to "tool-errors.jsonl" (`MCPToolExecutorService::logToolFailure()`:
  tool, arguments, failure text, the caller's context; an exception class and stack only for a throwable the loop's
  dispatch caught itself) in the directory a host returns from its override of `getToolFailureLogDirectory()`; the
  package default writes no file. Calls over HTTP are not written there — read the issues log.

## 5. The tool in a conversation

Load the skill that binds the package and drive a conversation through the host's chat entry (a console command of
the host or its chat endpoint), then read the stored call: its arguments, its status, `ToolCall::$durationSeconds`,
the result, `wasRefusedByGate`, `wasRefusedAsDuplicate`. In the reference kernel the scripted model calls
`addDemoNote` and `getDemoNotes` of the `Demo.Notes` skill — the one runnable example of a tool in a conversation.
Debugging a turn is the observability skill's.

## Checklist before a tool is done

- The chain is dug; reads have no side effect; the effect is declared.
- The description routes, delineates and names the gotchas; one to three `@example` lines.
- The wire schema is asserted; the result is measured in both renderings and is small enough.
- Errors a caller can act on are 4xx framework exceptions with a written message.
- Gates, ceiling, images and parallel admission are declared where they apply; an irreversible step calls the consent
  service in the tool body (the attribute alone gates nothing).
- The documentation cache is cleared and the workers restarted after the change.
