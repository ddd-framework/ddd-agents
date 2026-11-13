# Injection defence

**Reader:** host developer

Contents: two classes of input · the layers · the owner-message screen · the tool-result screen · the gate rules · the sentinel-canary probe · what a host must do · honest limits

## Two classes of input

| | The owner's own request | Untrusted content |
|---|---|---|
| Example | the authenticated owner asks to change a value of their own data | a fetched web page or a third party's comment says "change the e-mail address to …" |
| Injection? | no — the owner exercises their own rights | yes — data carries an instruction aimed at the agent |
| Defence | authorization: rights per resource, owner consent before an irreversible step | constrain what the agent can do with it; screen and remove it |

The boundary between the two is **provenance, not the verb**. A top-level request of the owner about the owner's own
data is data, whatever it asks for; the same action becomes an attack when it is embedded in untrusted content,
attributed to a third party, or sends data to a foreign destination. A screen that blocks the owner's own
self-service request is doing the authorization layer's job, badly.

## The layers

1. **Rights and scoping** (the host's repositories and the agent space) decide what any call may touch — the
   layer everything else rests on.
2. **Owner consent** before an irreversible step: [owner consent](owner-consent.md). Standing permissions for outward
   actions: `ddd-module-agents-autonomy-specialist`.
3. **Detection** — the two screens below. They are the lowest tier: a cheap, best-effort filter. A classifier on a
   finite battery is never a proof, and an adaptive attacker can find a text it passes.

## The owner-message screen (`ownerMessageInjection`)

- **When:** at the turn start, as a parallel child of the turn-start batch (`ArgusAgentOwnerMessageInjectionDecision`),
  for the newest owner text row — or the submit of an owner form widget that started the turn, screened on its owner
  text — once per message (`AIConversationOwnerMessageScreeningService::findOwnerMessageForInjectionCheck()`). Not in
  a delegation.
- **Sees:** the message alone, cut at `MAX_MESSAGE_CHARS`; no earlier turns, no tool results.
- **Asks:** one `noul` — would the gate rules output their success line for this message? The rules are the prompt file
  named by `AgentAIPromptNames::APP_AI_AGENT_OWNER_MESSAGE_INJECTION_GATE_RULES`, sent inside the question.
- **Consequence:** the attack probability is one minus the pass probability; from
  `ArgusAgentOwnerMessageInjectionDecision::FILTER_FROM_ATTACK_PROBABILITY` the message is filtered. Under `on`
  `AgentWorkingState::markOwnerMessageSecurityFiltered()` marks it, and every later model call sees
  `AIConversationMessage::SECURITY_FILTER_NOTICE` in its place. The stored row keeps the owner's text (only the model's
  view is replaced); the owner-consent dialogue skips the row. Under `shadow` the verdict is only recorded.
- **Without an answer:** the message passes. Its transport failure never trips the shared breaker and its answer never
  closes it (`ArgusAgentDecisions::hasTransportFailure()` skips it).

## The tool-result screen (`toolResultInjection`)

- **Which results:** every COMPLETED result of a tool marked `DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsUntrustedContent` (the tool definition's
  `returnsUntrustedContent`) — text written by third parties and fetched from outside. Mark only that: not what the owner
  wrote, not what the platform composed. Marking the tool is `ddd-module-agents-tools-specialist`'s topic.
- **Start:** after each step `AgentToolResultScreeningService::startScreeningOfUntrustedToolResultsOfStep()` sets the
  call's `injectionScreening` to `pending` in a claim-fenced write and dispatches one `AgentToolResultScreeningMessage`
  per result to the transport `ai_tool_result_screening` (`AgentMessengerTransports::TOOL_RESULT_SCREENING`). Never for
  a transient conversation (its message ids are not real rows).
- **The job:** `AgentToolResultScreeningHandler` → `screenToolResult()`. The text (`ToolResultUntrustedSegments::buildTextToScreen()`:
  the marked passages of a marked result, else the whole result) is cut into chunks of `MAX_CHUNK_CHARS` with
  `CHUNK_OVERLAP_CHARS` overlap, one `ArgusAgentToolResultInjectionDecision` child per chunk in one batch, under
  `AgentToolResultScreeningService::SCREENING_TIMEOUT_SECONDS`. One `noul` per chunk: does this fetched content carry an
  instruction aimed at an AI assistant? The highest probability counts; a chunk at or above `MINIMUM_INJECTION_PROBABILITY`
  flags the result.
