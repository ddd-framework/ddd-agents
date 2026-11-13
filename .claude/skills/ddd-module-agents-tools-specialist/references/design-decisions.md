# Design decisions of the tool layer

**Reader:** host developer and package maintainer

Contents: Tools are methods of packages, grouped by skills and bundles · One execution core for the agent and for MCP clients · A tool declares what it is; the core never lists tool names · Inputs are request DTOs and the schema is generated · The model reads markdown by default · Parallel calls run as child processes, admitted by declaration · The time limit is an alarm in the worker, raised per tool · A closed owner question is a meta tool with the model's own options · Tool names are global and never prefixed

## Tools are methods of packages, grouped by skills and bundles

**Rule.** A tool is a public method with `MCPTool` on an `MCPPackage`. The agent reaches it through the skill that
binds the package (`skillPackages.php`); an MCP client reaches it through the bundle of a mount
(`MCPBundle::INCLUDED_PACKAGES`). Skills load on demand: the agent first sees a catalog, then the tool schemas of the
skills it loaded.

**Reason.** A host grows to hundreds of tools. All of them on the wire cost tokens on every model call and lower the
model's choice accuracy; a feature-sized package keeps a tool next to the domain it acts on, and a skill is the unit
the agent can choose from a short line.

**Rejected.** One flat tool list for the agent: too large to send, too many to choose from. One router agent per
domain: a second model call per request and lost context between agents. A skill per tool: the catalog would be as
long as the tool list.

**Would change if.** The models chose reliably among hundreds of tools at no per-call cost, or providers deferred
tool schemas natively for every model the host uses.

## One execution core for the agent and for MCP clients

**Rule.** Every tool call runs through `MCPToolExecutorService`: the agent loop in process, the HTTP mount through
`MCPController`, a console command of the host directly. The caller passes the candidate packages; the executor
resolves the tool, applies every gate, hydrates the arguments, runs the tool and renders the result.

**Reason.** Two execution paths drift: a gate, a time-zone rule or a failure text added to one is missing in the
other. A parameter for the package list keeps the listed and the callable tools identical on every path.

**Rejected.** The agent calling the HTTP endpoint of its own host: a network round trip, a token bridge and SSE parsing per
call for nothing. The agent invoking tool methods itself: every gate would be duplicated in the loop.

**Would change if.** The agent ran in another process or language than the tools; the executor would then sit
behind a transport, still as the only path.

## A tool declares what it is; the core never lists tool names

**Rule.** What the loop must know about a tool — effect, ceiling, parallel admission, pinning, gates, owner-facing
subject — is an attribute on the method or the package class, read by reflection (`MCPTool`, `ToolDefinition`,
`MCPToolAttributeCatalog`, `MCPToolEffectCatalog`). A name prefix (`get…`) means nothing.

**Reason.** The package is shared by applications whose tools it cannot know. A list of tool names in the core is
application knowledge in the wrong place and goes stale with every rename; a name is no proof of what a method does.

**Rejected.** Name conventions: a "read" by name may write. A registry file per host listing tool facts: a second
place to keep in sync with the method.

**Would change if.** Tools were declared in data (configuration) instead of code; the facts would then move into that
declaration, still beside the tool.

## Inputs are request DTOs and the schema is generated

**Rule.** A tool takes request DTOs, container services and the server-provided call context; the JSON-Schema is
generated from the DTO properties and their docblocks. A value object parameter is filled only through a static
`fromArray()` of its class; a framework entity, builtin and union parameters are refused at the schema build; an
unknown class is never built from the model's arguments.

**Reason.** The same DTO serves the REST endpoint, the MCP schema and validation — one contract. Generated schemas
cannot drift from the code. Refusing arbitrary classes keeps the model from instantiating objects the host never
meant as input.

**Rejected.** Hand-written JSON-Schemas: drift. Scalar parameters: no place for docblocks, validation or hydration
from a request. A separate DTO per tool beside the controller's: twice the contract for the same action.

