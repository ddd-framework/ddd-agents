# Package, bundle and controller

**Reader:** host developer

Contents
- The three pieces and the two paths
- The package
- The agent path: binding a package to a skill
- The HTTP path: bundle and controller
- Tool names are one global namespace
- The admin audience
- Documentation caches
- What the package maintainer must keep

## The three pieces and the two paths

| Piece | Class | What it is |
|---|---|---|
| Tool | a public method with the `MCPTool` attribute | one callable action; its name is the method name |
| Package | a subclass of `MCPPackage` | a group of tools; static `$name` (the package id) and `$description` |
| Bundle | a subclass of `MCPBundle` | the tool list of one HTTP mount: `INCLUDED_PACKAGES` |

The same tool method is reached two ways, and both end in one execution core, `MCPToolExecutorService`:

| | Agent path (the agent loop) | HTTP path (an external MCP client) |
|---|---|---|
| Who chooses the tools | the skills the agent loaded: their packages, from the skill binding | the bundle of the mount |
| Entry | `AgentToolCallDispatchService::dispatchToolCall()` → `AgentToolCallExecutionService::executeToolCall()` → `MCPToolExecutorService::execute()` | `MCPController::handleMcpRequest()` → `MCPToolExecutorService::callTool()` |
| Call context | an `AgentToolCallProvenance` built by the execution service | none: a server-provided parameter arrives as an empty instance |
| Schema the model or client reads | `ToolDefinition::toOpenAiToolSchema()` (agent-space defaults applied) | `MCPBundle::buildDocumentation()` |
| Time limit | the loop's alarm (see time-limits-and-parallel-calls.md) | none from the package |

The candidate package list is a parameter of the executor, so what is listed and what is callable can never differ
on either path.

## The package

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

    /** … @example {} */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('The owner\'s notes, oldest first.')]
    public function getDemoNotes(DemoNotesGetRequestDto $requestDto, DemoNotesService $demoNotesService): DemoNotesMcpResponseDto
```

(The reference kernel's `DemoNotesMCPPackage`; copy `templates/` for a new one.)

- Only public methods carrying `MCPTool` are tools. Any other public method is invisible to both paths.
- A package is no container service. The executor asks the container for the package class first and falls back to
  instantiating it; the reference kernel's `services.yaml` excludes its MCP folder from service registration. Put
  domain work into a host service the tool takes as a method parameter.
- The package's own tool packages (`AgentMandatesMCPPackage`, `AgentTaskRunReportsMCPPackage`) are working examples of
  every pattern in this skill; their mechanism is explained by the autonomy skill
  (`ddd-module-agents-autonomy-specialist`).
- A package that must reuse the work of a tool under another request class derives from a class that holds the work
  in protected methods, and declares its own tool methods: a tool method cannot be overridden with another request
  class, because the executor builds the request object from the declared parameter type
  (`AgentMandateToolCallsMCPPackage` is the example).

## The agent path: binding a package to a skill

A tool reaches the agent only through a loaded skill. The host's `config/app/AI/skillPackages.php` maps each skill
name to its packages, by class or by package id; it **replaces the package's file whole**, so a host that wants the
package's own skills (`Assistant.Mandates`, `Assistant.TaskRunReports`) lists them itself.
`AISkill::getPackageClassesBySkillName()` reads the binding once per process. A package id resolves only for a package
the source-root scan finds (`MCPPackageIdsService`); the rules of the scan are the skills-and-purposes skill's, and the
way out for a host under another root namespace — bind by class, as the reference kernel does — is the entry
skill's. How a skill file is written and loaded is the
skills-and-purposes skill (`ddd-module-agents-skills-and-purposes-specialist`).

At run time the conversation's loaded skills give the candidate packages
(`AIConversation::findLoadedPackages()`); `ToolDefinitions::forPackages()` builds the offered tools from them.

## The HTTP path: bundle and controller

```php
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\Router\Routes\Post;
use DDD\Presentation\Base\Router\Routes\Route;
use DDD\Presentation\Mcp\Dtos\MCPRequestDto;
use DDD\Presentation\Mcp\Dtos\MCPResponseDto;
use DDD\Presentation\Mcp\MCPController;
use Symfony\Component\HttpFoundation\Request;

#[Route('/demo-notes')]
#[Tag(name: 'DemoNotes MCP', group: 'MCP', description: 'Read and add the owner\'s items.')]
class DemoNotesMCPController extends MCPController
{
    public const ?string BUNDLE = DemoNotesMCPBundle::class;

