---
decisionCriteria: "Standing permissions {%AGENT_NAME%} holds to act outward on the owner's behalf: grant one, list them, revoke one."
shortDescription: "standing permissions you grant {%AGENT_NAME%} for outward actions: grant, list, revoke"
description: "Standing permissions for {%AGENT_NAME%} to act outward on its own: grant one after the owner's clear say-so or after a tool was refused for a missing mandate, list them, revoke one, time-bounded permissions."
---

# Mandates — the customer's standing authorizations

**Scope and boundaries.** Load it when (a) a gated tool was refused with "requires an active MANDATE" → grant one here
after the customer's clear say-so; (b) the customer hands an outward action over to you to carry out on your own
going forward → `grantMandate`; a hand-over bounded to a PERIOD is a `TIME_WINDOW` mandate; a hand-over bounded to a
QUANTITY (a number of items) also starts here, but a quantity cannot become a mandate: offer a time-limited or an
unlimited mandate instead (rule below); (c) the customer asks what you are allowed to do on your own →
`listMandates`; (d) the customer takes a permission BACK → `revokeMandate` (any utterance suffices — when in doubt,
revoke). NOT a mandate: one-off actions the customer just asked for in chat (do those directly; irreversible ones need
the owner's own confirmation); preferences about how you talk to the owner in chat.

A MANDATE is the customer's persisted permission for you to perform a CLASS of consequential, outward-facing actions
— for ONE customer request (`ONE_INSTRUCTION`), for a period (`TIME_WINDOW`), or until revoked (`UNLIMITED`). The
action classes are the values the `grantMandate` schema lists.

**A single action now needs no grant from you.** When you call a mandate-gated tool, the platform checks the
customer's current request against the dialogue itself and grants the ONE_INSTRUCTION when they asked for or agreed to
the action (a direct order, or their yes to what you showed them). A refusal means the customer has not asked yet:
ask them plainly, then call the tool again after their answer. Call `grantMandate` only for a STANDING permission
(`TIME_WINDOW`, `UNLIMITED`) the customer asked for, and for a deferred order (below). Before you delegate gated work
to sub-agents, make the FIRST gated call yourself: it checks the consent, and that permission then covers the
sub-agents working the same request.

## How granting works — and why you cannot shortcut it

`grantMandate` takes NO transcript and NO quotes. The server pulls the recent dialogue from this conversation itself
and an independent check verifies that the CUSTOMER actually granted exactly what you request — action class AND
scope. It stores the customer's verbatim words as evidence, and rejects with a reason when consent is missing,
ambiguous, or mismatched. Consequences:

- **Never call grantMandate on your own initiative.** First the customer must have clearly asked for it or clearly
  agreed to your offer in THIS conversation. A polite approval of a general idea is not consent — ask plainly: once,
  for a period, or until revoked?
- **Match the scope to their words.** An open-ended hand-over (from now on, always) → `UNLIMITED`; a stated period →
  `TIME_WINDOW` + `validUntil`; a single execution now needs no grant (the platform checks it at the tool call).
- **A batch is ONE grant.** A ONE_INSTRUCTION covers every call of the customer's current request through the tool
  you use first under it (tools of one intent share it). A DIFFERENT action of the same class is not covered: it needs
  the customer's own consent. Put what the customer asked for into `constraintsNote` as one plain sentence — never id
  lists, never counts. Delegated sub-agents working the same request are covered automatically — never grant inside a
  delegate.
- **Compute relative dates yourself.** For TIME_WINDOW, derive `validUntil` from the customer's relative wording and
  today's date (you know it from your context) — NEVER ask the customer to spell out a calendar date they already
  gave relatively, and NEVER produce a past date.
- **Withdrawing a permission is ALWAYS `revokeMandate`.** Get the id via `listMandates`, call `revokeMandate`, and
  never claim a permission is withdrawn without that COMPLETED call. Where the permission also powers a standing
  automation, additionally switch the automation off with its own stop tool and confirm both results.
- **A deferred/scheduled order needs TIME_WINDOW, never ONE_INSTRUCTION.** An order carried out later runs in a
  scheduled conversation where the customer is absent. Grant TIME_WINDOW with `validUntil` shortly after the planned
  run time, in the same turn you schedule the task; the customer's timed instruction IS that window's consent.
- **Quantity wishes cannot become mandates.** Offer a time-limited or unlimited mandate instead and let the customer
  choose; never widen the consent silently.
- **Constraints belong in `constraintsNote`** exactly as stated — the check verifies them too.
- A mandate binds to the current workspace by default; `accountWide: true` only when the customer clearly meant all
  their workspaces.

## Changing a mandate

Changing (extend, shorten, different scope type, new constraints) is simply a NEW `grantMandate` for the same action
class — the server REPLACES the covered old mandate automatically (it becomes SUPERSEDED, kept in the history) and the
tool response names what was replaced. If the new grant is rejected, the old mandate stays untouched. NARROWING an
account-wide mandate down to one workspace is an explicit `revokeMandate` of the account-wide one plus a new
workspace-bound grant.

## Revoking

`revokeMandate` is immediate and needs NO verification — any customer utterance that they no longer want it suffices;
when in doubt, revoke. Get the id from `listMandates`, confirm briefly what is now off.

## Reporting

After a successful STANDING grant, tell the customer in ONE sentence what you are now allowed to do and that a word
from them stops it at once. A single action the customer asked for is reported as done "as you asked" — never as a
permission you granted.
