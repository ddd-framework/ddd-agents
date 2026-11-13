# Traps

**Reader:** host developer and package maintainer

Contents
- Before you write the tool: dig the call chain
- Traps that only bite in the agent loop
- Traps of the schema and the result
- Traps of the gates
- Package maintainer traps

## Before you write the tool: dig the call chain

One tool = parse the request → one domain call → render the result. Before mirroring an endpoint, read the whole
chain it triggers (controller → service → entity → repository, remote call, dispatch) and write down what it really
does:

- **A "read" that writes.** A getter whose cold path persists a suggestion, starts a job or refreshes a connection is
  a write for the agent. A read tool must be side-effect free: strip the persist or the dispatch, or declare the tool
  a WRITE and keep it out of `MCPToolParallelSafe`. The exception: a widget the tool writes into its own conversation
  does not make it a WRITE (`MCPToolEffect`), though it still bars `MCPToolParallelSafe`.
- **A field is not an action.** Setting a status field and performing the action (publishing, sending) are different
  chains; a write tool calls the method that matches the owner's intent.
- **A query option is only accepted, not proven.** A key declared for filtering may be ignored, inverted or paginate
  differently in the repository or the remote call. Call the tool with the exact parameters a real client sends and
  count what comes back before you document a filter.
- **One capability, one focused package**, delineated against the tools of other skills that share a word.

## Traps that only bite in the agent loop

The loop runs many tool calls in ONE process, often in a long-running worker. What one request on the HTTP path
forgets, the loop keeps:

- **Default query options are static per class and leak** into the next call. A tool that changes them takes a
  snapshot and restores it: `setDefaultQueryOptionsSnapshot()` … `finally { restoreDefaultQueryOptionsSnapshot(); }` on
  the same class.
- **A lazily loaded relation keeps the previous call's filter.** `find()` returns the registry's cached entity with its
  relation already loaded; `unset()` the exact relation before reading it again under new options.
- **A write that diffs a set needs a fresh load** (`find($id, useEntityRegistrCache: false)`), or the second write of a
  turn diffs against the stale cached set.
- **`throwErrors` survives the call unless the service was injected.** The executor restores the flag of every
  service among the tool method's arguments (`MCPToolCallServiceThrowErrorsSnapshot`). A service the tool resolves
  itself is not covered: save, set and restore it in `finally`, or take it as a parameter.
- **Static hide and TOON settings** are set inside the tool, per call, on the right class.
- **A catch-all around a remote call** swallows `AgentToolExecutionTimeoutException`; rethrow it.
- **A long-running worker keeps its code and its documentation memos** until it restarts; clear the documentation
  cache AND restart the workers after a tool change.

## Traps of the schema and the result

- `@var array<T>` is read as a plain array — write `@var T[]`.
- A builtin, a union or a framework `Entity` parameter type fails the schema build; a `ValueObject` parameter without
  a static `fromArray()` arrives empty; a union property type of a request DTO fails the
  hydration. Use one resolvable type (`?array`, a value object).
- The schema has no `required` list: say "required" in the description and refuse a missing value in the tool.
- A bare entity as the return value skips the DTO's hide lists; with `outputFormat=structured` it is serialized
  as is. Return a `RestResponseDto`. Test both renderings.
- `""` on an optional property is "not passed". A property whose empty string clears a text needs
  `MCPEmptyStringIsValue`, or the clear never happens.
- Date-time values are `Y-m-d H:i:s` wall-clock times; a tool never converts zones.
- Instructions to the model go into the platform note, never into the result — the injection screening reads the
  result.
- An image in a JSON text part is billed as text and never seen: attach it with `addImageContent()`.
- A tool that returns durable files returns their ids, never the bytes.
- Never guard a mis-route with a keyword match inside the tool; scope the description and the skill text, and prove
  it with evaluation cases.
- Tool and skill texts are English only.

## Traps of the gates

- A tool bound to no skill is unknown to `MCPToolAttributeCatalog` and `MCPToolEffectCatalog`: its stored calls carry
  no effect and no attribute for any reader.
- A missing `MCPToolEffect` makes the tool neither a read nor a write: no read reuse, no identical-write refusal, no
  annotations for external clients.
- A gate declared only on a skill is an offer filter: the executor receives packages, not skills. Declare a feature
  flag or a right on the package or method as well.
- Whether a right gates depends on the host's `AgentAccountRightsInterface` implementation (`getMissingRightNames()`):
  a right the host defaults to granted gates only where it is revoked.
- A destructive write still checks its target against the owner's own records; the consent gate checks the owner's
  words, not the target.
- The HTTP path and executor-direct calls are not gated by the owner-confirmation and mandate gates: the authenticated
  owner is the consent-giver there. Do not expose a destructive tool on a mount for clients that are not the owner.

## Package maintainer traps

- The core never names a tool of an application. A rule about a kind of tool is an attribute read by the catalog.
  Today three names of tools the package does not ship are hard-coded: `ToolCall::buildExecutionArguments()` injects
  the conversation id for "recallConversation", and `AgentToolCallForkJoinService::INLINE_WRITES_OF_CONVERSATION_STATE_ONLY`
  lists "remember", "updateMemory", "forget". Move them behind a declaration before adding a fourth.
- A new gate is enforced on the offer AND in `MCPToolExecutorService::dispatchTool()` from one rule object; a gate on
  the offer alone is bypassed by the HTTP path and by a tool that stayed resident through a downgrade.
- `AgentToolCallDispatchService::dispatchToolCall()` never lets a tool exception abort the loop; only a lost run claim
  and a message-index lock failure propagate. A new branch keeps that.
- The alarm handler and async signals are restored in `finally`; a new execution path that bounds a call does the same.
