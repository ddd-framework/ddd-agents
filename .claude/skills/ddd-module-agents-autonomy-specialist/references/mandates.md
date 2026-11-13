# Mandates

**Reader:** host developer and package maintainer

Contents: what a mandate is · the entity · declaring a gated tool · the gate chain · one instruction · unattended runs · the grant path · the tools · revocation · what a host provides · traps

## What a mandate is

A mandate is the owner's persisted permission for the agent to perform one **action class** of the host — for one
request of the owner, for a period, or until revoked. The package gates a tool by a mandate only when the tool says so;
it decides nothing from the tool's effect. Owner consent for one irreversible step in an attended
turn (the tool body calls `OwnerConsentsService`, which reads the owner's own words in the conversation) is a different
gate, owned by the decisions-and-guards skill `ddd-module-agents-decisions-and-guards-specialist`. The two compose — a
tool may need both — and a mandate never replaces a consent step.

## The entity

`AgentMandate` (one row per grant, kept as evidence):

| Group | Properties |
|---|---|
| scope | `accountId`, `agentSpaceId` (empty = account-wide; written once), `actionClass` |
| kind | `scopeType`: `SCOPE_ONE_INSTRUCTION`, `SCOPE_TIME_WINDOW` (needs `validUntil`), `SCOPE_UNLIMITED`; `validFrom` |
| state | `status`: `STATUS_ACTIVE`, `STATUS_CONSUMED`, `STATUS_EXPIRED`, `STATUS_REVOKED`, `STATUS_SUPERSEDED` (+ `supersededByMandateId`); `revokedAt`, `revokedByMessageId` |
| evidence | `customerApprovalExcerpt` (the owner's verbatim words, written by the approval check), `constraintsNote`, `sourceConversationId`, `sourceUserMessageId` |
| binding | `coveredToolName` — the first-use binding of a one-instruction grant |

There is no count field: "the next 20 items" cannot be a mandate; the approval check rejects it and the skill offers a
period or an unlimited grant instead.

**Action classes are the host's.** The package declares only `AgentMandate::ACTION_STANDING_AUTOMATION`. A host
redeclares `ACTION_CLASSES`, `ACTION_CLASS_DESCRIPTIONS` and `ACTION_CLASSES_NEEDING_A_STANDING_GRANT` on its
same-path `AgentMandate`; package code reads them through `getActionClasses()`, `getActionClassDescription()` and
`isStandingGrantNeededForActionClass()`. The descriptions feed the approval check and the `grantMandate` schema.

## Declaring a gated tool

The method attribute `MCPToolRequiresMandate` (`actionClass`, optional `bindingKey`) — named in the tools skill
`ddd-module-agents-tools-specialist` with the other tool attributes. Tools that are one intent of the owner through
different mechanics share a `bindingKey`, so one consent covers both (`MCPToolRequiresMandate::bindingKeyForTool()`).
A gated tool gets `MCPToolRequiresMandate::CONSENT_CHECK_SECONDS` added to its time limit, because the first call may
run the approval check. **No package tool carries the attribute**; the gate is the host's choice per tool.

**"Inward actions need no mandate" is not a package rule.** The gate reads only this attribute — never
`MCPToolEffect` or its `isOutwardAction`, which feed the audit and the MCP hints. A host that wants the rule ("only
consequential actions that leave the account in the owner's name") applies it when it tags its tools. Stopping a
standing automation should never need a mandate: give every such automation an ungated way to switch it off.

## The gate chain

`MCPToolExecutorService` per tool call, in order: admin gate, agent-space defaults, target space, entitlement,
eligibility, **mandate** (`refuseActionLackingMandate()`), then the tool body, which may call the owner-consent step
itself (`OwnerConsentsService`; the attribute `MCPToolRequiresOwnerConfirmation` is only a marker, and the executor
only turns an `OwnerConsentMissingException` into a gate refusal). Calls without `AgentToolCallProvenance` — an MCP client over HTTP, where the authenticated caller is the
consent-giver, and executor-direct calls of a console or admin path — and eval-harness calls are not gated.

`AgentMandatesService::findActiveMandateFor()` (registry cache off, after `markExpiredMandates()`): a mandate of the
call's space or an account-wide one. Then:

Which column applies is decided by the provenance, not the initiator type: a call with a `scheduleId` or inside a
delegation child takes the right column; every other call — a root run of an admin task or of a host's own owner-absent
type without a `scheduleId` included — takes the middle one, and its seed message is the "request" the grant is
checked against.

| Found | Root call without `scheduleId` | Delegation child or call with `scheduleId` |
|---|---|---|
| TIME_WINDOW or UNLIMITED, valid | pass | pass |
| ONE_INSTRUCTION that `authorizesRequest()` | bind it to the tool on first use, then pass | pass (a child) / never covers a scheduled run |
| ONE_INSTRUCTION of an earlier request | consumed, then as "none" | consumed; refusal (a scheduled run: "a deferred order needs a TIME_WINDOW mandate") |
| none | the platform grants a ONE_INSTRUCTION from the current request (`grantOneInstructionMandateFromTheCurrentRequest()`): no consent in the dialogue → refusal `MCPToolResponse::REFUSAL_MANDATE_REQUIRED` telling the model to ask the owner | refusal: a child ends and reports that the parent must make the first gated call; a scheduled run reports the missing permission |

**The consume happens before the refusal.** Any active ONE_INSTRUCTION mandate of the account (the space or
account-wide) and the class that `authorizesRequest()` rejects is closed by `consumeOneInstructionMandate()` before
the gate refuses or re-grants — also one that another conversation is still using. A gated call of a scheduled run, of
a parallel conversation or of another tree therefore ends the owner's live one-instruction grant elsewhere.

A class in `ACTION_CLASSES_NEEDING_A_STANDING_GRANT` is never granted per request; the refusal steers to `grantMandate`.
A call that fails after it stamped a first-use binding gives the binding back
(`releaseOneInstructionMandateBinding()`).

## One instruction

The unit of a ONE_INSTRUCTION grant is **the owner's request, never the tool call**:

- `AgentMandate::authorizesRequest()` passes when the call's root conversation and the owner message it serves
  (`AgentToolCallProvenance`) equal the grant's `sourceConversationId` and `sourceUserMessageId`, and the tool matches
  the binding (or the mandate is still unbound). Nothing is consumed per call; a redelivered call passes again.
- The first gated call binds the mandate to its tool's binding key (`bindOneInstructionMandateToTool()`, a guarded
  update); "delete my drafts" never also covers "publish".
- A sub-agent rides the root's grant without the message comparison. That is safe because the turn start closes the
  conversation's one-instruction mandates of earlier requests (`closeOutOneInstructionMandatesFromEarlierRequests()`),
  so while a turn runs only its own grant is active. The skill tells the model to make the first gated call itself
  before delegating gated work.
- The served owner message is `AgentToolCallProvenance::$sourceUserMessage`, set per call by
  `AgentToolCallExecutionService::executeToolCall()` to `AIConversation::getNewestUserMessage()` (the newest
  owner-authored loaded row); the dialogue the approval check reads is the provenance's loaded message set. An owner
  message that reaches the run's message set changes it, and the next gated call checks consent against it.
- Before every gate lookup `findActiveMandateFor()` runs `markExpiredMandates()`: passed time windows become EXPIRED and
  one-instruction grants older than a day CONSUMED. A request that runs longer than a day loses its grant.

## Unattended runs

No owner is present to consent, so a call with a `scheduleId` and a call inside a sub-agent are **never** granted a
mandate by the platform. The gate does not read the initiator type: an owner-absent root run without a `scheduleId`
(an admin task, a host's own type) is treated like an attended turn and its seed message is checked for consent.
A deferred order ("do it tonight at eight") needs a TIME_WINDOW mandate granted while the owner is present, in the
same turn that creates the schedule. Every gated call of a scheduled run passes the live gate, so a revoke stops a
running schedule at its next gated call. Nothing re-checks a mandate when a schedule fires — the docblock of the
revoke speaks of a fire-time re-validation that has no code. A worker of the host that acts outward without the agent
loop must ask `findActiveMandateFor()` itself.

## The grant path

`AgentMandatesService::grantMandateFromDialogue()`, used by `grantMandate` and by the per-request auto-grant:

1. Space check; idempotency — a ONE_INSTRUCTION for the same request that already exists is returned without a model
   call (`AgentMandateGrantOutcome::forAlreadyCovered()`).
2. The dialogue is pulled server-side: `AIConversationMessages::buildUserVisibleDialogueExcerptEndingAtMessage()` (owner
   and agent text only, fenced blocks stripped, word-budgeted). The model can neither supply nor forge the evidence.
3. `ArgusAgentMandateApproval` judges consent (prompt `AgentAIPromptNames::APP_AI_MANDATE_APPROVAL_CHECK`, JSON, the
   task prompt in the system role, budget not enforced). A verdict counts only with a boolean `approved` and a matching
   `verdictCode` (`AgentMandateApproval::VERDICT_CONSENT_FOUND` or one of `REJECTION_VERDICT_CODES`); anything else
   is retried once, then reported as "did not complete", never as a rejection.
4. Approved: the ACTIVE row with the excerpt, then `supersedeCoveredMandates()` (same class, covered space).

The prompt's rules a host should know: only the owner's lines count; the class is a fact of the code, not judged; a
direct order of one concrete action is one-instruction consent; a standing grant needs consent to the class as a
standing permission; a later moment allows a TIME_WINDOW ending at it; never reject a subset of what was consented.

## The tools

Package `AgentMandatesMCPPackage` (skill `Assistant.Mandates`): `grantMandate` (class, scope, `validUntil`,
constraints, space or account-wide), `listMandates`, `revokeMandate`. Their logic is `AgentMandateToolCallsMCPPackage`.
The skill teaches: never grant on the agent's own initiative; match the scope to the owner's words; revoke on any
utterance that takes a permission back.

## Revocation

`revokeMandateOfAccount()` → REVOKED, no approval check (withdrawing is always allowed). Then
`AgentMandateRevocationEffectsService::onMandateRevoked()` — **the default does nothing**. A host overrides it to switch
off what the mandate allowed (a standing automation of its own). It never blocks the revoke.

## What a host provides

Full class names of what a host extends or calls here: `DDD\Domain\AI\Entities\Mandates\AgentMandate`, `DDD\Domain\AI\Services\Mandates\AgentMandateRevocationEffectsService`, `DDD\Domain\AI\Services\Mandates\AgentMandatesService`.

- Its action classes and descriptions on its same-path `AgentMandate`.
- `MCPToolRequiresMandate` on each tool that needs one, with shared binding keys where one intent spans tools.
- `AgentMandateRevocationEffectsService` when a revoke must stop something.
- A screen to list and revoke mandates, if the owner should see them outside the chat.

## Traps

- A tool tagged with an action class the host did not declare: the grant refuses the class.
- Expecting a scheduled run to ride the owner's one-instruction grant: it never does — and its gated call closes that
  grant (see the gate chain).
- Starting an owner-absent run without a `scheduleId` that calls gated tools: the gate reads its seed message as the
  owner's consent.
- A new grant supersedes an active mandate of the same class and space regardless of scope, so a one-instruction
  `grantMandate` replaces an unlimited mandate there. The skill reserves `grantMandate` for standing grants.