    #[Post]
    #[Summary('MCP Endpoint')]
    public function mcp(MCPRequestDto $requestDto, Request $request): MCPResponseDto
    {
        return $this->handleMcpRequest($requestDto, $request);
    }
}
```

`MCPController::handleMcpRequest()` answers the JSON-RPC methods:

| Method | Answer |
|---|---|
| `initialize` | `McpCapabilitiesResponseDto`: the `Tag` name, the instructions "Use for the following purpose: " + the `Tag` description, then `MCPController::buildAdditionalInitializeInstructions()`, then the query-options grammar and the shared request parameters once when the bundle's tools use them |
| `tools/list` | `MCPController::buildToolsListDocumentation()` — by default `MCPBundle::buildDocumentation()` with objects in `$defs`, depth 6, no output schema |
| `tools/call` | refuses without an authenticated account (`UnauthorizedException`), mints a short-lived access token when the request carries no bearer, calls `MCPToolExecutorService::callTool()` with the bundle's packages, then `MCPController::shapeToolResponseForAudience()` |
| `ping`, `logging/setLevel`, `notifications/initialized` | an empty acknowledgement |
| anything else | `JsonRpcError` with `JSON_RPC_CODE_METHOD_NOT_FOUND` |

The response is `MCPResponseDto`: one Server-Sent-Events frame (`event: message`, `data:` lines) by default, static
JSON after `MCPResponseDto::setServerSentEvents(false)`. `MCPController::buildDocumentationResponse()` serves the tool list as static JSON for a
`GET /documentation` action; its `noCache` rebuilds and refreshes only ITS OWN cache entry (built without `$defs`),
not the entry `tools/list` serves (built with them) — the cache key carries the flags.

Override points of the controller: `MCPController::buildAdditionalInitializeInstructions()`, `MCPController::buildAdditionalCapabilities()`,
`MCPController::buildToolsListDocumentation()` (a per-caller subset), `MCPController::shapeToolResponseForAudience()` (cut an oversized result),
`MCPController::mayCallerReadRawToolErrors()` (see testing-a-tool-in-a-host.md).

**The firewall is the host's.** `initialize` and `tools/list` check no account of their own; without a firewall rule
the tool catalogue of the mount is public. `tools/call` fails closed without an account, and every tool still runs
under the account's rights.

## Tool names are one global namespace

The model calls a tool by its bare method name, across every package on the wire at once.

- `MCPToolExecutorService::dispatchTool()` resolves the name to exactly one package; two candidate packages that both
  expose it answer `JsonRpcError` (`JSON_RPC_CODE_INVALID_PARAMS`, "AMBIGUOUS"), never the first match.
- `MCPToolAttributeCatalog::getToolMethodsByToolName()` keeps the first method of a duplicate name silently, so a
  stored call's attributes may be read off the wrong method.
- The package ships no uniqueness test. A host keeps one: scan every `MCPTool` method of its packages and of the
  package's own, fail on a duplicate.
- Resolve a collision by renaming (name the domain in the verb phrase: `getDemoNotes`, not "getNotes"), never by a
  prefix on the wire — a prefix costs tokens on every call and blurs routing.

## The admin audience

A package class marked `MCPRequiresAdminWithAccessToAllResources` runs only for an admin with access to all
resources, on every path (`REFUSAL_ADMIN_ACCESS_REQUIRED` otherwise), is exempt from the automation-eligibility gate,
and every call of it reaches the override point `MCPToolExecutorService::recordAdminAudienceToolCall()` in a `finally` block (the default
records nothing).

## Documentation caches

| Cache | Key | Invalidated by |
|---|---|---|
| `MCPPackage::buildDocumentation()` | Redis, `mcp.doc.package.<class>`, one day | TTL only |
| `MCPBundle::buildDocumentation()` | Redis, the bundle class plus a hash of `INCLUDED_PACKAGES` plus the flags, one day | adding or removing a package; `MCPBundle::clearDocumentationCache()`; `noCache` (its own flag combination only) |
| `MCPPackageDocumentation::createFromClassName()` | in process, per class and flags | a new process |
| `ToolDefinitions::forPackages()` | in process, per package list | a new process |

A changed tool signature or DTO is **not** in the bundle key. After such a change call
`MCPBundle::clearDocumentationCache()` (it drops all four flag combinations at depth 6, so `tools/list` is rebuilt
too), and restart long-running workers — the in-process memos live as long as the worker.

## What the package maintainer must keep

- The executor is the only place that runs a tool. A new caller passes its package list and, in the loop, a
  provenance; it never invokes a tool method itself.
- Offer and execution read the same rule objects (`MCPToolEntitlement`, the attribute readers): a new gate is added
  to both or to neither.