**Would change if.** The framework's DTOs could not express a schema feature the model needs (a required list is the
one gap today, carried by the description).

## The model reads markdown by default

**Rule.** A response DTO that implements `LlmMarkdownRenderable` is rendered with `toLlmMarkdown()` for the model;
the structured shape stays available to a caller that asks for it, and the model cannot ask (`outputFormat` is not
in its schema). Loop-control fields ride in a control-only `structuredContent`.

**Reason.** A result is re-sent with every later model call; JSON structure (keys, nesting, class markers) is pure
overhead for a reader that relays the data. A compact, legended reading is also misread less often.

**Rejected.** TOON or field hiding alone: they shrink the encoding but keep the structure. Structured by default with
markdown opt-in: every tool author would have to remember it.

**Would change if.** Results were consumed mainly by programs rather than by the model, or providers billed repeated
context at no cost.

## Parallel calls run as child processes, admitted by declaration

**Rule.** The calls of one model response whose tools declare `MCPToolParallelSafe` (at least two, never two of the
same tool) run in short-lived console processes of the host while the turn worker runs the rest inline; each child
claims its call row with a lease and writes only that row. Without the host's command every call runs inline.

**Reason.** Several reads of one response cost the sum of their durations when run one after another; a child process
costs one console boot and adds no run state. Admission by the tool's author is the only safe test — the effect on
shared rows is visible only in the call chain.

**Rejected.** A job queue with its own workers, a new run state and a resume: seconds of overhead per batch and many
new branches in the loop. Threads: a second PHP build, no shared objects. Admitting every READ by effect: "reads"
that persist on a cold path exist.

**Would change if.** PHP offered cheap shared-memory concurrency in the standard build, or tools became side-effect
free by construction so that admission could be derived.

## The time limit is an alarm in the worker, raised per tool

**Rule.** The loop bounds each tool call with SIGALRM at 60 s by default; a tool raises its own ceiling with
`MCPToolExecutionTimeout`. A timeout is a failed tool result the model reads; the loop recovers.

**Reason.** A remote call without a timeout can pin a turn forever. A per-tool ceiling lets a tool whose honest work is
a remote job finish instead of being aborted mid-job, while every other tool keeps the tight default.

**Rejected.** A timeout per remote client only: not every tool goes through one client. One global ceiling large
enough for the slowest tool: a stuck call would hold every turn that long. No limit: hung turns.

**Would change if.** Tool execution moved out of the turn worker into processes the worker supervises anyway.

## A closed owner question is a meta tool with the model's own options

**Rule.** The model asks a closed question through `askOwner`, with the question and 2–4 options it formulates; the
platform renders them as a widget under the final text, reads the pick back by label, and forces the next step to be
the final text. A catalogue lets the platform record the pick of a known question itself; a misfit key degrades to a
free question instead of a refusal.

**Reason.** Options attached by the platform from a plan item belonged to the item, not to the question the model
actually asked, and appeared under the wrong question. The model knows its question; the platform knows how to render
and read a choice. Refusing a misfit key sent models into loops of re-asks.

**Rejected.** Seeded option lists per plan item: wrong buttons under the wrong question. Detecting a question by its
question mark: brittle. Enforcing the tool for every closed question: a prose question is not wrong.

**Would change if.** The frontend asked the owner without the model (a form for every choice), or models emitted
widget blocks reliably on their own.

## Tool names are global and never prefixed

**Rule.** The bare method name is the tool's name on the wire, across every package at once. A name two candidate
packages expose is refused as ambiguous at execution, never resolved first-match; the host keeps a uniqueness test.

**Reason.** A silent misroute runs the wrong domain's tool — the worst failure, wrong data or a wrong write. A prefix
on the wire costs tokens on every call and blurs the model's routing.

**Rejected.** Namespacing tool names by package id: cost and routing. First match wins: silent misroutes.

**Would change if.** The provider protocol carried a namespace field the model routes on at no token cost.