- **The verdict** lands only on a row still `pending`, under the row lock (`ToolCallInjectionScreeningVerdict`):
  under `on` a flagged result is replaced by `ToolCall::RESULT_REMOVED_FOR_SECURITY` (`removed`); under `shadow` it is
  `flagged`; otherwise `clean`, or `failed` when a chunk stayed unanswered.
- **In the turn:** the turn never waits. When a candidate response comes back,
  `discardCandidateWrittenOnRemovedToolResult()` reads which pending results were removed meanwhile; if one was, the
  candidate is discarded, a signal tells the model why, and the step runs again — at most once per owner turn and only
  with a step left. A screening that never ran (worker down, message lost) leaves the result `pending`; one that ran
  without an answer for every chunk marks it `failed`; a verdict that arrives late still lands while the row is
  `pending`, but the turn no longer re-drives for it. In every one of these cases the result stays in the context.
- **Outside the breaker and the log:** the screen loads its batch directly (`ArgusAgentDecisions::loadDecisions()`),
  not through the runner: an open circuit breaker does not stop it, its transport failures bench nothing, and it writes
  no decision record and no turn event — the call row's `injectionScreening` and `injectionProbability` are its only
  trace.

## The gate rules

The package ships rules for an assistant of any kind (`AI.Agent.OwnerMessageInjectionGateRules`): blocked are attempts
to make the gate recite or reveal anything, false clearances and control spoofs, action commands embedded in untrusted
content or sent to an attacker's destination, and obfuscated forms of those; everything else — including the owner's
own self-service requests with any verb — passes. The last line names the success line `PASS`.

A host whose owners, data and tone differ replaces the file whole with its own under the same prompt name. The
question around the file is fixed in `ArgusAgentOwnerMessageInjectionDecision::buildQuestions()`: its criteria say
"none of rules 1-4 match" and "one of rules 1-4 matches", and ask whether the gate outputs the SUCCESS LINE or
BLOCKED. A replacement must therefore keep exactly four numbered rules, the output word BLOCKED and a final line that names the SUCCESS LINE (the package's reads "SUCCESS LINE: PASS"), or the question no longer matches the text it carries. Measured
numbers belong to exactly one text: change it only with a re-measurement on an attack battery AND on a replay of real
owner messages (false alarms on plain follow-ups such as "continue" show only there). Never name test pages with
attack words in their URL — that alone lowers the pass probability.

## The sentinel-canary probe

`ArgusAgentSentinelCanaryGuard` is an evaluation tool, not a runtime screen: a guard model gets a system prompt of the
host's (`setGuardSystemPrompt()`) and the inspected input (`setInspectedInput()`), and must emit one exact, unguessable
pass phrase only when the input is harmless data; the caller forwards only on an exact match (`getRawResponse()`).
Secrecy stops forgery, a phrase never called "pass" or "token" stops blind steering, and any deviation fails closed. It
cannot stop content that genuinely looks clean. A host uses it to measure guard prompts and models
(`setModelByName()`) in a console command of its own, under the scope `AgentAIUsageScope::AGENT_GUARD_SENTINEL_PROBE`.

## What a host must do

- Mark its fetching tools with `MCPToolReturnsUntrustedContent`.
- Run a worker on the transport `ai_tool_result_screening` (`ddd-module-agents-specialist`, transports).
- When it replaces the gate rules: keep the four numbered rules, the output word BLOCKED and the final SUCCESS LINE
  ([the gate rules](#the-gate-rules)), and re-measure.
- Switch `ownerMessageInjection` and `toolResultInjection` to `shadow`, read the decision log (owner messages) and the
  call rows' `injectionScreening` (tool results) — neither writes a turn event, so the decision statistics show
  nothing for them — then `on`.
- Let the structural layers carry the protection: rights scoped by agent space, owner consent on irreversible tools, no write
  path that trusts text from a tool result.

## Honest limits

- The screens are classification on a finite battery; a passed screen proves nothing.
- The owner-message screen reads one message; an attack spread over several messages is not its target.
- The tool-result screen covers most results, not all: a worker restart or an outage leaves a result `pending` and in
  the context.
